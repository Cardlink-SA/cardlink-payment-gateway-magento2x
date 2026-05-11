<?php

namespace Cardlink\Checkout\Model\Config\Source;

use Cardlink\Checkout\Model\Config\Source\SelectBoxOptionsAbstract;

/**
 * Business partner options for wallet payment methods (Google Pay, Apple Pay).
 * Only Worldline is supported for wallet transactions.
 *
 * @author Cardlink S.A.
 */
class BusinessPartnersWallet extends SelectBoxOptionsAbstract implements \Magento\Framework\Option\ArrayInterface
{
    const BUSINESS_PARTNER_WORLDLINE = 'worldline';

    protected $options = array(
        self::BUSINESS_PARTNER_WORLDLINE => 'Worldline'
    );
}
