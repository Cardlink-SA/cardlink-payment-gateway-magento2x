<?php

namespace Cardlink\Checkout\Helper;

use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Model\StoredToken;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Intl\DateTimeFactory;
use Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\App\ResourceConnection;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Vault\Model\ResourceModel\PaymentToken as PaymentTokenResourceModel;

/**
 * Helper class containing methods to handle card tokenization functionalities.
 * 
 * @author Cardlink S.A.
 */
class Tokenization extends AbstractHelper
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var ResourceConnection
     */
    protected $resource;

    /**
     * @var PaymentTokenResourceModel
     */
    protected $paymentTokenResourceModel;

    /**
     * @var PaymentTokenRepositoryInterface
     */
    private $paymentTokenRepository;

    /**
     * @var PaymentTokenFactoryInterface
     */
    private $paymentTokenFactory;

    /**
     * @var FilterBuilder
     */
    private $filterBuilder;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var DateTimeFactory
     */
    private $dateTimeFactory;


    /**
     * Constructor.
     * 
     * @param Logger $logger
     * @param ResourceConnection $resource
     * @param PaymentTokenResourceModel $paymentTokenResourceModel
     * @param FilterBuilder $filterBuilder
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param DateTimeFactory $dateTimeFactory
     */
    public function __construct(
        Logger $logger,
        ResourceConnection $resource,
        PaymentTokenResourceModel $paymentTokenResourceModel,
        PaymentTokenRepositoryInterface $paymentTokenRepository,
        PaymentTokenFactoryInterface $paymentTokenFactory,
        PaymentTokenInterface $paymentToken,
        FilterBuilder $filterBuilder,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        DateTimeFactory $dateTimeFactory
    ) {
        $this->logger = $logger;
        $this->resource = $resource;
        $this->paymentTokenResourceModel = $paymentTokenResourceModel;
        $this->paymentTokenRepository = $paymentTokenRepository;
        $this->paymentTokenFactory = $paymentTokenFactory;
        $this->paymentToken = $paymentToken;
        $this->filterBuilder = $filterBuilder;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->dateTimeFactory = $dateTimeFactory;
    }

    /**
     * Retrieved all the stored card tokens belonging to a customer.
     * 
     * @param string $merchantId The merchant ID that the token must be bound to.
     * @param int $customerId The customer's entity ID.
     * @param bool $fetchActiveOnly Identifies that the function should only retrieve active tokens (rows that have not expired).
     * 
     * @return array Array of StoredToken objects representing the retrieved tokens of the customer.
     */
    public function getCustomerPaymentTokens($customerId, $fetchActiveOnly = false, $fetchExpired = true)
    {
        $result = array();

        if ($customerId) {
            $this->searchCriteriaBuilder->addFilters(
                [
                    $this->filterBuilder->setField(PaymentTokenInterface::CUSTOMER_ID)
                        ->setValue($customerId)
                        ->create(),
                ]
            );
        }

        $this->searchCriteriaBuilder->addFilters(
            [
                $this->filterBuilder->setField(PaymentTokenInterface::PAYMENT_METHOD_CODE)
                    ->setValue(\Cardlink\Checkout\Model\Config\Settings::CODE)
                    ->create(),
            ]
        );

        if ($fetchActiveOnly) {
            $this->searchCriteriaBuilder->addFilters(
                [
                    $this->filterBuilder->setField(PaymentTokenInterface::IS_ACTIVE)
                        ->setValue(1)
                        ->create(),
                ]
            );
        }

        if (!$fetchExpired) {
            $this->searchCriteriaBuilder->addFilters(
                [
                    $this->filterBuilder->setField(PaymentTokenInterface::EXPIRES_AT)
                        ->setConditionType('gt')
                        ->setValue(
                            $this->dateTimeFactory->create(
                                'now',
                                new \DateTimeZone('UTC')
                            )->format('Y-m-d 00:00:00')
                        )
                        ->create(),
                ]
            );
        }

        $searchCriteria = $this->searchCriteriaBuilder->create();

        foreach ($this->paymentTokenRepository->getList($searchCriteria)->getItems() as $token) {
            $storedToken = new StoredToken();
            $storedToken->loadData($token);
            $result[] = $storedToken;
        }

        return $result;
    }

    /**
     * Retrieve a specific stored token belonging to a customer.
     * 
     * @param int $customerId The customer's entity ID.
     * @param int $tokenId The token's entity ID.
     * 
     * @return StoredToken|null
     */
    public function getCustomerStoredToken($customerId, $tokenId)
    {
        /** @var PaymentTokenInterface $paymentToken */
        $paymentToken = $this->paymentTokenRepository->getById($tokenId);

        if ($paymentToken != null && $paymentToken->getCustomerId() == $customerId) {
            $storedToken = new StoredToken();
            $storedToken->loadData($paymentToken);
            return $storedToken;
        }

        return null;
    }

    /**
     * Retrieve a specific stored token by its entity ID.
     * 
     * @param int $tokenId The token's entity ID.
     * 
     * @return StoredToken|null
     */
    public function getStoredToken($tokenId)
    {
        /** @var PaymentTokenInterface $paymentToken */
        $paymentToken = $this->paymentTokenRepository->getById($tokenId);

        if ($paymentToken != null) {
            $storedToken = new StoredToken();
            $storedToken->loadData($paymentToken);
            return $storedToken;
        }
        return null;
    }

    /**
     * Retrieve a specific payment token by its entity ID.
     * 
     * @param int $tokenId The token's entity ID.
     * 
     * @return PaymentTokenInterface|null
     */
    public function getPaymentToken($tokenId)
    {
        /** @var PaymentTokenInterface $paymentToken */
        return $this->paymentTokenRepository->getById($tokenId);
    }

    /**
     * Retrieve a specific payment token belonging to a customer by its entity ID.
     * 
     * @param int $customerId The customer's entity ID.
     * @param int $tokenId The token's entity ID.
     * 
     * @return PaymentTokenInterface|null
     */
    public function getCustomerPaymentToken($customerId, $tokenId)
    {
        /** @var PaymentTokenInterface $paymentToken */
        $paymentToken = $this->paymentTokenRepository->getById($tokenId);

        if ($paymentToken != null && $paymentToken->getCustomerId() == $customerId) {
            return $paymentToken;
        }
        return null;
    }

    /**
     * Stores a new token belonging to the customer.
     * 
     * @param int $customerId The customer's entity ID.
     * @param string $token The actual card token.
     * @param string $type The type of the card that the token belongs to (i.e. visa, mastercard, amex, etc).
     * @param string $panLastDigits The last digits of the customer's PAN (Permanent Account Number) card.
     * @param string $panExpiration The expiration date of the customer's PAN (Permanent Account Number) card.
     */
    public function storeTokenForCustomer($customerId, $token, $type, $panLastDigits, $panExpiration)
    {
        try {
            $expirationDateTime = \DateTime::createFromFormat('Ymd', $panExpiration);
            $expiresAtDateTime = \DateTime::createFromFormat('Ymd', $panExpiration);
            $expiresAtDateTime->add(new \DateInterval('P1D'));

            /** @var PaymentTokenInterface $paymentToken */
            $paymentToken = $this->paymentTokenFactory->create(PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD);
            $paymentToken->setPublicHash(sha1($customerId . $token . $type . $panLastDigits . $panExpiration, false));
            $paymentToken->setCustomerId($customerId);
            $paymentToken->setGatewayToken($token);
            $paymentToken->setExpiresAt(date_format($expiresAtDateTime, 'Y-m-d 00:00:00'));
            $paymentToken->setIsActive(true);
            $paymentToken->setIsVisible(true);
            $paymentToken->setPaymentMethodCode(\Cardlink\Checkout\Model\Config\Settings::CODE);
            $paymentToken->setTokenDetails(json_encode([
                'type' => $this->getCreditCardType($type),
                'maskedCC' => $panLastDigits,
                'expirationDate' => date_format($expirationDateTime, 'm/Y')
            ]));

            $storedPaymentToken = $this->paymentTokenRepository->save($paymentToken);

            return $storedPaymentToken;
        } catch (\Exception $ex) {
        }
    }

    /**
     * Convert the reported card type of the payment gateway to the internal card type of Magento.
     * 
     * @param string $paymentGatewayType
     * @return string
     */
    private function getCreditCardType($paymentGatewayType)
    {
        switch ($paymentGatewayType) {
            case 'visa':
                return 'VI';
            case 'mastercard':
                return 'MC';
            case 'amex':
                return 'AE';
            case 'diners':
                return 'DN';
            case 'discover':
                return 'DI';
        }
    }

    /**
     * Use either the payment gateway's card type code or that of Magento's to return the card type name.
     * 
     * @param string $type
     * @return string
     */
    public function getCardTypeName($type)
    {
        switch (strtolower($type)) {
            case 'visa':
            case 'vi':
                return 'Visa';
            case 'mastercard':
            case 'mc':
                return 'MasterCard';
            case 'amex':
            case 'ae':
                return 'American Express';
            case 'diners':
            case 'dn':
                return 'Diners';
            case 'discover':
            case 'di':
                return 'Discover';
        }
    }

    /**
     * Link the payment token to the order payment.
     * 
     * @var int $paymentTokenId
     * @var int $orderPaymentId
     */
    public function addLinkToOrderPayment($paymentTokenId, $orderPaymentId)
    {
        return $this->paymentTokenResourceModel->addLinkToOrderPayment($paymentTokenId, $orderPaymentId);
    }
}
