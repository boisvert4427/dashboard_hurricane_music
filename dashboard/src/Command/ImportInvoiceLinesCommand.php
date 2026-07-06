<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\InvoiceLineImportService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:etl:import-invoice-lines',
    description: 'Import new K_LI_FAC rows into reporting_invoice_line_fact.'
)]
final class ImportInvoiceLinesCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.reporting_connection')]
        private readonly Connection $reportingConnection,
        #[Autowire(service: 'doctrine.dbal.prestashop_connection')]
        private readonly Connection $prestashopConnection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of imported rows for a test run.');
        $this->addOption('since', null, InputOption::VALUE_REQUIRED, 'Import rows from this date (YYYY-MM-DD) and refresh matching reporting rows.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $this->parseLimit($input->getOption('limit'));
        $since = $this->parseSinceDate($input->getOption('since'));

        try {
            $output->writeln('Starting import from K_LI_FAC...');
            $importService = new InvoiceLineImportService($this->reportingConnection, $this->prestashopConnection);
            $stats = $importService->run(500, $limit, $since);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('Done. %d rows imported into reporting_invoice_line_fact.', $stats['inserted']));

        return Command::SUCCESS;
    }

    private function parseLimit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $limit = (int) $value;

        return $limit > 0 ? $limit : null;
    }

    private function parseSinceDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);

        return $date !== false ? $date->format('Y-m-d') : null;
    }
}
