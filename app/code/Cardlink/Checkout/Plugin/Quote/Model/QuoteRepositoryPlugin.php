<?php

namespace Cardlink\Checkout\Plugin\Quote\Model;

use Magento\Quote\Api\CartRepositoryInterface;

class QuoteRepositoryPlugin
{
    /**
     * Ensure guest customer email is set on quote before save
     */
    public function beforeSave(CartRepositoryInterface $subject, $quote)
    {
        if (!$quote->getCustomerId() && !$quote->getCustomerEmail()) {
            $email = $this->getEmailFromRequest();
            if ($email) {
                $quote->setCustomerEmail($email);
            }
        }
        return [$quote];
    }

    private function getEmailFromRequest()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $request = $objectManager->get(\Magento\Framework\App\RequestInterface::class);

        // Try to get email from request parameters
        $email = $request->getParam('quote') ? $request->getParam('quote')['email'] : null;
        if (!$email) {
            $email = $request->getParam('email');
        }
        if (!$email && $request->getPost('email')) {
            $email = $request->getPost('email');
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
