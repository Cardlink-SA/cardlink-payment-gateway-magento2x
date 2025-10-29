<?php

namespace Cardlink\Checkout\Block;

use Magento\Framework\Data\Form\FormKey;
use Magento\Backend\Block\Widget\Context;

/**
 * Block used to render a page inside the IFRAME with an autosubmitting HTML form redirecting the customer coming back from the payment gateway to the store on the top level window.
 * This wil allow displaying the payment success or failure page outside the IFRAME.
 * 
 * @author Cardlink S.A.
 */
class Response extends \Magento\Framework\View\Element\Template
{
    // Mark as private so it’s not stored in FPC output
    protected function _isScopePrivate()
    {
        return true;
    }

    // If you also override cache lifetime, returning null is fine here.
    public function getCacheLifetime()
    {
        return null; // not cached as a block fragment
    }

    /**
     * @var FormKey
     */
    private $formKey;
    /**
     * @param \Magento\Backend\Block\Widget\Context $context
     * @param \Magento\Framework\Data\Form\FormKey $formKey
     * @param array $data
     */
    public function __construct(
        Context $context,
        FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->formKey = $formKey;
    }

    /**
     * Get the form security key
     *
     * @return string
     */
    public function getFormKey()
    {
        return $this->formKey->getFormKey();
    }

    /**
     * Set the final redirection URL.
     * 
     * @return $this
     */
    public function setRedirectUrl($url)
    {
        return $this->setData('redirectUrl', $url);
    }

    /**
     * Get the stored final redirection URL.
     * 
     * @return string
     */
    public function getRedirectUrl()
    {
        return $this->getData('redirectUrl');
    }

    /**
     * Set the order ID of the transaction.
     * 
     * @return $this
     */
    public function setOrderId($orderId)
    {
        return $this->setData('orderId', $orderId);
    }

    /**
     * Get the order ID of the transaction.
     * 
     * @return string
     */
    public function getOrderId()
    {
        return $this->getData('orderId');
    }

    /**
     * Set the payment gateway's message for the transaction.
     * 
     * @return $this
     */
    public function setMessage($message)
    {
        return $this->setData('message', $message);
    }

    /**
     * Get the payment gateway's message for the transaction.
     * 
     * @return string
     */
    public function getMessage()
    {
        return $this->getData('message');
    }

    /**
     * Retrieve the data to be passed to the redirection form.
     * 
     * @return array
     */
    public function getFormData()
    {
        return [
            'orderId' => $this->getOrderId(),
            //'message' => $this->getMessage()
        ];
    }

    /**
     * Retrieve the rendered HTML code of the payment gateway response redirection form.
     * 
     * @param bool $autoSubmit Determine whether the form will be automatically submitted when the page load event fires in the IFRAME.
     * @return string
     */
    public function getRedirectForm($autoSubmit = true)
    {

        $ret = '<form name="cardlink_checkout" method="get" target="_top" action="' . $this->getRedirectUrl() . '">' . PHP_EOL;
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
