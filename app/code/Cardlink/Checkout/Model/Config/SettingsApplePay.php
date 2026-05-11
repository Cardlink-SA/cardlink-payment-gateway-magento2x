<?php

namespace Cardlink\Checkout\Model\Config;

use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Logger\Logger;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Customer\Model\SessionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Configuration provider for Apple Pay payment method.
 *
 * @author Cardlink S.A.
 */
class SettingsApplePay implements ConfigProviderInterface
{
    public const CODE = 'cardlink_checkout_applepay';

    protected $logger;
    protected $scopeConfig;
    protected $storeManager;
    private $sessionFactory;
    private $urlBuilder;
    private $assetRepo;
    private $dataHelper;

    public function __construct(
        Logger $logger,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        SessionFactory $sessionFactory,
        UrlInterface $urlBuilder,
        Repository $assetRepo,
        Data $dataHelper
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->sessionFactory = $sessionFactory;
        $this->urlBuilder = $urlBuilder;
        $this->assetRepo = $assetRepo;
        $this->dataHelper = $dataHelper;
    }

    public function getConfig()
    {
        return [
            'payment' => [
                self::CODE => [
                    'enable' => $this->dataHelper->isApplePayEnabled(),
                    'description' => $this->dataHelper->getApplePayDescription(),
                    'displayLogoInTitle' => $this->dataHelper->displayApplePayLogoInTitle(),
                    'logoUrl' => $this->dataHelper->getApplePayLogoUrl(),
                    'applePayDirectScriptUrl' => $this->dataHelper->getApplePayDirectScriptUrl(),
                    'applePayGatewayMerchantId' => $this->dataHelper->getApplePayMerchantId(),
                    'applePayGatewayUrl' => $this->dataHelper->getApplePayGatewayUrl(),
                    'scriptInfoUrl' => $this->urlBuilder->getUrl('cardlink_checkout/payment/applepayinit', ['_secure' => true]),
                    'walletUrl' => $this->urlBuilder->getUrl('cardlink_checkout/payment/applepaywallet', ['_secure' => true]),
                    'threeDsUrl' => $this->urlBuilder->getUrl('cardlink_checkout/payment/applepay3ds', ['_secure' => true]),
                    'createXidUrl' => $this->urlBuilder->getUrl('cardlink_checkout/payment/applepaycreatexid', ['_secure' => true]),
                    'signDataUrl' => $this->urlBuilder->getUrl('cardlink_checkout/payment/applepaysigndata', ['_secure' => true]),
                    'mpiUrl' => $this->dataHelper->getApplePayMpiUrl(),
                    'threeDsSuccessUrl' => $this->urlBuilder->getUrl('cardlink_checkout/payment/applepay3ds', ['_secure' => true, 'result' => 'success']),
                    'threeDsFailureUrl' => $this->urlBuilder->getUrl('cardlink_checkout/payment/applepay3ds', ['_secure' => true, 'result' => 'failure']),
                    'checkoutInIFrame' => $this->dataHelper->doApplePayCheckoutInIframe(),
                ]
            ]
        ];
    }
}
