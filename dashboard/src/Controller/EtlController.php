<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\InvoiceLineImportService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class EtlController extends AbstractController
{
    #[Route('/etl/import', name: 'app_etl_import', methods: ['GET', 'POST'])]
    public function import(
        Request $request,
        #[Autowire(service: 'doctrine.dbal.reporting_connection')]
        Connection $reportingConnection,
        #[Autowire(service: 'doctrine.dbal.prestashop_connection')]
        Connection $prestashopConnection,
    ): JsonResponse
    {
        $expectedToken = (string) $this->getParameter('etl_web_token');
        $providedToken = (string) ($request->query->get('token') ?? $request->headers->get('X-ETL-TOKEN', ''));
        $limit = $this->parseLimit($request->query->get('limit'));

        if ($expectedToken === '') {
            return $this->json([
                'ok' => false,
                'error' => 'ETL web token is not configured.',
            ], 503);
        }

        if (!hash_equals($expectedToken, $providedToken)) {
            return $this->json([
                'ok' => false,
                'error' => 'Forbidden',
            ], 403);
        }

        try {
            $importService = new InvoiceLineImportService($reportingConnection, $prestashopConnection);
            $stats = $importService->run(500, $limit);
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }

        return $this->json([
            'ok' => true,
            'inserted' => $stats['inserted'],
            'source_rows' => $stats['source_rows'],
        ]);
    }

    private function parseLimit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $limit = (int) $value;

        return $limit > 0 ? $limit : null;
    }
}
