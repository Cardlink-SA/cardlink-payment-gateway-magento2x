<?php

namespace Cardlink\Checkout\Controller\Payment;

use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Helper\Payment;
use Cardlink\Checkout\Lib\CardlinkXmlApi;
use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Model\ApiFields;
use Cardlink\Checkout\Service\GPayTransactionInfoManager;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * 3DS callback controller for Apple Pay transactions.
 *
 * The MPI (Merchant Plug-In) redirects back here after 3D-Secure authentication:
 *   okUrl  → .../applepay3ds/result/success/
 *   failUrl → .../applepay3ds/result/failure/
 *
 * The callback arrives as a standard form POST (application/x-www-form-urlencoded)
 * with fields: version, merchantID, xid, mdStatus, mdErrorMsg, eci, cavv,
 * veresEnrolledStatus, paresTxStatus, protocol, etc.
 *
 * Flow on success (okUrl):
 *   1. Parse MPI response form fields (3DS authentication results)
 *   2. Look up stored transaction info by XID (from the initial SaleRequest that returned PROCESSING)
 *   3. Send a SECOND SaleRequest to VPOS XML API with the 3DS data
 *      (PaymentInfo.preparedTxId, ThreeDSecure element)
 *   4. If CAPTURED → create a Magento order and redirect to success page
 *
 * Flow on failure (failUrl):
 *   1. Log the failure details
 *   2. Redirect to checkout/cart with an error message
 *
 * @author Cardlink S.A.
 */
class ApplePay3ds extends Action implements CsrfAwareActionInterface
{
    /** @var Logger */
    private $logger;

    /** @var Data */
    private $dataHelper;

    /** @var Payment */
    private $paymentHelper;

    /** @var Session */
    private $checkoutSession;

    /** @var QuoteFactory */
    private $quoteFactory;

    /** @var QuoteManagement */
    private $quoteManagement;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var CacheInterface */
    private $cache;

    /** @var CartRepositoryInterface */
    private $quoteRepository;

    /** @var UrlInterface */
    private $urlBuilder;

    public function __construct(
        Context $context,
        Logger $logger,
        Data $dataHelper,
        Payment $paymentHelper,
        Session $checkoutSession,
        QuoteFactory $quoteFactory,
        QuoteManagement $quoteManagement,
        OrderRepositoryInterface $orderRepository,
        CacheInterface $cache,
        CartRepositoryInterface $quoteRepository,
        UrlInterface $urlBuilder
    ) {
        $this->logger = $logger;
        $this->dataHelper = $dataHelper;
        $this->paymentHelper = $paymentHelper;
        $this->checkoutSession = $checkoutSession;
        $this->quoteFactory = $quoteFactory;
        $this->quoteManagement = $quoteManagement;
        $this->orderRepository = $orderRepository;
        $this->cache = $cache;
        $this->quoteRepository = $quoteRepository;
        $this->urlBuilder = $urlBuilder;
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

    /**
     * Handle the 3DS MPI callback.
     *
     * @return ResultInterface
     */
    public function execute()
    {
        $xid = '';
        try {
            // MPI redirects back via form POST (application/x-www-form-urlencoded)
            $params = $this->getRequest()->getParams();

            if ($this->dataHelper->applePayLogDebugInfoEnabled()) {
                $this->logger->debug(
                    'ApplePay 3DS callback: ' . json_encode($params, JSON_UNESCAPED_UNICODE)
                );
            }

            // Determine success/failure from route param and mdStatus
            $routeResult = $this->getRequest()->getParam('result');
            $mdStatus = $params['mdStatus'] ?? '';
            $mdErrorMsg = $params['mdErrorMsg'] ?? '';

            // mdStatus 0 = no auth (not enrolled), 1 = full auth, 2 = couldn't auth,
            // 3 = denied, 4 = attempted. 5-9 = various failures.
            // Accept 0, 1, 2, 4 as "proceed" — let VPOS make the final decision.
            $acceptable = ['0', '1', '2', '4'];

            if ($routeResult === 'failure' || !in_array($mdStatus, $acceptable, true)) {
                $this->logger->warning(
                    "ApplePay 3DS failed: mdStatus={$mdStatus}, msg={$mdErrorMsg}"
                );

                // Clean up stale transaction data so the next attempt starts fresh
                $failedXid = $params['xid'] ?? '';
                if ($failedXid !== '') {
                    GPayTransactionInfoManager::remove($failedXid);
                    GPayTransactionInfoManager::persistToSession($this->checkoutSession);
                    GPayTransactionInfoManager::removeFromCache($this->cache, $failedXid);
                }

                $this->messageManager->addErrorMessage(
                    __('3D-Secure authentication failed. Please try again or choose a different payment method.')
                );
                return $this->handleRedirect('checkout/cart', ['applepay_err' => 'E1_3ds_auth_failed']);
            }

            // ── Restore transaction info from session or cache ────────
            GPayTransactionInfoManager::restoreFromSession($this->checkoutSession);

            $xid = $params['xid'] ?? '';
            $txInfo = GPayTransactionInfoManager::get($xid);

            // Fallback: try server-side cache (survives cross-domain redirect)
            if (!$txInfo) {
                $this->logger->info("ApplePay 3DS: Session lookup failed for XID={$xid}, trying cache");
                $txInfo = GPayTransactionInfoManager::restoreFromCache($this->cache, $xid);
            }

            if (!$txInfo) {
                $this->logger->error("ApplePay 3DS: No stored transaction info for XID={$xid} (session + cache)");
                $this->messageManager->addErrorMessage(
                    __('Payment session expired. Please try again.')
                );
                return $this->handleRedirect('checkout/cart', ['applepay_err' => 'E2_no_txinfo']);
            }

            $txId = $txInfo['trId'] ?? '';
            $orderId = $txInfo['orderId'] ?? '';
            $paymentTotal = $txInfo['paymentTotal'] ?? '';
            $currency = $txInfo['currency'] ?? 'EUR';
            $payMethod = $txInfo['payMethod'] ?? 'visa';

            if ($this->dataHelper->applePayLogDebugInfoEnabled()) {
                $eci = $params['eci'] ?? '';
                $this->logger->debug(
                    "ApplePay 3DS: xid={$xid}, eci={$eci}, "
                    . "mdStatus={$mdStatus}, txId={$txId}, orderId={$orderId}"
                );
            }

            // ── Build the 3DS data from MPI response fields ─────────────
            $protocol = $params['protocol']
                ?? $params['tds2MessageVersion']
                ?? '';
            if ($protocol === '' && isset($params['version'])) {
                $mpiVer = $params['version'];
                if (version_compare($mpiVer, '2.0', '>=')) {
                    $protocol = '3DS2.2.0';
                }
            }

            $authenticationStatus = $params['txstatus']
                ?? $params['paresTxStatus']
                ?? '';

            $threeDSData = [
                'enrollmentStatus'     => $params['veresEnrolledStatus'] ?? '',
                'authenticationStatus' => $authenticationStatus,
                'cavv'                 => $params['cavv'] ?? '',
                'xid'                  => $xid,
                'eci'                  => $params['eci'] ?? '',
                'protocol'             => $protocol,
            ];

            // ── Send a SECOND SaleRequest with the 3DS results ──────────
            $api = new CardlinkXmlApi(
                $this->dataHelper->getApplePayMerchantId(),
                $this->dataHelper->getApplePaySharedSecret(),
                $this->dataHelper->getApplePayBusinessPartner(),
                $this->dataHelper->getApplePayTransactionEnvironment()
            );

            if ($this->dataHelper->applePayLogDebugInfoEnabled()) {
                $api->setDebug(true, function ($msg) {
                    $this->logger->debug($msg);
                });
            }

            $orderDescQuoteId = explode('x', $orderId)[1] ?? $orderId;
            $orderDesc = 'QUOTE ' . $orderDescQuoteId;

            $this->logger->info(
                "ApplePay 3DS: Sending second SaleRequest with 3DS data for orderId={$orderId}, "
                . "preparedTxId={$txId}, payMethod={$payMethod}"
            );

            $saleResponse = $api->walletSaleWith3DS(
                $orderId,
                $paymentTotal,
                $currency,
                $txId,
                $payMethod,
                $threeDSData,
                $orderDesc
            );

            if ($this->dataHelper->applePayLogDebugInfoEnabled()) {
                $this->logger->debug(
                    'ApplePay 3DS: Second SaleResponse: '
                    . json_encode($saleResponse->getData(), JSON_UNESCAPED_UNICODE)
                );
            }

            $vposStatus = strtoupper($saleResponse->getStatus() ?? '');

            $this->logger->info(
                "ApplePay 3DS: Second SaleResponse status={$vposStatus}, "
                . "isSuccess=" . ($saleResponse->isSuccess() ? 'YES' : 'NO') . ", "
                . "data=" . json_encode($saleResponse->getData(), JSON_UNESCAPED_UNICODE)
            );

            if (in_array($vposStatus, ['CAPTURED', 'AUTHORIZED'], true)) {
                // ── Payment succeeded — create order ────────────────────
                $this->logger->info(
                    "ApplePay 3DS: Payment {$vposStatus} for orderId={$orderId}, txId={$txId}"
                );

                $order = $this->createOrderFromQuote($txInfo['quoteId'] ?? null);

                if ($order) {
                    $vposTxId = $saleResponse->getTransactionId() ?? $txId;
                    $vposPayRef = $saleResponse->getPaymentRef() ?? '';
                    $vposPayTotal = $saleResponse->getPaymentTotal() ?? $paymentTotal;
                    $vposAmount = $saleResponse->getOrderAmount() ?? $paymentTotal;
                    $vposCurrency = $saleResponse->getCurrency() ?? $currency;
                    $vposDesc = $saleResponse->get('Description', '');
                    $vposMid = $this->dataHelper->getApplePayMerchantId();

                    $responseData = [
                        ApiFields::Status             => $vposStatus,
                        ApiFields::TransactionId      => $vposTxId,
                        ApiFields::PaymentMethod      => strtoupper($payMethod),
                        ApiFields::PaymentReferenceId => $vposPayRef,
                        ApiFields::OrderId            => $orderId,
                        ApiFields::OrderAmount        => $vposAmount,
                        ApiFields::PaymentTotal       => $vposPayTotal,
                        ApiFields::Currency           => $vposCurrency,
                        ApiFields::MerchantId         => $vposMid,
                        ApiFields::Message            => $vposDesc,
                    ];

                    $this->paymentHelper->markSuccessfulPayment($order, $responseData);

                    $this->checkoutSession->setLastQuoteId($order->getQuoteId())
                        ->setLastSuccessQuoteId($order->getQuoteId())
                        ->setLastOrderId($order->getId())
                        ->setLastRealOrderId($order->getIncrementId())
                        ->setLastOrderStatus($order->getStatus());

                    $this->logger->info(
                        "ApplePay 3DS: Order {$order->getIncrementId()} created successfully"
                    );

                    // Clean up session and cache data
                    GPayTransactionInfoManager::remove($xid);
                    GPayTransactionInfoManager::persistToSession($this->checkoutSession);
                    GPayTransactionInfoManager::removeFromCache($this->cache, $xid);

                    return $this->handleRedirect('checkout/onepage/success');
                }

                // Quote submission failed
                $this->logger->error('ApplePay 3DS: Failed to create order from quote');
                GPayTransactionInfoManager::remove($xid);
                GPayTransactionInfoManager::persistToSession($this->checkoutSession);
                GPayTransactionInfoManager::removeFromCache($this->cache, $xid);
                $this->messageManager->addErrorMessage(
                    __('Payment could not be completed. Please try again or choose a different payment method.')
                );
                return $this->handleRedirect('checkout/cart', ['applepay_err' => 'E4_order_creation_failed']);
            } else {
                $errorCode = $saleResponse->get('ErrorCode', '');
                $errorDesc = $saleResponse->get('Description', '')
                    ?: ($saleResponse->getError() ?? '');
                $this->logger->error(
                    "ApplePay 3DS: Second SaleResponse status={$vposStatus} (not captured/authorized) "
                    . "for orderId={$orderId}, errorCode={$errorCode}, errorDesc={$errorDesc}, "
                    . "fullData=" . json_encode($saleResponse->getData(), JSON_UNESCAPED_UNICODE)
                );
            }

            // ── Payment failed or status not final ──────────────────────
            GPayTransactionInfoManager::remove($xid);
            GPayTransactionInfoManager::persistToSession($this->checkoutSession);
            GPayTransactionInfoManager::removeFromCache($this->cache, $xid);

            $this->messageManager->addErrorMessage(
                __('Payment could not be completed. Please try again or choose a different payment method.')
            );

            $errSuffix = strtolower($vposStatus);
            if (!empty($errorCode)) {
                $errSuffix .= '_' . strtolower($errorCode);
            }
            return $this->handleRedirect('checkout/cart', ['applepay_err' => 'E3_vpos_' . $errSuffix]);

        } catch (\Exception $e) {
            $this->logger->error('ApplePay 3DS error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());

            $failedXid = $xid !== '' ? $xid : '';
            if ($failedXid !== '') {
                try {
                    GPayTransactionInfoManager::remove($failedXid);
                    GPayTransactionInfoManager::persistToSession($this->checkoutSession);
                    GPayTransactionInfoManager::removeFromCache($this->cache, $failedXid);
                } catch (\Exception $cleanupEx) {
                    $this->logger->warning('ApplePay 3DS: Cleanup failed: ' . $cleanupEx->getMessage());
                }
            }

            $this->messageManager->addErrorMessage(
                __('An error occurred during payment processing. Please try again.')
            );
            return $this->handleRedirect('checkout/cart', ['applepay_err' => 'E5_exception']);
        }
    }

    /**
     * Redirect to the given path, breaking out of the iframe if 3DS was rendered inside one.
     *
     * When the "checkout_in_iframe" setting is enabled the MPI callback arrives inside an
     * iframe. A normal redirect would only navigate the iframe. Instead, we return a minimal
     * HTML page containing a form with target="_top" that auto-submits, causing the parent
     * window to navigate to the final destination (success page or cart with error).
     *
     * When the setting is disabled this behaves as a standard redirect.
     *
     * @param string $path   Magento route path (e.g. 'checkout/onepage/success')
     * @param array  $params Route parameters
     * @return ResultInterface
     */
    private function handleRedirect(string $path, array $params = []): ResultInterface
    {
        if (!$this->dataHelper->doApplePayCheckoutInIframe()) {
            return $this->resultRedirectFactory->create()->setPath($path, $params);
        }

        $redirectUrl = $this->urlBuilder->getUrl($path, array_merge($params, ['_secure' => true]));

        $html = '<!DOCTYPE html><html><head><title>Redirecting…</title></head><body>'
            . '<form name="cardlink_checkout_applepay_3ds_breakout" method="get" target="_top" '
            . 'action="' . htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') . '"></form>'
            . '<script>window.addEventListener("load",function(){document.forms["cardlink_checkout_applepay_3ds_breakout"].submit();});</script>'
            . '</body></html>';

        /** @var \Magento\Framework\Controller\Result\Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHeader('Content-Type', 'text/html');
        $result->setContents($html);
        return $result;
    }

    /**
     * Create a Magento order from the checkout quote.
     *
     * @param int|null $quoteId  Quote ID from stored txInfo (fallback)
     * @return \Magento\Sales\Model\Order|null
     */
    private function createOrderFromQuote(?int $quoteId = null)
    {
        try {
            $quote = $this->checkoutSession->getQuote();

            if ((!$quote || !$quote->getId() || !$quote->getIsActive()) && $quoteId) {
                $this->logger->info(
                    "ApplePay 3DS: Session quote unavailable, loading quote by ID={$quoteId}"
                );
                try {
                    $quote = $this->quoteRepository->get($quoteId);
                } catch (\Exception $e) {
                    $this->logger->error(
                        "ApplePay 3DS: Failed to load quote ID={$quoteId}: " . $e->getMessage()
                    );
                    return null;
                }
            }

            if (!$quote || !$quote->getId()) {
                $this->logger->error('ApplePay 3DS: No active quote in session or by ID');
                return null;
            }

            // Ensure guest settings are properly configured before order creation
            $this->ensureQuoteIsReady($quote);

            $quote->setReservedOrderId(null);
            $quote->reserveOrderId();

            $this->logger->info(
                "ApplePay 3DS: Creating order from quote {$quote->getId()}, "
                . "reserved ID: {$quote->getReservedOrderId()}"
            );

            $order = $this->quoteManagement->submit($quote);
            $quote->setIsActive(false)->save();

            return $order;

        } catch (\Exception $e) {
            $this->logger->error('ApplePay 3DS: Order creation failed: ' . $e->getMessage());
            return null;
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
                    $this->logger->debug("ApplePay 3DS: Set guest customer email to {$email} for quote {$quote->getId()}");
                }
            } else {
                $this->logger->warning("ApplePay 3DS: Unable to determine customer email for quote {$quote->getId()}");
            }
        }
        $quote->save();
    }
}
