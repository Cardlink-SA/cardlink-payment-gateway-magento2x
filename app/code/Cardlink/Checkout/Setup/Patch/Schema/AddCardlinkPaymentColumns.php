<?php

namespace Cardlink\Checkout\Setup\Patch\Schema;

use Cardlink\Checkout\Setup\CardlinkColumns;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Adds the Cardlink payment columns to stores that were installed before those columns
 * were part of InstallSchema.
 *
 * InstallSchema runs only on the very first installation of the module, and the module
 * ships no upgrade script, so every column added to InstallSchema after a store went
 * live was silently missing on that store. Magento drops unknown attributes when saving
 * a payment, so cardlink_order_id was accepted in memory and lost on write: capture,
 * refund and void then fell back to the Magento increment ID and the gateway answered
 * "Original transaction is not found" (error code O2).
 *
 * @author Cardlink S.A.
 * @codeCoverageIgnore
 */
class AddCardlinkPaymentColumns implements SchemaPatchInterface
{
    /**
     * @var SchemaSetupInterface
     */
    private $schemaSetup;

    /**
     * @param SchemaSetupInterface $schemaSetup
     */
    public function __construct(SchemaSetupInterface $schemaSetup)
    {
        $this->schemaSetup = $schemaSetup;
    }

    /**
     * {@inheritdoc}
     */
    public function apply()
    {
        $this->schemaSetup->startSetup();

        CardlinkColumns::addMissing($this->schemaSetup);

        $this->schemaSetup->endSetup();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }
}
