<?php

namespace Cardlink\Checkout\Helper;

use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Model\ApiFields;
use Cardlink\Checkout\Model\PaymentStatus;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Checkout\Model\Session;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Spi\OrderResourceInterface;
use Magento\Sales\Api\Data\OrderInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;

/**
 * Helper class containing methods to handle payment related functionalities.
 *
 * @author Cardlink S.A.
 */
class Payment extends AbstractHelper
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var OrderInterface
     */
    protected $order;

    /**
     * @var OrderResourceInterface
     */
    protected $orderResource;

    /**
     * @var OrderInterfaceFactory
     */
    protected $orderFactory;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var OrderManagementInterface
     */
    protected $orderManagement;

    /**
     * @var CartRepositoryInterface
     */
    protected $quoteRepository;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var Data
     */
    protected $dataHelper;

    /**
     * @var PaymentHelper
     */
    protected $paymentHelper;

    /**
     * @var Tokenization
     */
    protected $tokenizationHelper;

    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * @var BuilderInterface
     */
    protected $transactionBuilder;

    /**
     * @var QuoteFactory
     */
    protected $quoteFactory;

    /**
     * @var QuoteResource
     */
    protected $quoteResource;

    /**
     * Constructor.
     *
     * @param Logger $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderInterface $order
     * @param Session $checkoutSession
     * @param OrderResourceInterface $orderResource
     * @param OrderInterfaceFactory $orderFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderManagementInterface $orderManagement
     * @param CartRepositoryInterface $quoteRepository
     * @param UrlInterface $urlBuilder
     * @param Data $dataHelper
     * @param Tokenization $tokenizationHelper
     * @param ManagerInterface $messageManager
     * @param BuilderInterface $transactionBuilder
     * @param QuoteFactory $quoteFactory
     * @param QuoteResource $quoteResource
     */
    public function __construct(
        Logger $logger,
        ScopeConfigInterface $scopeConfig,
        OrderInterface $order,
        Session $checkoutSession,
        OrderResourceInterface $orderResource,
        OrderInterfaceFactory $orderFactory,
        OrderRepositoryInterface $orderRepository,
        OrderManagementInterface $orderManagement,
        CartRepositoryInterface $quoteRepository,
        UrlInterface $urlBuilder,
        Data $dataHelper,
        PaymentHelper $paymentHelper,
        Tokenization $tokenizationHelper,
        ManagerInterface $messageManager,
        BuilderInterface $transactionBuilder,
        QuoteFactory $quoteFactory,
        QuoteResource $quoteResource
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->order = $order;
        $this->orderResource = $orderResource;
        $this->orderFactory = $orderFactory;
        $this->orderRepository = $orderRepository;
        $this->orderManagement = $orderManagement;
        $this->quoteRepository = $quoteRepository;
        $this->checkoutSession = $checkoutSession;
        $this->urlBuilder = $urlBuilder;
        $this->dataHelper = $dataHelper;
        $this->paymentHelper = $paymentHelper;
        $this->tokenizationHelper = $tokenizationHelper;
        $this->messageManager = $messageManager;
        $this->transactionBuilder = $transactionBuilder;
        $this->quoteFactory = $quoteFactory;
        $this->quoteResource = $quoteResource;
    }

    /**
     * Gets the URL of the Cardlink payment gateway according to the configured business partner and transaction environment.
     *
     * @return string
     */
    function getPaymentGatewayUrl()
    {
        return $this->urlBuilder->getUrl('cardlink_checkout/payment/gateway', array('_secure' => true));
    }

    /**
     * Returns the payment gateway redirection URL the configured Business Partner and the transactions environment.
     *
     * @return string The URL of the payment gateway.
     */
    public function getPaymentGatewayDataPostUrl($payment_method_code)
    {
        if ($payment_method_code == \Cardlink\Checkout\Model\Config\SettingsIris::CODE) {
            $businessPartner = $this->dataHelper->getIrisBusinessPartner();
            $transactionEnvironment = $this->dataHelper->getIrisTransactionEnvironment();
        } elseif ($payment_method_code == \Cardlink\Checkout\Model\Config\SettingsGooglePay::CODE) {
            $businessPartner = $this->dataHelper->getGooglePayBusinessPartner();
            $transactionEnvironment = $this->dataHelper->getGooglePayTransactionEnvironment();
        } elseif ($payment_method_code == \Cardlink\Checkout\Model\Config\SettingsApplePay::CODE) {
            $businessPartner = $this->dataHelper->getApplePayBusinessPartner();
            $transactionEnvironment = $this->dataHelper->getApplePayTransactionEnvironment();
        } else {
            $businessPartner = $this->dataHelper->getBusinessPartner();
            $transactionEnvironment = $this->dataHelper->getTransactionEnvironment();
        }

        if ($transactionEnvironment == \Cardlink\Checkout\Model\Config\Source\TransactionEnvironments::PRODUCTION_ENVIRONMENT) {
            switch ($businessPartner) {
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce.cardlink.gr/vpos/shophandlermpi';

                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_NEXI:
                    return 'https://www.alphaecommerce.gr/vpos/shophandlermpi';

                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://vpos.eurocommerce.gr/vpos/shophandlermpi';

                default:
            }
        } else {
            switch ($businessPartner) {
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce-test.cardlink.gr/vpos/shophandlermpi';

                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_NEXI:
                    return 'https://alphaecommerce-test.cardlink.gr/vpos/shophandlermpi';

                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://eurocommerce-test.cardlink.gr/vpos/shophandlermpi';

                default:
            }
        }
        return NULL;
    }

    /**
     * Returns the maximum number of installments according to the order amount.
     *
     * @param float|string $orderAmount The total amount of the order to be used for calculating the maximum number of installments.
     * @return int The maximum number of installments.
     */
    public function getMaxInstallments($orderAmount)
    {
        $maxInstallments = 1;
        $installmentsConfiguration = $this->dataHelper->getInstallmentsConfiguration();

        if (!empty($installmentsConfiguration)) {

            foreach ($installmentsConfiguration as $range) {
                if (
                    $range['start_amount'] <= $orderAmount
                    && (
                        ($range['end_amount'] > 0 && $range['end_amount'] >= $orderAmount)
                        || $range['end_amount'] == 0
                    )
                ) {
                    $maxInstallments = $range['max_installments'];
                }
            }
        }

        return $maxInstallments;
    }

    /**
     * Returns the URL that the customer will be redirected after a successful payment transaction.
     *
     * @return string The URL of the checkout payment success page.
     */
    private function getTransactionSuccessUrl()
    {
        return $this->urlBuilder->getUrl('cardlink_checkout/payment/response', ['_secure' => true]);
    }

    /**
     * Returns the URL that the customer will be redirected after a failed or canceled payment transaction.
     *
     * @return string The URL of the store's checkout payment failure/cancelation page.
     */
    private function getTransactionCancelUrl()
    {
        return $this->urlBuilder->getUrl('cardlink_checkout/payment/response', ['_secure' => true]);
    }

    /**
     * Returns the required payment gateway's API value for the transaction type (trType) property.
     *
     * @return string '1' for Sale/Capture, '2' for Authorize.
     */
    private function getTransactionTypeValue()
    {
        switch ($this->dataHelper->getTransactionType()) {
            case \Cardlink\Checkout\Model\Config\Source\TransactionTypes::TRANSACTION_TYPE_CAPTURE:
                return '1';

            case \Cardlink\Checkout\Model\Config\Source\TransactionTypes::TRANSACTION_TYPE_AUTHORIZE:
                return '2';
        }
    }

    /**
     * Loads the order information for
     *
     * @param Quote $quote The entity of the quote.
     * @return array An associative array containing the data that will be sent to the payment gateway's API endpoint to perform the requested transaction.
     */
    public function getFormDataForQuote(Quote $quote, $checkoutSession)
    {
        $quoteId = $quote->getEntityId();

        $billingAddress = $quote->getBillingAddress();
        $shippingAddress = $quote->getShippingAddress();
        $payment = $quote->getPayment();

        if ($billingAddress == false || $shippingAddress == false) {
            if ($this->dataHelper->logDebugInfoEnabled()) {
                $this->logger->error("Invalid billing/shipping address for quote {$quoteId}.");
            }
            return false;
        }

        $paymentMethodCode = $checkoutSession->getQuote()->getPayment()->getMethod();

        return $this->buildFormData(
            'QUOTEx' . $quoteId . 'x' . self::incrementalHash(ApiFields::OrderId_SuffixLength - 1),
            floatval($quote->getGrandTotal()),
            $quote->getQuoteCurrencyCode(),
            $quote->getStore()->getCode(),
            $billingAddress,
            $shippingAddress,
            $payment,
            $paymentMethodCode,
            $quote->getCustomerId(),
            'QUOTE'
        );
    }

    /**
     * Build the form data for the payment gateway API endpoint to perform the requested transaction.
     *
     * @param Order $order The entity of the order.
     * @return array An associative array containing the data that will be sent to the payment gateway's API endpoint to perform the requested transaction.
     */
    public function getFormDataForOrder($order)
    {
        $orderId = $order->getIncrementId();

        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();
        $payment = $order->getPayment();

        if ($billingAddress == false || $shippingAddress == false) {
            if ($this->dataHelper->logDebugInfoEnabled()) {
                $this->logger->error("Invalid billing/shipping address for order {$orderId}.");
            }
            return false;
        }

        $paymentMethodCode = $payment->getMethodInstance()->getCode();

        return $this->buildFormData(
            $orderId,
            floatval($order->getGrandTotal()),
            $order->getOrderCurrencyCode(),
            $order->getStore()->getCode(),
            $billingAddress,
            $shippingAddress,
            $payment,
            $paymentMethodCode,
            $order->getCustomerId(),
            'ORDER'
        );
    }

    /**
     * Build the form data for the payment gateway API endpoint.
     *
     * This method contains the common logic for building payment request data,
     * shared between quote and order processing.
     *
     * @param string $entityId The quote or order ID
     * @param float $grandTotal The total amount
     * @param string $currencyCode The currency code
     * @param string $storeCode The store code
     * @param mixed $billingAddress The billing address object
     * @param mixed $shippingAddress The shipping address object
     * @param mixed $payment The payment object
     * @param string $paymentMethodCode The payment method code
     * @param int|null $customerId The customer ID
     * @param string $descriptionPrefix 'QUOTE' or 'ORDER' prefix for description
     * @return array The signed form data array
     */
    private function buildFormData(
        $entityId,
        float $grandTotal,
        string $currencyCode,
        string $storeCode,
        $billingAddress,
        $shippingAddress,
        $payment,
        string $paymentMethodCode,
        $customerId,
        string $descriptionPrefix
    ): array {
        $formData = [];

        // Version number - must be '2'
        $formData[ApiFields::Version] = '2';
        // Device category - always '0'
        $formData[ApiFields::DeviceCategory] = '0';

        // The type of transaction to perform (Sale/Authorize).
        $formData[ApiFields::TransactionType] = $this->getTransactionTypeValue();

        // Transaction success/failure return URLs
        $formData[ApiFields::ConfirmUrl] = $this->getTransactionSuccessUrl();
        $formData[ApiFields::CancelUrl] = $this->getTransactionCancelUrl();

        // Order information
        $formData[ApiFields::OrderId] = $entityId . 'x' . self::incrementalHash(ApiFields::OrderId_SuffixLength - 1);
        $formData[ApiFields::OrderAmount] = $grandTotal;
        $formData[ApiFields::Currency] = $currencyCode;

        $enableIrisPayments = $this->dataHelper->isIrisEnabled();
        $isIrisPayment = ($paymentMethodCode == \Cardlink\Checkout\Model\Config\SettingsIris::CODE && $enableIrisPayments);

        $enableGooglePay = $this->dataHelper->isGooglePayEnabled();
        $isGooglePayPayment = ($paymentMethodCode == \Cardlink\Checkout\Model\Config\SettingsGooglePay::CODE && $enableGooglePay);

        $enableApplePay = $this->dataHelper->isApplePayEnabled();
        $isApplePayPayment = ($paymentMethodCode == \Cardlink\Checkout\Model\Config\SettingsApplePay::CODE && $enableApplePay);

        if ($isIrisPayment) {
            // The Merchant ID
            $formData[ApiFields::MerchantId] = $this->dataHelper->getIrisMerchantId();
            $sharedSecret = $this->dataHelper->getIrisSharedSecret();

            $formData[ApiFields::PaymentMethod] = 'IRIS';
            $formData[ApiFields::TransactionType] = '1';

            // The optional URL of a CSS file to be included in the pages of the payment gateway for custom formatting.
            $cssUrl = trim((string) $this->dataHelper->getIrisCssUrl());

            // Instruct the payment gateway to use the store language for its UI.
            if ($this->dataHelper->getIrisForceStoreLanguage()) {
                $locale = $this->scopeConfig->getValue(
                    'general/locale/code',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $storeCode
                );
                $lang = explode('_', $locale)[0];
                $formData[ApiFields::Language] = $lang;
            }
        } elseif ($isGooglePayPayment) {
            // Google Pay payment configuration
            $formData[ApiFields::MerchantId] = $this->dataHelper->getGooglePayMerchantId();
            $sharedSecret = $this->dataHelper->getGooglePaySharedSecret();

            $formData[ApiFields::OrderDescription] = $descriptionPrefix . ' ' . $entityId;

            // Google Pay transaction type
            $googlePayTransactionType = $this->dataHelper->getGooglePayTransactionType();
            if ($googlePayTransactionType == \Cardlink\Checkout\Model\Config\Source\TransactionTypes::TRANSACTION_TYPE_AUTHORIZE) {
                $formData[ApiFields::TransactionType] = '2';
            } else {
                $formData[ApiFields::TransactionType] = '1';
            }

            $cssUrl = '';
        } elseif ($isApplePayPayment) {
            // Apple Pay payment configuration
            $formData[ApiFields::MerchantId] = $this->dataHelper->getApplePayMerchantId();
            $sharedSecret = $this->dataHelper->getApplePaySharedSecret();

            $formData[ApiFields::OrderDescription] = $descriptionPrefix . ' ' . $entityId;

            // Apple Pay transaction type
            $applePayTransactionType = $this->dataHelper->getApplePayTransactionType();
            if ($applePayTransactionType == \Cardlink\Checkout\Model\Config\Source\TransactionTypes::TRANSACTION_TYPE_AUTHORIZE) {
                $formData[ApiFields::TransactionType] = '2';
            } else {
                $formData[ApiFields::TransactionType] = '1';
            }

            $cssUrl = '';
        } else {
            // The Merchant ID
            $formData[ApiFields::MerchantId] = $this->dataHelper->getMerchantId();
            $sharedSecret = $this->dataHelper->getSharedSecret();

            $formData[ApiFields::OrderDescription] = $descriptionPrefix . ' ' . $entityId;

            // The optional URL of a CSS file to be included in the pages of the payment gateway for custom formatting.
            $cssUrl = trim((string) $this->dataHelper->getCssUrl());

            // Installments information.
            if ($this->dataHelper->acceptsInstallments()) {
                // Enforce installments limit
                $maxInstallments = $this->getMaxInstallments($formData[ApiFields::OrderAmount]);
                $installments = max(0, min($maxInstallments, $payment->getCardlinkInstallments() + 0));

                if ($installments > 1) {
                    $formData[ApiFields::ExtInstallmentoffset] = 0;
                    $formData[ApiFields::ExtInstallmentperiod] = $installments;
                }
            }

            // Tokenization
            if ($this->dataHelper->allowsTokenization()) {
                if ($payment->getCardlinkStoredToken() > 0) {
                    $paymentToken = $this->tokenizationHelper->getCustomerPaymentToken(
                        $customerId,
                        $payment->getCardlinkStoredToken()
                    );

                    if ($paymentToken != null && $paymentToken->getIsActive() && !$paymentToken->getIsExpired()) {
                        $formData[ApiFields::ExtTokenOptions] = 100;
                        $formData[ApiFields::ExtToken] = $paymentToken->getGatewayToken();
                    }
                } else if ($payment->getCardlinkTokenizeCard()) {
                    $formData[ApiFields::ExtTokenOptions] = 100;
                }
            }

            // Instruct the payment gateway to use the store language for its UI.
            if ($this->dataHelper->getForceStoreLanguage()) {
                $locale = $this->scopeConfig->getValue(
                    'general/locale/code',
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $storeCode
                );
                $lang = explode('_', $locale)[0];
                $formData[ApiFields::Language] = $lang;
            }
        }

        // Payer/customer information
        $formData[ApiFields::PayerEmail] = $billingAddress->getEmail();
        $formData[ApiFields::PayerPhone] = $billingAddress->getTelephone();

        // Billing information
        $formData[ApiFields::BillCountry] = $billingAddress->getCountryId();
        $formData[ApiFields::BillZip] = $billingAddress->getPostcode();
        $formData[ApiFields::BillCity] = $billingAddress->getCity();
        $formData[ApiFields::BillAddress] = $billingAddress->getStreet(1)[0];

        // Shipping information
        $formData[ApiFields::ShipCountry] = $shippingAddress->getCountryId();
        $formData[ApiFields::ShipZip] = $shippingAddress->getPostcode();
        $formData[ApiFields::ShipCity] = $shippingAddress->getCity();
        $formData[ApiFields::ShipAddress] = $shippingAddress->getStreet(1)[0];

        if ($cssUrl != '') {
            $formData[ApiFields::CssUrl] = $cssUrl;
        }

        // Calculate the digest of the transaction request data and append it.
        $signedFormData = self::signRequestFormData($formData, $sharedSecret);

        if ($this->dataHelper->logDebugInfoEnabled()) {
            $this->logger->debug("Valid payment request created for {$descriptionPrefix} {$entityId}.");
            $this->logger->debug(json_encode($signedFormData, JSON_PRETTY_PRINT));
        }

        return $signedFormData;
    }

    public function incrementalHash($len = 3)
    {
        $charset = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $base = strlen($charset);
        $result = '';

        $now = (int) explode(' ', microtime())[1];
        while ($now >= $base) {
            $i = (int) $now % (int) $base;
            $result = $charset[$i] . $result;
            $now /= $base;
        }
        return substr($result, -1 * $len);
    }

    /**
     * Sign a bank request with the merchant's shared key and insert the digest in the data.
     *
     * @param array $formData The payment request data.
     * @param string $sharedSecret The shared secret code of the merchant.
     *
     * @return array The original request data put in proper order including the calculated data digest.
     */
    public function signRequestFormData($formData, $sharedSecret)
    {
        $ret = [];
        $concatenatedData = '';

        foreach (ApiFields::TRANSACTION_REQUEST_DIGEST_CALCULATION_FIELD_ORDER as $field) {
            if (array_key_exists($field, $formData)) {
                $ret[$field] = trim((string) $formData[$field]);
                $concatenatedData .= $ret[$field];
            }
        }

        $concatenatedData .= $sharedSecret;
        $ret[ApiFields::Digest] = self::generateDigest($concatenatedData);

        return $ret;
    }

    /**
     * Validate the response data of the payment gateway by recalculating and comparing the data digests in order to identify legitimate incoming request.
     *
     * @param array $formData The payment gateway response data.
     * @param string $sharedSecret The shared secret code of the merchant.
     *
     * @return bool Identifies that the incoming data were sent by the payment gateway.
     */
    public function validateResponseData($formData, $sharedSecret)
    {
        $concatenatedData = '';

        foreach (ApiFields::TRANSACTION_RESPONSE_DIGEST_CALCULATION_FIELD_ORDER as $field) {
            if ($field != ApiFields::Digest) {
                if (array_key_exists($field, $formData)) {
                    $concatenatedData .= $formData[$field];
                }
            }
        }

        $concatenatedData .= $sharedSecret;
        $generatedDigest = $this->GenerateDigest($concatenatedData);

        return $formData[ApiFields::Digest] == $generatedDigest;
    }

    /**
     * Validate the response data of the payment gateway for Alpha Bonus transactions
     * by recalculating and comparing the data digests in order to identify legitimate incoming request.
     *
     * @param array $formData The payment gateway response data.
     * @param string $sharedSecret The shared secret code of the merchant.
     *
     * @return bool Identifies that the incoming data were sent by the payment gateway.
     */
    public function validateXlsBonusResponseData($formData, $sharedSecret)
    {
        $concatenatedData = '';

        foreach (ApiFields::TRANSACTION_RESPONSE_XLSBONUS_DIGEST_CALCULATION_FIELD_ORDER as $field) {
            if ($field != ApiFields::XlsBonusDigest) {
                if (array_key_exists($field, $formData)) {
                    $concatenatedData .= $formData[$field];
                }
            }
        }

        $concatenatedData .= $sharedSecret;
        $generatedDigest = $this->GenerateDigest($concatenatedData);

        return $formData[ApiFields::XlsBonusDigest] == $generatedDigest;
    }

    /**
     * Generate the message digest from a concatenated data string.
     *
     * @param string $concatenatedData The data to calculate the digest for.
     */
    public function generateDigest($concatenatedData)
    {
        return base64_encode(hash('sha256', $concatenatedData, true));
    }

    /**
     * Mark an order as paid, store additional payment information and handle customer's card tokenization request.
     *
     * @param object The order object.
     * @param array The data from the payment gateway's response.
     */
    public function markSuccessfulPayment($order, $responseData)
    {
        if ($order != null && $order !== false && $order->getId()) {
            $charge = $responseData[ApiFields::OrderAmount];

            if ($this->dataHelper->logDebugInfoEnabled()) {
                $this->logger->debug("Processing successful payment for order {$order->getIncrementId()}.");
            }

            $payment = $order->getPayment();

            $payment->setCardlinkPayStatus($responseData[ApiFields::Status]);
            $payment->setCardlinkTxId($responseData[ApiFields::TransactionId]);
            $payment->setCardlinkPayMethod($responseData[ApiFields::PaymentMethod]);
            $payment->setCardlinkPayRef($responseData[ApiFields::PaymentReferenceId]);
            $payment->setCardlinkOrderId($responseData[ApiFields::OrderId]);

            // Save payment immediately to persist cardlink fields (especially cardlink_order_id
            // which is required for capture/refund/void operations via the XML API)
            try {
                $payment->save();
            } catch (\Exception $e) {
                $this->logger->error('Failed to save payment with Cardlink fields: ' . $e->getMessage());
            }

            if ($this->dataHelper->logDebugInfoEnabled()) {
                $this->logger->debug("Setting payment gateway information to payment object {$payment->getId()} (order {$order->getIncrementId()}).");
                $this->logger->debug("Cardlink Order ID set to: {$responseData[ApiFields::OrderId]}");
            }

            // Handle order state based on payment status
            if ($responseData[ApiFields::Status] == PaymentStatus::CAPTURED) {
                // Payment was captured - set order to Processing and create invoice
                if ($this->dataHelper->logDebugInfoEnabled()) {
                    $this->logger->debug("Payment was captured for order {$order->getIncrementId()}.");
                    $this->logger->debug("Setting state of order {$order->getIncrementId()} to 'Processing'.");
                }

                $order
                    ->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING)
                    ->setState(\Magento\Sales\Model\Order::STATE_PROCESSING, true)
                    ->addStatusHistoryComment("Captured Transaction ID: " . $responseData[ApiFields::TransactionId]);

                try {
                    $this->orderRepository->save($order);
                } catch (\Exception $e) {
                    $this->logger->error($e);
                    $this->messageManager->addExceptionMessage($e, $e->getMessage());
                }

                if ($order->canInvoice()) {
                    $order->getPayment()->setSkipTransactionCreation(false);
                    $invoice = $order->prepareInvoice();
                    // Use CAPTURE_OFFLINE since the payment was already captured at Cardlink's side
                    // CAPTURE_ONLINE would trigger the gateway capture command again, which would fail
                    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
                    $invoice->register();
                    $invoice->save();

                    if ($this->dataHelper->logDebugInfoEnabled()) {
                        $this->logger->debug("Created invoice {$invoice->getIncrementId()} for order {$order->getIncrementId()}.");
                    }
                }

                if ($order->hasInvoices()) {
                    $order->setBaseTotalInvoiced($charge);
                    $order->setTotalInvoiced($charge);
                    $order->setBaseTotalPaid($charge);
                    $order->setTotalPaid($charge);

                    try {
                        $this->orderRepository->save($order);
                    } catch (\Exception $e) {
                        $this->logger->error($e);
                        $this->messageManager->addExceptionMessage($e, $e->getMessage());
                    }

                    if ($this->dataHelper->logDebugInfoEnabled()) {
                        $this->logger->debug("Setting total invoiced amount for order {$order->getIncrementId()} to {$charge}.");
                    }

                    foreach ($order->getInvoiceCollection() as $orderInvoice) {
                        $orderInvoice->setState(\Magento\Sales\Model\Order\Invoice::STATE_PAID)
                            // Set TransactionId to enable online refunds via the Cardlink XML API
                            ->setTransactionId($responseData[ApiFields::TransactionId])
                            ->setBaseGrandTotal($charge)
                            ->setGrandTotal($charge)
                            ->save();
                    }
                }
            } elseif ($responseData[ApiFields::Status] == PaymentStatus::AUTHORIZED) {
                // Payment was authorized (preauthorized) - set order to Processing with custom status
                // The capture will happen when an invoice is created with CAPTURE_ONLINE
                if ($this->dataHelper->logDebugInfoEnabled()) {
                    $this->logger->debug("Payment was authorized (preauthorized) for order {$order->getIncrementId()}.");
                    $this->logger->debug("Setting state of order {$order->getIncrementId()} to 'Processing' with status 'cardlink_authorized'.");
                }

                $order
                    ->setStatus('cardlink_authorized')
                    ->setState(\Magento\Sales\Model\Order::STATE_PROCESSING, true)
                    ->addStatusHistoryComment("Authorized Transaction ID: " . $responseData[ApiFields::TransactionId] . " - Awaiting capture via invoice creation.");

                try {
                    $this->orderRepository->save($order);
                } catch (\Exception $e) {
                    $this->logger->error($e);
                    $this->messageManager->addExceptionMessage($e, $e->getMessage());
                }
            }

            // Add transaction
            $this->addTransactionToOrder(
                $order,
                $responseData[ApiFields::Status] == PaymentStatus::CAPTURED
                    ? Transaction::TYPE_CAPTURE
                    : Transaction::TYPE_AUTH,
                [
                    ApiFields::OrderId => $responseData[ApiFields::OrderId],
                    ApiFields::PaymentMethod => strtoupper($responseData[ApiFields::PaymentMethod]),
                    ApiFields::Status => $responseData[ApiFields::Status],
                    ApiFields::Currency => $responseData[ApiFields::Currency],
                    ApiFields::OrderAmount => $responseData[ApiFields::OrderAmount],
                    ApiFields::PaymentTotal => $responseData[ApiFields::PaymentTotal],
                    ApiFields::MerchantId => $responseData[ApiFields::MerchantId],
                    ApiFields::TransactionId => $responseData[ApiFields::TransactionId],
                    ApiFields::PaymentReferenceId => $responseData[ApiFields::PaymentReferenceId],
                    ApiFields::Message => array_key_exists(ApiFields::Message, $responseData) ? $responseData[ApiFields::Message] : ''
                ]
            );
            $customerId = $order->getCustomerId();

            // If payment method tokenization is allowed.
            if ($customerId > 0 && $this->dataHelper->allowsTokenization()) {
                // If the user asked for card tokenization.
                if (
                    $payment->getCardlinkTokenizeCard()
                    && array_key_exists(ApiFields::ExtToken, $responseData)
                ) {
                    if ($this->dataHelper->logDebugInfoEnabled()) {
                        $this->logger->debug("Storing token {$responseData[ApiFields::PaymentMethod]}/{$responseData[ApiFields::ExtTokenPanEnd]} for customer {$customerId}.");
                    }

                    // Store the tokenized card information.
                    $storedToken = $this->tokenizationHelper->storeTokenForCustomer(
                        $customerId,
                        $responseData[ApiFields::ExtToken],
                        $responseData[ApiFields::PaymentMethod],
                        $responseData[ApiFields::ExtTokenPanEnd],
                        $responseData[ApiFields::ExtTokenExpiration]
                    );

                    if ($storedToken != null) {
                        $payment->setCardlinkStoredToken($storedToken->getEntityId());
                        $payment->save();
                    }
                }

                $paymentToken = $this->tokenizationHelper->getCustomerPaymentToken($customerId, $payment->getCardlinkStoredToken());

                if ($paymentToken != null) {
                    try {
                        $this->tokenizationHelper->addLinkToOrderPayment($paymentToken->getEntityId(), $payment->getId());
                    } catch (\Exception $ex) {
                    }
                }
            }
        }
    }

    /**
     * Mark an order as canceled, store additional payment information and restore the user's cart.
     *
     * @param object The order object.
     * @param array The data from the payment gateway's response.
     */
    public function markCanceledPayment($order, $responseData = null)
    {
        if ($order->getId()) {

            if (isset($responseData)) {
                $paymentStatus = '';
                $paymentMethod = '';

                if (isset($responseData[ApiFields::PaymentMethod])) {
                    $paymentMethod = strtoupper($responseData[ApiFields::PaymentMethod]);
                }

                if (isset($responseData[ApiFields::Status])) {
                    $paymentStatus = strtoupper($responseData[ApiFields::Status]);
                }

                $order->addStatusHistoryComment(trim('Payment canceled - ' . trim($paymentMethod . ' ' . $paymentStatus), " \t\n\r\0\x0B-"));

                try {
                    $this->orderRepository->save($order);
                } catch (\Exception $e) {
                    $this->logger->error($e);
                    $this->messageManager->addExceptionMessage($e, $e->getMessage());
                }
            }

            if ($this->dataHelper->logDebugInfoEnabled()) {
                $this->logger->debug("Order {$order->getIncrementId()} was canceled.");
            }

            $payment = $order->getPayment();

            if (isset($responseData)) {
                if (isset($responseData[ApiFields::Status])) {
                    $payment->setCardlinkPayStatus($responseData[ApiFields::Status]);
                }
                if (isset($responseData[ApiFields::TransactionId])) {
                    $payment->setCardlinkTxId($responseData[ApiFields::TransactionId]);
                }
                if (isset($responseData[ApiFields::PaymentMethod])) {
                    $payment->setCardlinkPayMethod($responseData[ApiFields::PaymentMethod]);
                }
                if (isset($responseData[ApiFields::PaymentReferenceId])) {
                    $payment->setCardlinkPayRef($responseData[ApiFields::PaymentReferenceId]);
                }
            }
            $payment->save();

            $this->orderManagement->cancel($order->getId());
            $this->restoreQuote($order);
        }
    }

    /**
     * Restore last active quote based on checkout session
     *
     * @return bool True if quote restored successfully, false otherwise
     */
    private function restoreQuote($order)
    {
        $logEnabled = $this->dataHelper->logDebugInfoEnabled();

        if ($order->getId()) {
            $quote = $this->getQuoteById($order->getQuoteId());

            if ($quote->getId()) {
                $quote->setIsActive(1)->setReservedOrderId(null)->save();
                $this->checkoutSession->replaceQuote($quote);

                if ($logEnabled) {
                    $this->logger->info("Quote {$quote->getId()} of order {$order->getIncrementId()} was restored.");
                }

                return true;
            } else if ($logEnabled) {
                $this->logger->info("Failed to retrieve the quote of order {$order->getIncrementId()}.");
            }
        } else if ($logEnabled) {
            $this->logger->info("Failed to retrieve order to restore quote.");
        }
        return false;
    }

    /**
     * Retrieve an order using its increment ID.
     *
     * @param string $incrementId The increment ID of the order.
     * @return \Magento\Sales\Api\Data\OrderInterface|null The order object if found. Otherwise, null.
     */
    public function getOrderByIncrementId($incrementId)
    {
        $order = $this->orderFactory->create();
        $this->orderResource->load($order, $incrementId, OrderInterface::INCREMENT_ID);
        return $order;
    }

    /**
     * Get a quote by its reserved order ID.
     *
     * @param string $reservedOrderId
     * @return \Magento\Quote\Model\Quote|null
     */
    public function getQuoteByReservedOrderId($reservedOrderId)
    {
        $quote = $this->quoteFactory->create();
        $this->quoteResource->load($quote, $reservedOrderId, 'reserved_order_id');

        return $quote->getId() ? $quote : null;
    }

    /**
     * Retrieve an order using its database entity ID
     * @param string $orderId The database entity ID of the order.
     * @return \Magento\Sales\Api\Data\OrderInterface|null The order object if found. Otherwise, null.
     */
    public function getOrderById($orderId)
    {
        $order = $this->orderFactory->create();
        $this->orderResource->load($order, $orderId, OrderInterface::ENTITY_ID);
        return $order;
    }

    /**
     * Return sales quote instance for specified ID
     *
     * @param int $quoteId Quote identifier
     * @return Mage_Sales_Model_Quote
     */
    public function getQuoteById($quoteId)
    {
        return $this->quoteRepository->get($quoteId);
    }

    /**
     * Add transaction entry to the order.
     *
     * @param Order $order
     * @param array $paymentData
     */
    public function addTransactionToOrder($order, $type, $paymentData = array())
    {
        try {
            // Prepare payment object
            $payment = $order->getPayment();
            $payment->setLastTransId($paymentData[ApiFields::TransactionId]);
            $payment->setTransactionId($paymentData[ApiFields::TransactionId]);
            $payment->setAdditionalInformation([Transaction::RAW_DETAILS => (array) $paymentData]);

            // For authorization transactions, keep the transaction open so it can be captured later
            // For capture transactions, close the transaction
            if ($type === Transaction::TYPE_AUTH) {
                $payment->setIsTransactionClosed(false);
            } else {
                $payment->setIsTransactionClosed(true);
            }

            // Formatted price
            $formatedPrice = $order->getBaseCurrency()->formatTxt($order->getGrandTotal());

            // Prepare transaction
            $transaction = $this->transactionBuilder->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($paymentData[ApiFields::TransactionId])
                ->setAdditionalInformation([Transaction::RAW_DETAILS => (array) $paymentData])
                ->setFailSafe(true)
                ->build($type);

            // For authorization, mark transaction as open (IsClosed = 0) to allow capture
            if ($type === Transaction::TYPE_AUTH) {
                $transaction->setIsClosed(false);
            }

            // Add transaction to payment
            $payment->addTransactionCommentsToOrder($transaction, __('The authorized amount is %1.', $formatedPrice));

            // Only clear parent transaction for auth transactions (first in chain)
            if ($type === Transaction::TYPE_AUTH) {
                $payment->setParentTransactionId(null);
            }

            // Save payment, transaction and order
            $payment->save();
            $order->save();
            $transaction->save();

            return $transaction->getTransactionId();
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
        }
    }
}
