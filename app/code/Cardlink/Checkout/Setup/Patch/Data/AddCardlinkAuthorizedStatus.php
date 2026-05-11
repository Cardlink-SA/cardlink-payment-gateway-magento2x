<?php

namespace Cardlink\Checkout\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;

/**
 * Data patch to create the custom 'cardlink_authorized' order status
 * for preauthorized payments awaiting capture.
 */
class AddCardlinkAuthorizedStatus implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * Custom status code
     */
    const STATUS_CODE = 'cardlink_authorized';

    /**
     * Custom status label
     */
    const STATUS_LABEL = 'Authorized - Pending Capture';

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * {@inheritdoc}
     */
    public function apply()
    {
        $this->moduleDataSetup->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $statusTable = $this->moduleDataSetup->getTable('sales_order_status');
        $statusStateTable = $this->moduleDataSetup->getTable('sales_order_status_state');

        // Check if status already exists
        $select = $connection->select()
            ->from($statusTable, ['status'])
            ->where('status = ?', self::STATUS_CODE);
        
        $existingStatus = $connection->fetchOne($select);

        if (!$existingStatus) {
            // Insert the new status
            $connection->insert(
                $statusTable,
                [
                    'status' => self::STATUS_CODE,
                    'label' => self::STATUS_LABEL
                ]
            );
        }

        // Check if status-state assignment already exists
        $selectState = $connection->select()
            ->from($statusStateTable, ['status'])
            ->where('status = ?', self::STATUS_CODE)
            ->where('state = ?', Order::STATE_PROCESSING);
        
        $existingStateAssignment = $connection->fetchOne($selectState);

        if (!$existingStateAssignment) {
            // Assign the status to the 'processing' state
            $connection->insert(
                $statusStateTable,
                [
                    'status' => self::STATUS_CODE,
                    'state' => Order::STATE_PROCESSING,
                    'is_default' => 0,
                    'visible_on_front' => 1
                ]
            );
        }

        $this->moduleDataSetup->endSetup();

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
