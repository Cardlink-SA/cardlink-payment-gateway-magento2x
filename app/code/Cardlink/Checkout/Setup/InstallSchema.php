<?php

namespace Cardlink\Checkout\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;


/**
 * Database schema installation script.
 * 
 * @author Cardlink S.A.
 * @codeCoverageIgnore
 */
class InstallSchema implements InstallSchemaInterface
{
    /**
     * {@inheritdoc}
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup = $setup;

        $setup->run("ALTER TABLE `{$setup->getTable('quote_payment')}` ADD `cardlink_tokenize_card` SMALLINT( 1 ) NOT NULL;");
        $setup->run("ALTER TABLE `{$setup->getTable('quote_payment')}` ADD `cardlink_stored_token` INT( 10 ) NOT NULL;");
        $setup->run("ALTER TABLE `{$setup->getTable('quote_payment')}` ADD `cardlink_installments` SMALLINT( 5 ) NOT NULL;");

        $setup->run("ALTER TABLE `{$setup->getTable('sales_order_payment')}` ADD `cardlink_tokenize_card` SMALLINT( 1 ) NOT NULL;");
        $setup->run("ALTER TABLE `{$setup->getTable('sales_order_payment')}` ADD `cardlink_stored_token` INT( 10 ) NOT NULL;");
        $setup->run("ALTER TABLE `{$setup->getTable('sales_order_payment')}` ADD `cardlink_installments` SMALLINT( 5 ) NOT NULL;");

        $setup->run("ALTER TABLE `{$setup->getTable('sales_order_payment')}` ADD `cardlink_pay_method` VARCHAR( 20 );");
        $setup->run("ALTER TABLE `{$setup->getTable('sales_order_payment')}` ADD `cardlink_pay_status` VARCHAR( 16 );");
        $setup->run("ALTER TABLE `{$setup->getTable('sales_order_payment')}` ADD `cardlink_tx_id` VARCHAR( 20 );");
        $setup->run("ALTER TABLE `{$setup->getTable('sales_order_payment')}` ADD `cardlink_pay_ref` VARCHAR( 64 );");

        $setup->endSetup();
    }
}
