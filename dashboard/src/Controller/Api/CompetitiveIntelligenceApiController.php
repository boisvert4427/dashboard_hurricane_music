<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Competitor;
use App\Service\CompetitiveIntelligence\CompetitiveFinalPriceIngestionService;
use App\Service\CompetitiveIntelligence\CompetitiveBatchRunner;
use App\Service\CompetitiveIntelligence\CompetitiveTestResultIngestionService;
use App\Service\CompetitiveIntelligence\FinalPriceBatchRunner;
use App\Service\CompetitiveIntelligence\FinalUrlPriceBatchProvider;
use App\Service\CompetitiveIntelligence\PrestashopProductBatchProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Process\Process;

#[Route('/api/competitive')]
final class CompetitiveIntelligenceApiController extends AbstractController
{
    private const RUN_ALL_LOCK_NAME = 'run-all.lock';

    #[Route('/products/next-batch', name: 'api_competitive_products_next_batch', methods: ['GET'])]
    public function nextBatch(
        Request $request,
        PrestashopProductBatchProvider $batchProvider,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        if (!$this->isAuthorized($request)) {
            return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $competitorId = max(1, (int) $request->query->get('competitor_id', 0));
        $limit = max(1, (int) $request->query->get('limit', 50));
        $afterId = max(0, (int) $request->query->get('after_id', 0));
        $langId = max(1, (int) $request->query->get('lang_id', 1));
        $shopId = max(1, (int) $request->query->get('shop_id', 1));

        $competitor = $entityManager->getRepository(Competitor::class)->find($competitorId);
        if (!$competitor instanceof Competitor) {
            return $this->json([
                'ok' => false,
                'error' => sprintf('Unknown competitor_id "%d".', $competitorId),
            ], 404);
        }

        try {
            $batch = $batchProvider->getNextBatch($competitorId, $limit, $afterId, $langId, $shopId);
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }

        return $this->json(array_merge([
            'ok' => true,
            'competitor' => [
                'id' => $competitor->getId(),
                'name' => $competitor->getName(),
                'domain' => $competitor->getDomain(),
                'search_url_pattern' => $competitor->getSearchUrlPattern(),
            ],
        ], $batch));
    }

    #[Route('/run-batch', name: 'api_competitive_run_batch', methods: ['GET', 'POST'])]
    public function runBatch(
        Request $request,
        PrestashopProductBatchProvider $batchProvider,
        CompetitiveBatchRunner $batchRunner,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        if (!$this->isAuthorized($request)) {
            return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $competitorId = max(1, (int) $request->query->get('competitor_id', 0));
        $limit = max(1, (int) $request->query->get('limit', 10));
        $afterId = max(0, (int) $request->query->get('after_id', 0));
        $langId = max(1, (int) $request->query->get('lang_id', 1));
        $shopId = max(1, (int) $request->query->get('shop_id', 1));
        $debug = in_array(strtolower((string) $request->query->get('debug', '0')), ['1', 'true', 'yes', 'on'], true);
        $maxParallel = max(0, (int) $request->query->get('max_parallel', 0));

        $competitor = $entityManager->getRepository(Competitor::class)->find($competitorId);
        if (!$competitor instanceof Competitor) {
            return $this->json([
                'ok' => false,
                'error' => sprintf('Unknown competitor_id "%d".', $competitorId),
            ], 404);
        }

        try {
            $projectDir = (string) $this->getParameter('kernel.project_dir');
            $projectRoot = dirname($projectDir);
            $batch = $batchProvider->getNextBatch($competitorId, $limit, $afterId, $langId, $shopId);
            $run = $batchRunner->start(
                $projectDir,
                $request->getSchemeAndHttpHost(),
                (string) $this->getParameter('competitive_intelligence_api_token'),
                $competitorId,
                $limit,
                $afterId,
                $langId,
                $shopId,
                $debug,
                $maxParallel,
            );
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }

        return $this->json([
            'ok' => true,
            'started' => true,
            'pid' => $run['pid'],
            'command' => $run['command'],
            'project_root' => $projectRoot,
            'log_file' => $run['log_file'] ?? null,
            'max_parallel' => $maxParallel,
            'batch' => $batch,
            'competitor' => [
                'id' => $competitor->getId(),
                'name' => $competitor->getName(),
                'domain' => $competitor->getDomain(),
                'search_url_pattern' => $competitor->getSearchUrlPattern(),
            ],
        ]);
    }

    #[Route('/run-all', name: 'api_competitive_run_all', methods: ['GET', 'POST'])]
    #[Route('/run-both', name: 'api_competitive_run_both', methods: ['GET', 'POST'])]
    public function runBoth(
        Request $request,
        CompetitiveBatchRunner $batchRunner,
        FinalPriceBatchRunner $finalPriceBatchRunner,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        if (!$this->isAuthorized($request)) {
            return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $limit = max(1, (int) $request->query->get('limit', 5));
        $langId = max(1, (int) $request->query->get('lang_id', 1));
        $shopId = max(1, (int) $request->query->get('shop_id', 1));
        $debug = in_array(strtolower((string) $request->query->get('debug', '0')), ['1', 'true', 'yes', 'on'], true);
        $maxParallel = max(0, (int) $request->query->get('max_parallel', 2));
        $priceLimit = max(1, (int) $request->query->get('price_limit', $limit));
        $afterIdWoodbrass = max(0, (int) $request->query->get('after_id_woodbrass', 0));
        $afterIdStarsMusic = max(0, (int) $request->query->get('after_id_starsmusic', 0));
        $afterIdThomann = max(0, (int) $request->query->get('after_id_thomann', 0));
        $afterIdMichenaud = max(0, (int) $request->query->get('after_id_michenaud', 0));
        $priceAfterIdWoodbrass = max(0, (int) $request->query->get('price_after_id_woodbrass', 0));
        $priceAfterIdStarsMusic = max(0, (int) $request->query->get('price_after_id_starsmusic', 0));
        $priceAfterIdThomann = max(0, (int) $request->query->get('price_after_id_thomann', 0));
        $priceAfterIdMichenaud = max(0, (int) $request->query->get('price_after_id_michenaud', 0));

        $competitorIds = [
            'woodbrass' => 1,
            'stars_music' => 2,
            'thomann' => 3,
            'michenaud' => 4,
        ];

        $competitors = [];
        foreach ($competitorIds as $key => $competitorId) {
            $competitor = $entityManager->getRepository(Competitor::class)->find($competitorId);
            if (!$competitor instanceof Competitor) {
                return $this->json([
                    'ok' => false,
                    'error' => sprintf('Unknown competitor_id "%d" for "%s".', $competitorId, $key),
                ], 404);
            }
            $competitors[$key] = $competitor;
        }

        $lockHandle = $this->acquireRunAllLock();
        if ($lockHandle === null) {
            return $this->json([
                'ok' => false,
                'error' => 'run-all is already running.',
            ], 409);
        }

        try {
            try {
                $projectDir = (string) $this->getParameter('kernel.project_dir');
                $apiBaseUrl = $request->getSchemeAndHttpHost();
                $apiToken = (string) $this->getParameter('competitive_intelligence_api_token');

                $runs = [
                    'woodbrass' => $batchRunner->start(
                        $projectDir,
                        $apiBaseUrl,
                        $apiToken,
                        $competitorIds['woodbrass'],
                        $limit,
                        $afterIdWoodbrass,
                        $langId,
                        $shopId,
                        $debug,
                        $maxParallel,
                    ),
                    'stars_music' => $batchRunner->start(
                        $projectDir,
                        $apiBaseUrl,
                        $apiToken,
                        $competitorIds['stars_music'],
                        $limit,
                        $afterIdStarsMusic,
                        $langId,
                        $shopId,
                        $debug,
                        $maxParallel,
                    ),
                    'thomann' => $batchRunner->start(
                        $projectDir,
                        $apiBaseUrl,
                        $apiToken,
                        $competitorIds['thomann'],
                        $limit,
                        $afterIdThomann,
                        $langId,
                        $shopId,
                        $debug,
                        $maxParallel,
                    ),
                    'michenaud' => $batchRunner->start(
                        $projectDir,
                        $apiBaseUrl,
                        $apiToken,
                        $competitorIds['michenaud'],
                        $limit,
                        $afterIdMichenaud,
                        $langId,
                        $shopId,
                        $debug,
                        $maxParallel,
                    ),
                ];
                $priceRuns = [
                    'woodbrass' => $finalPriceBatchRunner->start(
                        $projectDir,
                        $apiBaseUrl,
                        $apiToken,
                        $competitorIds['woodbrass'],
                        $priceLimit,
                        $priceAfterIdWoodbrass,
                        $debug,
                        $maxParallel,
                    ),
                    'stars_music' => $finalPriceBatchRunner->start(
                        $projectDir,
                        $apiBaseUrl,
                        $apiToken,
                        $competitorIds['stars_music'],
                        $priceLimit,
                        $priceAfterIdStarsMusic,
                        $debug,
                        $maxParallel,
                    ),
                    'thomann' => $finalPriceBatchRunner->start(
                        $projectDir,
                        $apiBaseUrl,
                        $apiToken,
                        $competitorIds['thomann'],
                        $priceLimit,
                        $priceAfterIdThomann,
                        $debug,
                        $maxParallel,
                    ),
                    'michenaud' => $finalPriceBatchRunner->start(
                        $projectDir,
                        $apiBaseUrl,
                        $apiToken,
                        $competitorIds['michenaud'],
                        $priceLimit,
                        $priceAfterIdMichenaud,
                        $debug,
                        $maxParallel,
                    ),
                ];
            } catch (\Throwable $e) {
                return $this->json([
                    'ok' => false,
                    'error' => $e->getMessage(),
                ], 500);
            }
        } finally {
            $this->releaseRunAllLock($lockHandle);
        }

        return $this->json([
            'ok' => true,
            'started' => true,
            'limit' => $limit,
            'lang_id' => $langId,
            'shop_id' => $shopId,
            'debug' => $debug,
            'max_parallel' => $maxParallel,
            'price_limit' => $priceLimit,
            'runs' => [
                'woodbrass' => [
                    'competitor' => [
                        'id' => $competitors['woodbrass']->getId(),
                        'name' => $competitors['woodbrass']->getName(),
                        'domain' => $competitors['woodbrass']->getDomain(),
                    ],
                    'pid' => $runs['woodbrass']['pid'],
                    'command' => $runs['woodbrass']['command'],
                    'log_file' => $runs['woodbrass']['log_file'] ?? null,
                    'after_id' => $afterIdWoodbrass,
                ],
                'stars_music' => [
                    'competitor' => [
                        'id' => $competitors['stars_music']->getId(),
                        'name' => $competitors['stars_music']->getName(),
                        'domain' => $competitors['stars_music']->getDomain(),
                    ],
                    'pid' => $runs['stars_music']['pid'],
                    'command' => $runs['stars_music']['command'],
                    'log_file' => $runs['stars_music']['log_file'] ?? null,
                    'after_id' => $afterIdStarsMusic,
                ],
                'thomann' => [
                    'competitor' => [
                        'id' => $competitors['thomann']->getId(),
                        'name' => $competitors['thomann']->getName(),
                        'domain' => $competitors['thomann']->getDomain(),
                    ],
                    'pid' => $runs['thomann']['pid'],
                    'command' => $runs['thomann']['command'],
                    'log_file' => $runs['thomann']['log_file'] ?? null,
                    'after_id' => $afterIdThomann,
                ],
                'michenaud' => [
                    'competitor' => [
                        'id' => $competitors['michenaud']->getId(),
                        'name' => $competitors['michenaud']->getName(),
                        'domain' => $competitors['michenaud']->getDomain(),
                    ],
                    'pid' => $runs['michenaud']['pid'],
                    'command' => $runs['michenaud']['command'],
                    'log_file' => $runs['michenaud']['log_file'] ?? null,
                    'after_id' => $afterIdMichenaud,
                ],
            ],
            'price_runs' => [
                'woodbrass' => [
                    'competitor' => [
                        'id' => $competitors['woodbrass']->getId(),
                        'name' => $competitors['woodbrass']->getName(),
                        'domain' => $competitors['woodbrass']->getDomain(),
                    ],
                    'pid' => $priceRuns['woodbrass']['pid'],
                    'command' => $priceRuns['woodbrass']['command'],
                    'log_file' => $priceRuns['woodbrass']['log_file'] ?? null,
                    'after_id' => $priceAfterIdWoodbrass,
                ],
                'stars_music' => [
                    'competitor' => [
                        'id' => $competitors['stars_music']->getId(),
                        'name' => $competitors['stars_music']->getName(),
                        'domain' => $competitors['stars_music']->getDomain(),
                    ],
                    'pid' => $priceRuns['stars_music']['pid'],
                    'command' => $priceRuns['stars_music']['command'],
                    'log_file' => $priceRuns['stars_music']['log_file'] ?? null,
                    'after_id' => $priceAfterIdStarsMusic,
                ],
                'thomann' => [
                    'competitor' => [
                        'id' => $competitors['thomann']->getId(),
                        'name' => $competitors['thomann']->getName(),
                        'domain' => $competitors['thomann']->getDomain(),
                    ],
                    'pid' => $priceRuns['thomann']['pid'],
                    'command' => $priceRuns['thomann']['command'],
                    'log_file' => $priceRuns['thomann']['log_file'] ?? null,
                    'after_id' => $priceAfterIdThomann,
                ],
                'michenaud' => [
                    'competitor' => [
                        'id' => $competitors['michenaud']->getId(),
                        'name' => $competitors['michenaud']->getName(),
                        'domain' => $competitors['michenaud']->getDomain(),
                    ],
                    'pid' => $priceRuns['michenaud']['pid'],
                    'command' => $priceRuns['michenaud']['command'],
                    'log_file' => $priceRuns['michenaud']['log_file'] ?? null,
                    'after_id' => $priceAfterIdMichenaud,
                ],
            ],
        ]);
    }

    #[Route('/final-prices/next-batch', name: 'api_competitive_final_prices_next_batch', methods: ['GET'])]
    public function nextFinalPriceBatch(
        Request $request,
        FinalUrlPriceBatchProvider $batchProvider,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        if (!$this->isAuthorized($request)) {
            return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $competitorId = max(1, (int) $request->query->get('competitor_id', 0));
        $limit = max(1, (int) $request->query->get('limit', 50));
        $afterId = max(0, (int) $request->query->get('after_id', 0));

        $competitor = $entityManager->getRepository(Competitor::class)->find($competitorId);
        if (!$competitor instanceof Competitor) {
            return $this->json([
                'ok' => false,
                'error' => sprintf('Unknown competitor_id "%d".', $competitorId),
            ], 404);
        }

        try {
            $batch = $batchProvider->getNextBatch($competitorId, $limit, $afterId);
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }

        return $this->json(array_merge([
            'ok' => true,
            'competitor' => [
                'id' => $competitor->getId(),
                'name' => $competitor->getName(),
                'domain' => $competitor->getDomain(),
                'search_url_pattern' => $competitor->getSearchUrlPattern(),
            ],
        ], $batch));
    }

    #[Route('/final-prices', name: 'api_competitive_final_prices_ingest', methods: ['POST'])]
    public function ingestFinalPrices(
        Request $request,
        CompetitiveFinalPriceIngestionService $finalPriceIngestionService,
    ): JsonResponse {
        if (!$this->isAuthorized($request)) {
            return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['ok' => false, 'error' => 'Invalid JSON payload.'], 400);
        }

        try {
            $stats = ['inserted' => 0, 'updated' => 0, 'ignored' => 0];
            if (array_key_exists('observations', $payload)) {
                $stats = $finalPriceIngestionService->ingest($payload);
            }
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        }

        return $this->json([
            'ok' => true,
            'price_inserted' => $stats['inserted'],
            'price_updated' => $stats['updated'],
            'price_ignored' => $stats['ignored'],
        ]);
    }

    #[Route('/fix-pending-image-urls', name: 'api_competitive_fix_pending_image_urls', methods: ['GET', 'POST'])]
    public function fixPendingImageUrls(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $batchSize = max(1, (int) $request->query->get('batch_size', 10));
        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $script = $projectDir . '/scripts/fix_pending_competitor_image_urls.php';

        if (!is_file($script)) {
            return $this->json([
                'ok' => false,
                'error' => sprintf('Script not found at "%s".', $script),
            ], 500);
        }

        $command = [
            'php',
            $script,
            '--batch-size=' . $batchSize,
        ];
        if (in_array(strtolower((string) $request->query->get('apply', '1')), ['1', 'true', 'yes', 'on'], true)) {
            $command[] = '--apply';
        }

        $process = new Process($command, $projectDir, null, null, 1800);
        $process->run();

        return $this->json([
            'ok' => $process->isSuccessful(),
            'batch_size' => $batchSize,
            'apply' => in_array(strtolower((string) $request->query->get('apply', '1')), ['1', 'true', 'yes', 'on'], true),
            'exit_code' => $process->getExitCode(),
            'output' => trim($process->getOutput()),
            'error_output' => trim($process->getErrorOutput()),
            'command' => implode(' ', array_map('escapeshellarg', $command)),
        ], $process->isSuccessful() ? 200 : 500);
    }

    /**
     * @return resource|null
     */
    private function acquireRunAllLock()
    {
        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $lockDir = $projectDir . '/competitive_intelligence_python/var/lock/competitive-intelligence';
        if (!is_dir($lockDir) && !mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            throw new \RuntimeException(sprintf('Unable to create lock directory "%s".', $lockDir));
        }

        $lockPath = $lockDir . '/' . self::RUN_ALL_LOCK_NAME;
        $lockHandle = fopen($lockPath, 'c+');
        if ($lockHandle === false) {
            throw new \RuntimeException(sprintf('Unable to open lock file "%s".', $lockPath));
        }

        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return null;
        }

        return $lockHandle;
    }

    /**
     * @param resource|null $lockHandle
     */
    private function releaseRunAllLock($lockHandle): void
    {
        if (!is_resource($lockHandle)) {
            return;
        }

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    #[Route('/candidates', name: 'api_competitive_candidates_ingest', methods: ['POST'])]
    public function ingest(
        Request $request,
        CompetitiveTestResultIngestionService $testResultIngestionService,
    ): JsonResponse {
        if (!$this->isAuthorized($request)) {
            return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['ok' => false, 'error' => 'Invalid JSON payload.'], 400);
        }

        try {
            $testStats = ['inserted' => 0, 'updated' => 0, 'ignored' => 0];
            if (array_key_exists('tests', $payload)) {
                $testStats = $testResultIngestionService->ingest($payload);
            }
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        }

        return $this->json([
            'ok' => true,
            'test_inserted' => $testStats['inserted'],
            'test_updated' => $testStats['updated'],
            'test_ignored' => $testStats['ignored'],
        ]);
    }

    #[Route('/candidates/{id}/status', name: 'api_competitive_candidate_status', methods: ['POST'])]
    public function updateStatus(
        int $id,
        Request $request,
        CompetitiveCandidateStatusService $statusService,
    ): JsonResponse {
        if (!$this->isAuthorized($request)) {
            return $this->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['ok' => false, 'error' => 'Invalid JSON payload.'], 400);
        }

        $status = (string) ($payload['status'] ?? '');

        try {
            $candidate = $statusService->updateStatus($id, $status);
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 400);
        }

        return $this->json([
            'ok' => true,
            'id' => $candidate->getId(),
            'status' => $candidate->getStatus(),
        ]);
    }

    private function isAuthorized(Request $request): bool
    {
        $expectedToken = (string) $this->getParameter('competitive_intelligence_api_token');
        if ($expectedToken === '') {
            return false;
        }

        $providedToken = (string) ($request->query->get('token') ?? $request->headers->get('X-COMPETITIVE-TOKEN', ''));

        return hash_equals($expectedToken, $providedToken);
    }
}
