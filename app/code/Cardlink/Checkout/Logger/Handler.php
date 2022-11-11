<?php

namespace Cardlink\Checkout\Logger;

use Monolog\Logger;

/**
 * Handler class for the custom Logger facility.
 * 
 * @author Cardlink S.A.
 */
class Handler extends \Magento\Framework\Logger\Handler\Base
{
    /**
     * Logging level
     * @var int
     */
    protected $loggerType = Logger::DEBUG;

    /**
     * Path of the custom log file.
     * @var string
     */
    protected $fileName = '/var/log/cardlink_checkout.log';
}
