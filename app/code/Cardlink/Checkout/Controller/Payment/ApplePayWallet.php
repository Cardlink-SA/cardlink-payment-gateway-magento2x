<?php

namespace Cardlink\Checkout\Controller\Payment;

use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Helper\Payment;
use Cardlink\Checkout\Lib\CardlinkXmlApi;
use Cardlink\Checkout\Lib\CardlinkXmlResponse;
use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Model\ApiFields;
use Cardlink\Checkout\Service\GPayTransactionInfoManager;
use Cardlink\Checkout\Service\XIDUtil;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteManagement;

/**
 * POST /api/wallet/sale — processes Apple Pay wallet payments.
 *
 * Receives JSON from applepaydirect.js:
 * {
 *   "version":          "2.1",
 *   "merchantId":       "0101119349",
 *   "orderId":          "1234567890",
 *   "amount":           "55.55",
 *   "currency":         "EUR",
 *   "applePayResponse": "{...encrypted payment data...}"
 * }
 *
 * Builds a <SaleRequest> XML with <WalletInfo><Attribute name="applePaymentData"> element,
 * signs it (v2.1 HMAC-SHA256), POSTs to the VPOS xmlpayvpos endpoint.
 *
 * Magento route: cardlink_checkout/payment/applepaywallet
 *
 * @author Cardlink S.A.
 */
class ApplePayWallet extends Action implements CsrfAwareActionInterface
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

    /**
     * @var QuoteManagement
     */
    private $quoteManagement;

    public function __construct(
        Context $context,
        Logger $logger,
        Data $dataHelper,
        Payment $paymentHelper,
        JsonFactory $jsonFactory,
        Session $checkoutSession,
        CartRepositoryInterface $quoteRepository,
        CacheInterface $cache,
        QuoteManagement $quoteManagement
    ) {
        $this->logger = $logger;
        $this->dataHelper = $dataHelper;
        $this->paymentHelper = $paymentHelper;
        $this->jsonFactory = $jsonFactory;
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->cache = $cache;
        $this->quoteManagement = $quoteManagement;
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
     * Main entry point — routes to start-session or sale based on the URL path.
     *
     * The Cardlink applepaydirect.js script calls:
     *   merchantURL + "/start-session"  → merchant session validation (WalletRequest)
     *   merchantURL + "/sale"           → payment authorization (SaleRequest)
     *
     * Both resolve to this controller in Magento routing; the trailing
     * segment is used to determine which operation to perform.
     */
    public function execute()
    {
        try {
            $pathInfo = trim($this->getRequest()->getPathInfo(), '/');
            $startSessionSuffix = 'start-session';
            $isStartSession = strlen($pathInfo) >= strlen($startSessionSuffix)
                && substr($pathInfo, -strlen($startSessionSuffix)) === $startSessionSuffix;

            if ($isStartSession) {
                return $this->executeStartSession();
            }

            return $this->executeSale();
        } catch (\Throwable $e) {
            $result = $this->jsonFactory->create();
            $errorId = 'APW-EXEC-' . (string) round(microtime(true) * 1000);

            try {
                $this->logger->error(
                    'ApplePayWallet /execute fatal [' . $errorId . ']: ' . $e->getMessage()
                        . ' in ' . $e->getFile() . ':' . $e->getLine()
                );
            } catch (\Throwable $ignored) {
            }

            $result->setData([
                'error' => 'ApplePayWallet execute fatal',
                'errorCode' => 'SE',
                'errorId' => $errorId,
                'message' => $e->getMessage(),
            ]);
            $result->setHttpResponseCode(500);
            return $result;
        }
    }

    /**
     * Handle the /start-session endpoint.
     *
     * Receives JSON from applepaydirect.js when the user clicks the Apple Pay button:
     * {
     *   "version":       "2.1",
     *   "validationUrl": "https://apple-pay-gateway-cert.apple.com/paymentservices/startSession",
     *   "merchantId":    "0101119349",
     *   "amount":        "55.55",
     *   "currency":      "EUR"
     * }
     *
     * Builds a WalletRequest XML, POSTs it to the VPOS, and returns the
     * walletData JSON required by the Apple Pay JS to continue.
     */
    private function executeStartSession()
    {
        $result = $this->jsonFactory->create();

        try {
            $body = $this->getRequest()->getContent();
            $requestData = json_decode($body, true);

            if ($this->dataHelper->applePayLogDebugInfoEnabled()) {
                $this->logger->debug('ApplePayWallet /start-session request body: ' . $body);
            }

            if (!$requestData || empty($requestData['validationUrl'])) {
                $result->setData(['error' => 'Invalid start-session request data']);
                $result->setHttpResponseCode(400);
                return $result;
            }

            $mid = $this->dataHelper->getApplePayMerchantId();
            $sharedSecret = $this->dataHelper->getApplePaySharedSecret();

            if (empty($mid) || empty($sharedSecret)) {
                $result->setData([
                    'error' => 'Apple Pay configuration is incomplete (missing Merchant ID or Shared Secret).',
                    'errorCode' => 'CONFIG'
                ]);
                $result->setHttpResponseCode(500);
                return $result;
            }

            if (!empty($requestData['merchantId']) && (string)$requestData['merchantId'] !== (string)$mid) {
                $result->setData([
                    'error' => 'Apple Pay merchantId mismatch between frontend and backend configuration.',
                    'errorCode' => 'MID_MISMATCH',
                    'frontendMerchantId' => (string)$requestData['merchantId'],
                    'backendMerchantId' => (string)$mid,
                ]);
                $result->setHttpResponseCode(400);
                return $result;
            }

            $requestVersion = trim((string)($requestData['version'] ?? '2.1'));
            if ($requestVersion === '2.0') {
                $requestVersion = '2.1';
            }
            if ($requestVersion !== '2.1') {
                $result->setData([
                    'error' => 'Unsupported XML API version for wallet session.',
                    'errorCode' => 'UNSUPPORTED_XML_VERSION',
                    'requestedVersion' => $requestVersion,
                    'supportedVersion' => '2.1',
                ]);
                $result->setHttpResponseCode(400);
                return $result;
            }

            // Generate an order ID for the wallet session
            $orderId = 'O' . (int)(microtime(true) * 1000);
            $amount = $this->normalizeAmount($requestData['amount'] ?? null);
            if ($amount === null) {
                $result->setData([
                    'error' => 'Invalid amount for wallet session request.',
                    'errorCode' => 'INVALID_AMOUNT',
                    'amount' => $requestData['amount'] ?? null,
                ]);
                $result->setHttpResponseCode(400);
                return $result;
            }

            $currency = strtoupper(trim((string)($requestData['currency'] ?? 'EUR')));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                $result->setData([
                    'error' => 'Invalid currency code for wallet session request.',
                    'errorCode' => 'INVALID_CURRENCY',
                    'currency' => $requestData['currency'] ?? null,
                ]);
                $result->setHttpResponseCode(400);
                return $result;
            }

            $validationUrl = $requestData['validationUrl'];

            if (!$this->isValidAppleValidationUrl($validationUrl)) {
                $result->setData([
                    'error' => 'Invalid Apple validation URL received from browser session.',
                    'errorCode' => 'INVALID_VALIDATION_URL',
                    'validationUrl' => $validationUrl,
                ]);
                $result->setHttpResponseCode(400);
                return $result;
            }

            $configuredEnvironment = (string)$this->dataHelper->getApplePayTransactionEnvironment();
            $urlEnvironment = $this->inferEnvironmentFromValidationUrl($validationUrl);
            $effectiveEnvironment = $configuredEnvironment;
            $envUrlMismatch = !$this->isValidationUrlCompatibleWithEnvironment($validationUrl, $configuredEnvironment);
            $effectiveValidationUrl = $this->normalizeValidationUrlForEnvironment($validationUrl, $configuredEnvironment);
            $validationUrlRewritten = ($effectiveValidationUrl !== $validationUrl);

            $api = new CardlinkXmlApi(
                $mid,
                $sharedSecret,
                $this->dataHelper->getApplePayBusinessPartner(),
                $effectiveEnvironment
            );
            $businessPartner = (string)$this->dataHelper->getApplePayBusinessPartner();
            $vposUrl = $api->getApiUrl();

            if ($this->dataHelper->applePayLogDebugInfoEnabled()) {
                $api->setDebug(true, function ($msg) {
                    $this->logger->debug($msg);
                });
            }

            if ($envUrlMismatch && $this->dataHelper->applePayLogDebugInfoEnabled()) {
                $this->logger->debug(
                    'ApplePayWallet /start-session: validation URL/environment mismatch. '
                        . 'validationUrl=' . $validationUrl
                        . ', configuredEnvironment=' . $configuredEnvironment
                        . ', effectiveEnvironment=' . $effectiveEnvironment
                        . ', urlEnvironment=' . ($urlEnvironment ?: '(unknown)')
                        . ', validationUrlRewritten=' . ($validationUrlRewritten ? 'true' : 'false')
                        . ', effectiveValidationUrl=' . $effectiveValidationUrl
                );
            }

            $walletResponse = $api->walletSession(
                $orderId,
                $amount,
                $currency,
                $effectiveValidationUrl,
                'Apple Pay Direct'
            );

            if ($this->dataHelper->applePayLogDebugInfoEnabled()) {
                $lastRequest = $api->getLastRequest();
                $lastResponse = $api->getLastResponse();

                $this->logger->debug(
                    'ApplePayWallet /start-session outbound XML: '
                        . ($lastRequest['xml'] ?? '(missing)')
                );

                $this->logger->debug(
                    'ApplePayWallet /start-session inbound raw response: '
                        . ($lastResponse['body'] ?? '(missing)')
                );

                $this->logger->debug(
                    'ApplePayWallet /start-session transport meta: '
                        . json_encode([
                            'url' => $lastRequest['url'] ?? null,
                            'http_code' => $lastResponse['http_code'] ?? null,
                            'curl_errno' => $lastResponse['curl_errno'] ?? null,
                            'curl_error' => $lastResponse['curl_error'] ?? null,
                        ], JSON_UNESCAPED_UNICODE)
                );
            }

            // Always log full response data to help diagnose format issues
            if ($this->dataHelper->applePayLogDebugInfoEnabled()) {
                $this->logger->debug(
                    'ApplePayWallet /start-session VPOS response data: '
                        . json_encode($walletResponse->getData(), JSON_UNESCAPED_UNICODE)
                );
            }

            $walletData = $walletResponse->getData();

            if ($this->dataHelper->applePayLogDebugInfoEnabled()) {
                $this->logger->debug('ApplePayWallet /start-session walletData: ' . (is_string($walletData) ? $walletData : json_encode($walletData)));
            }

            if (empty($walletData)) {
                $errorCode = $walletResponse->get('ErrorCode', '') ?: $walletResponse->get('errorCode', '');
                $errorDesc = $walletResponse->get('Description', '')
                    ?: $walletResponse->get('description', '')
                    ?: $walletResponse->getError()
                    ?: 'Failed to obtain wallet session data';
                $gatewayErrorId = $this->extractGatewayErrorId($errorDesc);

                $this->logger->error(
                    'ApplePayWallet /start-session: empty walletData from VPOS. '
                        . 'errorCode=' . $errorCode . ', error=' . $errorDesc
                );

                $result->setData([
                    'error' => $errorDesc,
                    'errorCode' => $errorCode,
                    'gatewayErrorId' => $gatewayErrorId,
                    'requestDiagnostics' => [
                        'version' => $requestVersion,
                        'amount' => $amount,
                        'currency' => $currency,
                        'validationUrlHost' => (string)(parse_url($validationUrl, PHP_URL_HOST) ?? ''),
                        'effectiveValidationUrlHost' => (string)(parse_url($effectiveValidationUrl, PHP_URL_HOST) ?? ''),
                        'validationUrlRewritten' => $validationUrlRewritten,
                        'businessPartner' => $businessPartner,
                        'vposUrl' => $vposUrl,
                        'configuredEnvironment' => $configuredEnvironment,
                        'effectiveEnvironment' => $effectiveEnvironment,
                        'urlEnvironment' => $urlEnvironment,
                        'envUrlMismatch' => $envUrlMismatch,
                    ],
                ]);
                $result->setHttpResponseCode(500);
                return $result;
            }

            // If walletData is a JSON string (the merchant session), try to decode it
            // so that applepaydirect.js receives the session object directly.
            $result->setData($walletData);

        } catch (\Throwable $e) {
            $errorId = 'APW-' . (string) round(microtime(true) * 1000);
            $this->logger->error(
                'ApplePayWallet /start-session fatal [' . $errorId . ']: ' . $e->getMessage()
                    . ' in ' . $e->getFile() . ':' . $e->getLine()
            );

            $errorMessage = 'Internal error processing wallet session';
            if ($this->dataHelper->applePayLogDebugInfoEnabled()) {
                $errorMessage .= ': ' . $e->getMessage();
            }

            $result->setData([
                'error' => $errorMessage,
                'errorCode' => 'SE',
                'errorId' => $errorId,
            ]);
            $result->setHttpResponseCode(500);
        }

        return $result;
    }

    private function isValidAppleValidationUrl(string $validationUrl): bool
    {
        if ($validationUrl === '') {
            return false;
        }

        $parts = parse_url($validationUrl);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        return strpos($host, 'apple.com') !== false;
    }

    private function isValidationUrlCompatibleWithEnvironment(string $validationUrl, string $transactionEnvironment): bool
    {
        $host = strtolower((string)(parse_url($validationUrl, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }

        $isSandboxAppleHost = strpos($host, 'apple-pay-gateway-cert.apple.com') !== false;

        if ($transactionEnvironment === 'production') {
            return !$isSandboxAppleHost;
        }

        if ($transactionEnvironment === 'sandbox') {
            return $isSandboxAppleHost;
        }

        return true;
    }

    private function inferEnvironmentFromValidationUrl(string $validationUrl): string
    {
        $host = strtolower((string)(parse_url($validationUrl, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return '';
        }

        if (strpos($host, 'apple-pay-gateway-cert.apple.com') !== false) {
            return 'sandbox';
        }

        if (strpos($host, 'apple-pay-gateway.apple.com') !== false) {
            return 'production';
        }

        return '';
    }

    private function normalizeValidationUrlForEnvironment(string $validationUrl, string $transactionEnvironment): string
    {
        $host = (string)(parse_url($validationUrl, PHP_URL_HOST) ?? '');
        if ($host === '') {
            return $validationUrl;
        }

        if ($transactionEnvironment === 'sandbox') {
            return str_replace('apple-pay-gateway.apple.com', 'apple-pay-gateway-cert.apple.com', $validationUrl);
        }

        if ($transactionEnvironment === 'production') {
            return str_replace('apple-pay-gateway-cert.apple.com', 'apple-pay-gateway.apple.com', $validationUrl);
        }

        return $validationUrl;
    }

    private function normalizeAmount($rawAmount): ?string
    {
        if ($rawAmount === null) {
            return null;
        }

        $amount = trim((string)$rawAmount);
        if ($amount === '') {
            return null;
        }

        $amount = str_replace(',', '.', $amount);
        if (!preg_match('/^\d+(\.\d+)?$/', $amount)) {
            return null;
        }

        $amountFloat = (float)$amount;
        if ($amountFloat <= 0.0) {
            return null;
        }

        return number_format($amountFloat, 2, '.', '');
    }

    private function extractGatewayErrorId(string $message): string
    {
        if (preg_match('/Err+or\s+id:\s*(\d+)/i', $message, $matches)) {
            return $matches[1] ?? '';
        }

        return '';
    }

    /**
     * Handle the /sale endpoint — processes the Apple Pay wallet sale request.
     */
    private function executeSale()
    {
        $result = $this->jsonFactory->create();

        try {
            // ── 1. Parse incoming JSON ──────────────────────────────────
            $body = $this->getRequest()->getContent();
            $requestData = json_decode($body, true);

            if ($this->dataHelper->applePayLogDebugInfoEnabled()) {
                $this->logger->debug('ApplePayWallet /sale request body: ' . $body);
            }

            if (!$requestData || empty($requestData['orderId'])) {
                $result->setData(['status' => 'fail', 'error' => 'Invalid request data']);
                return $result;
            }

            // ── 2. Resolve MID configuration ────────────────────────────
            $mid = $this->dataHelper->getApplePayMerchantId();
            $sharedSecret = $this->dataHelper->getApplePaySharedSecret();
            $businessPartner = (string)$this->dataHelper->getApplePayBusinessPartner();
            $transactionEnvironment = (string)$this->dataHelper->getApplePayTransactionEnvironment();

            $payMethod = 'applepay';

            // ── 3. Execute wallet sale via CardlinkXmlApi ────────────
            $walletPaymentData = $requestData['applePayResponse'] ?? '';
            if ($walletPaymentData === '') {
                $result->setData([
                    'status' => 'fail',
                    'error' => 'Missing applePayResponse in sale request.',
                    'errorCode' => 'INVALID_REQUEST',
                ]);
                return $result;
            }

            $amount = $this->normalizeAmount($requestData['amount'] ?? null);
            if ($amount === null) {
                $result->setData([
                    'status' => 'fail',
                    'error' => 'Invalid amount in sale request.',
                    'errorCode' => 'INVALID_AMOUNT',
                ]);
                return $result;
            }

            $currency = strtoupper(trim((string)($requestData['currency'] ?? 'EUR')));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                $result->setData([
                    'status' => 'fail',
                    'error' => 'Invalid currency in sale request.',
                    'errorCode' => 'INVALID_CURRENCY',
                ]);
                return $result;
            }

            $api = new CardlinkXmlApi(
                $mid,
                $sharedSecret,
                $businessPartner,
                $transactionEnvironment
            );
            $vposUrl = $api->getApiUrl();

            // Enable debug logging when configured
            if ($this->dataHelper->applePayLogDebugInfoEnabled()) {
                $api->setDebug(true, function ($msg) {
                    $this->logger->debug($msg);
                });
            }

            // Derive order description from orderId
            $orderDescQuoteId = explode('x', $requestData['orderId'])[1] ?? $requestData['orderId'];
            $orderDesc = 'QUOTE ' . $orderDescQuoteId;

            $apiResponse = $api->walletSale(
                $requestData['orderId'],
                $amount,
                $currency,
                $walletPaymentData,
                $payMethod,
                $orderDesc
            );

            if ($this->dataHelper->applePayLogDebugInfoEnabled()) {
                $lastRequest = $api->getLastRequest();
                $lastResponse = $api->getLastResponse();

                $this->logger->debug(
                    'ApplePayWallet /sale outbound XML: '
                        . ($lastRequest['xml'] ?? '(missing)')
                );

                $this->logger->debug(
                    'ApplePayWallet /sale inbound raw response: '
                        . ($lastResponse['body'] ?? '(missing)')
                );

                $this->logger->debug(
                    'ApplePayWallet /sale transport meta: '
                        . json_encode([
                            'url' => $lastRequest['url'] ?? null,
                            'http_code' => $lastResponse['http_code'] ?? null,
                            'curl_errno' => $lastResponse['curl_errno'] ?? null,
                            'curl_error' => $lastResponse['curl_error'] ?? null,
                        ], JSON_UNESCAPED_UNICODE)
                );
            }

            // ── 4. Convert CardlinkXmlResponse to JSON ─────────────────
            $jsonResponse = $this->buildJsonResponse($apiResponse, $requestData, $payMethod);

            // ── 5. On success, create Magento order and mark payment ────
            if (($jsonResponse['status'] ?? '') === 'success') {
                $orderCreated = $this->submitOrderOnSuccess(
                    $apiResponse,
                    $requestData,
                    $payMethod,
                    $mid
                );
                if (!$orderCreated) {
                    $jsonResponse = [
                        'status' => 'fail',
                        'error'  => 'Payment was successful but order creation failed. Please contact support.',
                        'errorCode' => 'ORDER_CREATION_FAILED',
                    ];
                }
            }

            if (($jsonResponse['status'] ?? '') === 'fail') {
                $jsonResponse['requestDiagnostics'] = [
                    'orderId' => (string)$requestData['orderId'],
                    'amount' => $amount,
                    'currency' => $currency,
                    'businessPartner' => $businessPartner,
                    'transactionEnvironment' => $transactionEnvironment,
                    'vposUrl' => $vposUrl,
                    'payMethod' => $payMethod,
                ];
            }
            $result->setData($jsonResponse);
        } catch (\Throwable $e) {
            $errorId = 'APW-' . (string) round(microtime(true) * 1000);
            $this->logger->error(
                'ApplePayWallet /sale fatal [' . $errorId . ']: ' . $e->getMessage()
                    . ' in ' . $e->getFile() . ':' . $e->getLine()
            );

            $errorMessage = 'Internal error processing wallet payment';
            if ($this->dataHelper->applePayLogDebugInfoEnabled()) {
                $errorMessage .= ': ' . $e->getMessage();
            }

            $result->setData([
                'status' => 'fail',
                'error' => $errorMessage,
                'errorCode' => 'SE',
                'errorId' => $errorId,
            ]);
        }

        return $result;
    }

    /**
     * Convert a CardlinkXmlResponse from the VPOS into a JSON-friendly array.
     */
    private function buildJsonResponse(
        CardlinkXmlResponse $apiResponse,
        array $requestData,
        string $payMethod
    ): array {
        $status = strtoupper($apiResponse->getStatus() ?? '');
        $txId = $apiResponse->getTransactionId() ?? '';

        if ($this->dataHelper->applePayLogDebugInfoEnabled()) {
            $this->logger->debug(
                "ApplePayWallet VPOS status={$status}, TxId={$txId}, data="
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
                $attrs = $apiResponse->get('attributes', []);
                $trExtId = $attrs['trExtId'] ?? '';
                $trMpiCounts = $attrs['trMpiCounts'] ?? '';
                $cardEncData = $attrs['cardEncData'] ?? '';
                $cardType = $attrs['cardType'] ?? 'visa';
                $orderId = $requestData['orderId'];
                $orderAmount = $requestData['amount'];
                $mid = $this->dataHelper->getApplePayMerchantId();

                $xid = XIDUtil::calculateXID($txId, $trExtId, $trMpiCounts);

                $quoteId = (int) $this->checkoutSession->getQuoteId();

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
                $errCode = $apiResponse->get('ErrorCode', '') ?: $apiResponse->get('errorCode', '');
                $errDesc = $apiResponse->get('Description')
                    ?? $apiResponse->get('description')
                    ?? $apiResponse->getError()
                    ?? 'Payment declined';
                $gatewayErrorId = $this->extractGatewayErrorId((string)$errDesc);
                return [
                    'status' => 'fail',
                    'error' => $errDesc,
                    'errorCode' => $errCode,
                    'gatewayErrorId' => $gatewayErrorId,
                ];
        }
    }

    // =====================================================================
    //  ORDER CREATION ON SUCCESSFUL WALLET PAYMENT
    // =====================================================================

    /**
     * Create a Magento order from the quote after a successful VPOS payment.
     *
     * Follows the same pattern as ApplePay3ds::createOrderFromQuote — loads the
     * quote, submits it via QuoteManagement, marks the payment, and sets
     * checkout session variables so the success page works.
     *
     * @param CardlinkXmlResponse $apiResponse
     * @param array               $requestData
     * @param string              $payMethod
     * @param string              $mid
     * @return bool True if the order was created successfully
     */
    private function submitOrderOnSuccess(
        CardlinkXmlResponse $apiResponse,
        array $requestData,
        string $payMethod,
        string $mid
    ): bool {
        try {
            // Extract quote ID from the orderId format "QUOTEx{quoteId}x{suffix}"
            $parts = explode('x', $requestData['orderId']);
            $quoteId = isset($parts[1]) ? (int) $parts[1] : 0;

            if ($quoteId <= 0) {
                $this->logger->error(
                    'ApplePayWallet: Cannot extract quote ID from orderId=' . $requestData['orderId']
                );
                return false;
            }

            // Load the quote
            $quote = $this->checkoutSession->getQuote();

            if (!$quote || !$quote->getId() || !$quote->getIsActive()) {
                $this->logger->info(
                    "ApplePayWallet: Session quote unavailable, loading quote by ID={$quoteId}"
                );
                try {
                    $quote = $this->quoteRepository->get($quoteId);
                } catch (\Exception $e) {
                    $this->logger->error(
                        "ApplePayWallet: Failed to load quote ID={$quoteId}: " . $e->getMessage()
                    );
                    return false;
                }
            }

            if (!$quote || !$quote->getId()) {
                $this->logger->error('ApplePayWallet: No active quote found for order creation');
                return false;
            }

            // Ensure guest settings are properly configured before order creation
            $this->ensureQuoteIsReady($quote);

            // Reserve a fresh order increment ID
            $quote->setReservedOrderId(null);
            $quote->reserveOrderId();

            $this->logger->info(
                "ApplePayWallet: Creating order from quote {$quote->getId()}, "
                . "reserved ID: {$quote->getReservedOrderId()}"
            );

            // Submit the quote to create the order
            $order = $this->quoteManagement->submit($quote);

            if (!$order || !$order->getId()) {
                $this->logger->error('ApplePayWallet: QuoteManagement::submit returned no order');
                return false;
            }

            // Deactivate the quote
            $quote->setIsActive(false)->save();

            // Build response data for markSuccessfulPayment
            $txId = $apiResponse->getTransactionId() ?? '';
            $vposStatus = strtoupper($apiResponse->getStatus() ?? '');
            $responseData = [
                ApiFields::Status             => $vposStatus,
                ApiFields::TransactionId      => $txId,
                ApiFields::PaymentMethod      => strtoupper($payMethod),
                ApiFields::PaymentReferenceId => $apiResponse->getPaymentRef() ?? '',
                ApiFields::OrderId            => $requestData['orderId'],
                ApiFields::OrderAmount        => $requestData['amount'],
                ApiFields::PaymentTotal       => $apiResponse->getPaymentTotal() ?? $requestData['amount'],
                ApiFields::Currency           => $requestData['currency'] ?? 'EUR',
                ApiFields::MerchantId         => $mid,
                ApiFields::Message            => $apiResponse->get('Description', ''),
            ];

            $this->paymentHelper->markSuccessfulPayment($order, $responseData);

            // Set checkout session for the success page
            $this->checkoutSession->setLastQuoteId($order->getQuoteId())
                ->setLastSuccessQuoteId($order->getQuoteId())
                ->setLastOrderId($order->getId())
                ->setLastRealOrderId($order->getIncrementId())
                ->setLastOrderStatus($order->getStatus());

            $this->logger->info(
                "ApplePayWallet: Order {$order->getIncrementId()} created successfully "
                . "from quote {$quote->getId()}, txId={$txId}"
            );

            return true;

        } catch (\Exception $e) {
            $this->logger->error(
                'ApplePayWallet: Order creation failed: ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine()
            );
            return false;
        }
    }

    /**
     * Ensure quote is ready for order submission by setting required guest fields.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return void
     */
    private function ensureQuoteIsReady(\Magento\Quote\Model\Quote $quote): void
    {
        // Resolve customer email (existing quote value, then billing, then shipping).
        $email = $quote->getCustomerEmail();
        if (!$email) {
            $billingAddress = $quote->getBillingAddress();
            if ($billingAddress && $billingAddress->getEmail()) {
                $email = $billingAddress->getEmail();
            } else {
                $shippingAddress = $quote->getShippingAddress();
                if ($shippingAddress && $shippingAddress->getEmail()) {
                    $email = $shippingAddress->getEmail();
                }
            }
        }

        // For a quote without a logged-in customer, force a clean guest checkout.
        // Without the is-guest flag and NOT_LOGGED_IN group, QuoteManagement::submit()
        // runs CustomerManagement::populateCustomerInfo() and tries to create a Customer
        // account from the quote's empty customer data object, failing with
        // "The customer email is missing."
        if (!$quote->getCustomerId()) {
            $quote->setCheckoutMethod('guest');
            $quote->setCustomerIsGuest(true);
            $quote->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);

            if ($email) {
                $quote->setCustomerEmail($email);
                $customer = $quote->getCustomer();
                if ($customer) {
                    $customer->setEmail($email);
                    $customer->setGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
                    $quote->setCustomer($customer);
                }
                if ($this->dataHelper->logDebugInfoEnabled()) {
                    $this->logger->debug("ApplePayWallet: Set guest customer email to {$email} for quote {$quote->getId()}");
                }
            } else {
                $this->logger->warning("ApplePayWallet: Unable to determine customer email for quote {$quote->getId()}");
            }
        }
        $quote->save();
    }
}
