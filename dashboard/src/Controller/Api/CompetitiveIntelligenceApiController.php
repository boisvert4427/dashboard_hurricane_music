<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Competitor;
use App\Service\CompetitiveIntelligence\CompetitiveCandidateIngestionService;
use App\Service\CompetitiveIntelligence\CompetitiveBatchRunner;
use App\Service\CompetitiveIntelligence\CompetitiveCandidateStatusService;
use App\Service\CompetitiveIntelligence\CompetitiveTestResultIngestionService;
use App\Service\CompetitiveIntelligence\PrestashopProductBatchProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/competitive')]
final class CompetitiveIntelligenceApiController extends AbstractController
{
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
            'batch' => $batch,
            'competitor' => [
                'id' => $competitor->getId(),
                'name' => $competitor->getName(),
                'domain' => $competitor->getDomain(),
                'search_url_pattern' => $competitor->getSearchUrlPattern(),
            ],
        ]);
    }

    #[Route('/candidates', name: 'api_competitive_candidates_ingest', methods: ['POST'])]
    public function ingest(
        Request $request,
        CompetitiveCandidateIngestionService $ingestionService,
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
            $stats = $ingestionService->ingest($payload);
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
            'inserted' => $stats['inserted'],
            'updated' => $stats['updated'],
            'ignored' => $stats['ignored'],
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
