<?php

namespace Cardlink\Checkout\Controller\Payment;

use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Service\XIDUtil;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;

/**
 * POST /api/create-xid — calculates the 3D-Secure XID from VPOS transaction fields.
 *
 * Called by the frontend JS threeDSHandler after the /sale endpoint returns
 * status "processing". The XID is then used in the MPI form submission.
 *
 * Request JSON:
 *   { "trId": "…", "trExtId": "…", "trMpiCounts": "…" }
 *
 * Response: plain-text Base64-encoded XID string (28 chars).
 *
 * Magento route: cardlink_checkout/payment/googlepaycreatexid
 *
 * @author Cardlink S.A.
 */
class GooglePayCreateXid extends Action implements CsrfAwareActionInterface
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var RawFactory
     */
    private $rawFactory;

    public function __construct(
        Context $context,
        Logger $logger,
        RawFactory $rawFactory
    ) {
        $this->logger = $logger;
        $this->rawFactory = $rawFactory;
        parent::__construct($context);
    }

    /** @inheritDoc */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /** @inheritDoc */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/plain; charset=UTF-8');

        try {
            $body = $this->getRequest()->getContent();
            $data = json_decode($body, true);

            $trId = $data['trId'] ?? '';
            $trExtId = $data['trExtId'] ?? '';
            $trMpiCounts = $data['trMpiCounts'] ?? '';

            if (empty($trId)) {
                $result->setHttpResponseCode(400);
                $result->setContents('Missing trId');
                return $result;
            }

            $xid = XIDUtil::calculateXID($trId, $trExtId, $trMpiCounts);

            $this->logger->debug(
                "GooglePayCreateXid: trId={$trId}, trExtId={$trExtId}, "
                . "trMpiCounts={$trMpiCounts} → XID={$xid}"
            );

            $result->setContents($xid);

        } catch (\Exception $e) {
            $this->logger->error('GooglePayCreateXid error: ' . $e->getMessage());
            $result->setHttpResponseCode(500);
            $result->setContents('Error calculating XID');
        }

        return $result;
    }
}
