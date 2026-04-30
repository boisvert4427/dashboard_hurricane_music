<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Competitor;
use App\Entity\CompetitorUrlCandidate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/veille-concurrentielle')]
final class CompetitiveIntelligenceController extends AbstractController
{
    #[Route('', name: 'app_competitive_home', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $statusCounts = $this->getCandidateStatusCounts($entityManager);
        $competitors = $this->getCompetitorRepository($entityManager)
            ->createQueryBuilder('c')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        $recentCandidates = $this->getCandidateRepository($entityManager)
            ->createQueryBuilder('c')
            ->leftJoin('c.competitor', 'competitor')
            ->addSelect('competitor')
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults(12)
            ->getQuery()
            ->getResult();

        return $this->render('competitive_intelligence/home.html.twig', [
            'competitor_count' => count($competitors),
            'status_counts' => $statusCounts,
            'competitors' => $competitors,
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

    #[Route('/candidats', name: 'app_competitive_candidates', methods: ['GET'])]
    public function candidates(Request $request, EntityManagerInterface $entityManager): Response
    {
        $status = (string) $request->query->get('status', '');
        $allowedStatuses = [
            CompetitorUrlCandidate::STATUS_PENDING,
            CompetitorUrlCandidate::STATUS_VALID,
            CompetitorUrlCandidate::STATUS_REJECTED,
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            $status = '';
        }

        $queryBuilder = $this->getCandidateRepository($entityManager)
            ->createQueryBuilder('c')
            ->leftJoin('c.competitor', 'competitor')
            ->addSelect('competitor')
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults(100);

        if ($status !== '') {
            $queryBuilder->andWhere('c.status = :status')
                ->setParameter('status', $status);
        }

        return $this->render('competitive_intelligence/candidates.html.twig', [
            'selected_status' => $status,
            'candidates' => $queryBuilder->getQuery()->getResult(),
            'status_counts' => $this->getCandidateStatusCounts($entityManager),
            'status_values' => [
                'pending' => CompetitorUrlCandidate::STATUS_PENDING,
                'valid' => CompetitorUrlCandidate::STATUS_VALID,
                'rejected' => CompetitorUrlCandidate::STATUS_REJECTED,
            ],
        ]);
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

        $rows = $this->getCandidateRepository($entityManager)
            ->createQueryBuilder('c')
            ->select('c.status AS status, COUNT(c.id) AS total')
            ->groupBy('c.status')
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

    private function getCandidateRepository(EntityManagerInterface $entityManager)
    {
        return $entityManager->getRepository(CompetitorUrlCandidate::class);
    }
}
