<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use Symfony\Component\HttpKernel\KernelInterface;

final class CompetitiveTaskLogService
{
    private const COMPETITOR_KEYS = [
        1 => ['key' => 'woodbrass', 'label' => 'Woodbrass'],
        2 => ['key' => 'starsmusic', 'label' => 'Stars Music'],
        3 => ['key' => 'thomann', 'label' => 'Thomann'],
        4 => ['key' => 'michenaud', 'label' => 'Michenaud'],
    ];

    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
    }

    public function getLogDirectory(): string
    {
        return dirname($this->kernel->getProjectDir()) . '/var/log/competitive-intelligence';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRecentLogs(int $limit = 50): array
    {
        $entries = [];
        foreach ($this->listLogPaths() as $path) {
            $parsed = $this->parseLogFile($path);
            if ($parsed === null) {
                continue;
            }
            $entries[] = $parsed;
        }

        usort($entries, static function (array $left, array $right): int {
            return ($right['modified_at_ts'] ?? 0) <=> ($left['modified_at_ts'] ?? 0);
        });

        return array_slice($entries, 0, max(1, $limit));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function latestLogsByTask(): array
    {
        $index = [];
        foreach ($this->listRecentLogs(500) as $entry) {
            $taskKey = (string) ($entry['task_key'] ?? '');
            if ($taskKey === '' || isset($index[$taskKey])) {
                continue;
            }
            $index[$taskKey] = $entry;
        }

        return $index;
    }

    /**
     * @return array{filename:string, content:string, path:string}|null
     */
    public function readTail(string $filename, int $maxLines = 200): ?array
    {
        $filename = basename(trim($filename));
        if ($filename === '') {
            return null;
        }

        $path = $this->getLogDirectory() . '/' . $filename;
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return null;
        }

        $tail = array_slice($lines, -max(1, $maxLines));

        return [
            'filename' => $filename,
            'path' => $path,
            'content' => implode(PHP_EOL, $tail),
        ];
    }

    /**
     * @return array{filename:string, content:string, path:string}|null
     */
    public function readFull(string $filename): ?array
    {
        $filename = basename(trim($filename));
        if ($filename === '') {
            return null;
        }

        $path = $this->getLogDirectory() . '/' . $filename;
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        return [
            'filename' => $filename,
            'path' => $path,
            'content' => $content,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function listLogPaths(): array
    {
        $directory = $this->getLogDirectory();
        if (!is_dir($directory)) {
            return [];
        }

        $paths = glob($directory . '/*.log');

        return is_array($paths) ? $paths : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseLogFile(string $path): ?array
    {
        $filename = basename($path);
        $modifiedAt = @filemtime($path) ?: 0;
        $size = @filesize($path) ?: 0;

        if (preg_match('/^(?:batch|url)-(\d{14})-c(\d+)-(new_url|retry_url)\.log$/', $filename, $matches) === 1) {
            $competitorId = (int) $matches[2];
            $mode = (string) $matches[3];
            $taskType = $mode === PrestashopProductBatchProvider::MODE_RETRY_URL ? 'retry_urls' : 'new_urls';
            $competitor = self::COMPETITOR_KEYS[$competitorId] ?? null;
            if ($competitor === null) {
                return null;
            }

            return [
                'filename' => $filename,
                'path' => $path,
                'size' => $size,
                'modified_at' => $modifiedAt > 0 ? date(\DateTimeInterface::ATOM, $modifiedAt) : null,
                'modified_at_ts' => $modifiedAt,
                'competitor_id' => $competitorId,
                'competitor_key' => $competitor['key'],
                'competitor_label' => $competitor['label'],
                'task_type' => $taskType,
                'task_label' => $taskType === 'retry_urls' ? 'Retry URLs' : 'New URLs',
                'task_key' => $competitor['key'] . '.' . $taskType,
            ];
        }

        if (preg_match('/^(?:final-prices|prices)-(\d{14})-c(\d+)\.log$/', $filename, $matches) === 1) {
            $competitorId = (int) $matches[2];
            $competitor = self::COMPETITOR_KEYS[$competitorId] ?? null;
            if ($competitor === null) {
                return null;
            }

            return [
                'filename' => $filename,
                'path' => $path,
                'size' => $size,
                'modified_at' => $modifiedAt > 0 ? date(\DateTimeInterface::ATOM, $modifiedAt) : null,
                'modified_at_ts' => $modifiedAt,
                'competitor_id' => $competitorId,
                'competitor_key' => $competitor['key'],
                'competitor_label' => $competitor['label'],
                'task_type' => 'prices',
                'task_label' => 'Prices',
                'task_key' => $competitor['key'] . '.prices',
            ];
        }

        return null;
    }
}
