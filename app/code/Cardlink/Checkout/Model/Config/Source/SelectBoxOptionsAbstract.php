<?php

namespace Cardlink\Checkout\Model\Config\Source;

/**
 * Abstract class used to provide methods that describe the available options of a select box Adminhtml field.
 * The actual options are provided by the classes that extend this class.
 * 
 * @author Cardlink S.A.
 */
abstract class SelectBoxOptionsAbstract implements \Magento\Framework\Option\ArrayInterface
{
    protected $options;

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        $optionsArray = array();

        foreach ($this->options as $key => $value) {
            $optionsArray[] = array('value' => $key, 'label' => __($value));
        }

        return $optionsArray;
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    //public function toArray()
    //{
    //    $optionsArray = array();
    //
    //    foreach ($this->options as $key => $value) {
    //        $optionsArray[$key] = __($value);
    //    }
    //
    //    return $optionsArray;
    //}
}
