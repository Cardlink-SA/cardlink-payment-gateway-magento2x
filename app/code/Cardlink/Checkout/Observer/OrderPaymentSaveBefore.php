<?php

namespace Cardlink\Checkout\Observer;

use Psr\Log\LoggerInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Webapi\Controller\Rest\InputParamsResolver;

/**
 * Observer class to copy quote custom fields to the order payment object.
 * 
 * @author Cardlink S.A.
 */
class OrderPaymentSaveBefore implements \Magento\Framework\Event\ObserverInterface
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
     * @param OrderInterface
     */
    private $order;

    /**
     * @param CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @param InputParamsResolver
     */
    private $inputParamsResolver;

    /**
     * @var array
     */
    private $customFields = [
        'cardlink_tokenize_card',
        'cardlink_stored_token',
        'cardlink_installments'
    ];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger
     * @param State $state
     * @param OrderInterface $order
     * @param CartRepositoryInterface $quoteRepository
     * @param InputParamsResolver $inputParamsResolver
     */
    public function __construct(
        LoggerInterface $logger,
        State $state,
        OrderInterface $order,
        CartRepositoryInterface $quoteRepository,
        InputParamsResolver $inputParamsResolver
    ) {
        $this->order = $order;
        $this->logger = $logger;
        $this->quoteRepository = $quoteRepository;
        $this->inputParamsResolver = $inputParamsResolver;
        $this->state = $state;
    }

    /**
     * Execute observer
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        // If not executing inside the Admin section
        if ($this->state->getAreaCode() != \Magento\Framework\App\Area::AREA_ADMINHTML) {
            $order = $observer->getOrder();

            if ($order != null) {
                $paymentOrder = $order->getPayment();

                if ($paymentOrder != null) {
                    $quote = $this->quoteRepository->get($order->getQuoteId());

                    if ($quote != null) {
                        $paymentQuote = $quote->getPayment();
                        $method = $paymentQuote->getMethodInstance()->getCode();

                        if ($method == \Cardlink\Checkout\Model\Config\Settings::CODE) {
                            // Copy all field from the quote to the order payment object.
                            foreach ($this->customFields as $fieldName) {
                                if ($paymentQuote->getData($fieldName)) {
                                    $paymentOrder->setData($fieldName, $paymentQuote->getData($fieldName));
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}