<?php

namespace Cardlink\Checkout\Observer;

use Psr\Log\LoggerInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;

class SaveCustomFieldsToQuotePayment extends AbstractDataAssignObserver
{
    /**
     * @param LoggerInterface 
     */
    private $logger;

    /**
     * @param State 
     */
    private $state;

    /**
     * @var array
     */
    private $customFields = [
        'cardlink_tokenize_card',
        'cardlink_stored_token',
        'cardlink_installments'
    ];

    public function __construct()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->state = $objectManager->get(\Magento\Framework\App\State::class);
        $this->logger = $objectManager->get(\Cardlink\Checkout\Logger\Logger::class);
    }

    public function execute(Observer $observer)
    {
        if ($this->state->getAreaCode() != \Magento\Framework\App\Area::AREA_ADMINHTML) {
            $data = $this->readDataArgument($observer);

            if ($data != null) {
                $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);

                if (!is_array($additionalData)) {
                    return;
                }

                $paymentModel = $this->readPaymentModelArgument($observer);

                if ($paymentModel != null) {
                    foreach ($this->customFields as $fieldName) {
                        if (isset($additionalData[$fieldName])) {
                            $paymentModel->setData($fieldName, $additionalData[$fieldName]);
                        }
                    }
                }
            }
        }
    }
}

