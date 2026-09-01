<?php

namespace Cardlink\Checkout\Setup;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Definitions of the columns this module adds to the quote and order payment tables.
 *
 * Shared by InstallSchema (fresh installations) and by the schema patch that brings
 * existing installations up to date. InstallSchema only ever runs once, when the module
 * is first installed, so stores that installed an older release never received the
 * columns added later - most importantly cardlink_order_id, without which capture,
 * refund and void cannot address the original transaction at the gateway.
 *
 * @author Cardlink S.A.
 * @codeCoverageIgnore
 */
class CardlinkColumns
{
    /**
     * Column definitions, keyed by table name and then by column name.
     *
     * @return array
     */
    public static function getDefinitions()
    {
        return [
            'quote_payment' => [
                'cardlink_tokenize_card' => [
                    'type' => Table::TYPE_SMALLINT,
                    'nullable' => false,
                    'default' => 0,
                    'comment' => 'Cardlink - Tokenize Card',
                ],
                'cardlink_stored_token' => [
                    'type' => Table::TYPE_INTEGER,
                    'nullable' => false,
                    'default' => 0,
                    'comment' => 'Cardlink - Stored Token ID',
                ],
                'cardlink_installments' => [
                    'type' => Table::TYPE_SMALLINT,
                    'nullable' => false,
                    'default' => 0,
                    'comment' => 'Cardlink - Installments',
                ],
            ],
            'sales_order_payment' => [
                'cardlink_tokenize_card' => [
                    'type' => Table::TYPE_SMALLINT,
                    'nullable' => false,
                    'default' => 0,
                    'comment' => 'Cardlink - Tokenize Card',
                ],
                'cardlink_stored_token' => [
                    'type' => Table::TYPE_INTEGER,
                    'nullable' => false,
                    'default' => 0,
                    'comment' => 'Cardlink - Stored Token ID',
                ],
                'cardlink_installments' => [
                    'type' => Table::TYPE_SMALLINT,
                    'nullable' => false,
                    'default' => 0,
                    'comment' => 'Cardlink - Installments',
                ],
                'cardlink_pay_method' => [
                    'type' => Table::TYPE_TEXT,
                    'length' => 20,
                    'nullable' => true,
                    'comment' => 'Cardlink - Payment Method',
                ],
                'cardlink_pay_status' => [
                    'type' => Table::TYPE_TEXT,
                    'length' => 16,
                    'nullable' => true,
                    'comment' => 'Cardlink - Payment Status',
                ],
                'cardlink_tx_id' => [
                    'type' => Table::TYPE_TEXT,
                    'length' => 20,
                    'nullable' => true,
                    'comment' => 'Cardlink - Transaction ID',
                ],
                'cardlink_pay_ref' => [
                    'type' => Table::TYPE_TEXT,
                    'length' => 64,
                    'nullable' => true,
                    'comment' => 'Cardlink - Payment Reference',
                ],
                'cardlink_order_id' => [
                    'type' => Table::TYPE_TEXT,
                    'length' => 64,
                    'nullable' => true,
                    'comment' => 'Cardlink - Gateway Order ID',
                ],
            ],
        ];
    }

    /**
     * Add every column that is not present yet. Existing columns are left untouched.
     *
     * @param SchemaSetupInterface $setup
     * @return void
     */
    public static function addMissing(SchemaSetupInterface $setup)
    {
        $connection = $setup->getConnection();

        foreach (self::getDefinitions() as $tableName => $columns) {
            $table = $setup->getTable($tableName);

            if (!$connection->isTableExists($table)) {
                continue;
            }

            foreach ($columns as $columnName => $definition) {
                if ($connection->tableColumnExists($table, $columnName)) {
                    continue;
                }

                $connection->addColumn($table, $columnName, $definition);
            }
        }
    }
}
