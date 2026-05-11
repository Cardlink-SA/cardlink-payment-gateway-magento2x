<?php

namespace Cardlink\Checkout\Model\Config\Source;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Config\Source\Order\Status;

/**
 * Order status source model that includes statuses from both "new" and "pending_payment" states.
 * 
 * This extends Magento's default order status source model to allow merchants to select
 * statuses belonging to either the "new" or "pending_payment" order states for new orders
 * created by the Cardlink payment gateway.
 * 
 * @author Cardlink S.A.
 */
class OrderStatus extends Status
{
    /**
     * @var string[]
     */
    protected $_stateStatuses = [
        Order::STATE_NEW,
        Order::STATE_PENDING_PAYMENT,
    ];

    /**
     * Get options as array
     *
     * @return array
     */
    public function toOptionArray()
    {
        $statuses = $this->_stateStatuses
            ? $this->_orderConfig->getStateStatuses($this->_stateStatuses)
            : $this->_orderConfig->getStatuses();

        $options = [['value' => '', 'label' => __('-- Please Select --')]];
        foreach ($statuses as $code => $label) {
            $options[] = ['value' => $code, 'label' => $label];
        }
        return $options;
    }
}
