<?php

namespace Cardlink\Checkout\Controller\Payment;

use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Helper\Payment;
use Cardlink\Checkout\Lib\CardlinkXmlApi;
use Cardlink\Checkout\Lib\CardlinkXmlResponse;
use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Service\GPayTransactionInfoManager;
use Cardlink\Checkout\Service\XIDUtil;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Quote\Api\CartRepositoryInterface;

/**
 * POST /api/wallet/sale — processes Apple Pay and Google Pay wallet payments.
 *
 * Receives JSON from googlepaydirect.js (endWalletSessionGetResults):
 * {
 *   "version":          "2.1",
 *   "merchantId":       "9000010866",
 *   "orderId":          "1234567890",
 *   "amount":           "55.55",
 *   "currency":         "EUR",
 *   "googlePayResponse":"{...tokenised card data...}"
 * }
 *
 * Builds a <SaleRequest> XML with <WalletInfo><Attribute> elements, signs it
 * (v2.1 HMAC-SHA256 or v4.1 SHA256withRSA), POSTs to the VPOS xmlpayvpos
 * endpoint, and returns one of:
 *
 *   {"status":"success"}
 *   {"status":"processing", "orderId":"…", "orderAmount":"…", "txId":"…",
 *    "cardEncData":"…", "trExtId":"…", "trMpiCounts":"…"}
 *   {"status":"fail", "error":"…"}
 *
 * Magento route: cardlink_checkout/payment/googlepaywallet  (+ /sale appended
 * by googlepaydirect.js — Magento treats the extra segment as a parameter).
 *
 * @author Cardlink S.A.
 */
class GooglePayWallet extends Action implements CsrfAwareActionInterface
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
     * @var Payment
     */
    private $paymentHelper;

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var Session
     */
    private $checkoutSession;

    /**
     * @var CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @var CacheInterface
     */
    private $cache;

    public function __construct(
        Context $context,
        Logger $logger,
        Data $dataHelper,
        Payment $paymentHelper,
        JsonFactory $jsonFactory,
        Session $checkoutSession,
        CartRepositoryInterface $quoteRepository,
        CacheInterface $cache
    ) {
        $this->logger = $logger;
        $this->dataHelper = $dataHelper;
        $this->paymentHelper = $paymentHelper;
        $this->jsonFactory = $jsonFactory;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->cache = $cache;
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

    /**
     * Main entry point — processes the wallet sale request.
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            // ── 1. Parse incoming JSON ──────────────────────────────────
            $body = $this->getRequest()->getContent();
            $requestData = json_decode($body, true);

            if ($this->dataHelper->googlePayLogDebugInfoEnabled()) {
                $this->logger->debug('GooglePayWallet /sale request body: ' . $body);
            }

            if (!$requestData || empty($requestData['orderId'])) {
                $result->setData(['status' => 'fail', 'error' => 'Invalid request data']);
                return $result;
            }

            // ── 2. Resolve MID configuration ────────────────────────────
            $mid = $this->dataHelper->getGooglePayMerchantId();
            $sharedSecret = $this->dataHelper->getGooglePaySharedSecret();

            // Detect wallet type from request payload
            $payMethod = 'googlepay'; // default
            if (!empty($requestData['applePayResponse'])) {
                $payMethod = 'applepay';
            }

            // ── 3. Execute wallet sale via CardlinkXmlApi ────────────
            $walletPaymentData = $requestData['googlePayResponse']
                ?? $requestData['applePayResponse']
                ?? '';

            $api = new CardlinkXmlApi(
                $mid,
                $sharedSecret,
                $this->dataHelper->getGooglePayBusinessPartner(),
                $this->dataHelper->getGooglePayTransactionEnvironment()
            );

            // Enable debug logging when configured
            if ($this->dataHelper->googlePayLogDebugInfoEnabled()) {
                $api->setDebug(true, function ($msg) {
                    $this->logger->debug($msg);
                });
            }

            // Derive order description from orderId: "{quoteId}x{suffix}" → "QUOTE {quoteId}"
            $orderDescQuoteId = explode('x', $requestData['orderId'])[1] ?? $requestData['orderId'];
            $orderDesc = 'QUOTE ' . $orderDescQuoteId;

            $apiResponse = $api->walletSale(
                $requestData['orderId'],
                $requestData['amount'],
                $requestData['currency'] ?? 'EUR',
                $walletPaymentData,
                $payMethod,
                $orderDesc
            );

            // ── 4. Convert CardlinkXmlResponse to JSON ─────────────────
            $jsonResponse = $this->buildJsonResponse($apiResponse, $requestData, $payMethod);
            $result->setData($jsonResponse);

        } catch (\Exception $e) {
            $this->logger->error('GooglePayWallet error: ' . $e->getMessage());
            $result->setData([
                'status' => 'fail',
                'error' => 'Internal error processing wallet payment',
            ]);
        }

        return $result;
    }

    // =====================================================================
    //  RESPONSE CONVERSION
    // =====================================================================

    /**
     * Convert a CardlinkXmlResponse from the VPOS into a JSON-friendly array.
     *
     * Possible outcomes:
     *   CAPTURED / AUTHORIZED → {"status":"success"}
     *   PROCESSING           → {"status":"processing", …3DS fields…}
     *   ERROR / REFUSED      → {"status":"fail","error":"…"}
     */
    private function buildJsonResponse(
        CardlinkXmlResponse $apiResponse,
        array $requestData,
        string $payMethod
    ): array {
        $status = strtoupper($apiResponse->getStatus() ?? '');
        $txId = $apiResponse->getTransactionId() ?? '';

        if ($this->dataHelper->googlePayLogDebugInfoEnabled()) {
            $this->logger->debug(
                "GooglePayWallet VPOS status={$status}, TxId={$txId}, data="
                . json_encode($apiResponse->getData(), JSON_UNESCAPED_UNICODE)
            );
        }

        switch ($status) {

            // ── SUCCESS ──────────────────────────────────────────
            case 'AUTHORIZED':
            case 'CAPTURED':
                return ['status' => 'success', 'txId' => $txId];

            // ── 3DS REQUIRED ─────────────────────────────────────
            case 'PROCESSING':
                // 3DS fields come back as <Attribute name="..."> elements,
                // which the parser stores under data['attributes'][name].
                $attrs = $apiResponse->get('attributes', []);
                $trExtId = $attrs['trExtId'] ?? '';
                $trMpiCounts = $attrs['trMpiCounts'] ?? '';
                $cardEncData = $attrs['cardEncData'] ?? '';
                // The card type from VPOS (e.g. "visa", "mastercard") — used as
                // PayMethod in the second SaleRequest after 3DS completes.
                $cardType = $attrs['cardType'] ?? 'visa';
                $orderId = $requestData['orderId'];
                $orderAmount = $requestData['amount'];
                $mid = $this->dataHelper->getGooglePayMerchantId();

                // Calculate the XID for 3DS
                $xid = XIDUtil::calculateXID($txId, $trExtId, $trMpiCounts);

                // Get the current quote ID so we can reload it after cross-domain
                // MPI redirect (session cookie is lost due to SameSite policy).
                $quoteId = (int) $this->checkoutSession->getQuoteId();

                // Store transaction info for the 3DS callback
                GPayTransactionInfoManager::store($xid, [
                    'trId' => $txId,
                    'paymentTotal' => $orderAmount,
                    'currency' => $requestData['currency'] ?? 'EUR',
                    'payMethod' => $cardType,
                    'orderId' => $orderId,
                    'mid' => $mid,
                    'trExtId' => $trExtId,
                    'trMpiCounts' => $trMpiCounts,
                    'cardEncData' => $cardEncData,
                    'quoteId' => $quoteId,
                ]);

                // Persist to session (same-origin AJAX) AND cache (cross-domain MPI redirect)
                GPayTransactionInfoManager::persistToSession($this->checkoutSession);
                GPayTransactionInfoManager::persistToCache($this->cache);

                return [
                    'status' => 'processing',
                    'orderId' => $orderId,
                    'orderAmount' => $orderAmount,
                    'txId' => $txId,
                    'cardEncData' => $cardEncData,
                    'trExtId' => $trExtId,
                    'trMpiCounts' => $trMpiCounts,
                ];

            // ── FAILURE ──────────────────────────────────────────
            default:
                $errCode = $apiResponse->get('ErrorCode', '');
                $errDesc = $apiResponse->get('Description')
                    ?? $apiResponse->getError()
                    ?? 'Payment declined';
                return [
                    'status' => 'fail',
                    'error' => $errDesc,
                    'errorCode' => $errCode,
                ];
        }
    }
}
