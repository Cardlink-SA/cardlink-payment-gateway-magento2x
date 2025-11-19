<?php
declare(strict_types=1);

namespace Cardlink\Checkout\Console\Command;

use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BackfillQuoteId extends Command
{
    protected static $defaultName = 'cardlink:order-grid:backfill-quote-id';
    protected static $defaultDescription = 'Backfill sales_order_grid.quote_id from sales_order.quote_id';

    private ResourceConnection $resource;

    public function __construct(ResourceConnection $resource, ?string $name = null)
    {
        $this->resource = $resource;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        // Keeping this for older symfony versions; harmless if $defaultName is present.
        $this->setName(self::$defaultName);
        $this->setDescription(self::$defaultDescription);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $conn = $this->resource->getConnection();
        $sog  = $conn->getTableName('sales_order_grid');
        $so   = $conn->getTableName('sales_order');

        $sql = "
            UPDATE {$sog} AS sog
            INNER JOIN {$so} AS so ON so.entity_id = sog.entity_id
            SET sog.quote_id = so.quote_id
            WHERE sog.quote_id IS NULL
        ";

        $affected = (int)$conn->exec($sql);
        $output->writeln("<info>Backfilled rows: {$affected}</info>");
        return Command::SUCCESS;
    }
}
