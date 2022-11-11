<?php

namespace Cardlink\Checkout\Model\Config\Source;

use Cardlink\Checkout\Model\Config\Source\SelectBoxOptionsAbstract;

/**
 * Class used to describe the available options of a select box Adminhtml field.
 * The described select box manages the configuration of the working transaction environment.
 * 
 * @author Cardlink S.A.
 */
class TransactionEnvironments extends SelectBoxOptionsAbstract implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * All transactions happen in the real world through actual financial institutes.
     */
    const PRODUCTION_ENVIRONMENT = 'production';
    /**
     * All transaction are performed in a development/sandbox environment for testing purposes.
     */
    const SANDBOX_ENVIRONMENT = 'sandbox';

    protected $options = array(
        self::PRODUCTION_ENVIRONMENT => 'Production',
        self::SANDBOX_ENVIRONMENT  => 'Sandbox'
    );
}
