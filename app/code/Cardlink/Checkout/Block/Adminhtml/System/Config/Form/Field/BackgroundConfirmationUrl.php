<?php

namespace Cardlink\Checkout\Block\Adminhtml\System\Config\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class BackgroundConfirmationUrl extends Field
{
    private StoreManagerInterface $storeManager;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->storeManager = $storeManager;
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element): string
    {
        try {
            $store = $this->storeManager->getDefaultStoreView() ?? current($this->storeManager->getStores());
            $baseUrl = rtrim($store->getBaseUrl(UrlInterface::URL_TYPE_WEB), '/');
        } catch (\Exception $e) {
            $baseUrl = '';
        }

        $confirmationUrl = $baseUrl . '/cardlink_checkout/payment/backgroundconfirmation';

        $label = __('Background Confirmation URL');
        $instruction = __(
            'To enable background confirmation (server-to-server) payment notifications, '
            . 'please copy this URL and send it to Cardlink to request feature activation. '
            . 'This URL is shared across all Cardlink payment methods configured on this store.'
        );

        return '<tr id="row_' . $this->escapeHtml($element->getHtmlId()) . '">'
            . '<td class="label"><label><strong>' . $this->escapeHtml((string) $label) . '</strong></label></td>'
            . '<td class="value" colspan="2">'
            . '<div style="padding:8px 10px;background:#f8f8f8;border:1px solid #ccc;border-radius:3px;margin-bottom:6px;">'
            . '<code style="font-family:monospace;font-size:13px;word-break:break-all;">'
            . $this->escapeHtml($confirmationUrl)
            . '</code>'
            . '</div>'
            . '<p class="note"><span>' . $this->escapeHtml((string) $instruction) . '</span></p>'
            . '</td>'
            . '</tr>';
    }
}
