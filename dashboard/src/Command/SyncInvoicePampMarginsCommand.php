<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\InvoicePampMarginService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:etl:sync-invoice-pamp-margins',
    description: 'Update dashboard invoice margins from calculated PAMP stock costs.'
)]
final class SyncInvoicePampMarginsCommand extends Command
{
    public function __construct(private readonly InvoicePampMarginService $service)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $updatedRows = $this->service->syncAll();
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Done. %d invoice rows linked to a calculated PAMP.', $updatedRows));

        return Command::SUCCESS;
    }
}
