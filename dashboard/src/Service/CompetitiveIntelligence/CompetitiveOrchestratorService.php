<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use Doctrine\ORM\EntityManagerInterface;

final class CompetitiveOrchestratorService
{
    private const COMPETITORS = [
        'woodbrass' => ['id' => 1, 'label' => 'Woodbrass'],
        'starsmusic' => ['id' => 2, 'label' => 'Stars Music'],
        'thomann' => ['id' => 3, 'label' => 'Thomann'],
        'michenaud' => ['id' => 4, 'label' => 'Michenaud'],
    ];

    private const TASK_TYPES = [
        'new_urls' => ['label' => 'New URLs', 'mode' => PrestashopProductBatchProvider::MODE_NEW_URL],
        'retry_urls' => ['label' => 'Retry URLs', 'mode' => PrestashopProductBatchProvider::MODE_RETRY_URL],
        'prices' => ['label' => 'Prices', 'mode' => null],
    ];

    public function __construct(
        private readonly CompetitiveBatchRunner $batchRunner,
        private readonly FinalPriceBatchRunner $finalPriceBatchRunner,
        private readonly PrestashopProductBatchProvider $productBatchProvider,
        private readonly FinalUrlPriceBatchProvider $finalUrlPriceBatchProvider,
        private readonly CompetitiveOrchestratorStateStorage $stateStorage,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function defaultTasks(): array
    {
        $tasks = [];
        foreach (self::COMPETITORS as $competitorKey => $competitor) {
            $tasks[$this->taskKey($competitorKey, 'new_urls')] = [
                'enabled' => true,
                'limit' => 10,
                'interval_minutes' => 720,
                'priority' => 100,
            ];
            $tasks[$this->taskKey($competitorKey, 'retry_urls')] = [
                'enabled' => true,
                'limit' => 10,
                'interval_minutes' => 1440,
                'priority' => 30,
            ];
            $tasks[$this->taskKey($competitorKey, 'prices')] = [
                'enabled' => true,
                'limit' => 5,
                'interval_minutes' => 15,
                'priority' => 80,
            ];
        }

        return $tasks;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $state
     * @return array<int, array<string, mixed>>
     */
    public function describeTasks(array $config, array $state, int $langId = 1, int $shopId = 1): array
    {
        $configTasks = is_array($config['tasks'] ?? null) ? $config['tasks'] : [];
        $stateTasks = is_array($state['tasks'] ?? null) ? $state['tasks'] : [];
        $descriptions = [];

        foreach (self::COMPETITORS as $competitorKey => $competitor) {
            foreach (self::TASK_TYPES as $taskType => $taskDefinition) {
                $key = $this->taskKey($competitorKey, $taskType);
                $taskConfig = is_array($configTasks[$key] ?? null) ? $configTasks[$key] : [];
                $taskState = is_array($stateTasks[$key] ?? null) ? $stateTasks[$key] : [];
                $running = $this->isTaskRunning((int) $competitor['id'], $taskType, $langId, $shopId);
                $pending = $this->hasPendingWork((int) $competitor['id'], $taskType, $langId, $shopId);

                $descriptions[] = [
                    'key' => $key,
                    'competitor_key' => $competitorKey,
                    'competitor_id' => (int) $competitor['id'],
                    'competitor_label' => $competitor['label'],
                    'task_type' => $taskType,
                    'task_label' => $taskDefinition['label'],
                    'enabled' => (bool) ($taskConfig['enabled'] ?? false),
                    'limit' => (int) ($taskConfig['limit'] ?? 0),
                    'interval_minutes' => (int) ($taskConfig['interval_minutes'] ?? 0),
                    'priority' => (int) ($taskConfig['priority'] ?? 0),
                    'running' => $running,
                    'pending_work' => $pending,
                    'last_started_at' => $taskState['last_started_at'] ?? null,
                    'last_result' => $taskState['last_result'] ?? null,
                    'last_reason' => $taskState['last_reason'] ?? null,
                ];
            }
        }

        usort($descriptions, static function (array $left, array $right): int {
            return [$left['competitor_id'], $left['task_type']] <=> [$right['competitor_id'], $right['task_type']];
        });

        return $descriptions;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function orchestrate(
        array $config,
        string $projectDir,
        string $apiBaseUrl,
        string $apiToken,
        int $langId = 1,
        int $shopId = 1,
        bool $debug = false,
    ): array {
        $state = $this->stateStorage->load();
        $taskDescriptions = $this->describeTasks($config, $state, $langId, $shopId);
        $global = is_array($config['global'] ?? null) ? $config['global'] : [];

        if (!(bool) ($global['enabled'] ?? false)) {
            return [
                'ok' => true,
                'started' => false,
                'decision' => 'idle',
                'reason' => 'orchestrator_disabled',
                'active_tasks' => $this->countActiveTasks($taskDescriptions),
                'tasks' => $taskDescriptions,
            ];
        }

        $activeTasks = $this->countActiveTasks($taskDescriptions);
        $maxParallel = max(1, (int) ($global['max_parallel'] ?? 1));
        if ($activeTasks >= $maxParallel) {
            return [
                'ok' => true,
                'started' => false,
                'decision' => 'idle',
                'reason' => 'global_parallel_limit_reached',
                'active_tasks' => $activeTasks,
                'tasks' => $taskDescriptions,
            ];
        }

        $candidate = $this->selectNextTask($taskDescriptions);
        if ($candidate === null) {
            return [
                'ok' => true,
                'started' => false,
                'decision' => 'idle',
                'reason' => 'no_due_task',
                'active_tasks' => $activeTasks,
                'tasks' => $taskDescriptions,
            ];
        }

        $run = $this->startTask($candidate, $projectDir, $apiBaseUrl, $apiToken, $langId, $shopId, $debug, $maxParallel);
        $taskKey = (string) $candidate['key'];
        $state['tasks'][$taskKey] = [
            'last_started_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'last_result' => 'started',
            'last_pid' => $run['pid'] ?? null,
            'last_reason' => 'started_by_orchestrator',
            'last_log_file' => $run['log_file'] ?? null,
        ];
        $savedState = $this->stateStorage->save($state);

        return [
            'ok' => true,
            'started' => true,
            'decision' => 'start_task',
            'task' => $candidate,
            'run' => $run,
            'active_tasks' => $activeTasks,
            'state' => $savedState,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function launchTaskOnce(
        array $config,
        string $taskKey,
        string $projectDir,
        string $apiBaseUrl,
        string $apiToken,
        int $langId = 1,
        int $shopId = 1,
        bool $debug = false,
    ): array {
        $state = $this->stateStorage->load();
        $taskDescriptions = $this->describeTasks($config, $state, $langId, $shopId);
        $task = null;
        foreach ($taskDescriptions as $description) {
            if (($description['key'] ?? null) === $taskKey) {
                $task = $description;
                break;
            }
        }

        if (!is_array($task)) {
            throw new \RuntimeException(sprintf('Unknown orchestrator task "%s".', $taskKey));
        }

        $run = $this->startTask(
            $task,
            $projectDir,
            $apiBaseUrl,
            $apiToken,
            $langId,
            $shopId,
            $debug,
            max(1, (int) ($config['global']['max_parallel'] ?? 1)),
        );

        $state['tasks'][$taskKey] = [
            'last_started_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'last_result' => 'started',
            'last_pid' => $run['pid'] ?? null,
            'last_reason' => 'started_manually',
            'last_log_file' => $run['log_file'] ?? null,
        ];
        $savedState = $this->stateStorage->save($state);

        return [
            'ok' => true,
            'started' => true,
            'task' => $task,
            'run' => $run,
            'state' => $savedState,
        ];
    }

    private function taskKey(string $competitorKey, string $taskType): string
    {
        return $competitorKey . '.' . $taskType;
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     */
    private function countActiveTasks(array $tasks): int
    {
        return count(array_filter($tasks, static fn (array $task): bool => (bool) ($task['running'] ?? false)));
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     * @return array<string, mixed>|null
     */
    private function selectNextTask(array $tasks): ?array
    {
        $eligible = array_values(array_filter($tasks, function (array $task): bool {
            if (!(bool) ($task['enabled'] ?? false)) {
                return false;
            }
            if ((bool) ($task['running'] ?? false)) {
                return false;
            }
            if (!(bool) ($task['pending_work'] ?? false)) {
                return false;
            }

            return $this->isDue($task);
        }));

        if ($eligible === []) {
            return null;
        }

        usort($eligible, function (array $left, array $right): int {
            $leftPriority = (int) ($left['priority'] ?? 0);
            $rightPriority = (int) ($right['priority'] ?? 0);
            if ($leftPriority !== $rightPriority) {
                return $rightPriority <=> $leftPriority;
            }

            $leftAge = $this->minutesSince($left['last_started_at'] ?? null);
            $rightAge = $this->minutesSince($right['last_started_at'] ?? null);
            if ($leftAge !== $rightAge) {
                return $rightAge <=> $leftAge;
            }

            return strcmp((string) $left['key'], (string) $right['key']);
        });

        return $eligible[0] ?? null;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function isDue(array $task): bool
    {
        $intervalMinutes = max(1, (int) ($task['interval_minutes'] ?? 1));
        $lastStartedAt = $task['last_started_at'] ?? null;
        if (!is_string($lastStartedAt) || trim($lastStartedAt) === '') {
            return true;
        }

        try {
            $last = new \DateTimeImmutable($lastStartedAt);
        } catch (\Throwable) {
            return true;
        }

        $nextRunAt = $last->modify('+' . $intervalMinutes . ' minutes');

        return $nextRunAt <= new \DateTimeImmutable();
    }

    private function minutesSince(mixed $date): int
    {
        if (!is_string($date) || trim($date) === '') {
            return PHP_INT_MAX;
        }

        try {
            $last = new \DateTimeImmutable($date);
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }

        return max(0, (int) floor((time() - $last->getTimestamp()) / 60));
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function startTask(
        array $task,
        string $projectDir,
        string $apiBaseUrl,
        string $apiToken,
        int $langId,
        int $shopId,
        bool $debug,
        int $maxParallel,
    ): array {
        $competitorId = (int) $task['competitor_id'];
        $limit = (int) $task['limit'];
        $taskType = (string) $task['task_type'];

        if ($taskType === 'prices') {
            return $this->finalPriceBatchRunner->start(
                $projectDir,
                $apiBaseUrl,
                $apiToken,
                $competitorId,
                $limit,
                0,
                $debug,
                $maxParallel,
            );
        }

        $mode = $taskType === 'retry_urls'
            ? PrestashopProductBatchProvider::MODE_RETRY_URL
            : PrestashopProductBatchProvider::MODE_NEW_URL;

        return $this->batchRunner->start(
            $projectDir,
            $apiBaseUrl,
            $apiToken,
            $competitorId,
            $limit,
            0,
            $langId,
            $shopId,
            $debug,
            $maxParallel,
            $mode,
        );
    }

    private function isTaskRunning(int $competitorId, string $taskType, int $langId, int $shopId): bool
    {
        $projectRoot = dirname(__DIR__, 4);
        $lockDir = $projectRoot . '/competitive_intelligence_python/var/lock/competitive-intelligence';
        if ($taskType === 'prices') {
            return $this->isLockTaken(sprintf('%s/price-competitor-%d.lock', $lockDir, $competitorId));
        }

        $mode = $taskType === 'retry_urls'
            ? PrestashopProductBatchProvider::MODE_RETRY_URL
            : PrestashopProductBatchProvider::MODE_NEW_URL;

        return $this->isLockTaken(sprintf(
            '%s/competitor-%d-lang-%d-shop-%d-%s.lock',
            $lockDir,
            $competitorId,
            $langId,
            $shopId,
            $mode,
        ));
    }

    private function isLockTaken(string $lockPath): bool
    {
        $directory = dirname($lockPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return true;
            }

            flock($handle, LOCK_UN);

            return false;
        } finally {
            fclose($handle);
        }
    }

    private function hasPendingWork(int $competitorId, string $taskType, int $langId, int $shopId): bool
    {
        try {
            if ($taskType === 'prices') {
                return $this->finalUrlPriceBatchProvider->hasPendingWork($competitorId, 0);
            }

            $mode = $taskType === 'retry_urls'
                ? PrestashopProductBatchProvider::MODE_RETRY_URL
                : PrestashopProductBatchProvider::MODE_NEW_URL;

            return $this->productBatchProvider->hasPendingWork($competitorId, 0, $mode);
        } catch (\Throwable) {
            return false;
        }
    }
}
