<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\StockCostExecutionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:etl:calculate-stock-costs',
    description: 'Recalculate independent FIFO and PAMP stock costs from K_HISTO_STOCK.'
)]
final class CalculateStockCostsCommand extends Command
{
    public function __construct(
        private readonly StockCostExecutionService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('pair-limit', null, InputOption::VALUE_REQUIRED, 'Product/site pairs processed per batch until the queue is empty.', '100')
            ->addOption('discovery-limit', null, InputOption::VALUE_REQUIRED, 'Source movements discovered per batch until none remain.', '5000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pairLimit = max(1, (int) $input->getOption('pair-limit'));
        $discoveryLimit = max(1, (int) $input->getOption('discovery-limit'));
        try {
            $stats = $this->service->run($pairLimit, $discoveryLimit);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($stats['status'] === 'skipped') {
            $message = $stats['reason'] === 'competitive_intelligence_worker_is_running'
                ? 'A competitive-intelligence worker is still running; stock calculation skipped.'
                : 'Another heavy task is running; stock calculation skipped.';
            $output->writeln('<comment>' . $message . '</comment>');

            return Command::SUCCESS;
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
}
