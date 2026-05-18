<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Competitor;
use App\Entity\CompetitorUrlFinal;
use App\Entity\CompetitorUrlPriceHistory;
use App\Entity\CompetitorUrlTestResult;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\CompetitiveIntelligence\CompetitiveOrchestratorConfigStorage;
use App\Service\CompetitiveIntelligence\CompetitiveOrchestratorService;
use App\Service\CompetitiveIntelligence\CompetitiveOrchestratorStateStorage;
use App\Service\CompetitiveIntelligence\CompetitivePriceHistoryService;
use App\Service\CompetitiveIntelligence\CompetitiveTaskLogService;
use App\Service\CompetitiveIntelligence\CompetitiveImageReviewService;
use App\Service\CompetitiveIntelligence\PrestashopProductBatchProvider;
use App\Service\CompetitiveIntelligence\CompetitiveTestResultReviewService;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

    #[Route('/prix', name: 'app_competitive_price_board', methods: ['GET'])]
    public function priceBoard(
        Request $request,
        EntityManagerInterface $entityManager,
        PrestashopProductBatchProvider $batchProvider,
    ): Response {
        $limit = max(10, min(150, (int) $request->query->get('limit', 60)));
        $selectedBucket = trim((string) $request->query->get('bucket', ''));
        $selectedCompetitor = trim((string) $request->query->get('competitor', ''));
        $board = $this->buildCompetitivePriceBoard($entityManager, $batchProvider, $limit, $selectedBucket, $selectedCompetitor);

        return $this->render('competitive_intelligence/price_board.html.twig', [
            'board' => $board,
            'limit' => $limit,
            'selected_bucket' => $selectedBucket,
            'selected_competitor' => $selectedCompetitor,
        ]);
    }

    #[Route('/prix/ecarts-fiables', name: 'app_competitive_price_trusted_gaps', methods: ['GET'])]
    public function trustedPriceGaps(
        Request $request,
        EntityManagerInterface $entityManager,
        PrestashopProductBatchProvider $batchProvider,
    ): Response {
        $limit = max(10, min(300, (int) $request->query->get('limit', 100)));
        $threshold = max(5.0, min(200.0, (float) $request->query->get('threshold', 30)));
        $showThomann = '0' !== (string) $request->query->get('show_thomann', '1');
        $showMichenaud = '0' !== (string) $request->query->get('show_michenaud', '1');
        $showBoth = '0' !== (string) $request->query->get('show_both', '1');
        $board = $this->buildTrustedGapBoard(
            $entityManager,
            $batchProvider,
            $limit,
            $threshold,
            $showThomann,
            $showMichenaud,
            $showBoth,
        );

        return $this->render('competitive_intelligence/trusted_price_gaps.html.twig', [
            'board' => $board,
            'limit' => $limit,
            'threshold' => $threshold,
            'show_thomann' => $showThomann,
            'show_michenaud' => $showMichenaud,
            'show_both' => $showBoth,
        ]);
    }

    #[Route('/orchestrateur', name: 'app_competitive_orchestrator', methods: ['GET', 'POST'])]
    public function orchestrator(
        Request $request,
        EntityManagerInterface $entityManager,
        CompetitiveOrchestratorConfigStorage $configStorage,
        CompetitiveOrchestratorStateStorage $stateStorage,
        CompetitiveOrchestratorService $orchestratorService,
        CompetitiveTaskLogService $taskLogService,
    ): Response {
        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action', 'save');
            if ($action === 'reset') {
                $configStorage->reset();
                $this->addFlash('success', 'Configuration orchestrateur réinitialisée aux valeurs par défaut.');

                return $this->redirectToRoute('app_competitive_orchestrator');
            }

            if ($action === 'run_task') {
                $taskKey = trim((string) $request->request->get('task_key', ''));
                $config = $configStorage->load();

                try {
                    $result = $orchestratorService->launchTaskOnce(
                        $config,
                        $taskKey,
                        (string) $this->getParameter('kernel.project_dir'),
                        $request->getSchemeAndHttpHost(),
                        (string) $this->getParameter('competitive_intelligence_api_token'),
                        (int) ($config['global']['lang_id'] ?? 1),
                        (int) ($config['global']['shop_id'] ?? 1),
                    );
                    $task = $result['task'] ?? [];
                    $message = sprintf(
                        'Tâche lancée: %s / %s (pid=%s).',
                        (string) ($task['competitor_label'] ?? 'Unknown'),
                        (string) ($task['task_label'] ?? 'Unknown'),
                        (string) (($result['run']['pid'] ?? null) ?? 'n/a'),
                    );
                    if (($task['task_type'] ?? null) === 'cleanup_logs') {
                        $message = sprintf(
                            'Tâche lancée: %s / %s, %d log(s) supprimé(s), rétention %d jour(s).',
                            (string) ($task['competitor_label'] ?? 'Unknown'),
                            (string) ($task['task_label'] ?? 'Unknown'),
                            (int) (($result['run']['deleted_count'] ?? 0)),
                            (int) (($result['run']['retention_days'] ?? 30)),
                        );
                    }
                    $this->addFlash('success', $message);
                } catch (\Throwable $e) {
                    $this->addFlash('error', $e->getMessage());
                }

                return $this->redirectToRoute('app_competitive_orchestrator');
            }

            $config = $this->buildOrchestratorConfig($request, $configStorage->load());
            $savedConfig = $configStorage->save($config);
            $this->addFlash('success', sprintf(
                'Configuration orchestrateur sauvegardée le %s.',
                (string) ($savedConfig['updated_at'] ?? '')
            ));

            return $this->redirectToRoute('app_competitive_orchestrator');
        }

        $config = $configStorage->load();
        $state = $stateStorage->load();
        $summary = [
            'pending_validation' => $this->countPendingValidationRows($entityManager),
            'pending_missing_images' => $this->getPendingMissingImageCountsByCompetitor($entityManager),
            'price_scrapes_last_24h' => $this->countPriceScrapesLast24h($entityManager),
            'price_scrapes_last_24h_by_competitor' => $this->countPriceScrapesLast24hByCompetitor($entityManager),
        ];
        $taskRows = $orchestratorService->describeTasks(
            $config,
            $state,
            (int) ($config['global']['lang_id'] ?? 1),
            (int) ($config['global']['shop_id'] ?? 1),
        );
        $latestLogsByTask = $taskLogService->latestLogsByTask();
        $taskRows = array_map(static function (array $row) use ($latestLogsByTask): array {
            $taskKey = (string) ($row['key'] ?? '');
            $row['latest_log'] = $taskKey !== '' ? ($latestLogsByTask[$taskKey] ?? null) : null;

            return $row;
        }, $taskRows);
        $recentLogs = $taskLogService->listRecentLogs(40);
        $selectedLog = trim((string) $request->query->get('log', ''));
        $logTail = $selectedLog !== '' ? $taskLogService->readTail($selectedLog, 200) : null;

        return $this->render('competitive_intelligence/orchestrator.html.twig', [
            'config' => $config,
            'config_json' => json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            'state_json' => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            'summary' => $summary,
            'config_path' => $configStorage->getConfigPath(),
            'state_path' => $stateStorage->getStatePath(),
            'task_rows' => $taskRows,
            'recent_logs' => $recentLogs,
            'selected_log' => $selectedLog,
            'log_tail' => $logTail,
            'log_directory' => $taskLogService->getLogDirectory(),
        ]);
    }

    #[Route('/orchestrateur/log/{filename}', name: 'app_competitive_orchestrator_log', methods: ['GET'])]
    public function orchestratorLog(
        string $filename,
        CompetitiveTaskLogService $taskLogService,
    ): Response {
        $log = $taskLogService->readFull($filename);
        if ($log === null) {
            throw $this->createNotFoundException(sprintf('Unknown log "%s".', $filename));
        }

        return $this->render('competitive_intelligence/orchestrator_log.html.twig', [
            'filename' => $log['filename'],
            'path' => $log['path'],
            'content' => $log['content'],
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
            'competitors' => $this->getCompetitorRepository($entityManager)
                ->createQueryBuilder('c')
                ->orderBy('c.name', 'ASC')
                ->getQuery()
                ->getResult(),
        ]);
    }

    #[Route('/recherche/{productId}/ajouter-url', name: 'app_competitive_search_manual_url', methods: ['POST'])]
    public function searchManualUrl(
        int $productId,
        Request $request,
        EntityManagerInterface $entityManager,
        HttpClientInterface $httpClient,
        CompetitivePriceHistoryService $priceHistoryService,
    ): Response {
        $backQuery = trim((string) $request->request->get('q', ''));
        $embedded = '1' === (string) $request->request->get('embed', '');
        $competitorId = (int) $request->request->get('competitor_id', 0);
        $url = trim((string) $request->request->get('url', ''));

        $redirectParams = array_filter([
            'q' => $backQuery !== '' ? $backQuery : null,
            'embed' => $embedded ? '1' : null,
        ], static fn (mixed $value): bool => $value !== null);

        if ($competitorId <= 0 || $url === '') {
            $this->addFlash('error', 'Concurrent et URL sont obligatoires.');

            return $this->redirectToRoute('app_competitive_search', $redirectParams);
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->addFlash('error', 'URL invalide.');

            return $this->redirectToRoute('app_competitive_search', $redirectParams);
        }

        $competitor = $this->getCompetitorRepository($entityManager)->find($competitorId);
        if (!$competitor instanceof Competitor) {
            $this->addFlash('error', 'Concurrent introuvable.');

            return $this->redirectToRoute('app_competitive_search', $redirectParams);
        }

        if (!$this->urlMatchesCompetitorDomain($url, $competitor)) {
            $this->addFlash('error', sprintf(
                'L\'URL ne correspond pas au domaine du concurrent %s.',
                $competitor->getName()
            ));

            return $this->redirectToRoute('app_competitive_search', $redirectParams);
        }

        $scrapedPrice = $this->fetchManualCompetitorPrice($httpClient, $competitor, $url);
        $priceString = $scrapedPrice !== null ? number_format($scrapedPrice, 2, '.', '') : null;

        $testResult = $entityManager->getRepository(CompetitorUrlTestResult::class)->findOneBy([
            'productId' => $productId,
            'competitor' => $competitor,
        ]);

        if (!$testResult instanceof CompetitorUrlTestResult) {
            $testResult = new CompetitorUrlTestResult(
                $productId,
                $competitor,
                CompetitorUrlTestResult::RESULT_MATCHED,
                $url,
                null,
                null,
                null,
                CompetitorUrlTestResult::PAGE_OK,
                null,
                100,
                $priceString,
                CompetitorUrlTestResult::REVIEW_VALID,
                'manual',
                'Ajout manuel depuis la recherche',
            );
            $entityManager->persist($testResult);
        } else {
            $testResult
                ->setResult(CompetitorUrlTestResult::RESULT_MATCHED)
                ->setUrl($url)
                ->setScore(100)
                ->setValidationStatus(CompetitorUrlTestResult::REVIEW_VALID)
                ->setCompetitorPageStatus(CompetitorUrlTestResult::PAGE_OK)
                ->setMatchedQuery('manual')
                ->setMessage('Ajout manuel depuis la recherche')
                ->touch();
            if ($priceString !== null) {
                $testResult->setCompetitorPrice($priceString);
            }
        }

        $final = $entityManager->getRepository(CompetitorUrlFinal::class)->findOneBy([
            'id' => $productId,
            'competitor' => $competitor,
        ]);

        if (!$final instanceof CompetitorUrlFinal) {
            $final = new CompetitorUrlFinal($productId, $competitor, $url, $testResult->getCompetitorPrice());
            $entityManager->persist($final);
        } else {
            $final
                ->setUrl($url)
                ->setCompetitorPrice($testResult->getCompetitorPrice())
                ->resetHttpFailureState();
        }

        if ($testResult->getCompetitorPrice() !== null) {
            $priceHistoryService->recordObservation(
                $competitor,
                $productId,
                $url,
                $testResult->getCompetitorPrice(),
                'manual',
            );
        }

        $entityManager->flush();

        $this->addFlash('success', sprintf(
            'URL ajoutée manuellement pour %s.',
            $competitor->getName()
        ));

        return $this->redirectToRoute('app_competitive_search', $redirectParams);
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
        $embedded = '1' === (string) $request->request->get('embed', '');

        try {
            $reviewService->updateReviewStatus($productId, $competitorId, CompetitorUrlTestResult::REVIEW_REJECTED);
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Unknown competitor_url_test_result')) {
                throw $e;
            }
            $this->addFlash('warning', sprintf(
                'Le test result n\'existe plus pour le produit %d / concurrent %d. URL finale supprimée uniquement.',
                $productId,
                $competitorId,
            ));
        }

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
            'embed' => $embedded ? '1' : null,
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
        $embedded = '1' === (string) $request->request->get('embed', '');

        try {
            $reviewService->restoreRejectedReview($productId, $competitorId);
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Unknown competitor_url_test_result')) {
                throw $e;
            }
            $this->addFlash('error', sprintf(
                'Impossible de revalider: le test result n\'existe plus pour le produit %d / concurrent %d.',
                $productId,
                $competitorId,
            ));
        }

        return $this->redirectToRoute('app_competitive_search', array_filter([
            'q' => $backQuery !== '' ? $backQuery : null,
            'embed' => $embedded ? '1' : null,
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
        $embedded = '1' === (string) $request->request->get('embed', '');

        try {
            $reviewService->updateReviewStatus($productId, $competitorId, CompetitorUrlTestResult::REVIEW_VALID);
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'Unknown competitor_url_test_result')) {
                throw $e;
            }
            $this->addFlash('error', sprintf(
                'Impossible de valider: le test result n\'existe plus pour le produit %d / concurrent %d.',
                $productId,
                $competitorId,
            ));
        }

        return $this->redirectToRoute('app_competitive_search', array_filter([
            'q' => $backQuery !== '' ? $backQuery : null,
            'embed' => $embedded ? '1' : null,
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

    private function urlMatchesCompetitorDomain(string $url, Competitor $competitor): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return false;
        }

        $host = strtolower($host);
        $domain = strtolower($competitor->getDomain());

        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    private function fetchManualCompetitorPrice(HttpClientInterface $httpClient, Competitor $competitor, string $url): ?float
    {
        try {
            $response = $httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
                    'Referer' => sprintf('https://%s/', $competitor->getDomain()),
                ],
                'timeout' => 30,
                'max_redirects' => 5,
            ]);
            if ($response->getStatusCode() >= 400) {
                return null;
            }

            $html = $response->getContent();
        } catch (\Throwable) {
            return null;
        }

        return $this->extractCompetitorPriceFromHtml($competitor, $html);
    }

    private function extractCompetitorPriceFromHtml(Competitor $competitor, string $html): ?float
    {
        $domain = strtolower($competitor->getDomain());
        $crawler = new Crawler($html);

        $metaSelectors = [
            'meta[itemprop="price"]' => 'content',
            'meta[property="product:price:amount"]' => 'content',
            'meta[property="og:price:amount"]' => 'content',
        ];

        if (str_contains($domain, 'thomann')) {
            foreach ($metaSelectors as $selector => $attribute) {
                $value = $crawler->filter($selector)->first()->attr($attribute) ?? '';
                if ($value === '') {
                    continue;
                }
                $price = $this->parsePriceText($value);
                if ($price !== null) {
                    return $price;
                }
            }
        }

        $selectors = match (true) {
            str_contains($domain, 'woodbrass') => [
                'div.fwb.fs40.fs30-md.fs28-sm.lh1',
                '.fwb.fs40.fs30-md.fs28-sm.lh1',
                '.col-20.wsnw',
            ],
            str_contains($domain, 'thomann') => [
                'div.price.fx-text.fx-text--no-margin',
                'span.fx-typography-price-primary',
                'div.price',
                '.price.fx-text',
            ],
            str_contains($domain, 'stars-music') => [
                '.product-final-price',
                '.product-final-price .price-decimal',
            ],
            str_contains($domain, 'michenaud') => [
                'span.price',
                '.price',
            ],
            default => [],
        };

        foreach ($selectors as $selector) {
            $text = trim($crawler->filter($selector)->first()->text(''));
            if ($text === '') {
                continue;
            }
            $price = $this->parsePriceText($text);
            if ($price !== null) {
                return $price;
            }
        }

        foreach ($metaSelectors as $selector => $attribute) {
            $value = $crawler->filter($selector)->first()->attr($attribute) ?? '';
            if ($value === '') {
                continue;
            }
            $price = $this->parsePriceText($value);
            if ($price !== null) {
                return $price;
            }
        }

        if (
            str_contains($domain, 'thomann')
            || str_contains($domain, 'woodbrass')
            || str_contains($domain, 'stars-music')
            || str_contains($domain, 'michenaud')
        ) {
            return null;
        }

        if (preg_match('/([0-9][0-9 .,\x{00A0}]*)\s*€/u', $html, $matches) === 1) {
            return $this->parsePriceText($matches[1]);
        }

        return null;
    }

    private function parsePriceText(string $text): ?float
    {
        $cleaned = trim(str_ireplace(['eur', '€', "\u{00A0}"], ['', '', ' '], $text));
        if ($cleaned === '') {
            return null;
        }

        if (preg_match('/([0-9][0-9\s.,]*)/', $cleaned, $matches) !== 1) {
            return null;
        }

        $numeric = preg_replace('/\s+/', '', $matches[1] ?? '');
        if (!is_string($numeric) || $numeric === '') {
            return null;
        }

        if (str_contains($numeric, ',') && str_contains($numeric, '.')) {
            $lastComma = strrpos($numeric, ',');
            $lastDot = strrpos($numeric, '.');
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $numeric = str_replace($thousandsSeparator, '', $numeric);
            $numeric = str_replace($decimalSeparator, '.', $numeric);
        } elseif (substr_count($numeric, ',') > 1) {
            $numeric = str_replace(',', '', $numeric);
        } elseif (substr_count($numeric, '.') > 1) {
            $numeric = str_replace('.', '', $numeric);
        } else {
            $separator = str_contains($numeric, ',') ? ',' : (str_contains($numeric, '.') ? '.' : null);
            if ($separator !== null) {
                [$left, $right] = array_pad(explode($separator, $numeric, 2), 2, '');
                if (ctype_digit($right) && strlen($right) === 3 && $left !== '') {
                    $numeric = $left . $right;
                } elseif ($separator === ',') {
                    $numeric = $left . '.' . $right;
                }
            }
        }

        if (!is_numeric($numeric)) {
            return null;
        }

        return (float) $numeric;
    }

    /**
     * @param array<string, mixed> $currentConfig
     * @return array<string, mixed>
     */
    private function buildOrchestratorConfig(Request $request, array $currentConfig): array
    {
        $currentConfig['global'] = is_array($currentConfig['global'] ?? null) ? $currentConfig['global'] : [];
        $currentConfig['tasks'] = is_array($currentConfig['tasks'] ?? null) ? $currentConfig['tasks'] : [];

        $currentConfig['global']['enabled'] = $request->request->has('global_enabled');
        $currentConfig['global']['max_parallel'] = max(1, min(12, (int) $request->request->get('global_max_parallel', 1)));
        $currentConfig['global']['lang_id'] = max(1, min(12, (int) $request->request->get('global_lang_id', 1)));
        $currentConfig['global']['shop_id'] = max(1, min(12, (int) $request->request->get('global_shop_id', 1)));

        foreach ($request->request->all('tasks') as $taskKey => $taskConfig) {
            if (!is_array($taskConfig)) {
                continue;
            }

            $intervalHours = max(0, min(168, (int) ($taskConfig['interval_hours'] ?? 0)));
            $intervalMinutes = max(0, min(59, (int) ($taskConfig['interval_minutes_part'] ?? 0)));
            $intervalTotalMinutes = max(1, min(10080, ($intervalHours * 60) + $intervalMinutes));

            $currentConfig['tasks'][(string) $taskKey] = [
                'enabled' => array_key_exists('enabled', $taskConfig),
                'limit' => max(1, min(100, (int) ($taskConfig['limit'] ?? 10))),
                'interval_minutes' => $intervalTotalMinutes,
                'priority' => max(1, min(1000, (int) ($taskConfig['priority'] ?? 100))),
            ];
        }

        return $currentConfig;
    }

    /**
     * @return array{
     *   competitors:array<int, array{id:int,key:string,name:string}>,
     *   totals:array{products:int,cheaper:int,same:int,pricier:int,avg_delta:float},
     *   histogram:array<int, array{label:string,count:int,tone:string}>,
     *   rows:array<int, array<string, mixed>>
     * }
     */
    private function buildCompetitivePriceBoard(
        EntityManagerInterface $entityManager,
        PrestashopProductBatchProvider $batchProvider,
        int $limit,
        string $selectedBucket = '',
        string $selectedCompetitor = '',
    ): array {
        $competitors = $this->getCompetitorRepository($entityManager)
            ->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        $competitorMap = [];
        $competitorKeyMap = [];
        foreach ($competitors as $competitor) {
            if (!$competitor instanceof Competitor || $competitor->getId() === null) {
                continue;
            }

            $entry = [
                'id' => $competitor->getId(),
                'key' => $this->slugifyCompetitorKey($competitor->getName()),
                'name' => $competitor->getName(),
            ];
            $competitorMap[$competitor->getId()] = $entry;
            $competitorKeyMap[$entry['key']] = (int) $competitor->getId();
        }

        $selectedCompetitorId = null;
        $selectedCompetitorInfo = null;
        if ($selectedCompetitor !== '' && isset($competitorKeyMap[$selectedCompetitor])) {
            $selectedCompetitorId = $competitorKeyMap[$selectedCompetitor];
            $selectedCompetitorInfo = $competitorMap[$selectedCompetitorId] ?? null;
        }

        $finalRows = $entityManager->getRepository(\App\Entity\CompetitorUrlFinal::class)
            ->createQueryBuilder('f')
            ->leftJoin('f.competitor', 'competitor')
            ->addSelect('competitor')
            ->andWhere('f.competitorPrice IS NOT NULL')
            ->orderBy('f.id', 'DESC')
            ->getQuery()
            ->getResult();

        $groupedPrices = [];
        foreach ($finalRows as $finalRow) {
            if (!$finalRow instanceof \App\Entity\CompetitorUrlFinal) {
                continue;
            }

            $productId = $finalRow->getId();
            $competitor = $finalRow->getCompetitor();
            $competitorId = $competitor->getId();
            if ($competitorId === null || !isset($competitorMap[$competitorId])) {
                continue;
            }

            $price = $finalRow->getCompetitorPrice();
            if ($price === null) {
                continue;
            }

            $groupedPrices[$productId][$competitorId] = [
                'price' => (float) $price,
                'url' => $finalRow->getUrl(),
                'competitor' => $competitorMap[$competitorId],
            ];
        }

        $productIds = array_keys($groupedPrices);
        $snapshots = $batchProvider->getProductSnapshotsByIds($productIds);

        $allRows = [];
        $deltaValues = [];
        $cheaper = 0;
        $same = 0;
        $pricier = 0;

        foreach ($productIds as $productId) {
            $snapshot = $snapshots[$productId] ?? null;
            if (!is_array($snapshot)) {
                continue;
            }

            $sourcePrice = isset($snapshot['source_price']) ? (float) $snapshot['source_price'] : null;
            if ($sourcePrice === null || $sourcePrice <= 0) {
                continue;
            }

            $competitorPrices = $groupedPrices[$productId] ?? [];
            if ($competitorPrices === []) {
                continue;
            }

            if ($selectedCompetitorId !== null && !isset($competitorPrices[$selectedCompetitorId])) {
                continue;
            }

            $prices = array_map(static fn (array $entry): float => (float) $entry['price'], $competitorPrices);
            $avgPrice = array_sum($prices) / count($prices);
            if ($avgPrice <= 0) {
                continue;
            }

            $deltaPercent = (($sourcePrice - $avgPrice) / $avgPrice) * 100;
            $priceIndex = ($sourcePrice / $avgPrice) * 100;
            $deltaValues[] = $deltaPercent;

            if ($deltaPercent < -1.0) {
                $cheaper++;
                $tone = 'cheaper';
            } elseif ($deltaPercent > 1.0) {
                $pricier++;
                $tone = 'pricier';
            } else {
                $same++;
                $tone = 'same';
            }

            $competitorCells = [];
            foreach ($competitorMap as $competitorId => $competitorInfo) {
                $entry = $competitorPrices[$competitorId] ?? null;
                if ($entry === null) {
                    $competitorCells[] = [
                        'competitor' => $competitorInfo,
                        'price' => null,
                        'delta_percent' => null,
                        'tone' => 'empty',
                        'url' => null,
                    ];
                    continue;
                }

                $competitorPrice = (float) $entry['price'];
                $competitorDelta = (($sourcePrice - $competitorPrice) / $competitorPrice) * 100;
                $competitorCells[] = [
                    'competitor' => $competitorInfo,
                    'price' => $competitorPrice,
                    'delta_percent' => $competitorDelta,
                    'tone' => $competitorDelta < -1.0 ? 'cheaper' : ($competitorDelta > 1.0 ? 'pricier' : 'same'),
                    'url' => $entry['url'],
                ];
            }

            $allRows[] = [
                'product_id' => $productId,
                'name' => (string) ($snapshot['name'] ?? ('Produit ' . $productId)),
                'brand' => $snapshot['brand'] ?? null,
                'supplier_reference' => $snapshot['supplier_reference'] ?? null,
                'source_image_url' => $snapshot['source_image_url'] ?? null,
                'source_price' => $sourcePrice,
                'avg_price' => $avgPrice,
                'price_index' => $priceIndex,
                'delta_percent' => $deltaPercent,
                'tone' => $tone,
                'competitor_count' => count($prices),
                'competitors' => $competitorCells,
            ];
        }

        usort($allRows, static function (array $left, array $right): int {
            return abs((float) $right['delta_percent']) <=> abs((float) $left['delta_percent']);
        });

        $histogram = $this->buildPriceDeltaHistogram($deltaValues);
        $filteredRows = $allRows;
        $matchedBucketLabel = null;
        if ($selectedBucket !== '') {
            foreach ($histogram as $bucket) {
                if (($bucket['key'] ?? null) !== $selectedBucket) {
                    continue;
                }

                $matchedBucketLabel = (string) $bucket['label'];
                $filteredRows = array_values(array_filter(
                    $allRows,
                    static function (array $row) use ($bucket): bool {
                        $delta = (float) ($row['delta_percent'] ?? 0.0);
                        $isLast = ((float) $bucket['max']) === 1000.0;

                        return $delta >= (float) $bucket['min']
                            && ($delta < (float) $bucket['max'] || ($isLast && $delta <= (float) $bucket['max']));
                    }
                ));
                break;
            }
        }

        $displayedRows = array_slice($filteredRows, 0, $limit);

        return [
            'competitors' => array_values($competitorMap),
            'totals' => [
                'products' => count($allRows),
                'cheaper' => $cheaper,
                'same' => $same,
                'pricier' => $pricier,
                'avg_delta' => $deltaValues === [] ? 0.0 : array_sum($deltaValues) / count($deltaValues),
            ],
            'histogram' => $histogram,
            'rows' => $displayedRows,
            'filtered_total' => count($filteredRows),
            'selected_bucket' => $selectedBucket !== '' ? $selectedBucket : null,
            'selected_bucket_label' => $matchedBucketLabel,
            'selected_competitor' => $selectedCompetitorInfo,
        ];
    }

    /**
     * @return array{
     *   rows:array<int, array<string, mixed>>,
     *   totals:array{products:int,thomann:int,michenaud:int,both:int},
     *   threshold:float
     * }
     */
    private function buildTrustedGapBoard(
        EntityManagerInterface $entityManager,
        PrestashopProductBatchProvider $batchProvider,
        int $limit,
        float $threshold,
        bool $showThomann = true,
        bool $showMichenaud = true,
        bool $showBoth = true,
    ): array {
        $competitors = $this->getCompetitorRepository($entityManager)
            ->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        $competitorMap = [];
        foreach ($competitors as $competitor) {
            if (!$competitor instanceof Competitor || $competitor->getId() === null) {
                continue;
            }

            $entry = [
                'id' => $competitor->getId(),
                'key' => $this->slugifyCompetitorKey($competitor->getName()),
                'name' => $competitor->getName(),
            ];
            $competitorMap[(int) $competitor->getId()] = $entry;
        }

        $trustedIds = [];
        $targetIds = [];
        foreach ($competitorMap as $competitorId => $competitorInfo) {
            if (in_array($competitorInfo['key'], ['woodbrass', 'stars-music'], true)) {
                $trustedIds[$competitorInfo['key']] = $competitorId;
            }
            if (in_array($competitorInfo['key'], ['thomann', 'michenaud'], true)) {
                $targetIds[$competitorInfo['key']] = $competitorId;
            }
        }

        $finalRows = $entityManager->getRepository(\App\Entity\CompetitorUrlFinal::class)
            ->createQueryBuilder('f')
            ->leftJoin('f.competitor', 'competitor')
            ->addSelect('competitor')
            ->andWhere('f.competitorPrice IS NOT NULL')
            ->orderBy('f.id', 'DESC')
            ->getQuery()
            ->getResult();

        $groupedPrices = [];
        foreach ($finalRows as $finalRow) {
            if (!$finalRow instanceof \App\Entity\CompetitorUrlFinal) {
                continue;
            }

            $productId = $finalRow->getId();
            $competitorId = $finalRow->getCompetitor()->getId();
            if ($competitorId === null || !isset($competitorMap[$competitorId])) {
                continue;
            }

            $price = $finalRow->getCompetitorPrice();
            if ($price === null) {
                continue;
            }

            $groupedPrices[$productId][$competitorId] = [
                'price' => (float) $price,
                'url' => $finalRow->getUrl(),
                'competitor' => $competitorMap[$competitorId],
            ];
        }

        $productIds = array_keys($groupedPrices);
        $snapshots = $batchProvider->getProductSnapshotsByIds($productIds);

        $rows = [];
        $thomannCount = 0;
        $michenaudCount = 0;
        $bothCount = 0;

        foreach ($productIds as $productId) {
            $snapshot = $snapshots[$productId] ?? null;
            if (!is_array($snapshot)) {
                continue;
            }

            $pricesByCompetitor = $groupedPrices[$productId] ?? [];
            if ($pricesByCompetitor === []) {
                continue;
            }

            $trustedEntries = [];
            foreach ($trustedIds as $trustedId) {
                if (isset($pricesByCompetitor[$trustedId])) {
                    $trustedEntries[] = $pricesByCompetitor[$trustedId];
                }
            }
            if ($trustedEntries === []) {
                continue;
            }

            $trustedPrices = array_map(static fn (array $entry): float => (float) $entry['price'], $trustedEntries);
            $trustedAvg = array_sum($trustedPrices) / count($trustedPrices);
            if ($trustedAvg <= 0) {
                continue;
            }

            $thomannGap = $this->buildTrustedGapEntry($pricesByCompetitor[$targetIds['thomann'] ?? 0] ?? null, $trustedAvg, $threshold);
            $michenaudGap = $this->buildTrustedGapEntry($pricesByCompetitor[$targetIds['michenaud'] ?? 0] ?? null, $trustedAvg, $threshold);

            if (!$thomannGap['is_offender'] && !$michenaudGap['is_offender']) {
                continue;
            }

            if ($thomannGap['is_offender']) {
                $thomannCount++;
            }
            if ($michenaudGap['is_offender']) {
                $michenaudCount++;
            }
            if ($thomannGap['is_offender'] && $michenaudGap['is_offender']) {
                $bothCount++;
            }

            $matchesVisibleFilter = (
                ($thomannGap['is_offender'] && $michenaudGap['is_offender'] && $showBoth)
                || ($thomannGap['is_offender'] && !$michenaudGap['is_offender'] && $showThomann)
                || (!$thomannGap['is_offender'] && $michenaudGap['is_offender'] && $showMichenaud)
            );
            if (!$matchesVisibleFilter) {
                continue;
            }

            $maxGap = max(
                (float) ($thomannGap['delta_percent_abs'] ?? 0.0),
                (float) ($michenaudGap['delta_percent_abs'] ?? 0.0),
            );

            $rows[] = [
                'product_id' => $productId,
                'name' => (string) ($snapshot['name'] ?? ('Produit ' . $productId)),
                'brand' => $snapshot['brand'] ?? null,
                'supplier_reference' => $snapshot['supplier_reference'] ?? null,
                'source_image_url' => $snapshot['source_image_url'] ?? null,
                'source_price' => isset($snapshot['source_price']) ? (float) $snapshot['source_price'] : null,
                'trusted_avg' => $trustedAvg,
                'trusted_entries' => $trustedEntries,
                'thomann' => $thomannGap,
                'michenaud' => $michenaudGap,
                'max_gap' => $maxGap,
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            return ((float) $right['max_gap']) <=> ((float) $left['max_gap']);
        });

        return [
            'rows' => array_slice($rows, 0, $limit),
            'totals' => [
                'products' => count($rows),
                'thomann' => $thomannCount,
                'michenaud' => $michenaudCount,
                'both' => $bothCount,
            ],
            'threshold' => $threshold,
        ];
    }

    /**
     * @param array<string, mixed>|null $entry
     * @return array{price:?float,url:?string,delta_percent:?float,delta_percent_abs:float,is_offender:bool,tone:string}
     */
    private function buildTrustedGapEntry(?array $entry, float $trustedAvg, float $threshold): array
    {
        if ($entry === null) {
            return [
                'price' => null,
                'url' => null,
                'delta_percent' => null,
                'delta_percent_abs' => 0.0,
                'is_offender' => false,
                'tone' => 'empty',
            ];
        }

        $price = (float) ($entry['price'] ?? 0.0);
        if ($price <= 0 || $trustedAvg <= 0) {
            return [
                'price' => $price > 0 ? $price : null,
                'url' => $entry['url'] ?? null,
                'delta_percent' => null,
                'delta_percent_abs' => 0.0,
                'is_offender' => false,
                'tone' => 'empty',
            ];
        }

        $deltaPercent = (($price - $trustedAvg) / $trustedAvg) * 100;
        $deltaAbs = abs($deltaPercent);

        return [
            'price' => $price,
            'url' => $entry['url'] ?? null,
            'delta_percent' => $deltaPercent,
            'delta_percent_abs' => $deltaAbs,
            'is_offender' => $deltaAbs > $threshold,
            'tone' => $deltaPercent > 0 ? 'pricier' : ($deltaPercent < 0 ? 'cheaper' : 'same'),
        ];
    }

    private function slugifyCompetitorKey(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? 'competitor';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'competitor';
    }

    /**
     * @param array<int, float> $deltaValues
     * @return array<int, array{key:string,label:string,count:int,tone:string,min:float,max:float}>
     */
    private function buildPriceDeltaHistogram(array $deltaValues): array
    {
        $buckets = [
            ['key' => 'lt-30', 'min' => -1000.0, 'max' => -30.0, 'label' => '< -30%', 'tone' => 'cheaper'],
            ['key' => 'm30-m20', 'min' => -30.0, 'max' => -20.0, 'label' => '-30%', 'tone' => 'cheaper'],
            ['key' => 'm20-m10', 'min' => -20.0, 'max' => -10.0, 'label' => '-20%', 'tone' => 'cheaper'],
            ['key' => 'm10-m5', 'min' => -10.0, 'max' => -5.0, 'label' => '-10%', 'tone' => 'cheaper'],
            ['key' => 'm5-m1', 'min' => -5.0, 'max' => -1.0, 'label' => '-5%', 'tone' => 'cheaper'],
            ['key' => 'eq', 'min' => -1.0, 'max' => 1.0, 'label' => '0%', 'tone' => 'same'],
            ['key' => 'p1-p5', 'min' => 1.0, 'max' => 5.0, 'label' => '5%', 'tone' => 'pricier'],
            ['key' => 'p5-p10', 'min' => 5.0, 'max' => 10.0, 'label' => '10%', 'tone' => 'pricier'],
            ['key' => 'p10-p20', 'min' => 10.0, 'max' => 20.0, 'label' => '20%', 'tone' => 'pricier'],
            ['key' => 'p20-p30', 'min' => 20.0, 'max' => 30.0, 'label' => '30%', 'tone' => 'pricier'],
            ['key' => 'gt-30', 'min' => 30.0, 'max' => 1000.0, 'label' => '> 30%', 'tone' => 'pricier'],
        ];

        $histogram = [];
        foreach ($buckets as $bucket) {
            $count = 0;
            foreach ($deltaValues as $delta) {
                $isLast = $bucket['max'] === 1000.0;
                if ($delta >= $bucket['min'] && ($delta < $bucket['max'] || ($isLast && $delta <= $bucket['max']))) {
                    $count++;
                }
            }

            $histogram[] = [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'count' => $count,
                'tone' => $bucket['tone'],
                'min' => $bucket['min'],
                'max' => $bucket['max'],
            ];
        }

        return $histogram;
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
     *     postponed: int,
     *     rejected: int,
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
                'postponed' => 0,
                'rejected' => 0,
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

            if ($validationStatus === CompetitorUrlTestResult::REVIEW_POSTPONED) {
                $report[$competitorId]['postponed'] += $total;
                $report[$competitorId]['total'] += $total;
                continue;
            }

            if ($validationStatus === CompetitorUrlTestResult::REVIEW_REJECTED) {
                $report[$competitorId]['rejected'] += $total;
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
     *     postponed:int,
     *     rejected:int,
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
            'postponed' => 0,
            'rejected' => 0,
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
     * @return array<string, int>
     */
    private function countPriceScrapesLast24hByCompetitor(EntityManagerInterface $entityManager): array
    {
        $cutoff = new \DateTimeImmutable('-24 hours');
        $rows = $entityManager->getRepository(CompetitorUrlPriceHistory::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.competitor', 'competitor')
            ->select('competitor.name AS competitor_name, COUNT(p.id) AS total')
            ->andWhere('p.observedAt >= :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->groupBy('competitor.id, competitor.name')
            ->orderBy('competitor.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['competitor_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $counts[$name] = (int) ($row['total'] ?? 0);
        }

        return $counts;
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
