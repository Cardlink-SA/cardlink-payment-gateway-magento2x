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
 * Configuration provider for Google Pay payment method.
 *
 * @author Cardlink S.A.
 */
class SettingsGooglePay implements ConfigProviderInterface
{
    public const CODE = 'cardlink_checkout_googlepay';

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
                    'enable' => $this->dataHelper->isGooglePayEnabled(),
                    'description' => $this->dataHelper->getGooglePayDescription(),
                    'displayLogoInTitle' => $this->dataHelper->displayGooglePayLogoInTitle(),
                    'logoUrl' => $this->dataHelper->getGooglePayLogoUrl(),
                    'googlePayDirectScriptUrl' => $this->dataHelper->getGooglePayDirectScriptUrl(),
                    'googlePayGatewayMerchantId' => $this->dataHelper->getGooglePayMerchantId(),
                    'googlePayGatewayUrl' => $this->dataHelper->getGooglePayGatewayUrl(),
                    'scriptInfoUrl' => $this->urlBuilder->getUrl('cardlink_checkout/payment/googlepayinit', ['_secure' => true]),
                    'walletUrl' => $this->urlBuilder->getUrl('cardlink_checkout/payment/googlepaywallet', ['_secure' => true]),
                    'threeDsUrl' => $this->urlBuilder->getUrl('cardlink_checkout/payment/googlepay3ds', ['_secure' => true]),
                    'createXidUrl' => $this->urlBuilder->getUrl('cardlink_checkout/payment/googlepaycreatexid', ['_secure' => true]),
                    'signDataUrl' => $this->urlBuilder->getUrl('cardlink_checkout/payment/googlepaysigndata', ['_secure' => true]),
                    'mpiUrl' => $this->dataHelper->getGooglePayMpiUrl(),
                    'threeDsSuccessUrl' => $this->urlBuilder->getUrl('cardlink_checkout/payment/googlepay3ds', ['_secure' => true, 'result' => 'success']),
                    'threeDsFailureUrl' => $this->urlBuilder->getUrl('cardlink_checkout/payment/googlepay3ds', ['_secure' => true, 'result' => 'failure']),
                    'checkoutInIFrame' => $this->dataHelper->doGooglePayCheckoutInIframe(),
                ]
            ]
        ];
    }
}
