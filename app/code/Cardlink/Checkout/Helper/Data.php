<?php

namespace Cardlink\Checkout\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Customer\Model\SessionFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Psr\Log\LoggerInterface;

/**
 * Helper class containing methods to handle the configured settings of the payment module.
 * 
 * @author Cardlink S.A.
 */
class Data extends AbstractHelper
{
    const XML_PATH_CONFIG_ENABLED = 'payment/cardlink_checkout/active';
    const XML_PATH_CONFIG_ORDER_STATUS = 'payment/cardlink_checkout/order_status';
    const XML_PATH_CONFIG_SHORT_DESCRIPTION = 'payment/cardlink_checkout/description';
    const XML_PATH_CONFIG_BUSINESS_PARTNER = 'payment/cardlink_checkout/business_partner';
    const XML_PATH_CONFIG_TRANSACTION_ENVIRONMENT = 'payment/cardlink_checkout/transaction_environment';
    const XML_PATH_CONFIG_MERCHANT_ID = 'payment/cardlink_checkout/merchant_id';
    const XML_PATH_CONFIG_SHARED_SECRET = 'payment/cardlink_checkout/shared_secret';
    const XML_PATH_CONFIG_TRANSACTION_TYPE = 'payment/cardlink_checkout/transaction_type';
    const XML_PATH_CONFIG_ACCEPT_INSTALLMENTS = 'payment/cardlink_checkout/accept_installments';
    const XML_PATH_CONFIG_MAX_INSTALLMENTS = 'payment/cardlink_checkout/max_installments';
    const XML_PATH_CONFIG_INSTALLMENTS_CONFIGURATION = 'payment/cardlink_checkout/installments_configuration';
    const XML_PATH_CONFIG_ALLOW_TOKENIZATION = 'payment/cardlink_checkout/allow_tokenization';
    const XML_PATH_CONFIG_FORCE_STORE_LANGUAGE = 'payment/cardlink_checkout/force_store_language';
    const XML_PATH_CONFIG_DISPLAY_PAYMENT_METHOD_LOGO = 'payment/cardlink_checkout/display_payment_method_logo';
    const XML_PATH_CONFIG_CHECKOUT_IN_IFRAME = 'payment/cardlink_checkout/checkout_in_iframe';
    const XML_PATH_CONFIG_CSS_URL = 'payment/cardlink_checkout/css_url';
    const XML_PATH_CONFIG_LOG_DEBUG_INFO = 'payment/cardlink_checkout/log_debug_info';
    const XML_PATH_CONFIG_HOLD_STOCK_ORDER = 'payment/cardlink_checkout/hold_stock_order';
    const XML_PATH_CONFIG_CANCELED_ORDER_EXPIRATION = 'payment/cardlink_checkout/canceled_order_expiration';

    
    const XML_PATH_CONFIG_IRIS_SHORT_DESCRIPTION = 'payment/cardlink_checkout_iris/description';
    const XML_PATH_CONFIG_IRIS_BUSINESS_PARTNER = 'payment/cardlink_checkout_iris/business_partner';
    const XML_PATH_CONFIG_IRIS_TRANSACTION_ENVIRONMENT = 'payment/cardlink_checkout_iris/transaction_environment';
    const XML_PATH_CONFIG_IRIS_MERCHANT_ID = 'payment/cardlink_checkout_iris/merchant_id';
    const XML_PATH_CONFIG_IRIS_SHARED_SECRET = 'payment/cardlink_checkout_iris/shared_secret';
    const XML_PATH_CONFIG_IRIS_ENABLED = 'payment/cardlink_checkout_iris/active';
    const XML_PATH_CONFIG_IRIS_ORDER_STATUS = 'payment/cardlink_checkout_iris/order_status';
    const XML_PATH_CONFIG_IRIS_CSS_URL = 'payment/cardlink_checkout_iris/css_url';
    const XML_PATH_CONFIG_IRIS_FORCE_STORE_LANGUAGE = 'payment/cardlink_checkout_iris/force_store_language';
    const XML_PATH_CONFIG_IRIS_DISPLAY_PAYMENT_METHOD_LOGO = 'payment/cardlink_checkout_iris/display_payment_method_logo';
    const XML_PATH_CONFIG_IRIS_HOLD_STOCK_ORDER = 'payment/cardlink_checkout_iris/hold_stock_order';
    const XML_PATH_CONFIG_IRIS_CANCELED_ORDER_EXPIRATION = 'payment/cardlink_checkout_iris/canceled_order_expiration';

    // Google Pay configuration paths
    const XML_PATH_CONFIG_GOOGLEPAY_ENABLED = 'payment/cardlink_checkout_googlepay/active';
    const XML_PATH_CONFIG_GOOGLEPAY_ORDER_STATUS = 'payment/cardlink_checkout_googlepay/order_status';
    const XML_PATH_CONFIG_GOOGLEPAY_SHORT_DESCRIPTION = 'payment/cardlink_checkout_googlepay/description';
    const XML_PATH_CONFIG_GOOGLEPAY_BUSINESS_PARTNER = 'payment/cardlink_checkout_googlepay/business_partner';
    const XML_PATH_CONFIG_GOOGLEPAY_TRANSACTION_ENVIRONMENT = 'payment/cardlink_checkout_googlepay/transaction_environment';
    const XML_PATH_CONFIG_GOOGLEPAY_MERCHANT_ID = 'payment/cardlink_checkout_googlepay/merchant_id';
    const XML_PATH_CONFIG_GOOGLEPAY_SHARED_SECRET = 'payment/cardlink_checkout_googlepay/shared_secret';
    const XML_PATH_CONFIG_GOOGLEPAY_DISPLAY_PAYMENT_METHOD_LOGO = 'payment/cardlink_checkout_googlepay/display_payment_method_logo';
    const XML_PATH_CONFIG_GOOGLEPAY_LOG_DEBUG_INFO = 'payment/cardlink_checkout_googlepay/log_debug_info';
    const XML_PATH_CONFIG_GOOGLEPAY_TRANSACTION_TYPE = 'payment/cardlink_checkout_googlepay/transaction_type';
    const XML_PATH_CONFIG_GOOGLEPAY_MPI_PRIVATE_KEY = 'payment/cardlink_checkout_googlepay/mpi_private_key';
    const XML_PATH_CONFIG_GOOGLEPAY_CHECKOUT_IN_IFRAME = 'payment/cardlink_checkout_googlepay/checkout_in_iframe';

    // Apple Pay configuration paths
    const XML_PATH_CONFIG_APPLEPAY_ENABLED = 'payment/cardlink_checkout_applepay/active';
    const XML_PATH_CONFIG_APPLEPAY_ORDER_STATUS = 'payment/cardlink_checkout_applepay/order_status';
    const XML_PATH_CONFIG_APPLEPAY_SHORT_DESCRIPTION = 'payment/cardlink_checkout_applepay/description';
    const XML_PATH_CONFIG_APPLEPAY_BUSINESS_PARTNER = 'payment/cardlink_checkout_applepay/business_partner';
    const XML_PATH_CONFIG_APPLEPAY_TRANSACTION_ENVIRONMENT = 'payment/cardlink_checkout_applepay/transaction_environment';
    const XML_PATH_CONFIG_APPLEPAY_MERCHANT_ID = 'payment/cardlink_checkout_applepay/merchant_id';
    const XML_PATH_CONFIG_APPLEPAY_SHARED_SECRET = 'payment/cardlink_checkout_applepay/shared_secret';
    const XML_PATH_CONFIG_APPLEPAY_DISPLAY_PAYMENT_METHOD_LOGO = 'payment/cardlink_checkout_applepay/display_payment_method_logo';
    const XML_PATH_CONFIG_APPLEPAY_LOG_DEBUG_INFO = 'payment/cardlink_checkout_applepay/log_debug_info';
    const XML_PATH_CONFIG_APPLEPAY_TRANSACTION_TYPE = 'payment/cardlink_checkout_applepay/transaction_type';
    const XML_PATH_CONFIG_APPLEPAY_MPI_PRIVATE_KEY = 'payment/cardlink_checkout_applepay/mpi_private_key';
    const XML_PATH_CONFIG_APPLEPAY_CHECKOUT_IN_IFRAME = 'payment/cardlink_checkout_applepay/checkout_in_iframe';

    // Shared configuration paths
    const XML_PATH_CONFIG_SHARED_PROCESSOR_CERT_TEST = 'payment/cardlink_checkout_shared/processor_certificate_test';
    const XML_PATH_CONFIG_SHARED_PROCESSOR_CERT_PRODUCTION = 'payment/cardlink_checkout_shared/processor_certificate_production';

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var Repository
     */
    private $assetRepo;

    /**
     * @var OrderConfig
     */
    private $orderConfig;

    /**
     * Constructor.
     * 
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param SerializerInterface $serializer
     * @param EncryptorInterface $encryptor
     * @param Repository $assetRepo
     * @param OrderConfig $orderConfig
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        SerializerInterface $serializer,
        EncryptorInterface $encryptor,
        Repository $assetRepo,
        OrderConfig $orderConfig
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->serializer = $serializer;
        $this->encryptor = $encryptor;
        $this->assetRepo = $assetRepo;
        $this->orderConfig = $orderConfig;
    }

    /**
     * Retrieve the current store code.
     * 
     * @return string
     * @throws NoSuchEntityException
     */
    public function getCurrentStoreCode()
    {
        return $this->storeManager->getStore()->getCode();
    }

    /**
     * @param $xmlPath
     * @return mixed
     */
    protected function getStoreConfigValue($xmlPath)
    {
        try {
            $storeCode = $this->getCurrentStoreCode();
        } catch (NoSuchEntityException $e) {
            return false;
        }
        return $this->scopeConfig->getValue($xmlPath, ScopeInterface::SCOPE_STORE, $storeCode);
    }

    /**
     * Get the enable status of the payment method for the current store.
     * 
     * @return bool
     */
    public function isEnabled()
    {
        return (bool) self::getStoreConfigValue(self::XML_PATH_CONFIG_ENABLED);
    }

    /**
     * Returns the configured short description.
     *
     * @return string
     */
    public function getDescription()
    {
        return self::getStoreConfigValue(self::XML_PATH_CONFIG_SHORT_DESCRIPTION);
    }

    /**
     * Returns the configured short description for IRIS.
     *
     * @return string
     */
    public function getIrisDescription()
    {
        return self::getStoreConfigValue(self::XML_PATH_CONFIG_IRIS_SHORT_DESCRIPTION);
    }

    /**
     * Returns the configured business partner.
     *
     * @return string
     */
    public function getBusinessPartner()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_BUSINESS_PARTNER);

        if (!$config) {
            return \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK;
        }

        return $config;
    }

    /**
     * Returns the configured order status after successful payment.
     *
     * @return string
     */
    public function getNewOrderStatus()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_ORDER_STATUS);

        if (!$config) {
            return \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT;
        }

        return $config;
    }

    /**
     * Returns the configured order status after successful payment for IRIS.
     *
     * @return string
     */
    public function getIrisNewOrderStatus()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_IRIS_ORDER_STATUS);

        if (!$config) {
            return \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT;
        }

        return $config;
    }

    /**
     * Get the order state for a given status code.
     *
     * Looks up what state a status belongs to and returns it.
     * Falls back to pending_payment if status is not found.
     *
     * @param string $statusCode The order status code
     * @return string The order state
     */
    public function getStateForStatus(string $statusCode): string
    {
        $statuses = $this->orderConfig->getStateStatuses([
            \Magento\Sales\Model\Order::STATE_NEW,
            \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT,
        ]);

        // Check which state this status belongs to
        foreach ([
            \Magento\Sales\Model\Order::STATE_NEW,
            \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT,
        ] as $state) {
            $stateStatuses = $this->orderConfig->getStateStatuses([$state]);
            if (isset($stateStatuses[$statusCode])) {
                return $state;
            }
        }

        // Default to pending_payment for redirect-based payments
        return \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT;
    }

    /**
     * Get the state and status for the card payment method's configured order status.
     *
     * @return array ['state' => string, 'status' => string]
     */
    public function getNewOrderStateAndStatus(): array
    {
        $status = $this->getNewOrderStatus();
        $state = $this->getStateForStatus($status);
        return ['state' => $state, 'status' => $status];
    }

    /**
     * Get the state and status for the IRIS payment method's configured order status.
     *
     * @return array ['state' => string, 'status' => string]
     */
    public function getIrisNewOrderStateAndStatus(): array
    {
        $status = $this->getIrisNewOrderStatus();
        $state = $this->getStateForStatus($status);
        return ['state' => $state, 'status' => $status];
    }

    /**
     * Returns the configured transaction environment (production/test).
     *
     * @return string
     */
    public function getTransactionEnvironment()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_TRANSACTION_ENVIRONMENT);

        if (!$config) {
            return \Cardlink\Checkout\Model\Config\Source\TransactionEnvironments::PRODUCTION_ENVIRONMENT;
        }

        return $config;
    }

    /**
     * Returns the configured transaction environment (production/test).
     *
     * @return string
     */
    public function getTransactionType()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_TRANSACTION_TYPE);

        if (!$config) {
            return \Cardlink\Checkout\Model\Config\Source\TransactionTypes::TRANSACTION_TYPE_CAPTURE;
        }

        return $config;
    }

    /**
     * Returns the configured merchant ID.
     *
     * @return string
     */
    public function getMerchantId()
    {
        return self::getStoreConfigValue(self::XML_PATH_CONFIG_MERCHANT_ID);
    }

    /**
     * Returns the configured shared secret code.
     *
     * @return string
     */
    public function getSharedSecret()
    {
        return $this->encryptor->decrypt(self::getStoreConfigValue(self::XML_PATH_CONFIG_SHARED_SECRET));
    }

    /**
     * Determines whether the payment method will accept installments.
     *
     * @return bool
     */
    public function acceptsInstallments()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_ACCEPT_INSTALLMENTS);

        if (
            !$config
            || $config == \Cardlink\Checkout\Model\Config\Source\AcceptInstallments::NO_INSTALLMENTS
        ) {
            return false;
        }

        return true;
    }

    /**
     * Returns an array of configured amount ranges and maximum number of installments. 
     *
     * @return array
     */
    public function getInstallmentsConfiguration()
    {
        $ret = array();

        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_ACCEPT_INSTALLMENTS);

        if (!$config || $config == \Cardlink\Checkout\Model\Config\Source\AcceptInstallments::NO_INSTALLMENTS) {
            // Return empty array to signify "no installments".
            return $ret;
        } else if ($config == \Cardlink\Checkout\Model\Config\Source\AcceptInstallments::FIXED_INSTALLMENTS) {
            $maxInstallments = self::getStoreConfigValue(self::XML_PATH_CONFIG_MAX_INSTALLMENTS);

            $ret = [
                [
                    'start_amount' => 0,
                    'end_amount' => 0,
                    'max_installments' => max(1, min(60, $maxInstallments))
                ]
            ];
        } else if ($config == \Cardlink\Checkout\Model\Config\Source\AcceptInstallments::BY_ORDER_AMOUNT) {
            // Retrieve and unserialize the configuration settings on the number of installments determined by order amount range.
            $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_INSTALLMENTS_CONFIGURATION);

            if (isset($config)) {
                try {
                    foreach ($this->serializer->unserialize($config) as $k => $v) {
                        $ret[] = $v;
                    }
                } catch (\Exception $exception) {
                    $this->_logger->error($exception);
                    $config = array(); // Return an array if failed to un-serialize data
                }
            }
        }

        return $ret;
    }

    /**
     * Determines whether the payment method allows tokenization of customer payment information.
     *
     * @return bool
     */
    public function allowsTokenization()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONFIG_ALLOW_TOKENIZATION);
    }

    /**
     * Determines whether the payment gateway must use the language of the store that the order was placed in.
     *
     * @return bool
     */
    public function getForceStoreLanguage()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONFIG_FORCE_STORE_LANGUAGE);
    }

    /**
     * Determines whether the payment gateway must use the language of the store that the order was placed in.
     *
     * @return bool
     */
    public function getIrisForceStoreLanguage()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONFIG_IRIS_FORCE_STORE_LANGUAGE);
    }

    /**
     * Determines that the payment flow will be executed inside an IFRAME at the checkout page.
     *
     * @return bool
     */
    public function doCheckoutInIframe()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONFIG_CHECKOUT_IN_IFRAME);
    }

    /**
     * Determines that the Cardlink logo will be displayed next to the payment method title at the checkout page.
     *
     * @return bool
     */
    public function displayLogoInTitle()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONFIG_DISPLAY_PAYMENT_METHOD_LOGO);
    }

    /**
     * Determines that the IRIS logo will be displayed next to the payment method title at the checkout page.
     *
     * @return bool
     */
    public function displayIrisLogoInTitle()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONFIG_IRIS_DISPLAY_PAYMENT_METHOD_LOGO);
    }

    /**
     * Returns the configured custom CSS URL for use in the Cardlink payment gateway's pages.
     *
     * @return string
     */
    public function getCssUrl()
    {
        return self::getStoreConfigValue(self::XML_PATH_CONFIG_CSS_URL);
    }

    /**
     * Identifies that the payment module should log debugging information. Use sparingly.
     *
     * @return bool
     */
    public function logDebugInfoEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONFIG_LOG_DEBUG_INFO);
    }

    /**
     * Identifies that the Google Pay payment method should log debugging information.
     *
     * @return bool
     */
    public function googlePayLogDebugInfoEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONFIG_GOOGLEPAY_LOG_DEBUG_INFO);
    }

    /**
     * Determines that the Google Pay 3DS authentication flow will be executed inside an IFRAME.
     *
     * @return bool
     */
    public function doGooglePayCheckoutInIframe()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONFIG_GOOGLEPAY_CHECKOUT_IN_IFRAME);
    }

    /**
     * Identifies that the Apple Pay payment method should log debugging information.
     *
     * @return bool
     */
    public function applePayLogDebugInfoEnabled()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONFIG_APPLEPAY_LOG_DEBUG_INFO);
    }

    /**
     * Determines that the Apple Pay 3DS authentication flow will be executed inside an IFRAME.
     *
     * @return bool
     */
    public function doApplePayCheckoutInIframe()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONFIG_APPLEPAY_CHECKOUT_IN_IFRAME);
    }

    /**
     * Returns the URL of the Cardlink logo.
     * 
     * @return string
     */
    public function getLogoUrl()
    {
        // Identify that the Cardlink logo will be appended to the payment method's label.        
        $ret = $this->assetRepo->getUrlWithParams(
            'Cardlink_Checkout::images/cardlink.svg',
            [
                '_secure' => true
            ]
        );
        return $ret;
    }

    /**
     * Returns the configured business partner for IRIS payments.
     *
     * @return string
     */
    public function getIrisBusinessPartner()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_IRIS_BUSINESS_PARTNER);

        if (!$config) {
            return \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_NEXI;
        }

        return $config;
    }

    /**
     * Returns the configured transaction environment (production/test) for IRIS payments.
     *
     * @return string
     */
    public function getIrisTransactionEnvironment()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_IRIS_TRANSACTION_ENVIRONMENT);

        if (!$config) {
            return \Cardlink\Checkout\Model\Config\Source\TransactionEnvironments::PRODUCTION_ENVIRONMENT;
        }

        return $config;
    }

    /**
     * Returns the configured merchant ID for IRIS payments.
     *
     * @return string
     */
    public function getIrisMerchantId()
    {
        return self::getStoreConfigValue(self::XML_PATH_CONFIG_IRIS_MERCHANT_ID);
    }

    /**
     * Returns the configured shared secret code for IRIS payments.
     *
     * @return string
     */
    public function getIrisSharedSecret()
    {
        return $this->encryptor->decrypt(self::getStoreConfigValue(self::XML_PATH_CONFIG_IRIS_SHARED_SECRET));
    }

    /**
     * Returns the URL of the IRIS logo.
     * 
     * @return string
     */
    public function getIrisLogoUrl()
    {
        // Identify that the IRIS logo will be appended to the payment method's label.        
        $ret = $this->assetRepo->getUrlWithParams(
            'Cardlink_Checkout::images/iris.jpg',
            [
                '_secure' => true
            ]
        );
        return $ret;
    }
    /**
     * Determines whether the IRIS payment method is enabled for the current store.
     *
     * @return bool
     */
    public function isIrisEnabled()
    {
        return trim((string) self::getStoreConfigValue(self::XML_PATH_CONFIG_IRIS_ENABLED));
    }


    /**
     * Returns the configured custom CSS URL for use in the Cardlink payment gateway's pages for IRIS.
     *
     * @return string
     */
    public function getIrisCssUrl()
    {
        return self::getStoreConfigValue(self::XML_PATH_CONFIG_IRIS_CSS_URL);
    }


    /**
     * Identifies that the payment module should create order and hold stock or quote.
     *
     * @return string
     */
    public function getHoldStockOrder()
    {
        return self::getStoreConfigValue(self::XML_PATH_CONFIG_HOLD_STOCK_ORDER);
    }

    /**
     * Identifies that the payment module should create order and hold stock or quote for IRIS.
     *
     * @return string
     */
    public function getIrisHoldStockOrder()
    {
        return self::getStoreConfigValue(self::XML_PATH_CONFIG_IRIS_HOLD_STOCK_ORDER);
    }

    /**
     * Identifies that the payment module should create order is enabled.
     *
     * @return bool
     */
    public function isCreateOrderEnabled()
    {
        return (bool) self::getHoldStockOrder();
    }

    /**
     * Identifies that the payment module should create order is enabled for IRIS.
     *
     * @return bool
     */
    public function isIrisCreateOrderEnabled()
    {
        return (bool) self::getIrisHoldStockOrder();
    }

    /**
     * Returns the configured canceled order expiration time.
     *
     * @return string
     */
    public function getCanceledOrderExpiration()
    {
        return self::getStoreConfigValue(self::XML_PATH_CONFIG_CANCELED_ORDER_EXPIRATION);
    }

    /**
     * Returns the configured canceled order expiration time for IRIS.
     *
     * @return string
     */
    public function getIrisCanceledOrderExpiration()
    {
        return self::getStoreConfigValue(self::XML_PATH_CONFIG_IRIS_CANCELED_ORDER_EXPIRATION);
    }

    /**
     * Identifies that the payment module should cancel order after expiration time.
     *
     * @return bool
     */
    public function isCanceledOrderExpirationEnabled()
    {
        $config = self::getCanceledOrderExpiration();
        return !!$config && intval($config) >= 10 && intval($config) <= 60;
    }

    /**
     * Identifies that the payment module should cancel order after expiration time for IRIS.
     *
     * @return bool
     */
    public function isIrisCanceledOrderExpirationEnabled()
    {
        $config = self::getIrisCanceledOrderExpiration();
        return !!$config && intval($config) >= 10 && intval($config) <= 60;
    }

    // ======================== Google Pay Methods ========================

    /**
     * Returns whether the Google Pay payment method is enabled.
     *
     * @return bool
     */
    public function isGooglePayEnabled()
    {
        return (bool) self::getStoreConfigValue(self::XML_PATH_CONFIG_GOOGLEPAY_ENABLED);
    }

    /**
     * Returns the configured description for Google Pay.
     *
     * @return string
     */
    public function getGooglePayDescription()
    {
        return self::getStoreConfigValue(self::XML_PATH_CONFIG_GOOGLEPAY_SHORT_DESCRIPTION);
    }

    /**
     * Returns whether the Google Pay logo should be displayed in the title.
     *
     * @return bool
     */
    public function displayGooglePayLogoInTitle()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONFIG_GOOGLEPAY_DISPLAY_PAYMENT_METHOD_LOGO);
    }

    /**
     * Returns the URL of the Google Pay logo.
     *
     * @return string
     */
    public function getGooglePayLogoUrl()
    {
        return $this->assetRepo->getUrlWithParams(
            'Cardlink_Checkout::images/googlepay.svg',
            ['_secure' => true]
        );
    }

    /**
     * Returns the configured business partner for Google Pay.
     *
     * @return string
     */
    public function getGooglePayBusinessPartner()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_GOOGLEPAY_BUSINESS_PARTNER);
        if (!$config) {
            return \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK;
        }
        return $config;
    }

    /**
     * Returns the configured transaction environment for Google Pay.
     *
     * @return string
     */
    public function getGooglePayTransactionEnvironment()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_GOOGLEPAY_TRANSACTION_ENVIRONMENT);
        if (!$config) {
            return \Cardlink\Checkout\Model\Config\Source\TransactionEnvironments::PRODUCTION_ENVIRONMENT;
        }
        return $config;
    }

    /**
     * Returns the configured merchant ID for Google Pay.
     *
     * @return string
     */
    public function getGooglePayMerchantId()
    {
        return self::getStoreConfigValue(self::XML_PATH_CONFIG_GOOGLEPAY_MERCHANT_ID);
    }

    /**
     * Returns the configured shared secret for Google Pay.
     *
     * @return string
     */
    public function getGooglePaySharedSecret()
    {
        return $this->encryptor->decrypt(self::getStoreConfigValue(self::XML_PATH_CONFIG_GOOGLEPAY_SHARED_SECRET));
    }

    /**
     * Returns the configured MPI private key (PEM) for Google Pay 3DS v4.0 signing.
     * Empty/null when not configured — the sign endpoint will fall back to HMAC.
     *
     * @return string|null
     */
    public function getGooglePayMpiPrivateKey()
    {
        $val = self::getStoreConfigValue(self::XML_PATH_CONFIG_GOOGLEPAY_MPI_PRIVATE_KEY);
        return $val ? trim($val) : null;
    }

    /**
     * Returns the Google Pay Direct Script URL based on environment and business partner.
     *
     * @return string
     */
    public function getGooglePayDirectScriptUrl()
    {
        $businessPartner = $this->getGooglePayBusinessPartner();
        $environment = $this->getGooglePayTransactionEnvironment();

        if ($environment == \Cardlink\Checkout\Model\Config\Source\TransactionEnvironments::PRODUCTION_ENVIRONMENT) {
            switch ($businessPartner) {
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce.cardlink.gr/vpos/js/googlepaydirect.js';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_NEXI:
                    return 'https://www.alphaecommerce.gr/vpos/js/googlepaydirect.js';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://vpos.eurocommerce.gr/vpos/js/googlepaydirect.js';
            }
        } else {
            switch ($businessPartner) {
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce-test.cardlink.gr/vpos/js/googlepaydirect.js';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_NEXI:
                    return 'https://alphaecommerce-test.cardlink.gr/vpos/js/googlepaydirect.js';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://eurocommerce-test.cardlink.gr/vpos/js/googlepaydirect.js';
            }
        }
        return '';
    }

    /**
     * Returns the Google Pay VPOS Gateway URL based on environment and business partner.
     *
     * @return string
     */
    public function getGooglePayGatewayUrl()
    {
        $businessPartner = $this->getGooglePayBusinessPartner();
        $environment = $this->getGooglePayTransactionEnvironment();

        if ($environment == \Cardlink\Checkout\Model\Config\Source\TransactionEnvironments::PRODUCTION_ENVIRONMENT) {
            switch ($businessPartner) {
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce.cardlink.gr/vpos/xmlpayvpos';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_NEXI:
                    return 'https://www.alphaecommerce.gr/vpos/xmlpayvpos';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://vpos.eurocommerce.gr/vpos/xmlpayvpos';
            }
        } else {
            switch ($businessPartner) {
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce-test.cardlink.gr/vpos/xmlpayvpos';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_NEXI:
                    return 'https://alphaecommerce-test.cardlink.gr/vpos/xmlpayvpos';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://eurocommerce-test.cardlink.gr/vpos/xmlpayvpos';
            }
        }
        return '';
    }

    /**
     * Returns the configured Google Pay order status.
     *
     * @return string
     */
    public function getGooglePayNewOrderStatus()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_GOOGLEPAY_ORDER_STATUS);
        if (!$config) {
            return \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT;
        }
        return $config;
    }

    /**
     * Get the state and status for Google Pay.
     *
     * @return array
     */
    public function getGooglePayNewOrderStateAndStatus(): array
    {
        $status = $this->getGooglePayNewOrderStatus();
        $state = $this->getStateForStatus($status);
        return ['state' => $state, 'status' => $status];
    }

    /**
     * Returns the transaction type for Google Pay.
     *
     * @return string
     */
    public function getGooglePayTransactionType()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_GOOGLEPAY_TRANSACTION_TYPE);
        if (!$config) {
            return \Cardlink\Checkout\Model\Config\Source\TransactionTypes::TRANSACTION_TYPE_CAPTURE;
        }
        return $config;
    }

    /**
     * Returns the Google Pay MPI (3D-Secure) URL based on environment and business partner.
     *
     * The MPI URL is the endpoint for the Merchant Plug-In server that handles
     * 3D-Secure authentication. Pattern: {vposDomain}/mdpaympi/MerchantServer
     *
     * @return string
     */
    public function getGooglePayMpiUrl()
    {
        $businessPartner = $this->getGooglePayBusinessPartner();
        $environment = $this->getGooglePayTransactionEnvironment();

        if ($environment == \Cardlink\Checkout\Model\Config\Source\TransactionEnvironments::PRODUCTION_ENVIRONMENT) {
            switch ($businessPartner) {
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce.cardlink.gr/mdpaympi/MerchantServer';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_NEXI:
                    return 'https://www.alphaecommerce.gr/mdpaympi/MerchantServer';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://vpos.eurocommerce.gr/mdpaympi/MerchantServer';
            }
        } else {
            switch ($businessPartner) {
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce-test.cardlink.gr/mdpaympi/MerchantServer';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_NEXI:
                    return 'https://alphaecommerce-test.cardlink.gr/mdpaympi/MerchantServer';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://eurocommerce-test.cardlink.gr/mdpaympi/MerchantServer';
            }
        }
        return '';
    }

    /**
     * Returns the Google Pay 3DS success callback URL base.
     *
     * @return string
     */
    public function getGooglePay3dsSuccessUrl()
    {
        return '';  // Will be set by the config provider using UrlInterface
    }

    /**
     * Returns the Google Pay 3DS failure callback URL base.
     *
     * @return string
     */
    public function getGooglePay3dsFailureUrl()
    {
        return '';  // Will be set by the config provider using UrlInterface
    }

    // =====================================================================
    //  APPLE PAY CONFIGURATION METHODS
    // =====================================================================

    /**
     * Returns whether Apple Pay is enabled.
     *
     * @return bool
     */
    public function isApplePayEnabled()
    {
        return (bool) self::getStoreConfigValue(self::XML_PATH_CONFIG_APPLEPAY_ENABLED);
    }

    /**
     * Returns the configured description for Apple Pay.
     *
     * @return string
     */
    public function getApplePayDescription()
    {
        return self::getStoreConfigValue(self::XML_PATH_CONFIG_APPLEPAY_SHORT_DESCRIPTION);
    }

    /**
     * Returns whether the Apple Pay logo should be displayed in the title.
     *
     * @return bool
     */
    public function displayApplePayLogoInTitle()
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONFIG_APPLEPAY_DISPLAY_PAYMENT_METHOD_LOGO);
    }

    /**
     * Returns the URL of the Apple Pay logo.
     *
     * @return string
     */
    public function getApplePayLogoUrl()
    {
        return $this->assetRepo->getUrlWithParams(
            'Cardlink_Checkout::images/applepay.svg',
            ['_secure' => true]
        );
    }

    /**
     * Returns the configured business partner for Apple Pay.
     *
     * @return string
     */
    public function getApplePayBusinessPartner()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_APPLEPAY_BUSINESS_PARTNER);
        if (!$config) {
            return \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK;
        }
        return $config;
    }

    /**
     * Returns the configured transaction environment for Apple Pay.
     *
     * @return string
     */
    public function getApplePayTransactionEnvironment()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_APPLEPAY_TRANSACTION_ENVIRONMENT);
        if (!$config) {
            return \Cardlink\Checkout\Model\Config\Source\TransactionEnvironments::PRODUCTION_ENVIRONMENT;
        }
        return $config;
    }

    /**
     * Returns the configured merchant ID for Apple Pay.
     *
     * @return string
     */
    public function getApplePayMerchantId()
    {
        return self::getStoreConfigValue(self::XML_PATH_CONFIG_APPLEPAY_MERCHANT_ID);
    }

    /**
     * Returns the configured shared secret for Apple Pay.
     *
     * @return string
     */
    public function getApplePaySharedSecret()
    {
        return $this->encryptor->decrypt(self::getStoreConfigValue(self::XML_PATH_CONFIG_APPLEPAY_SHARED_SECRET));
    }

    /**
     * Returns the configured MPI private key (PEM) for Apple Pay 3DS v4.0 signing.
     * Empty/null when not configured — the sign endpoint will fall back to HMAC.
     *
     * @return string|null
     */
    public function getApplePayMpiPrivateKey()
    {
        $val = self::getStoreConfigValue(self::XML_PATH_CONFIG_APPLEPAY_MPI_PRIVATE_KEY);
        return $val ? trim($val) : null;
    }

    /**
     * Returns the Apple Pay Direct Script URL based on environment and business partner.
     *
     * @return string
     */
    public function getApplePayDirectScriptUrl()
    {
        $businessPartner = $this->getApplePayBusinessPartner();
        $environment = $this->getApplePayTransactionEnvironment();

        if ($environment == \Cardlink\Checkout\Model\Config\Source\TransactionEnvironments::PRODUCTION_ENVIRONMENT) {
            switch ($businessPartner) {
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce.cardlink.gr/vpos/js/applepaydirect.js';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_NEXI:
                    return 'https://www.alphaecommerce.gr/vpos/js/applepaydirect.js';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://vpos.eurocommerce.gr/vpos/js/applepaydirect.js';
            }
        } else {
            switch ($businessPartner) {
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce-test.cardlink.gr/vpos/js/applepaydirect.js';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_NEXI:
                    return 'https://alphaecommerce-test.cardlink.gr/vpos/js/applepaydirect.js';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://eurocommerce-test.cardlink.gr/vpos/js/applepaydirect.js';
            }
        }
        return '';
    }

    /**
     * Returns the Apple Pay VPOS Gateway URL based on environment and business partner.
     *
     * @return string
     */
    public function getApplePayGatewayUrl()
    {
        $businessPartner = $this->getApplePayBusinessPartner();
        $environment = $this->getApplePayTransactionEnvironment();

        if ($environment == \Cardlink\Checkout\Model\Config\Source\TransactionEnvironments::PRODUCTION_ENVIRONMENT) {
            switch ($businessPartner) {
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce.cardlink.gr/vpos/xmlpayvpos';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_NEXI:
                    return 'https://www.alphaecommerce.gr/vpos/xmlpayvpos';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://vpos.eurocommerce.gr/vpos/xmlpayvpos';
            }
        } else {
            switch ($businessPartner) {
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce-test.cardlink.gr/vpos/xmlpayvpos';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_NEXI:
                    return 'https://alphaecommerce-test.cardlink.gr/vpos/xmlpayvpos';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://eurocommerce-test.cardlink.gr/vpos/xmlpayvpos';
            }
        }
        return '';
    }

    /**
     * Returns the configured Apple Pay order status.
     *
     * @return string
     */
    public function getApplePayNewOrderStatus()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_APPLEPAY_ORDER_STATUS);
        if (!$config) {
            return \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT;
        }
        return $config;
    }

    /**
     * Get the state and status for Apple Pay.
     *
     * @return array
     */
    public function getApplePayNewOrderStateAndStatus(): array
    {
        $status = $this->getApplePayNewOrderStatus();
        $state = $this->getStateForStatus($status);
        return ['state' => $state, 'status' => $status];
    }

    /**
     * Returns the transaction type for Apple Pay.
     *
     * @return string
     */
    public function getApplePayTransactionType()
    {
        $config = self::getStoreConfigValue(self::XML_PATH_CONFIG_APPLEPAY_TRANSACTION_TYPE);
        if (!$config) {
            return \Cardlink\Checkout\Model\Config\Source\TransactionTypes::TRANSACTION_TYPE_CAPTURE;
        }
        return $config;
    }

    /**
     * Returns the Apple Pay MPI (3D-Secure) URL based on environment and business partner.
     *
     * @return string
     */
    public function getApplePayMpiUrl()
    {
        $businessPartner = $this->getApplePayBusinessPartner();
        $environment = $this->getApplePayTransactionEnvironment();

        if ($environment == \Cardlink\Checkout\Model\Config\Source\TransactionEnvironments::PRODUCTION_ENVIRONMENT) {
            switch ($businessPartner) {
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce.cardlink.gr/mdpaympi/MerchantServer';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_NEXI:
                    return 'https://www.alphaecommerce.gr/mdpaympi/MerchantServer';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://vpos.eurocommerce.gr/mdpaympi/MerchantServer';
            }
        } else {
            switch ($businessPartner) {
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_CARDLINK:
                    return 'https://ecommerce-test.cardlink.gr/mdpaympi/MerchantServer';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_NEXI:
                    return 'https://alphaecommerce-test.cardlink.gr/mdpaympi/MerchantServer';
                case \Cardlink\Checkout\Model\Config\Source\BusinessPartners::BUSINESS_PARTNER_WORLDLINE:
                    return 'https://eurocommerce-test.cardlink.gr/mdpaympi/MerchantServer';
            }
        }
        return '';
    }

    /**
     * Returns the configured processor certificate (PEM) for the test/sandbox environment.
     *
     * @return string|null
     */
    public function getProcessorCertificateTest()
    {
        $val = self::getStoreConfigValue(self::XML_PATH_CONFIG_SHARED_PROCESSOR_CERT_TEST);
        return !empty($val) ? trim($val) : null;
    }

    /**
     * Returns the configured processor certificate (PEM) for the production environment.
     *
     * @return string|null
     */
    public function getProcessorCertificateProduction()
    {
        $val = self::getStoreConfigValue(self::XML_PATH_CONFIG_SHARED_PROCESSOR_CERT_PRODUCTION);
        return !empty($val) ? trim($val) : null;
    }

    /**
     * Returns the processor certificate (PEM) for the given transaction environment.
     *
     * @param string $environment 'production' or 'sandbox'/'test'
     * @return string|null
     */
    public function getProcessorCertificate($environment = null)
    {
        if ($environment === 'production') {
            return $this->getProcessorCertificateProduction();
        }
        return $this->getProcessorCertificateTest();
    }
}