<?php

namespace Cardlink\Checkout\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;


/**
 * Database schema installation script.
 *
 * Kept for Magento versions without schema patch support. On every other version the
 * same columns are added by Setup\Patch\Schema\AddCardlinkPaymentColumns, which also
 * covers stores that were installed before a column was introduced. Both entry points
 * share the definitions in CardlinkColumns and skip columns that already exist, so
 * running one after the other is safe.
 *
 * @author Cardlink S.A.
 * @codeCoverageIgnore
 */
class InstallSchema implements InstallSchemaInterface
{
    /**
     * {@inheritdoc}
     */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        CardlinkColumns::addMissing($setup);

        $setup->endSetup();
    }
}
