<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\StockCostCacheService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:etl:calculate-stock-costs',
    description: 'Recalculate independent FIFO and PAMP stock costs from K_HISTO_STOCK.'
)]
final class CalculateStockCostsCommand extends Command
{
    public function __construct(
        private readonly StockCostCacheService $service,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('pair-limit', null, InputOption::VALUE_REQUIRED, 'Maximum product/site pairs recalculated per run.', '100')
            ->addOption('discovery-limit', null, InputOption::VALUE_REQUIRED, 'Maximum new source movements queued per run.', '5000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pairLimit = max(1, (int) $input->getOption('pair-limit'));
        $discoveryLimit = max(1, (int) $input->getOption('discovery-limit'));
        $lockDirectory = $this->projectDir . '/var/lock';
        if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0775, true) && !is_dir($lockDirectory)) {
            $output->writeln('<error>Unable to create the ETL lock directory.</error>');
            return Command::FAILURE;
        }

        $lockHandle = fopen($lockDirectory . '/heavy-processing.lock', 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            $output->writeln('<comment>Another heavy task is running; stock calculation skipped.</comment>');
            return Command::SUCCESS;
        }

        if ($this->isCompetitiveTaskRunning()) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            $output->writeln('<comment>A competitive-intelligence worker is still running; stock calculation skipped.</comment>');
            return Command::SUCCESS;
        }

        try {
            $stats = $this->service->run($pairLimit, $discoveryLimit);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        $output->writeln(sprintf(
            'Done. %d source movements discovered, %d pairs queued, %d pairs/%d movements recalculated, %d failures.',
            $stats['discovered_movements'],
            $stats['queued_pairs'],
            $stats['processed_pairs'],
            $stats['processed_movements'],
            $stats['failed_pairs'],
        ));

        return $stats['failed_pairs'] > 0 ? Command::FAILURE : Command::SUCCESS;
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
