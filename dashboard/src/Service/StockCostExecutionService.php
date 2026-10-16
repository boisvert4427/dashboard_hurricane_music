<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class StockCostExecutionService
{
    public function __construct(
        private readonly StockCostCacheService $service,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{
     *     status: 'completed'|'skipped',
     *     reason: string|null,
     *     discovered_movements: int,
     *     queued_pairs: int,
     *     processed_pairs: int,
     *     processed_movements: int,
     *     failed_pairs: int
     * }
     */
    public function run(int $pairLimit = 100, int $discoveryLimit = 5000): array
    {
        $lockDirectory = $this->projectDir . '/var/lock';
        if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0775, true) && !is_dir($lockDirectory)) {
            throw new \RuntimeException('Unable to create the ETL lock directory.');
        }

        $lockHandle = fopen($lockDirectory . '/heavy-processing.lock', 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }

            return $this->skippedResult('another_heavy_task_is_running');
        }

        if ($this->isCompetitiveTaskRunning()) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);

            return $this->skippedResult('competitive_intelligence_worker_is_running');
        }

        try {
            $stats = [
                'discovered_movements' => 0,
                'queued_pairs' => 0,
                'processed_pairs' => 0,
                'processed_movements' => 0,
                'failed_pairs' => 0,
            ];
            do {
                $batch = $this->service->run(max(1, $pairLimit), max(1, $discoveryLimit));
                foreach ($stats as $key => $value) {
                    $stats[$key] = $value + $batch[$key];
                }
                $hasMoreWork = $batch['discovered_movements'] > 0
                    || $batch['processed_pairs'] > 0
                    || $batch['failed_pairs'] > 0;
            } while ($hasMoreWork);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        return ['status' => 'completed', 'reason' => null, ...$stats];
    }

    /**
     * @return array{status:'skipped', reason:string, discovered_movements:int, queued_pairs:int, processed_pairs:int, processed_movements:int, failed_pairs:int}
     */
    private function skippedResult(string $reason): array
    {
        return [
            'status' => 'skipped',
            'reason' => $reason,
            'discovered_movements' => 0,
            'queued_pairs' => 0,
            'processed_pairs' => 0,
            'processed_movements' => 0,
            'failed_pairs' => 0,
        ];
    }

    private function isCompetitiveTaskRunning(): bool
    {
        $lockDirectory = dirname($this->projectDir) . '/competitive_intelligence_python/var/lock/competitive-intelligence';
        $lockPaths = glob($lockDirectory . '/*.lock') ?: [];
        foreach ($lockPaths as $lockPath) {
            $handle = fopen($lockPath, 'c+');
            if ($handle === false) {
                continue;
            }

            $available = flock($handle, LOCK_EX | LOCK_NB);
            if ($available) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);

            if (!$available) {
                return true;
            }
        }

        return false;
    }
}
