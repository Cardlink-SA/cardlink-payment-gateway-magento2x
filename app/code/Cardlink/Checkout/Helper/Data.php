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
     * Constructor.
     * 
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param SerializerInterface $serializer
     * @param EncryptorInterface $encryptor
     * @param Repository $assetRepo
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        SerializerInterface $serializer,
        EncryptorInterface $encryptor,
        Repository $assetRepo
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->serializer = $serializer;
        $this->encryptor = $encryptor;
        $this->assetRepo = $assetRepo;
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
        return (bool)self::getStoreConfigValue(self::XML_PATH_CONFIG_ENABLED);
    }

    /**
     * Returns the configured shared secret code.
     *
     * @return string
     */
    public function getDescription()
    {
        return self::getStoreConfigValue(self::XML_PATH_CONFIG_SHORT_DESCRIPTION);
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
    public function  getTransactionType()
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
}
