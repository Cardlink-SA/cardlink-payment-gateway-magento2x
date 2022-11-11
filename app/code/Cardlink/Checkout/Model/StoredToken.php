<?php

namespace Cardlink\Checkout\Model;

/**
 * Data model of a stored card token's information.
 * 
 * @author Cardlink S.A.
 */
class StoredToken
{
    /**
     * The database table entity ID.
     * 
     * @var string|int
     */
    public $entityId;

    /**
     * The type of the card.
     * 
     * @var string
     */
    public $type;

    /**
     * The last 4 digits of the PAN of the card.
     * 
     * @var string
     */
    public $maskedCC;

    /**
     * The expiration date of the card (format MM/YYYY).
     * 
     * @var string
     */
    public $expirationDate;

    /**
     * The expiration date of the card (format YYYY-MM-DD H:i:s).
     * 
     * @var string
     */
    public $expiresAt;

    /**
     * The expiration year of the card.
     * 
     * @var string
     */
    public $expiryYear;

    /**
     * The expiration month of the card.
     * 
     * @var string
     */
    public $expiryMonth;

    /**
     * The expiration day of the card.
     * 
     * @var string
     */
    public $expiryDay;

    /**
     * Determines that the token is expired.
     * 
     * @return bool
     */
    public $isExpired;

    /**
     * Determines that the token is active or not (deleted).
     * 
     * @return bool
     */
    public $isActive;

    /**
     * Load base data in the object.
     * 
     * @param \Magento\Vault\Api\Data\PaymentTokenInterface $paymentToken The stored token object retrieved from the database.
     * @return $this
     */
    public function loadData($paymentToken)
    {
        $this->entityId = $paymentToken->getEntityId();
        $this->isActive = $paymentToken->getIsActive();
        $this->expiresAt = $paymentToken->getExpiresAt();

        $details = json_decode($paymentToken->getTokenDetails(), true);

        $this->type = $details['type'];
        $this->maskedCC = $details['maskedCC'];
        $this->expirationDate = $details['expirationDate'];

        $this->expiryYear = $this->getExpiryYear();
        $this->expiryMonth = $this->getExpiryMonth();
        $this->expiryDay = $this->getExpiryDay();
        $this->isExpired = $this->isExpired();

        return $this;
    }

    public function getEntityId()
    {
        return $this->entityId;
    }

    /**
     * Returns the year part of the card's expiration date.
     * 
     * @return string
     */
    private function getExpiryYear()
    {
        $dateParts = date_parse($this->expiresAt);
        return $dateParts['year'];
    }

    /**
     * Returns the month part of the card's expiration date.
     * 
     * @return string
     */
    private function getExpiryMonth()
    {
        $dateParts = date_parse($this->expiresAt);
        return $dateParts['month'];
    }

    /**
     * Returns the day part of the card's expiration date.
     * 
     * @return string
     */
    private function getExpiryDay()
    {
        $dateParts = date_parse($this->expiresAt);
        return $dateParts['day'];
    }

    /**
     * Determines that the card is currently expired.
     * 
     * @return bool
     */
    private function isExpired()
    {
        return date('Ymd') > date('Ymd', strtotime($this->expiresAt));
    }
}
