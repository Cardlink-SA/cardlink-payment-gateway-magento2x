<?php

namespace Cardlink\Checkout\Block\Adminhtml\System\Config\Form\Field;

/**
 * Block class that describes the fields of a Adminhtml form field array compound input control.
 * 
 * @author Cardlink S.A.
 */
class Installments extends \Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray
{
    /**
     * @var \Magento\Framework\Data\Form\Element\Factory
     */
    protected $_elementFactory;

    /**
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \Magento\Framework\Data\Form\Element\Factory $elementFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Data\Form\Element\Factory $elementFactory,
        array $data = []
    ) {
        $this->_elementFactory  = $elementFactory;
        parent::__construct($context, $data);
    }

    /**
     * Add custom config field columns, set template, add values.
     */
    protected function _construct()
    {
        // The start amount of the order amount range.
        $this->addColumn('start_amount', array(
            'name' => 'start_amount',
            'class' => 'required-entry validate-not-negative-number',
            'required' => true,
            'style' => 'width:80px',
            'label' => __('Start Amount'),
        ));

        // The end amount of the order amount range. If value is zero, then no maximum value is considered (i.e. infinity).
        $this->addColumn('end_amount', array(
            'name' => 'end_amount',
            'class' => 'required-entry validate-not-negative-number',
            'required' => true,
            'style' => 'width:80px',
            'label' => __('End Amount'),
        ));

        // The maximum number of installments that the order amount range will allow the customer to select on the checkout page.
        $this->addColumn('max_installments', array(
            'name' => 'max_installments',
            'class' => 'required-entry validate-not-negative-number',
            'required' => true,
            'style' => 'width:80px',
            'label' => __('Maximum Installments'),
        ));

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Range');
        parent::_construct();
    }

    /**
     * Render block HTML. Enclose the actual control's HTML code in a div in order to make field dependencies work 
     * (toggle control visibility according to the value of another control.
     *
     * @return string
     */
    protected function _toHtml()
    {
        // Wrap around a div with the proper id value to make dependencies on other fields work.
        return '<div id="' . $this->getElement()->getId() . '">' . parent::_toHtml() . '</div>';
    }
}
