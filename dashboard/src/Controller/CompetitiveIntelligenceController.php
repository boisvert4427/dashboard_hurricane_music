<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Competitor;
use App\Entity\CompetitorUrlTestResult;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\CompetitiveIntelligence\PrestashopProductBatchProvider;
use App\Service\CompetitiveIntelligence\CompetitiveTestResultReviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/veille-concurrentielle')]
final class CompetitiveIntelligenceController extends AbstractController
{
    #[Route('', name: 'app_competitive_home', methods: ['GET'])]
    public function index(
        EntityManagerInterface $entityManager,
        PrestashopProductBatchProvider $batchProvider,
    ): Response
    {
        $statusCounts = $this->getCandidateStatusCounts($entityManager);
        $competitors = $this->getCompetitorRepository($entityManager)
            ->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
        $testResultReport = $this->getTestResultReport($entityManager, $competitors, $batchProvider);
        $testResultTotals = $this->getTestResultTotals($testResultReport);
        $theoreticalTotal = array_sum(array_column($testResultReport, 'theoretical'));

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
                'score' => $result->getScore(),
                'status' => $result->getValidationStatus(),
                'updatedAt' => $result->getLastTestedAt(),
            ];
        }, array_filter($recentCandidates, static fn (mixed $item): bool => $item instanceof CompetitorUrlTestResult));

        return $this->render('competitive_intelligence/home.html.twig', [
            'competitor_count' => count($competitors),
            'status_counts' => $statusCounts,
            'competitors' => $competitors,
            'test_result_report' => $testResultReport,
            'test_result_totals' => $testResultTotals,
            'theoretical_total' => $theoreticalTotal,
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

        $finalsByProduct = $this->getFinalsByProductIds($entityManager, $productIds);

        $rows = [];
        foreach ($products as $product) {
            $productId = (int) $product['id_product'];
            $rows[] = [
                'product' => $product,
                'finals' => $finalsByProduct[$productId] ?? [],
            ];
        }

        return $this->render('competitive_intelligence/search.html.twig', [
            'query' => $query,
            'rows' => $rows,
        ]);
    }

    #[Route('/validation', name: 'app_competitive_validation', methods: ['GET'])]
    public function validation(
        Request $request,
        EntityManagerInterface $entityManager,
        PrestashopProductBatchProvider $batchProvider,
    ): Response {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $totalPending = $this->countPendingValidationRows($entityManager);
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
            ->andWhere('final_row.id IS NULL')
            ->setParameter('status', CompetitorUrlTestResult::REVIEW_PENDING)
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
            ->andWhere('final_row.id IS NULL')
            ->setParameter('status', CompetitorUrlTestResult::REVIEW_PENDING)
            ->groupBy('c.competitor')
            ->getQuery()
            ->getArrayResult();
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
            ->andWhere('final_row.id IS NULL')
            ->setParameter('status', CompetitorUrlTestResult::REVIEW_PENDING)
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
     * @return array<int, array{competitor_title:?string, competitor_price:?string, url:?string, score:?int, result:?string}>
     */
    private function getTestResultSnapshots(EntityManagerInterface $entityManager, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $value): bool => $value > 0)));
        if ($productIds === []) {
            return [];
        }

        $rows = $entityManager->getRepository(CompetitorUrlTestResult::class)
            ->createQueryBuilder('t')
            ->select('t.productId AS product_id, t.url AS url, t.competitorTitle AS competitor_title, t.competitorPrice AS competitor_price, t.score AS score, t.result AS result')
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
}
