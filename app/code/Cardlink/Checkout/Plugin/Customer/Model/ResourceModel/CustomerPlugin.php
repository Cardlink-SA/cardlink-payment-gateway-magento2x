<?php

namespace Cardlink\Checkout\Plugin\Customer\Model\ResourceModel;

use Magento\Framework\Registry;

class CustomerPlugin
{
    private $registry;

    public function __construct(Registry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Skip customer validation during Cardlink order submission
     */
    public function aroundSave(\Magento\Customer\Model\ResourceModel\Customer $subject, callable $proceed, $customer)
    {
        // Skip validation if flag is set
        if ($this->registry->registry('skip_customer_validation')) {
            // Temporarily disable validators
            $customer->setData('_resource_skip_validator', true);
        }

        return $proceed($customer);
    }
}
