<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use Symfony\Component\Process\Process;

final class CompetitiveBatchRunner
{
    /**
     * @return array{pid:int|null, command:string}
     */
    public function start(
        string $projectDir,
        string $apiBaseUrl,
        string $apiToken,
        int $competitorId,
        int $limit = 10,
        int $afterId = 0,
        int $langId = 1,
        int $shopId = 1,
        bool $debug = false,
    ): array {
        $projectRoot = dirname(rtrim($projectDir, '/'));
        $this->assertBatchNotRunning($projectRoot, $competitorId, $langId, $shopId);

        $python = $this->resolvePythonBinary();
        $script = $projectRoot . '/competitive_intelligence_python/run_batch.py';

        if (!is_file($script)) {
            throw new \RuntimeException(sprintf('Python batch script not found at "%s".', $script));
        }

        $env = [
            'CI_API_BASE_URL' => rtrim($apiBaseUrl, '/'),
            'CI_API_TOKEN' => $apiToken,
            'CI_COMPETITOR_ID' => (string) $competitorId,
            'CI_BATCH_LIMIT' => (string) $limit,
            'CI_AFTER_ID' => (string) $afterId,
            'CI_LANG_ID' => (string) $langId,
            'CI_SHOP_ID' => (string) $shopId,
        ];

        if ($debug) {
            $env['CI_DEBUG'] = '1';
        }

        $logDir = $projectRoot . '/var/log/competitive-intelligence';
        if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
            throw new \RuntimeException(sprintf('Unable to create log directory "%s".', $logDir));
        }

        $timestamp = (new \DateTimeImmutable())->format('YmdHis');
        $logFile = sprintf('%s/batch-%s-c%d.log', $logDir, $timestamp, $competitorId);

        $envParts = [];
        foreach ($env as $key => $value) {
            $envParts[] = sprintf('%s=%s', $key, escapeshellarg($value));
        }

        $commandLine = sprintf(
            'cd %s && nohup env %s %s %s >> %s 2>&1 < /dev/null & echo $!',
            escapeshellarg($projectRoot),
            implode(' ', $envParts),
            escapeshellarg($python),
            escapeshellarg($script),
            escapeshellarg($logFile)
        );

        $process = Process::fromShellCommandline($commandLine);
        $process->run();
        $pid = trim($process->getOutput());

        if ($pid === '') {
            throw new \RuntimeException(sprintf('Unable to start batch runner. Log file: %s', $logFile));
        }

        return [
            'pid' => ctype_digit($pid) ? (int) $pid : null,
            'command' => $commandLine,
            'log_file' => $logFile,
        ];
    }

    private function assertBatchNotRunning(string $projectRoot, int $competitorId, int $langId, int $shopId): void
    {
        $lockDir = $projectRoot . '/competitive_intelligence_python/var/lock/competitive-intelligence';
        if (!is_dir($lockDir) && !mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            throw new \RuntimeException(sprintf('Unable to create lock directory "%s".', $lockDir));
        }

        $lockPath = sprintf(
            '%s/competitor-%d-lang-%d-shop-%d.lock',
            $lockDir,
            $competitorId,
            $langId,
            $shopId,
        );

        $lockHandle = fopen($lockPath, 'c+');
        if ($lockHandle === false) {
            throw new \RuntimeException(sprintf('Unable to open lock file "%s".', $lockPath));
        }

        try {
            if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException(
                    sprintf(
                        'A batch is already running for competitor_id=%d, lang_id=%d, shop_id=%d.',
                        $competitorId,
                        $langId,
                        $shopId,
                    )
                );
            }
        } finally {
            fclose($lockHandle);
        }
    }

    private function resolvePythonBinary(): string
    {
        foreach (['python3', '/usr/bin/python3', '/usr/local/bin/python3'] as $candidate) {
            if (is_executable($candidate) || $candidate === 'python3') {
                return $candidate;
            }
        }

        return 'python3';
    }
}
