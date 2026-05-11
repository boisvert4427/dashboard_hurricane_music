<?php

declare(strict_types=1);

use App\Entity\CompetitorUrlTestResult;
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = dirname(__DIR__);
if (class_exists(Dotenv::class)) {
    (new Dotenv())->usePutenv()->bootEnv($projectRoot . '/.env');
}

$lockDir = $projectRoot . '/var/lock/competitive-intelligence';
if (!is_dir($lockDir) && !mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
    fwrite(STDERR, sprintf("Unable to create lock directory \"%s\".\n", $lockDir));
    exit(1);
}

$lockPath = $lockDir . '/fix-pending-image-urls.lock';
$lockHandle = fopen($lockPath, 'c+');
if ($lockHandle === false) {
    fwrite(STDERR, sprintf("Unable to open lock file \"%s\".\n", $lockPath));
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fclose($lockHandle);
    echo "Batch already running.\n";
    exit(0);
}

register_shutdown_function(static function () use ($lockHandle): void {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
});

$batchSize = 10;
$apply = false;

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--batch-size=')) {
        $batchSize = max(1, (int) substr($argument, 13));
    }
    if ($argument === '--apply') {
        $apply = true;
    }
}

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();

$doctrine = $kernel->getContainer()->get('doctrine');
$entityManager = $doctrine->getManager();
$connection = $entityManager->getConnection();
$cookieFile = tempnam(sys_get_temp_dir(), 'ci-image-cookie-') ?: null;
if (is_string($cookieFile) && $cookieFile !== '') {
    register_shutdown_function(static function () use ($cookieFile): void {
        if (is_file($cookieFile)) {
            @unlink($cookieFile);
        }
    });
}

$rows = $entityManager->getRepository(CompetitorUrlTestResult::class)
    ->createQueryBuilder('t')
    ->innerJoin('t.competitor', 'competitor')
    ->select(
        't.productId AS product_id, ' .
        'IDENTITY(t.competitor) AS competitor_id, ' .
        'competitor.name AS competitor_name, ' .
        't.url AS page_url, ' .
        't.competitorImageUrl AS current_image_url, ' .
        't.competitorPageStatus AS current_page_status'
    )
    ->andWhere('t.validationStatus = :status')
    ->andWhere('competitor.name IN (:names)')
    ->andWhere('t.competitorPageStatus = :page_status')
    ->andWhere('(t.competitorImageUrl IS NULL OR t.competitorImageUrl = \'\')')
    ->setParameter('status', CompetitorUrlTestResult::REVIEW_PENDING)
    ->setParameter('page_status', CompetitorUrlTestResult::PAGE_OK)
    ->setParameter('names', ['Thomann', 'Michenaud'])
    ->orderBy('t.lastTestedAt', 'DESC')
    ->setMaxResults($batchSize)
    ->getQuery()
    ->getArrayResult();

$connection->close();

$updated = 0;
$skipped = 0;
$failed = 0;
$processed = 0;

if ($rows === []) {
    echo "No pending rows found for Thomann or Michenaud.\n";
    exit(0);
}

echo sprintf("Processing %d row(s)\n", count($rows));

foreach ($rows as $row) {
    $processed++;
    $competitorName = (string) ($row['competitor_name'] ?? '');
    $productId = (int) ($row['product_id'] ?? 0);
    $competitorId = (int) ($row['competitor_id'] ?? 0);
    $pageUrl = (string) ($row['page_url'] ?? '');
    $currentImageUrl = isset($row['current_image_url']) ? (string) $row['current_image_url'] : null;
    $currentPageStatus = strtolower(trim((string) ($row['current_page_status'] ?? CompetitorUrlTestResult::PAGE_OK)));

    try {
        humanPause();
        $pageResult = fetchPage($pageUrl, $competitorName, $cookieFile);
        if ($pageResult['status'] === CompetitorUrlTestResult::PAGE_GONE) {
            echo sprintf(
                " - %d / %s: page gone (%s)\n",
                $productId,
                $competitorName,
                $pageUrl
            );
            if ($apply && $currentPageStatus !== CompetitorUrlTestResult::PAGE_GONE) {
                $connection->executeStatement(
                    'UPDATE competitor_url_test_result
                     SET competitor_page_status = :page_status
                     WHERE id_product = :product_id AND competitor_id = :competitor_id',
                    [
                        'page_status' => CompetitorUrlTestResult::PAGE_GONE,
                        'product_id' => $productId,
                        'competitor_id' => $competitorId,
                    ]
                );
                $updated++;
            } else {
                $skipped++;
            }
            continue;
        }

        if (isGoneRedirect($pageUrl, (string) ($pageResult['final_url'] ?? ''), $competitorName, (string) ($pageResult['html'] ?? ''))) {
            echo sprintf(
                " - %d / %s: page gone (%s -> %s)\n",
                $productId,
                $competitorName,
                $pageUrl,
                (string) ($pageResult['final_url'] ?? '')
            );
            if ($apply && $currentPageStatus !== CompetitorUrlTestResult::PAGE_GONE) {
                $connection->executeStatement(
                    'UPDATE competitor_url_test_result
                     SET competitor_page_status = :page_status
                     WHERE id_product = :product_id AND competitor_id = :competitor_id',
                    [
                        'page_status' => CompetitorUrlTestResult::PAGE_GONE,
                        'product_id' => $productId,
                        'competitor_id' => $competitorId,
                    ]
                );
                $updated++;
            } else {
                $skipped++;
            }
            continue;
        }

        $newImageUrl = extractCompetitorImageUrlFromHtml($pageResult['html'], $pageUrl, $competitorName);
        if ($newImageUrl === null || trim($newImageUrl) === '') {
            $failed++;
            echo sprintf(
                " - %d / %s: image not found (%s)\n",
                $productId,
                $competitorName,
                $pageUrl
            );
            continue;
        }

        if ($newImageUrl === $currentImageUrl) {
            $skipped++;
            echo sprintf(
                " - %d / %s: unchanged\n",
                $productId,
                $competitorName
            );
            continue;
        }

        echo sprintf(
            " - %d / %s:\n   old=%s\n   new=%s\n",
            $productId,
            $competitorName,
            $currentImageUrl ?? 'null',
            $newImageUrl
        );

        if ($apply) {
            $connection->executeStatement(
                'UPDATE competitor_url_test_result
                 SET competitor_image_url = :image_url,
                     competitor_page_status = :page_status
                 WHERE id_product = :product_id AND competitor_id = :competitor_id',
                [
                    'image_url' => $newImageUrl,
                    'page_status' => CompetitorUrlTestResult::PAGE_OK,
                    'product_id' => $productId,
                    'competitor_id' => $competitorId,
                ]
            );
        }
        $updated++;
    } catch (Throwable $e) {
        $failed++;
        echo sprintf(
            " - %d / %s: error: %s\n",
            $productId,
            $competitorName,
            $e->getMessage()
        );
    }
}

echo sprintf(
    "Done. processed=%d updated=%d skipped=%d failed=%d mode=%s\n",
    $processed,
    $updated,
    $skipped,
    $failed,
    $apply ? 'apply' : 'dry-run'
);

function humanPause(): void
{
    usleep(random_int(2000000, 5000000));
}

function extractCompetitorImageUrlFromHtml(?string $html, string $pageUrl, string $competitorName): ?string
{
    if ($html === null || trim($html) === '') {
        return null;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $baseUrl = getBaseUrl($pageUrl);
    $competitor = strtolower(trim($competitorName));

    $queries = [];
    if (str_contains($competitor, 'michenaud')) {
        $queries = [
            '//*[@id="photoCover"]/@src',
            '//*[@id="photoCover"]//img/@src',
            '//*[@id="photoCover"]/@data-src',
            '//*[@id="photoCover"]//img/@data-src',
            '//meta[@property="og:image"]/@content',
            '//meta[@name="twitter:image"]/@content',
            '//link[@rel="image_src"]/@href',
        ];
    } elseif (str_contains($competitor, 'thomann')) {
        $queries = [
            '//meta[@property="og:image"]/@content',
            '//meta[@name="twitter:image"]/@content',
            '//link[@rel="image_src"]/@href',
            '//img[@itemprop="image"]/@src',
            '//img[contains(@class,"product")]/@src',
        ];
    } else {
        $queries = [
            '//meta[@property="og:image"]/@content',
            '//meta[@name="twitter:image"]/@content',
            '//link[@rel="image_src"]/@href',
        ];
    }

    foreach ($queries as $query) {
        $nodes = $xpath->query($query);
        if ($nodes instanceof DOMNodeList && $nodes->length > 0) {
            $candidate = trim((string) $nodes->item(0)?->nodeValue);
            $candidate = absolutizeUrl($baseUrl, $candidate);
            if ($candidate !== null) {
                return $candidate;
            }
        }
    }

    return null;
}

function fetchPage(string $url, string $competitorName, ?string $cookieFile): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['status' => CompetitorUrlTestResult::PAGE_GONE, 'html' => null];
    }

    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: fr-FR,fr;q=0.9,en;q=0.8',
    ];
    $referer = getRefererForCompetitor($competitorName);

    curl_setopt_array($ch, [
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
    ]);

    if ($referer !== '') {
        curl_setopt($ch, CURLOPT_REFERER, $referer);
    }

    if (is_string($cookieFile) && $cookieFile !== '') {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    }

    $body = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($statusCode === 404 || $statusCode === 410) {
        return ['status' => CompetitorUrlTestResult::PAGE_GONE, 'html' => null, 'final_url' => $finalUrl, 'http_code' => $statusCode];
    }

    if ($body === false || $statusCode >= 400) {
        return ['status' => CompetitorUrlTestResult::PAGE_OK, 'html' => null, 'final_url' => $finalUrl, 'http_code' => $statusCode];
    }

    return ['status' => CompetitorUrlTestResult::PAGE_OK, 'html' => (string) $body, 'final_url' => $finalUrl, 'http_code' => $statusCode];
}

function getRefererForCompetitor(string $competitorName): string
{
    $competitor = strtolower(trim($competitorName));

    if (str_contains($competitor, 'thomann')) {
        return 'https://www.thomann.fr/';
    }

    if (str_contains($competitor, 'michenaud')) {
        return 'https://www.michenaud.com/';
    }

    return '';
}

function isGoneRedirect(string $originalUrl, string $finalUrl, string $competitorName, string $html): bool
{
    $originalUrl = trim($originalUrl);
    $finalUrl = trim($finalUrl);
    if ($originalUrl === '' || $finalUrl === '' || $originalUrl === $finalUrl) {
        return false;
    }

    $originalHost = parse_url($originalUrl, PHP_URL_HOST) ?: '';
    $finalHost = parse_url($finalUrl, PHP_URL_HOST) ?: '';
    if ($originalHost !== '' && $finalHost !== '' && strtolower($originalHost) !== strtolower($finalHost)) {
        return false;
    }

    $competitor = strtolower(trim($competitorName));
    $finalPath = (string) parse_url($finalUrl, PHP_URL_PATH);
    $originalPath = (string) parse_url($originalUrl, PHP_URL_PATH);

    if (str_contains($competitor, 'michenaud')) {
        return preg_match('~/p\\d+/~', $originalPath) === 1 && preg_match('~/p\\d+/~', $finalPath) !== 1;
    }

    if (str_contains($competitor, 'thomann')) {
        $hasProductExtension = preg_match('~\\.htm[l]?$~i', $originalPath) === 1;
        $finalLooksLikeProduct = preg_match('~\\.htm[l]?$~i', $finalPath) === 1;

        return $hasProductExtension && !$finalLooksLikeProduct && !str_contains(strtolower($html), 'just a moment');
    }

    return false;
}

function getBaseUrl(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $base = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $base .= ':' . $parts['port'];
    }

    return $base;
}

function absolutizeUrl(string $baseUrl, string $candidate): ?string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return null;
    }

    if (str_starts_with($candidate, '//')) {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

        return $scheme . ':' . $candidate;
    }

    if (preg_match('~^https?://~i', $candidate)) {
        return $candidate;
    }

    if ($baseUrl === '') {
        return null;
    }

    if (str_starts_with($candidate, '/')) {
        return rtrim($baseUrl, '/') . $candidate;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($candidate, '/');
}
