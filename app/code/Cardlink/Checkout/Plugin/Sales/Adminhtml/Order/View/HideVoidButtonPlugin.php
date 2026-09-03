<?php

namespace Cardlink\Checkout\Plugin\Sales\Adminhtml\Order\View;

use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Lib\CardlinkXmlApi;
use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Model\Config\Settings;
use Cardlink\Checkout\Model\Config\SettingsApplePay;
use Cardlink\Checkout\Model\Config\SettingsGooglePay;
use Cardlink\Checkout\Model\Config\Source\TransactionEnvironments;
use Cardlink\Checkout\Service\GatewayOrderId;
use Magento\Framework\App\CacheInterface;
use Magento\Sales\Block\Adminhtml\Order\View as OrderView;
use Magento\Sales\Model\Order\Payment;

class HideVoidButtonPlugin
{
    /**
     * Cache key prefix for a resolved voidability answer.
     */
    const CACHE_KEY_PREFIX = 'cardlink_voidable_';

    /**
     * Cache tag, so the answers can be flushed on their own.
     */
    const CACHE_TAG = 'CARDLINK_VOIDABILITY';

    /**
     * How long an answer from the gateway stays usable, in seconds.
     */
    const CACHE_LIFETIME = 300;

    /**
     * How long an unanswered query is remembered, in seconds. Kept short, but long
     * enough that an unreachable gateway costs one timeout rather than one per view.
     */
    const FAILURE_CACHE_LIFETIME = 60;

    /**
     * Seconds to wait for the status call. The admin order page renders synchronously,
     * so the library's 60 second default would stall the page for a full minute.
     */
    const API_TIMEOUT = 5;

    /**
     * @var Data
     */
    private $dataHelper;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var GatewayOrderId
     */
    private $gatewayOrderId;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @param Data $dataHelper
     * @param Logger $logger
     * @param GatewayOrderId $gatewayOrderId
     * @param CacheInterface $cache
     */
    public function __construct(
        Data $dataHelper,
        Logger $logger,
        GatewayOrderId $gatewayOrderId,
        CacheInterface $cache
    ) {
        $this->dataHelper = $dataHelper;
        $this->logger = $logger;
        $this->gatewayOrderId = $gatewayOrderId;
        $this->cache = $cache;
    }

    /**
     * Hide the Void button when a Cardlink transaction is no longer voidable.
     *
     * @param OrderView $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterSetLayout(OrderView $subject, $result)
    {
        try {
            $order = $subject->getOrder();
            if (!$order || !$order->getId()) {
                return $result;
            }

            $payment = $order->getPayment();
            if (!$payment) {
                return $result;
            }

            $methodCode = (string) $payment->getMethod();
            if (!$this->isSupportedMethod($methodCode)) {
                return $result;
            }

            // Magento adds the button only for an order whose authorization transaction
            // is still open, so for every other order there is no button to hide - and no
            // reason to spend a synchronous gateway round trip establishing that. This is
            // the common case: once an order is captured, cancelled or completed, the page
            // renders without touching the network at all.
            if (!$order->canVoidPayment()) {
                return $result;
            }

            $incrementId = (string) $order->getIncrementId();
            if (!$this->isVoidable($payment, $methodCode, $incrementId)) {
                $subject->removeButton('void_payment');
                if ($this->dataHelper->logDebugInfoEnabled()) {
                    $this->logger->debug(sprintf(
                        'Void button hidden for order %s (method: %s) because settlement status is no longer voidable.',
                        $incrementId,
                        $methodCode
                    ));
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to evaluate void availability in admin order view: ' . $e->getMessage());
            $subject->removeButton('void_payment');
        }

        return $result;
    }

    /**
     * @param string $methodCode
     * @return bool
     */
    private function isSupportedMethod(string $methodCode): bool
    {
        return in_array($methodCode, [
            Settings::CODE,
            SettingsGooglePay::CODE,
            SettingsApplePay::CODE,
        ], true);
    }

    /**
     * @param Payment $payment
     * @param string $methodCode
     * @param string $incrementId
     * @return bool
     */
    private function isVoidable(Payment $payment, string $methodCode, string $incrementId): bool
    {
        // The gateway only recognises the orderid sent with the original payment request,
        // which is never the Magento increment ID. Without it there is nothing to query,
        // so leave the button alone rather than querying a reference that cannot match.
        $orderId = $this->gatewayOrderId->resolveForPayment($payment);
        if ($orderId === null) {
            return true;
        }

        $cacheKey = self::CACHE_KEY_PREFIX . hash('sha256', $methodCode . '|' . $orderId);
        $cached = $this->cache->load($cacheKey);

        if ($cached !== false && $cached !== null) {
            return $cached === '1';
        }

        $api = $this->createApiClient($methodCode);
        $api->setTimeout(self::API_TIMEOUT);

        if ($this->dataHelper->logDebugInfoEnabled()) {
            $api->setDebug(true, function ($message) {
                $this->logger->debug('[CardlinkXmlApi][AdminVoidCheck] ' . $message);
            });
        }

        $statusResponse = $api->status($orderId);
        if (!$statusResponse->isSuccess()) {
            $this->logger->warning(sprintf(
                'Could not determine voidability for order %s. Hiding Void button as a safety measure. Error: %s',
                $incrementId,
                $statusResponse->getError()
            ));

            $this->cache->save('0', $cacheKey, [self::CACHE_TAG], self::FAILURE_CACHE_LIFETIME);

            return false;
        }

        $voidable = $statusResponse->canVoid();

        // Both answers are safe to keep briefly: a stale "voidable" only means the gateway
        // rejects the void with its own message, and a stale "not voidable" leaves the
        // refund path and the merchant portal untouched.
        $this->cache->save($voidable ? '1' : '0', $cacheKey, [self::CACHE_TAG], self::CACHE_LIFETIME);

        return $voidable;
    }

    /**
     * @param string $methodCode
     * @return CardlinkXmlApi
     */
    private function createApiClient(string $methodCode): CardlinkXmlApi
    {
        switch ($methodCode) {
            case SettingsGooglePay::CODE:
                $merchantId = (string) $this->dataHelper->getGooglePayMerchantId();
                $sharedSecret = (string) $this->dataHelper->getGooglePaySharedSecret();
                $businessPartner = (string) $this->dataHelper->getGooglePayBusinessPartner();
                $environment = (string) $this->dataHelper->getGooglePayTransactionEnvironment();
                break;

            case SettingsApplePay::CODE:
                $merchantId = (string) $this->dataHelper->getApplePayMerchantId();
                $sharedSecret = (string) $this->dataHelper->getApplePaySharedSecret();
                $businessPartner = (string) $this->dataHelper->getApplePayBusinessPartner();
                $environment = (string) $this->dataHelper->getApplePayTransactionEnvironment();
                break;

            case Settings::CODE:
            default:
                $merchantId = (string) $this->dataHelper->getMerchantId();
                $sharedSecret = (string) $this->dataHelper->getSharedSecret();
                $businessPartner = (string) $this->dataHelper->getBusinessPartner();
                $environment = (string) $this->dataHelper->getTransactionEnvironment();
                break;
        }

        return new CardlinkXmlApi(
            $merchantId,
            $sharedSecret,
            $businessPartner,
            $this->mapEnvironment($environment)
        );
    }

    /**
     * @param string $environment
     * @return string
     */
    private function mapEnvironment(string $environment): string
    {
        if ($environment === TransactionEnvironments::PRODUCTION_ENVIRONMENT) {
            return CardlinkXmlApi::ENV_PRODUCTION;
        }

        return CardlinkXmlApi::ENV_SANDBOX;
    }
}
