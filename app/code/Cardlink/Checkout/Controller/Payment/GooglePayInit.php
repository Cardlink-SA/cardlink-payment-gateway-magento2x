<?php

namespace Cardlink\Checkout\Controller\Payment;

use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Logger\Logger;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Controller to provide Google Pay script initialization info (MID, query string for script URL).
 *
 * @author Cardlink S.A.
 */
class GooglePayInit extends Action implements CsrfAwareActionInterface
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Data
     */
    private $dataHelper;

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    public function __construct(
        Context $context,
        Logger $logger,
        Data $dataHelper,
        JsonFactory $jsonFactory
    ) {
        $this->logger = $logger;
        $this->dataHelper = $dataHelper;
        $this->jsonFactory = $jsonFactory;
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $mid = $this->dataHelper->getGooglePayMerchantId();
            $sharedSecret = $this->dataHelper->getGooglePaySharedSecret();
            $vposVersion = '2';

            // Build the query string for the Google Pay Direct script
            // The script URL needs a hash parameter for authentication
            $athensTime = new \DateTime('now', new \DateTimeZone('Europe/Athens'));
            $timestamp = $athensTime->format('YmdHi');
            $hashData =  $vposVersion . $mid . $timestamp . $sharedSecret;
            // Use raw binary output for the hash before base64 encoding
            $hash = base64_encode(hash('sha256', $hashData, true)); 

            $queryString = '?' . http_build_query([
                'version' => $vposVersion,
                'mid' => $mid,
                'date' => $timestamp,
                'digest' => $hash,
            ]);

            $result->setData([
                'success' => true,
                'mid' => $mid,
                'queryString' => $queryString,
                'vposVersion' => $vposVersion
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Google Pay init error: ' . $e->getMessage());
            $result->setData([
                'success' => false,
                'message' => 'Failed to initialize Google Pay'
            ]);
            $result->setHttpResponseCode(500);
        }

        return $result;
    }
}
