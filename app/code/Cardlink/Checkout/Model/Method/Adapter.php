<?php

namespace Cardlink\Checkout\Model\Method;

use Magento\Framework\Event\ManagerInterface;
use Magento\Payment\Gateway\Command\CommandManagerInterface;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Gateway\Config\ValueHandlerPoolInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactory;
use Magento\Payment\Gateway\Validator\ValidatorPoolInterface;
use Magento\Quote\Api\Data\CartInterface;
use Psr\Log\LoggerInterface;

/**
 * Extends the framework Adapter to reliably enforce allowspecific/specificcountry
 * restrictions regardless of Magento version.
 *
 * The explicit constructor mirrors the parent's signature so that Magento's DI
 * compiler on all 2.3.x / 2.4.x versions can correctly inject the scalar
 * arguments ($code, $formBlockType, $infoBlockType) for each virtual type.
 * Without it the DI compiler may fail to follow the inheritance chain and leave
 * $code empty, causing "Payment model name is not provided in config!".
 */
class Adapter extends \Magento\Payment\Model\Method\Adapter
{
    public function __construct(
        ManagerInterface $eventManager,
        ValueHandlerPoolInterface $valueHandlerPool,
        PaymentDataObjectFactory $paymentDataObjectFactory,
        $code,
        $formBlockType,
        $infoBlockType,
        CommandPoolInterface $commandPool = null,
        ValidatorPoolInterface $validatorPool = null,
        CommandManagerInterface $commandExecutor = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct(
            $eventManager,
            $valueHandlerPool,
            $paymentDataObjectFactory,
            $code,
            $formBlockType,
            $infoBlockType,
            $commandPool,
            $validatorPool,
            $commandExecutor,
            $logger
        );
    }

    public function canUseForCountry($country)
    {
        if ((int)$this->getConfigData('allowspecific') === 1) {
            $raw = (string)($this->getConfigData('specificcountry') ?? '');
            if ($raw === '') {
                return false;
            }
            $allowed = explode(',', $raw);
            if (!in_array($country, $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    public function isAvailable(?CartInterface $quote = null)
    {
        if ($quote !== null) {
            $country = $quote->getBillingAddress()->getCountryId();
            if (!empty($country) && !$this->canUseForCountry($country)) {
                return false;
            }
        }
        return parent::isAvailable($quote);
    }
}
