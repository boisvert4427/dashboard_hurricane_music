<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ProductDimensionSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:etl:sync-product-dimension',
    description: 'Synchronize current K_ARTICLE metadata and refresh historical invoice lines.'
)]
final class SyncProductDimensionCommand extends Command
{
    public function __construct(private readonly ProductDimensionSyncService $syncService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stats = $this->syncService->sync();
        $output->writeln(sprintf(
            '<info>Done: %d products synchronized, %d historical lines updated.</info>',
            $stats['products'],
            $stats['historical_lines_updated']
        ));

        return Command::SUCCESS;
    }
}
