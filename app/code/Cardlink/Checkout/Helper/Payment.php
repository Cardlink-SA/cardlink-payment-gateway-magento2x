<?php

namespace Cardlink\Checkout\Helper;

use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Model\ApiFields;
use Cardlink\Checkout\Model\PaymentStatus;
use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Helper\Tokenization;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Checkout\Model\Session;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Spi\OrderResourceInterface;
use Magento\Sales\Api\Data\OrderInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;

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
        Tokenization $tokenizationHelper,
        ManagerInterface $messageManager,
        BuilderInterface $transactionBuilder
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
        $this->tokenizationHelper = $tokenizationHelper;
        $this->messageManager = $messageManager;
        $this->transactionBuilder = $transactionBuilder;
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
    public function getFormDataForOrder($quote, $checkoutSession)
    {
        $quoteId = $quote->getEntityId();

        $billingAddress = $quote->getBillingAddress();
        $shippingAddress = $quote->getShippingAddress();
        $payment = $quote->getPayment();

        if ($billingAddress == false || $shippingAddress == false) {
            if ($this->dataHelper->logDebugInfoEnabled()) {
                $this->_logger->error("Invalid billing/shipping address for quote {$quoteId}.");
            }
            return false;
        }

        $payerEmail = $billingAddress->getEmail();

        // Version number - must be '2'
        $formData[ApiFields::Version] = '2';
        // Device category - always '0'
        $formData[ApiFields::DeviceCategory] = '0';
        //// Maximum number of payment retries - set to 10
        //$formData[ApiFields::MaxPayRetries] = '10';

        // The type of transaction to perform (Sale/Authorize).
        $formData[ApiFields::TransactionType] = $this->getTransactionTypeValue();

        // Transaction success/failure return URLs
        $formData[ApiFields::ConfirmUrl] = $this->getTransactionSuccessUrl();
        $formData[ApiFields::CancelUrl] = $this->getTransactionCancelUrl();

        // Order information
        $formData[ApiFields::OrderId] = $quoteId . 'x' . self::incrementalHash(ApiFields::OrderId_SuffixLength - 1);
        $formData[ApiFields::OrderAmount] = floatval($quote->getGrandTotal()); // Get order total amount
        $formData[ApiFields::Currency] = $quote->getQuoteCurrencyCode(); // Get order currency code

        $diasCode = $this->dataHelper->getDiasCode();
        $enableIrisPayments = $this->dataHelper->isIrisEnabled() && $diasCode != '';

        $payment_method_code = $checkoutSession->getQuote()->getPayment()->getMethod();

        if ($payment_method_code == \Cardlink\Checkout\Model\Config\SettingsIris::CODE && $enableIrisPayments) {

            // The Merchant ID
            $formData[ApiFields::MerchantId] = $this->dataHelper->getIrisMerchantId();
            $sharedSecret = $this->dataHelper->getIrisSharedSecret();

            $formData[ApiFields::PaymentMethod] = 'IRIS';
            $formData[ApiFields::OrderDescription] = self::generateIrisRFCode($diasCode, $quoteId, $formData[ApiFields::OrderAmount]);
            $formData[ApiFields::TransactionType] = '1';

            // The optional URL of a CSS file to be included in the pages of the payment gateway for custom formatting.
            $cssUrl = trim((string) $this->dataHelper->getIrisCssUrl());

        } else {

            // The Merchant ID
            $formData[ApiFields::MerchantId] = $this->dataHelper->getMerchantId();
            $sharedSecret = $this->dataHelper->getSharedSecret();

            $formData[ApiFields::OrderDescription] = 'QUOTE ' . $quoteId;

            // The optional URL of a CSS file to be included in the pages of the payment gateway for custom formatting.
            $cssUrl = trim((string) $this->dataHelper->getCssUrl());

            // Installments information.
            if ($this->dataHelper->acceptsInstallments()) {
                // Enforce installments limit
                $maxInstallments = $this->getMaxInstallments($formData[ApiFields::OrderAmount]);

                $installments = max(0, min($maxInstallments, $quote->getPayment()->getCardlinkInstallments() + 0));

                if ($installments > 1) {
                    $formData[ApiFields::ExtInstallmentoffset] = 0;
                    $formData[ApiFields::ExtInstallmentperiod] = $installments;
                }
            }

            // Tokenization
            if ($this->dataHelper->allowsTokenization()) {
                if ($payment->getCardlinkStoredToken() > 0) {
                    $paymentToken = $this->tokenizationHelper->getCustomerPaymentToken(
                        $quote->getCustomerId(),
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

        }

        // Payer/customer information
        $formData[ApiFields::PayerEmail] = $payerEmail;
        $formData[ApiFields::PayerPhone] = $billingAddress->getTelephone();

        // Billing information
        $formData[ApiFields::BillCountry] = $billingAddress->getCountryId();
        //$formData[ApiFields::BillState] = $billingAddress->getRegionCode();
        $formData[ApiFields::BillZip] = $billingAddress->getPostcode();
        $formData[ApiFields::BillCity] = $billingAddress->getCity();
        $formData[ApiFields::BillAddress] = $billingAddress->getStreet(1)[0];

        // Shipping information
        $formData[ApiFields::ShipCountry] = $shippingAddress->getCountryId();
        //$formData[ApiFields::ShipState] = $shippingAddress->getRegionCode();
        $formData[ApiFields::ShipZip] = $shippingAddress->getPostcode();
        $formData[ApiFields::ShipCity] = $shippingAddress->getCity();
        $formData[ApiFields::ShipAddress] = $shippingAddress->getStreet(1)[0];

        if ($cssUrl != '') {
            $formData[ApiFields::CssUrl] = $cssUrl;
        }

        // Instruct the payment gateway to use the store language for its UI.
        if ($this->dataHelper->getForceStoreLanguage()) {
            $formData[ApiFields::Language] = explode('_', (string) $quote->getStore()->getLocaleCode())[0];
        }

        // Calculate the digest of the transaction request data and append it.
        $signedFormData = self::signRequestFormData($formData, $sharedSecret);

        if ($this->dataHelper->logDebugInfoEnabled()) {
            $this->logger->debug("Valid payment request created for quote {$quoteId}.");
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
     * Generate the Request Fund (RF) code for IRIS payments.
     * @param string $diasCustomerCode The DIAS customer code of the merchant.
     * @param mixed $orderId The ID of the order.
     * @param mixed $amount The amount due.
     * @return string The generated RF code.
     */
    public static function generateIrisRFCode(string $diasCustomerCode, $orderId, $amount)
    {
        /* calculate payment check code */
        $paymentSum = 0;

        if ($amount > 0) {
            $ordertotal = str_replace([','], '.', (string) $amount);
            $ordertotal = number_format($ordertotal, 2, '', '');
            $ordertotal = strrev($ordertotal);
            $factor = [1, 7, 3];
            $idx = 0;
            for ($i = 0; $i < strlen($ordertotal); $i++) {
                $idx = $idx <= 2 ? $idx : 0;
                $paymentSum += $ordertotal[$i] * $factor[$idx];
                $idx++;
            }
        }

        $orderIdNum = (int) filter_var($orderId, FILTER_SANITIZE_NUMBER_INT);

        $randomNumber = substr(str_pad($orderIdNum, 13, '0', STR_PAD_LEFT), -13);
        $paymentCode = $paymentSum ? ($paymentSum % 8) : '8';
        $systemCode = '12';
        $tempCode = $diasCustomerCode . $paymentCode . $systemCode . $randomNumber . '271500';
        $mod97 = bcmod($tempCode, '97');

        $cd = 98 - (int) $mod97;
        $cd = str_pad((string) $cd, 2, '0', STR_PAD_LEFT);
        $rf_payment_code = 'RF' . $cd . $diasCustomerCode . $paymentCode . $systemCode . $randomNumber;

        return $rf_payment_code;
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
                $this->logger->debug("Setting state of order {$order->getIncrementId()} to 'Payment Review'.");
            }

            $order
                ->setStatus(\Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW)
                ->setState(\Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW, true)
                ->addStatusHistoryComment("Payment Success - " . strtoupper($responseData[ApiFields::PaymentMethod]) . " " . $responseData[ApiFields::Status]);

            try {
                $this->orderRepository->save($order);
            } catch (\Exception $e) {
                $this->logger->error($e);
                $this->messageManager->addExceptionMessage($e, $e->getMessage());
            }

            $payment = $order->getPayment();
            $payment->setCardlinkPayStatus($responseData[ApiFields::Status]);
            $payment->setCardlinkTxId($responseData[ApiFields::TransactionId]);
            $payment->setCardlinkPayMethod($responseData[ApiFields::PaymentMethod]);
            $payment->setCardlinkPayRef($responseData[ApiFields::PaymentReferenceId]);
            $payment->save();

            if ($this->dataHelper->logDebugInfoEnabled()) {
                $this->logger->debug("Setting payment gateway information to payment object {$payment->getId()} (order {$order->getIncrementId()}).");
            }

            // Create invoice for order payment if transaction status was CAPTURED.
            if ($responseData[ApiFields::Status] == PaymentStatus::CAPTURED) {
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
                    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
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
                            // Only set TransactionId when possible to perform online refunds
                            //->setTransactionId($responseData[ApiFields::TransactionId])
                            ->setBaseGrandTotal($charge)
                            ->setGrandTotal($charge)
                            ->save();
                    }
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
            $payment->setMethod(\Cardlink\Checkout\Model\Config\Settings::CODE);
            $payment->setLastTransId($paymentData[ApiFields::TransactionId]);
            $payment->setTransactionId($paymentData[ApiFields::TransactionId]);
            $payment->setAdditionalInformation([Transaction::RAW_DETAILS => (array) $paymentData]);

            // Formatted price
            $formatedPrice = $order->getBaseCurrency()->formatTxt($order->getGrandTotal());

            // Prepare transaction
            $transaction = $this->transactionBuilder->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($paymentData[ApiFields::TransactionId])
                ->setAdditionalInformation([Transaction::RAW_DETAILS => (array) $paymentData])
                ->setFailSafe(true)
                ->build($type);

            // Add transaction to payment
            $payment->addTransactionCommentsToOrder($transaction, __('The authorized amount is %1.', $formatedPrice));
            $payment->setParentTransactionId(null);

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