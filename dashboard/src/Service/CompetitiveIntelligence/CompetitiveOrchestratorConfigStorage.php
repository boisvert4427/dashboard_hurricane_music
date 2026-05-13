<?php

declare(strict_types=1);

namespace App\Service\CompetitiveIntelligence;

use JsonException;
use Symfony\Component\HttpKernel\KernelInterface;

final class CompetitiveOrchestratorConfigStorage
{
    private const DEFAULT_TASKS = [
        'woodbrass.new_urls' => ['enabled' => true, 'limit' => 10, 'interval_minutes' => 720, 'priority' => 100],
        'woodbrass.retry_urls' => ['enabled' => true, 'limit' => 10, 'interval_minutes' => 720, 'priority' => 30],
        'woodbrass.prices' => ['enabled' => true, 'limit' => 5, 'interval_minutes' => 1, 'priority' => 80],
        'starsmusic.new_urls' => ['enabled' => true, 'limit' => 10, 'interval_minutes' => 720, 'priority' => 100],
        'starsmusic.retry_urls' => ['enabled' => true, 'limit' => 10, 'interval_minutes' => 720, 'priority' => 30],
        'starsmusic.prices' => ['enabled' => true, 'limit' => 5, 'interval_minutes' => 1, 'priority' => 80],
        'thomann.new_urls' => ['enabled' => true, 'limit' => 10, 'interval_minutes' => 720, 'priority' => 100],
        'thomann.retry_urls' => ['enabled' => true, 'limit' => 10, 'interval_minutes' => 720, 'priority' => 30],
        'thomann.prices' => ['enabled' => true, 'limit' => 5, 'interval_minutes' => 1, 'priority' => 80],
        'michenaud.new_urls' => ['enabled' => true, 'limit' => 10, 'interval_minutes' => 720, 'priority' => 100],
        'michenaud.retry_urls' => ['enabled' => true, 'limit' => 10, 'interval_minutes' => 720, 'priority' => 30],
        'michenaud.prices' => ['enabled' => true, 'limit' => 5, 'interval_minutes' => 1, 'priority' => 80],
    ];

    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $path = $this->getConfigPath();
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
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function save(array $config): array
    {
        $normalized = $this->normalize($config);
        $normalized['updated_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $path = $this->getConfigPath();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create orchestrator config directory "%s".', $directory));
        }

        $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode orchestrator config.');
        }

        $result = file_put_contents($path, $json . PHP_EOL, LOCK_EX);
        if ($result === false) {
            throw new \RuntimeException(sprintf('Unable to write orchestrator config to "%s".', $path));
        }

        return $normalized;
    }

    public function reset(): array
    {
        return $this->save($this->defaults());
    }

    public function getConfigPath(): string
    {
        return $this->kernel->getProjectDir() . '/var/competitive-intelligence/orchestrator.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'global' => [
                'enabled' => true,
                'max_parallel' => 1,
                'lang_id' => 1,
                'shop_id' => 1,
            ],
            'tasks' => self::DEFAULT_TASKS,
            'updated_at' => null,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalize(array $config): array
    {
        $defaults = $this->defaults();
        $global = is_array($config['global'] ?? null) ? $config['global'] : [];
        $tasks = is_array($config['tasks'] ?? null) ? $config['tasks'] : [];
        $normalizedTasks = [];
        foreach (self::DEFAULT_TASKS as $taskKey => $taskDefaults) {
            $taskConfig = is_array($tasks[$taskKey] ?? null) ? $tasks[$taskKey] : [];
            $normalizedTasks[$taskKey] = [
                'enabled' => $this->normalizeBool($taskConfig['enabled'] ?? $taskDefaults['enabled']),
                'limit' => $this->normalizeInt($taskConfig['limit'] ?? $taskDefaults['limit'], 1, 100),
                'interval_minutes' => $this->normalizeInt($taskConfig['interval_minutes'] ?? $taskDefaults['interval_minutes'], 1, 10080),
                'priority' => $this->normalizeInt($taskConfig['priority'] ?? $taskDefaults['priority'], 1, 1000),
            ];
        }

        return [
            'global' => [
                'enabled' => $this->normalizeBool($global['enabled'] ?? $defaults['global']['enabled']),
                'max_parallel' => $this->normalizeInt($global['max_parallel'] ?? $defaults['global']['max_parallel'], 1, 12),
                'lang_id' => $this->normalizeInt($global['lang_id'] ?? $defaults['global']['lang_id'], 1, 12),
                'shop_id' => $this->normalizeInt($global['shop_id'] ?? $defaults['global']['shop_id'], 1, 12),
            ],
            'tasks' => $normalizedTasks,
            'updated_at' => isset($config['updated_at']) && is_string($config['updated_at']) ? trim($config['updated_at']) : null,
        ];
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function normalizeInt(mixed $value, int $min, int $max): int
    {
        $intValue = (int) $value;
        if ($intValue < $min) {
            return $min;
        }

        if ($intValue > $max) {
            return $max;
        }

        return $intValue;
    }
}
