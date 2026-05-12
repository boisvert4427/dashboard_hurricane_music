<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Competitor;
use App\Entity\CompetitorUrlPriceHistory;
use App\Entity\CompetitorUrlTestResult;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\CompetitiveIntelligence\CompetitiveImageReviewService;
use App\Service\CompetitiveIntelligence\PrestashopProductBatchProvider;
use App\Service\CompetitiveIntelligence\CompetitiveTestResultReviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/veille-concurrentielle')]
final class CompetitiveIntelligenceController extends AbstractController
{
    private const IMAGE_REVIEW_LOCK_NAME = 'image-review.lock';
    private const IMAGE_REVIEW_LOCK_META_NAME = 'image-review.lock.meta.json';
    private const IMAGE_REVIEW_LOCK_ATTEMPT_NAME = 'image-review.lock.attempt.json';

    #[Route('', name: 'app_competitive_home', methods: ['GET'])]
    public function index(
        EntityManagerInterface $entityManager,
        PrestashopProductBatchProvider $batchProvider,
    ): Response
    {
        $statusCounts = $this->getCandidateStatusCounts($entityManager);
        $pendingMissingImages = $this->getPendingMissingImageCountsByCompetitor($entityManager);
        $competitors = $this->getCompetitorRepository($entityManager)
            ->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
        $testResultReport = $this->getTestResultReport($entityManager, $competitors, $batchProvider);
        $testResultTotals = $this->getTestResultTotals($testResultReport);
        $theoreticalTotal = array_sum(array_column($testResultReport, 'theoretical'));
        $priceScrapesLast24h = $this->countPriceScrapesLast24h($entityManager);

        $recentCandidates = $entityManager->getRepository(CompetitorUrlTestResult::class)
            ->createQueryBuilder('t')
            ->leftJoin('t.competitor', 'competitor')
            ->addSelect('competitor')
            ->orderBy('t.lastTestedAt', 'DESC')
            ->setMaxResults(12)
            ->getQuery()
            ->getResult();
        $recentCandidates = array_map(static function (CompetitorUrlTestResult $result): array {
            return [
                'productId' => $result->getProductId(),
                'competitor' => $result->getCompetitor(),
                'url' => $result->getUrl(),
                'competitorBrand' => $result->getCompetitorBrand(),
                'competitorBreadcrumb' => $result->getCompetitorBreadcrumb(),
                'score' => $result->getScore(),
                'status' => $result->getValidationStatus(),
                'updatedAt' => $result->getLastTestedAt(),
            ];
        }, array_filter($recentCandidates, static fn (mixed $item): bool => $item instanceof CompetitorUrlTestResult));

        return $this->render('competitive_intelligence/home.html.twig', [
            'competitor_count' => count($competitors),
            'status_counts' => $statusCounts,
            'pending_missing_images' => $pendingMissingImages,
            'competitors' => $competitors,
            'test_result_report' => $testResultReport,
            'test_result_totals' => $testResultTotals,
            'theoretical_total' => $theoreticalTotal,
            'price_scrapes_last_24h' => $priceScrapesLast24h,
            'recent_candidates' => $recentCandidates,
        ]);
    }

    #[Route('/concurrents', name: 'app_competitive_competitors', methods: ['GET'])]
    public function competitors(EntityManagerInterface $entityManager): Response
    {
        $competitors = $this->getCompetitorRepository($entityManager)
            ->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('competitive_intelligence/competitors.html.twig', [
            'competitors' => $competitors,
            'candidate_counts' => $this->getCandidateStatusCounts($entityManager),
        ]);
    }

    #[Route('/recherche', name: 'app_competitive_search', methods: ['GET'])]
    public function search(
        Request $request,
        EntityManagerInterface $entityManager,
        PrestashopProductBatchProvider $batchProvider,
    ): Response {
        $query = trim((string) $request->query->get('q', ''));
        $products = $query !== '' ? $batchProvider->searchProducts($query, 20) : [];
        $productIds = array_map(static fn (array $row): int => (int) $row['id_product'], $products);

        $sourceSnapshots = $batchProvider->getProductSnapshotsByIds($productIds);
        $finalsByProduct = $this->getFinalsByProductIds($entityManager, $productIds);
        $rejectedByProduct = $this->getRejectedByProductIds($entityManager, $productIds);
        $postponedByProduct = $this->getPostponedByProductIds($entityManager, $productIds);

        $rows = [];
        foreach ($products as $product) {
            $productId = (int) $product['id_product'];
            $rows[] = [
                'product' => $product,
                'source' => $sourceSnapshots[$productId] ?? null,
                'finals' => $finalsByProduct[$productId] ?? [],
                'rejected' => $rejectedByProduct[$productId] ?? [],
                'postponed' => $postponedByProduct[$productId] ?? [],
            ];
        }

        return $this->render('competitive_intelligence/search.html.twig', [
            'query' => $query,
            'rows' => $rows,
        ]);
    }

    #[Route('/recherche/{productId}/{competitorId}/retirer', name: 'app_competitive_search_remove_final', methods: ['POST'])]
    public function searchRemoveFinal(
        int $productId,
        int $competitorId,
        Request $request,
        EntityManagerInterface $entityManager,
        CompetitiveTestResultReviewService $reviewService,
    ): Response {
        $backQuery = trim((string) $request->request->get('q', ''));

        $reviewService->updateReviewStatus($productId, $competitorId, CompetitorUrlTestResult::REVIEW_REJECTED);

        $final = $entityManager->getRepository(\App\Entity\CompetitorUrlFinal::class)->findOneBy([
            'id' => $productId,
            'competitor' => $entityManager->getReference(Competitor::class, $competitorId),
        ]);

        if ($final instanceof \App\Entity\CompetitorUrlFinal) {
            $entityManager->remove($final);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_competitive_search', array_filter([
            'q' => $backQuery !== '' ? $backQuery : null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    #[Route('/recherche/{productId}/{competitorId}/revalider', name: 'app_competitive_search_revalidate_rejected', methods: ['POST'])]
    public function searchRevalidateRejected(
        int $productId,
        int $competitorId,
        Request $request,
        CompetitiveTestResultReviewService $reviewService,
    ): Response {
        $backQuery = trim((string) $request->request->get('q', ''));

        $reviewService->restoreRejectedReview($productId, $competitorId);

        return $this->redirectToRoute('app_competitive_search', array_filter([
            'q' => $backQuery !== '' ? $backQuery : null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    #[Route('/recherche/{productId}/{competitorId}/valider', name: 'app_competitive_search_validate_postponed', methods: ['POST'])]
    public function searchValidatePostponed(
        int $productId,
        int $competitorId,
        Request $request,
        CompetitiveTestResultReviewService $reviewService,
    ): Response {
        $backQuery = trim((string) $request->request->get('q', ''));

        $reviewService->updateReviewStatus($productId, $competitorId, CompetitorUrlTestResult::REVIEW_VALID);

        return $this->redirectToRoute('app_competitive_search', array_filter([
            'q' => $backQuery !== '' ? $backQuery : null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    #[Route('/validation', name: 'app_competitive_validation', methods: ['GET'])]
    public function validation(
        Request $request,
        EntityManagerInterface $entityManager,
        PrestashopProductBatchProvider $batchProvider,
    ): Response {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $totalPending = $this->countPendingValidationRows($entityManager);
        $pendingMissingImages = $this->getPendingMissingImageCountsByCompetitor($entityManager);
        $pendingRows = $this->getPendingValidationRows($entityManager, $limit, $offset);
        $sourceSnapshots = $batchProvider->getProductSnapshotsByIds(array_map(
            static fn (array $row): int => (int) $row['product_id'],
            $pendingRows
        ));
        $testResultSnapshots = $this->getTestResultSnapshots($entityManager, array_map(
            static fn (array $row): int => (int) $row['product_id'],
            $pendingRows
        ));

        foreach ($pendingRows as &$row) {
            $productId = (int) $row['product_id'];
            $row['source'] = $sourceSnapshots[$productId] ?? null;
            $row['test_result'] = $testResultSnapshots[$productId] ?? null;
        }
        unset($row);

        return $this->render('competitive_intelligence/validation.html.twig', [
            'pending_rows' => $pendingRows,
            'pending_total' => $totalPending,
            'pending_missing_images' => $pendingMissingImages,
            'pending_page' => $page,
            'pending_limit' => $limit,
            'pending_pages' => max(1, (int) ceil($totalPending / $limit)),
        ]);
    }

    #[Route('/validation/{productId}/{competitorId}', name: 'app_competitive_validation_update', methods: ['POST'])]
    public function validationUpdate(
        int $productId,
        int $competitorId,
        Request $request,
        CompetitiveTestResultReviewService $reviewService,
    ): Response {
        $status = (string) $request->request->get('status', '');

        if (!in_array($status, [
            CompetitorUrlTestResult::REVIEW_POSTPONED,
            CompetitorUrlTestResult::REVIEW_VALID,
            CompetitorUrlTestResult::REVIEW_REJECTED,
        ], true)) {
            return $this->redirectToRoute('app_competitive_validation');
        }

        $reviewService->updateReviewStatus($productId, $competitorId, $status);

        return $this->redirectToRoute('app_competitive_validation');
    }

    #[Route('/validation/bulk', name: 'app_competitive_validation_bulk_update', methods: ['POST'])]
    public function validationBulkUpdate(
        Request $request,
        EntityManagerInterface $entityManager,
        CompetitiveTestResultReviewService $reviewService,
    ): Response {
        $page = max(1, (int) $request->request->get('page', 1));
        $statuses = $request->request->all('statuses');

        if (!is_array($statuses)) {
            return $this->redirectToRoute('app_competitive_validation', ['page' => $page]);
        }

        foreach ($statuses as $productId => $competitorStatuses) {
            if (!is_array($competitorStatuses)) {
                continue;
            }

            $productId = (int) $productId;
            if ($productId <= 0) {
                continue;
            }

            foreach ($competitorStatuses as $competitorId => $status) {
                if (!is_string($status)) {
                    continue;
                }

                $competitorId = (int) $competitorId;
                if ($competitorId <= 0) {
                    continue;
                }

                if (!in_array($status, [
                    CompetitorUrlTestResult::REVIEW_POSTPONED,
                    CompetitorUrlTestResult::REVIEW_VALID,
                    CompetitorUrlTestResult::REVIEW_REJECTED,
                ], true)) {
                    continue;
                }

                $reviewService->updateReviewStatus($productId, $competitorId, $status, false);
            }
        }

        $entityManager->flush();

        return $this->redirectToRoute('app_competitive_validation', ['page' => $page]);
    }

    #[Route('/validation/image-review', name: 'app_competitive_validation_image_review', methods: ['GET', 'POST'])]
    public function validationImageReview(
        Request $request,
        CompetitiveImageReviewService $imageReviewService,
    ): Response {
        $page = max(1, (int) ($request->query->get('page') ?? $request->request->get('page', 1)));
        $limit = max(1, min(50, (int) ($request->query->get('limit') ?? $request->request->get('limit', 5))));
        $lockContext = $this->buildImageReviewLockContext($request, $page, $limit);
        $this->writeImageReviewLockAttempt($lockContext);
        $lockHandle = $this->acquireImageReviewLock($lockContext);
        if ($lockHandle === null) {
            $runningLock = $this->readImageReviewLockContext();
            if ($runningLock === null) {
                $runningLock = $this->readImageReviewLockAttempt();
            }
            $errorPayload = [
                'ok' => false,
                'error' => 'A image review batch is already running.',
                'lock' => $runningLock,
            ];

            if (
                '1' === (string) $request->query->get('detail')
                || '1' === (string) $request->query->get('raw')
                || 'json' === (string) $request->query->get('format')
                || str_contains((string) $request->headers->get('Accept', ''), 'application/json')
                || '1' === (string) $request->query->get('json')
            ) {
                return new JsonResponse($errorPayload, 409);
            }

            $message = 'Une vérification par image est déjà en cours.';
            if (is_array($runningLock) && isset($runningLock['started_at'], $runningLock['pid'])) {
                $message = sprintf(
                    'Une vérification par image est déjà en cours depuis %s (pid %s).',
                    (string) $runningLock['started_at'],
                    (string) $runningLock['pid']
                );
            }
            $this->addFlash('error', $message);

            return $this->redirectToRoute('app_competitive_validation', ['page' => $page]);
        }

        try {
            $batch = $imageReviewService->reviewPendingBatch($limit);
            $stats = $batch['stats'];
            $payload = [
                'ok' => true,
                'page' => $page,
                'limit' => $limit,
                'stats' => $stats,
            ];

            if (
                '1' === (string) $request->query->get('detail')
                || '1' === (string) $request->query->get('raw')
            ) {
                $results = array_map(static function (array $item): array {
                    return [
                        'product' => [
                            'id_product' => $item['product_id'] ?? null,
                            'competitor_id' => $item['competitor_id'] ?? null,
                            'competitor' => $item['competitor_name'] ?? null,
                            'url' => $item['url'] ?? null,
                            'source_image_url' => $item['source_image_url'] ?? null,
                            'competitor_image_url' => $item['competitor_image_url'] ?? null,
                        ],
                        'comparison' => $item['comparison'] ?? null,
                        'status' => $item['status'] ?? null,
                        'reason' => $item['reason'] ?? null,
                        'error' => $item['error'] ?? null,
                    ];
                }, $batch['items']);

                return new JsonResponse([
                    'ok' => true,
                    'page' => $page,
                    'limit' => $limit,
                    'results' => $results,
                ]);
            }

            if (
                'json' === (string) $request->query->get('format')
                || str_contains((string) $request->headers->get('Accept', ''), 'application/json')
                || '1' === (string) $request->query->get('json')
            ) {
                return new JsonResponse($payload);
            }

            $this->addFlash(
                'success',
                sprintf(
                    'Vérification par image lancée sur %d ligne(s): %d traités, %d validés, %d rejetés, %d reportés, %d sans image, %d erreur(s).',
                    $limit,
                    $stats['processed'],
                    $stats['valid'],
                    $stats['rejected'],
                    $stats['postponed'],
                    $stats['missing_images'],
                    $stats['errors']
                )
            );

            return $this->redirectToRoute('app_competitive_validation', ['page' => $page]);
        } finally {
            $this->releaseImageReviewLock($lockHandle);
        }
    }

    /**
     * @return array{pending:int, valid:int, rejected:int}
     */
    private function getCandidateStatusCounts(EntityManagerInterface $entityManager): array
    {
        $counts = [
            'pending' => 0,
            'valid' => 0,
            'rejected' => 0,
        ];

        $counts['pending'] = $this->countPendingValidationRows($entityManager);

        $rows = $entityManager->getRepository(CompetitorUrlTestResult::class)
            ->createQueryBuilder('c')
            ->select('c.validationStatus AS status, COUNT(c.productId) AS total')
            ->andWhere('c.validationStatus IN (:statuses)')
            ->setParameter('statuses', [
                CompetitorUrlTestResult::REVIEW_VALID,
                CompetitorUrlTestResult::REVIEW_REJECTED,
                CompetitorUrlTestResult::REVIEW_POSTPONED,
            ])
            ->groupBy('c.validationStatus')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) ($row['total'] ?? 0);
            }
        }

        return $counts;
    }

    private function getCompetitorRepository(EntityManagerInterface $entityManager)
    {
        return $entityManager->getRepository(Competitor::class);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function countPendingValidationRows(EntityManagerInterface $entityManager): int
    {
        return (int) $entityManager->getRepository(CompetitorUrlTestResult::class)
            ->createQueryBuilder('c')
            ->leftJoin(
                \App\Entity\CompetitorUrlFinal::class,
                'final_row',
                'WITH',
                'final_row.id = c.productId AND final_row.competitor = c.competitor'
            )
            ->select('COUNT(c.productId)')
            ->andWhere('c.validationStatus = :status')
            ->andWhere('c.competitorPageStatus = :page_status')
            ->andWhere('final_row.id IS NULL')
            ->setParameter('status', CompetitorUrlTestResult::REVIEW_PENDING)
            ->setParameter('page_status', CompetitorUrlTestResult::PAGE_OK)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<int, array{competitor_id:int,total:int}>
     */
    private function getPendingValidationCountsByCompetitor(EntityManagerInterface $entityManager): array
    {
        return $entityManager->getRepository(CompetitorUrlTestResult::class)
            ->createQueryBuilder('c')
            ->leftJoin(
                \App\Entity\CompetitorUrlFinal::class,
                'final_row',
                'WITH',
                'final_row.id = c.productId AND final_row.competitor = c.competitor'
            )
            ->select('IDENTITY(c.competitor) AS competitor_id, COUNT(c.productId) AS total')
            ->andWhere('c.validationStatus = :status')
            ->andWhere('c.competitorPageStatus = :page_status')
            ->andWhere('final_row.id IS NULL')
            ->setParameter('status', CompetitorUrlTestResult::REVIEW_PENDING)
            ->setParameter('page_status', CompetitorUrlTestResult::PAGE_OK)
            ->groupBy('c.competitor')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * @return array<string, int>
     */
    private function getPendingMissingImageCountsByCompetitor(EntityManagerInterface $entityManager): array
    {
        $counts = [
            'Thomann' => 0,
            'Michenaud' => 0,
        ];

        $rows = $entityManager->getRepository(CompetitorUrlTestResult::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.competitor', 'competitor')
            ->leftJoin(
                \App\Entity\CompetitorUrlFinal::class,
                'final_row',
                'WITH',
                'final_row.id = c.productId AND final_row.competitor = c.competitor'
            )
            ->select('competitor.name AS competitor_name, COUNT(c.productId) AS total')
            ->andWhere('c.validationStatus = :status')
            ->andWhere('c.competitorPageStatus = :page_status')
            ->andWhere('final_row.id IS NULL')
            ->andWhere('competitor.name IN (:competitors)')
            ->andWhere('(c.competitorImageUrl IS NULL OR c.competitorImageUrl = \'\')')
            ->setParameter('status', CompetitorUrlTestResult::REVIEW_PENDING)
            ->setParameter('page_status', CompetitorUrlTestResult::PAGE_OK)
            ->setParameter('competitors', ['Thomann', 'Michenaud'])
            ->groupBy('competitor.name')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $competitorName = (string) ($row['competitor_name'] ?? '');
            if (array_key_exists($competitorName, $counts)) {
                $counts[$competitorName] = (int) ($row['total'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getPendingValidationRows(EntityManagerInterface $entityManager, int $limit, int $offset): array
    {
        $rows = $entityManager->getRepository(CompetitorUrlTestResult::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.competitor', 'competitor')
            ->leftJoin(
                \App\Entity\CompetitorUrlFinal::class,
                'final_row',
                'WITH',
                'final_row.id = c.productId AND final_row.competitor = c.competitor'
            )
            ->addSelect('competitor')
            ->andWhere('c.validationStatus = :status')
            ->andWhere('c.competitorPageStatus = :page_status')
            ->andWhere('final_row.id IS NULL')
            ->setParameter('status', CompetitorUrlTestResult::REVIEW_PENDING)
            ->setParameter('page_status', CompetitorUrlTestResult::PAGE_OK)
            ->orderBy('c.score', 'DESC')
            ->addOrderBy('c.lastTestedAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        $pending = [];
        foreach ($rows as $testResult) {
            if (!$testResult instanceof CompetitorUrlTestResult) {
                continue;
            }

            $pending[] = [
                'product_id' => $testResult->getProductId(),
                'competitor' => $testResult->getCompetitor(),
                'url' => $testResult->getUrl(),
                'competitor_title' => $testResult->getCompetitorTitle(),
                'competitor_price' => $testResult->getCompetitorPrice(),
                'competitor_breadcrumb' => $testResult->getCompetitorBreadcrumb(),
                'score' => $testResult->getScore(),
                'status' => $testResult->getValidationStatus(),
                'updated_at' => $testResult->getLastTestedAt(),
            ];
        }

        return $pending;
    }

    /**
     * @param array<int, int> $productIds
     *
     * @return array<int, array{competitor_title:?string, competitor_brand:?string, competitor_breadcrumb:?string, competitor_price:?string, url:?string, score:?int, result:?string}>
     */
    private function getTestResultSnapshots(EntityManagerInterface $entityManager, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $value): bool => $value > 0)));
        if ($productIds === []) {
            return [];
        }

        $rows = $entityManager->getRepository(CompetitorUrlTestResult::class)
            ->createQueryBuilder('t')
            ->select('t.productId AS product_id, t.url AS url, t.competitorTitle AS competitor_title, t.competitorBrand AS competitor_brand, t.competitorBreadcrumb AS competitor_breadcrumb, t.competitorPrice AS competitor_price, t.score AS score, t.result AS result')
            ->andWhere('t.productId IN (:ids)')
            ->setParameter('ids', $productIds)
            ->getQuery()
            ->getArrayResult();

        $snapshots = [];
        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $snapshots[$productId] = [
                'url' => $row['url'] ?? null,
                'competitor_title' => $row['competitor_title'] ?? null,
                'competitor_brand' => $row['competitor_brand'] ?? null,
                'competitor_breadcrumb' => $row['competitor_breadcrumb'] ?? null,
                'competitor_price' => $row['competitor_price'] ?? null,
                'score' => isset($row['score']) ? (int) $row['score'] : null,
                'result' => $row['result'] ?? null,
            ];
        }

        return $snapshots;
    }

    /**
     * @param array<int, int> $productIds
     *
     * @return array<int, array<int, array{id:int,competitor:Competitor,url:string}>>
     */
    private function getFinalsByProductIds(EntityManagerInterface $entityManager, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $value): bool => $value > 0)));
        if ($productIds === []) {
            return [];
        }

        $rows = $entityManager->getRepository(\App\Entity\CompetitorUrlFinal::class)
            ->createQueryBuilder('f')
            ->leftJoin('f.competitor', 'competitor')
            ->addSelect('competitor')
            ->andWhere('f.id IN (:ids)')
            ->setParameter('ids', $productIds)
            ->orderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($rows as $row) {
            if (!$row instanceof \App\Entity\CompetitorUrlFinal) {
                continue;
            }

            $grouped[$row->getId()][] = [
                'id' => $row->getId(),
                'competitor' => $row->getCompetitor(),
                'url' => $row->getUrl(),
                'competitor_price' => $row->getCompetitorPrice(),
            ];
        }

        return $grouped;
    }

    /**
     * @param array<int, int> $productIds
     *
     * @return array<int, array<int, array{
     *     product_id:int,
     *     competitor:Competitor,
     *     url:?string,
     *     competitor_title:?string,
     *     competitor_brand:?string,
     *     competitor_price:?string,
     *     score:?int,
     *     last_tested_at:\DateTimeImmutable
     * }>>
     */
    private function getRejectedByProductIds(EntityManagerInterface $entityManager, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $value): bool => $value > 0)));
        if ($productIds === []) {
            return [];
        }

        $rows = $entityManager->getRepository(CompetitorUrlTestResult::class)
            ->createQueryBuilder('t')
            ->leftJoin('t.competitor', 'competitor')
            ->addSelect('competitor')
            ->andWhere('t.productId IN (:ids)')
            ->andWhere('t.validationStatus = :status')
            ->setParameter('ids', $productIds)
            ->setParameter('status', CompetitorUrlTestResult::REVIEW_REJECTED)
            ->orderBy('t.lastTestedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($rows as $row) {
            if (!$row instanceof CompetitorUrlTestResult) {
                continue;
            }

            $grouped[$row->getProductId()][] = [
                'product_id' => $row->getProductId(),
                'competitor' => $row->getCompetitor(),
                'url' => $row->getUrl(),
                'competitor_title' => $row->getCompetitorTitle(),
                'competitor_brand' => $row->getCompetitorBrand(),
                'competitor_price' => $row->getCompetitorPrice(),
                'score' => $row->getScore(),
                'last_tested_at' => $row->getLastTestedAt(),
            ];
        }

        return $grouped;
    }

    /**
     * @param array<int, int> $productIds
     *
     * @return array<int, array<int, array{
     *     product_id:int,
     *     competitor:Competitor,
     *     url:?string,
     *     competitor_title:?string,
     *     competitor_brand:?string,
     *     competitor_price:?string,
     *     score:?int,
     *     last_tested_at:\DateTimeImmutable
     * }>>
     */
    private function getPostponedByProductIds(EntityManagerInterface $entityManager, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $value): bool => $value > 0)));
        if ($productIds === []) {
            return [];
        }

        $rows = $entityManager->getRepository(CompetitorUrlTestResult::class)
            ->createQueryBuilder('t')
            ->leftJoin('t.competitor', 'competitor')
            ->addSelect('competitor')
            ->andWhere('t.productId IN (:ids)')
            ->andWhere('t.validationStatus = :status')
            ->setParameter('ids', $productIds)
            ->setParameter('status', CompetitorUrlTestResult::REVIEW_POSTPONED)
            ->orderBy('t.lastTestedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($rows as $row) {
            if (!$row instanceof CompetitorUrlTestResult) {
                continue;
            }

            $grouped[$row->getProductId()][] = [
                'product_id' => $row->getProductId(),
                'competitor' => $row->getCompetitor(),
                'url' => $row->getUrl(),
                'competitor_title' => $row->getCompetitorTitle(),
                'competitor_brand' => $row->getCompetitorBrand(),
                'competitor_price' => $row->getCompetitorPrice(),
                'score' => $row->getScore(),
                'last_tested_at' => $row->getLastTestedAt(),
            ];
        }

        return $grouped;
    }

    /**
     * @param array<int, Competitor> $competitors
     *
     * @return array<int, array{
     *     competitor: Competitor,
     *     total: int,
     *     matched: int,
     *     pending: int,
     *     not_found: int,
     *     cloudflare: int,
     *     search_input_not_found: int,
     *     error: int,
     *     theoretical: int
     * }>
     */
    private function getTestResultReport(
        EntityManagerInterface $entityManager,
        array $competitors,
        PrestashopProductBatchProvider $batchProvider,
    ): array
    {
        $statusKeys = [
            CompetitorUrlTestResult::RESULT_MATCHED,
            CompetitorUrlTestResult::REVIEW_PENDING,
            CompetitorUrlTestResult::RESULT_NOT_FOUND,
            CompetitorUrlTestResult::RESULT_CLOUDFLARE,
            CompetitorUrlTestResult::RESULT_SEARCH_INPUT_NOT_FOUND,
            CompetitorUrlTestResult::RESULT_ERROR,
        ];

        $report = [];
        foreach ($competitors as $competitor) {
            if (!$competitor instanceof Competitor) {
                continue;
            }

            $report[$competitor->getId() ?? 0] = [
                'competitor' => $competitor,
                'total' => 0,
                'matched' => 0,
                'pending' => 0,
                'not_found' => 0,
                'cloudflare' => 0,
                'search_input_not_found' => 0,
                'error' => 0,
                'theoretical' => $batchProvider->countEligibleProducts($competitor->getId() ?? 0),
            ];
        }

        foreach ($this->getPendingValidationCountsByCompetitor($entityManager) as $row) {
            $competitorId = (int) ($row['competitor_id'] ?? 0);
            if (!isset($report[$competitorId])) {
                continue;
            }

            $pendingTotal = (int) ($row['total'] ?? 0);
            $report[$competitorId]['pending'] = $pendingTotal;
            $report[$competitorId]['total'] += $pendingTotal;
        }

        $rows = $entityManager->getRepository(CompetitorUrlTestResult::class)
            ->createQueryBuilder('t')
            ->select(
                'IDENTITY(t.competitor) AS competitor_id, ' .
                't.validationStatus AS validation_status, ' .
                't.result AS result, ' .
                'COUNT(t.productId) AS total'
            )
            ->andWhere('t.validationStatus != :pendingStatus')
            ->groupBy('t.competitor, t.validationStatus, t.result')
            ->setParameter('pendingStatus', CompetitorUrlTestResult::REVIEW_PENDING)
            ->orderBy('competitor_id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $competitorId = (int) ($row['competitor_id'] ?? 0);
            $validationStatus = (string) ($row['validation_status'] ?? '');
            $result = (string) ($row['result'] ?? '');
            $total = (int) ($row['total'] ?? 0);

            if (!isset($report[$competitorId])) {
                continue;
            }

            if ($validationStatus === CompetitorUrlTestResult::REVIEW_VALID) {
                $report[$competitorId]['matched'] += $total;
                $report[$competitorId]['total'] += $total;
                continue;
            }

            if ($result === CompetitorUrlTestResult::REVIEW_PENDING) {
                continue;
            }

            if (!in_array($result, $statusKeys, true)) {
                continue;
            }

            $report[$competitorId][$result] += $total;
            $report[$competitorId]['total'] += $total;
        }

        return array_values($report);
    }

    /**
     * @param array<int, array{
     *     competitor: Competitor,
     *     total: int,
     *     matched: int,
     *     pending: int,
     *     not_found: int,
     *     cloudflare: int,
     *     search_input_not_found: int,
     *     error: int,
     *     theoretical: int
     * }> $report
     *
     * @return array{
     *     theoretical:int,
     *     total:int,
     *     matched:int,
     *     pending:int,
     *     not_found:int,
     *     cloudflare:int,
     *     search_input_not_found:int,
     *     error:int
     * }
     */
    private function getTestResultTotals(array $report): array
    {
        $totals = [
            'theoretical' => 0,
            'total' => 0,
            'matched' => 0,
            'pending' => 0,
            'not_found' => 0,
            'cloudflare' => 0,
            'search_input_not_found' => 0,
            'error' => 0,
        ];

        foreach ($report as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] += (int) ($row[$key] ?? 0);
            }
        }

        return $totals;
    }

    private function countPriceScrapesLast24h(EntityManagerInterface $entityManager): int
    {
        $cutoff = new \DateTimeImmutable('-24 hours');

        return (int) $entityManager->getRepository(CompetitorUrlPriceHistory::class)
            ->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.observedAt >= :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return resource|null
     */
    private function acquireImageReviewLock(array $context)
    {
        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $lockDir = $projectDir . '/competitive_intelligence_python/var/lock/competitive-intelligence';
        if (!is_dir($lockDir) && !mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            throw new \RuntimeException(sprintf('Unable to create lock directory "%s".', $lockDir));
        }

        $lockPath = $lockDir . '/' . self::IMAGE_REVIEW_LOCK_NAME;
        $lockHandle = fopen($lockPath, 'c+');
        if ($lockHandle === false) {
            throw new \RuntimeException(sprintf('Unable to open lock file "%s".', $lockPath));
        }

        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);
            return null;
        }

        $this->writeImageReviewLockContext($context);

        return $lockHandle;
    }

    /**
     * @param resource|null $lockHandle
     */
    private function releaseImageReviewLock($lockHandle): void
    {
        if (!is_resource($lockHandle)) {
            return;
        }

        $this->removeImageReviewLockContext();

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    /**
     * @return array{pid:int,started_at:string,request_id:string,page:int,limit:int,route:string}
     */
    private function buildImageReviewLockContext(Request $request, int $page, int $limit): array
    {
        $requestId = (string) ($request->headers->get('X-Request-Id') ?: bin2hex(random_bytes(6)));

        return [
            'pid' => getmypid() ?: 0,
            'started_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'request_id' => $requestId,
            'page' => $page,
            'limit' => $limit,
            'route' => (string) $request->attributes->get('_route', 'app_competitive_validation_image_review'),
        ];
    }

    /**
     * @param array{pid:int,started_at:string,request_id:string,page:int,limit:int,route:string} $context
     */
    private function writeImageReviewLockContext(array $context): void
    {
        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $metaPath = $projectDir . '/competitive_intelligence_python/var/lock/competitive-intelligence/' . self::IMAGE_REVIEW_LOCK_META_NAME;
        file_put_contents($metaPath, json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readImageReviewLockContext(): ?array
    {
        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $metaPath = $projectDir . '/competitive_intelligence_python/var/lock/competitive-intelligence/' . self::IMAGE_REVIEW_LOCK_META_NAME;
        if (!is_file($metaPath)) {
            return null;
        }

        $content = trim((string) @file_get_contents($metaPath));
        if ($content === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [
            'raw' => $content,
        ];
    }

    private function removeImageReviewLockContext(): void
    {
        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $metaPath = $projectDir . '/competitive_intelligence_python/var/lock/competitive-intelligence/' . self::IMAGE_REVIEW_LOCK_META_NAME;
        if (is_file($metaPath)) {
            @unlink($metaPath);
        }
    }

    /**
     * @param array{pid:int,started_at:string,request_id:string,page:int,limit:int,route:string} $context
     */
    private function writeImageReviewLockAttempt(array $context): void
    {
        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $attemptPath = $projectDir . '/competitive_intelligence_python/var/lock/competitive-intelligence/' . self::IMAGE_REVIEW_LOCK_ATTEMPT_NAME;
        file_put_contents($attemptPath, json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readImageReviewLockAttempt(): ?array
    {
        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $attemptPath = $projectDir . '/competitive_intelligence_python/var/lock/competitive-intelligence/' . self::IMAGE_REVIEW_LOCK_ATTEMPT_NAME;
        if (!is_file($attemptPath)) {
            return null;
        }

        $content = trim((string) @file_get_contents($attemptPath));
        if ($content === '') {
            return null;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [
            'raw' => $content,
        ];
    }
}
