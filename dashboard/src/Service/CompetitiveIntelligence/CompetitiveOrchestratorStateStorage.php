<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use JsonException;
use Symfony\Component\HttpKernel\KernelInterface;

final class CompetitiveOrchestratorStateStorage
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $path = $this->getStatePath();
        if (!is_file($path)) {
            return $this->defaults();
        }

        try {
            $content = file_get_contents($path);
            if ($content === false || trim($content) === '') {
                return $this->defaults();
            }

            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->defaults();
        }

        if (!is_array($decoded)) {
            return $this->defaults();
        }

        return $this->normalize($decoded);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function save(array $state): array
    {
        $normalized = $this->normalize($state);
        $normalized['updated_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $path = $this->getStatePath();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create orchestrator state directory "%s".', $directory));
        }

        $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode orchestrator state.');
        }

        if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to write orchestrator state to "%s".', $path));
        }

        return $normalized;
    }

    public function getStatePath(): string
    {
        return $this->kernel->getProjectDir() . '/var/competitive-intelligence/orchestrator.state.json';
    }

    public function putTaskCooldown(string $taskKey, \DateTimeImmutable $until, string $reason): array
    {
        $state = $this->load();
        $current = is_array($state['tasks'][$taskKey] ?? null) ? $state['tasks'][$taskKey] : [];
        $state['tasks'][$taskKey] = array_merge($current, [
            'last_result' => 'cooldown',
            'last_reason' => $reason,
            'cooldown_until' => $until->format(\DateTimeInterface::ATOM),
            'cooldown_reason' => $reason,
        ]);

        return $this->save($state);
    }

    public function recordTaskTelemetry(string $taskKey, string $transport, ?int $httpStatus): array
    {
        $state = $this->load();
        $current = is_array($state['tasks'][$taskKey] ?? null) ? $state['tasks'][$taskKey] : [];
        $state['tasks'][$taskKey] = array_merge($current, [
            'last_transport' => $transport,
            'last_http_status' => $httpStatus,
            'last_feedback_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        return $this->save($state);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'tasks' => [],
            'updated_at' => null,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function normalize(array $state): array
    {
        $tasks = is_array($state['tasks'] ?? null) ? $state['tasks'] : [];
        $normalizedTasks = [];
        foreach ($tasks as $key => $taskState) {
            if (!is_string($key) || !is_array($taskState)) {
                continue;
            }

            $normalizedTasks[$key] = [
                'last_started_at' => isset($taskState['last_started_at']) && is_string($taskState['last_started_at']) ? trim($taskState['last_started_at']) : null,
                'last_result' => isset($taskState['last_result']) && is_string($taskState['last_result']) ? trim($taskState['last_result']) : null,
                'last_pid' => isset($taskState['last_pid']) ? (int) $taskState['last_pid'] : null,
                'last_reason' => isset($taskState['last_reason']) && is_string($taskState['last_reason']) ? trim($taskState['last_reason']) : null,
                'last_log_file' => isset($taskState['last_log_file']) && is_string($taskState['last_log_file']) ? trim($taskState['last_log_file']) : null,
                'cooldown_until' => isset($taskState['cooldown_until']) && is_string($taskState['cooldown_until']) ? trim($taskState['cooldown_until']) : null,
                'cooldown_reason' => isset($taskState['cooldown_reason']) && is_string($taskState['cooldown_reason']) ? trim($taskState['cooldown_reason']) : null,
                'last_transport' => isset($taskState['last_transport']) && is_string($taskState['last_transport']) ? trim($taskState['last_transport']) : null,
                'last_http_status' => isset($taskState['last_http_status']) ? (int) $taskState['last_http_status'] : null,
                'last_feedback_at' => isset($taskState['last_feedback_at']) && is_string($taskState['last_feedback_at']) ? trim($taskState['last_feedback_at']) : null,
            ];
        }

        return [
            'tasks' => $normalizedTasks,
            'updated_at' => isset($state['updated_at']) && is_string($state['updated_at']) ? trim($state['updated_at']) : null,
        ];
    }
}
