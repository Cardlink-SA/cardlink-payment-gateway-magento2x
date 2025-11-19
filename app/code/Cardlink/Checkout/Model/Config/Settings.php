<?php

namespace Cardlink\Checkout\Model\Config;

use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Helper\Tokenization;
use Cardlink\Checkout\Logger\Logger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Customer\Model\Session;
use Magento\Customer\Model\SessionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Payment\Model\CcConfigProvider;

/**
 * Configuration provider class used to set front-end payment method configuration data object. 
 * 
 * @author Cardlink S.A.
 */
class Settings implements ConfigProviderInterface
{
    public const CODE = 'cardlink_checkout';

    /**
     * @var Logger
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
     * @var SessionFactory
     */
    private $sessionFactory;
    /**
     * @var UrlInterface
     */
    private $urlBuilder;
    /**
     * @var Repository
     */
    private $assetRepo;
    /**
     * @var Data
     */
    private $dataHelper;
    /**
     * @var Tokenization
     */
    private $tokenizationHelper;

    /**
     * @var CcConfigProvider
     */
    private $iconsProvider;

    /**
     * Constructor.
     * 
     * @param Logger $logger,
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param SessionFactory $sessionFactory
     * @param UrlInterface $urlBuilder
     * @param Repository $assetRepo
     * @param Data $dataHelper
     * @param Tokenization $tokenizationHelper
     * @param CcConfigProvider $iconsProvider
     */
    public function __construct(
        Logger $logger,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        SessionFactory $sessionFactory,
        UrlInterface $urlBuilder,
        Repository $assetRepo,
        Data $dataHelper,
        Tokenization $tokenizationHelper,
        CcConfigProvider $iconsProvider
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->sessionFactory = $sessionFactory;
        $this->urlBuilder = $urlBuilder;
        $this->assetRepo = $assetRepo;
        $this->dataHelper = $dataHelper;
        $this->tokenizationHelper = $tokenizationHelper;
        $this->iconsProvider = $iconsProvider;
    }

    /**
     * Returns an array of configuration data used by the front-end at the checkout page.
     * @return array
     */
    public function getConfig()
    {
        /**
         * @var Session $customer
         */
        $customer = $this->sessionFactory->create();
        $customerId = $customer->getCustomerId();
        $merchantId = $this->dataHelper->getMerchantId();

        return [
            'payment' => [
                self::CODE => [
                    'enable' => $this->dataHelper->isEnabled(),
                    'description' => $this->dataHelper->getDescription(),
                    'acceptsInstallments' => $this->dataHelper->acceptsInstallments(),
                    'installmentsConfiguration' => $this->dataHelper->getInstallmentsConfiguration(),
                    'allowsTokenization' => $this->dataHelper->allowsTokenization() && $customerId,
                    'displayLogoInTitle' => $this->dataHelper->displayLogoInTitle(),
                    'checkoutInIFrame' => $this->dataHelper->doCheckoutInIframe(),
                    'logoUrl' => $this->dataHelper->getLogoUrl(),
                    'storedTokens' => $this->tokenizationHelper->getCustomerPaymentTokens($customerId, true, false),
                    'cardTypeData' => $this->iconsProvider->getIcons()
                ]
            ]
        ];
    }
}
