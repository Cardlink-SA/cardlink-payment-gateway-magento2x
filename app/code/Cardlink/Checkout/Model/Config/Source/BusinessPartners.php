<?php

namespace Cardlink\Checkout\Model\Config\Source;

use Cardlink\Checkout\Model\Config\Source\SelectBoxOptionsAbstract;

/**
 * Class used to describe the available options of a select box Adminhtml field.
 * The described select box manages the configuration of the business partner that will perform the actual financial transactions.
 * 
 * @author Cardlink S.A.
 */
class BusinessPartners extends SelectBoxOptionsAbstract implements \Magento\Framework\Option\ArrayInterface
{
    const BUSINESS_PARTNER_CARDLINK = 'cardlink';
    const BUSINESS_PARTNER_NEXI = 'nexi';
    const BUSINESS_PARTNER_WORLDLINE = 'worldline';

    protected $options = array(
        self::BUSINESS_PARTNER_CARDLINK => 'Cardlink',
        self::BUSINESS_PARTNER_NEXI  => 'Nexi',
        self::BUSINESS_PARTNER_WORLDLINE => 'Worldline'
    );
}
