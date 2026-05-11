<?php

namespace Cardlink\Checkout\Gateway\Http;

use Cardlink\Checkout\Logger\Logger;
use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\TransferInterface;

/**
 * Transfer Factory for Cardlink payment gateway.
 * Creates transfer objects for API requests.
 *
 * @author Cardlink S.A.
 */
class TransferFactory implements TransferFactoryInterface
{
    /**
     * @var TransferBuilder
     */
    private $transferBuilder;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * Constructor.
     *
     * @param TransferBuilder $transferBuilder
     * @param Logger $logger
     */
    public function __construct(
        TransferBuilder $transferBuilder,
        Logger $logger
    ) {
        $this->transferBuilder = $transferBuilder;
        $this->logger = $logger;
    }

    /**
     * Builds gateway transfer object.
     *
     * @param array $request
     * @return TransferInterface
     */
    public function create(array $request)
    {
        $this->logger->debug('TransferFactory::create called with request: ' . json_encode($request, JSON_PRETTY_PRINT));
        
        return $this->transferBuilder
            ->setBody($request)
            ->build();
    }
}
