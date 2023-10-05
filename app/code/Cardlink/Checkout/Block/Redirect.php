<?php

namespace Cardlink\Checkout\Block;

/**
 * Block used for rendering the data form for the page redirecting the customers to the payment gateway.
 * 
 * @author Cardlink S.A.
 */
class Redirect extends \Magento\Framework\View\Element\Template
{
    /**
     * Retrieve the block data of the redirection form.
     * 
     * @return array
     */
    public function getFormData()
    {
        return $this->getData('formData');
    }

    /**
     * Retrieve the URL of the payment gateway that the transaction request will be sent to.
     * 
     * @return string
     */
    public function getPaymentGatewayUrl()
    {
        return $this->getData('paymentGatewayUrl');
    }

    /**
     * Retrieve the rendered HTML code of the payment gateway redirection form.
     * 
     * @param bool $autoSubmit Determine whether the form will be automatically submitted when the page load event fires.
     * @return string
     */
    public function getRedirectForm($autoSubmit = true)
    {
        $ret = '<form name="cardlink_checkout" method="post" target="_self" action="' . $this->getPaymentGatewayUrl() . '">' . PHP_EOL;
        foreach ($this->getFormData() as $formFieldKey => $formFieldValue) {
            $ret .= '<input type="hidden" name="' . $formFieldKey . '" value="' . $formFieldValue . '" />' . PHP_EOL;
        }
        $ret .= '</form>' . PHP_EOL;

        if ($autoSubmit) {
            $ret .= '<script> window.addEventListener("load", function() { document.forms["cardlink_checkout"].submit(); }); </script>' . PHP_EOL;
        }

        return $ret;
    }
}