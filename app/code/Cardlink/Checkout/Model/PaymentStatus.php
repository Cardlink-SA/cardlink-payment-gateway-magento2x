<?php

namespace Cardlink\Checkout\Model;

/**
 * Enumeration class for the payment gateway's transaction status response.
 * 
 * @author Cardlink S.A.
 */
class PaymentStatus
{
    /**
     * The transaction was successfully authorized.
     */
    const AUTHORIZED = 'AUTHORIZED';

    /**
     * The transaction was successfully captured (sale finalized).
     */
    const CAPTURED = 'CAPTURED';

    /**
     * The transaction was canceled by the customer.
     */
    const CANCELED = 'CANCELED';

    /**
     * The transaction was refused by the payment gateway.
     */
    const REFUSED = 'REFUSED';

    /**
     * The transaction has generated an error in the payment gateway.
     */
    const ERROR = 'ERROR';
}
