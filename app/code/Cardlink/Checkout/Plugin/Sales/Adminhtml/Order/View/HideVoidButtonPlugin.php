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
use Magento\Sales\Block\Adminhtml\Order\View as OrderView;
use Magento\Sales\Model\Order\Payment;

class HideVoidButtonPlugin
{
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
     * @param Data $dataHelper
     * @param Logger $logger
     * @param GatewayOrderId $gatewayOrderId
     */
    public function __construct(
        Data $dataHelper,
        Logger $logger,
        GatewayOrderId $gatewayOrderId
    ) {
        $this->dataHelper = $dataHelper;
        $this->logger = $logger;
        $this->gatewayOrderId = $gatewayOrderId;
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

        $api = $this->createApiClient($methodCode);
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
            return false;
        }

        return $statusResponse->canVoid();
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
