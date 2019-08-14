<?php

/**
 * Class Parcify_Carrier_Model_Carrier
 */
class Parcify_Carrier_Model_Carrier extends Mage_Shipping_Model_Carrier_Abstract implements Mage_Shipping_Model_Carrier_Interface
{

    // Properties
    protected $_code = 'parcify_carrier';
    protected static $_parcifyCache = array();

    /**
     * Collect rates for Parcify shipping method
     *
     * @param \Mage_Shipping_Model_Rate_Request $request
     * @return bool|false|\Mage_Core_Model_Abstract|\Mage_Shipping_Model_Rate_Result
     */
    public function collectRates(Mage_Shipping_Model_Rate_Request $request)
    {
        $result = Mage::getModel('shipping/rate_result');
        /* @var $result Mage_Shipping_Model_Rate_Result */

        // Only available in Antwerp
        $availablePostCodes = array('2000', '2020', '2050', '2060', '2018', '2600', '2610', '2140', '2100');
        if (!in_array($request->getDestPostcode(), $availablePostCodes)) {
            return false;
        }

        if (($request->getFreeShipping()) || ($request->getBaseSubtotalInclTax() >= floatval($this->getConfigData('free_shipping_subtotal')))) {
            /**
             *  If the request has the free shipping flag,
             *  append a free shipping rate to the result.
             */
            $shippingRate = $this->_getFreeShippingRate();
            $result->append($shippingRate);
        } else {
            /**
             * Standard rate.
             */
            $shippingRate = $this->_getStandardShippingRate();
            $result->append($shippingRate);
        }

        return $result;
    }

    /**
     * Get standard shipping rate
     *
     * @return false|\Mage_Core_Model_Abstract|\Mage_Shipping_Model_Rate_Result_Method
     */
    protected function _getStandardShippingRate()
    {
        $rate = Mage::getModel('shipping/rate_result_method');
        /* @var $rate Mage_Shipping_Model_Rate_Result_Method */

        $rate->setCarrier($this->_code);
        $rate->setCarrierTitle($this->getConfigData('title'));
        $rate->setMethod('free_shipping');
        $lbl = __('Personal delivery');
        $rate->setMethodTitle($lbl);

        $price = $this->getConfigData('price');
        $rate->setPrice($price);
        $rate->setCost(0);

        return $rate;
    }


    /**
     * Get free shipping rate
     *
     * @return false|\Mage_Core_Model_Abstract|\Mage_Shipping_Model_Rate_Result_Method
     */
    protected function _getFreeShippingRate()
    {
        $rate = Mage::getModel('shipping/rate_result_method');
        /* @var $rate Mage_Shipping_Model_Rate_Result_Method */
        $rate->setCarrier($this->_code);
        $rate->setCarrierTitle($this->getConfigData('title'));
        $rate->setMethod('free_shipping');
        $lbl = __('Personal delivery');
        $rate->setMethodTitle($lbl);
        $rate->setPrice(0);
        $rate->setCost(0);

        return $rate;
    }

    /**
     * Helper function - get allowed methods
     *
     * @return array
     */
    public function getAllowedMethods()
    {
        return array(
          'standard' => 'Standard',
          'free_shipping' => 'Free Shipping',
        );
    }

    /**
     * Return code of carrier
     *
     * @return string
     */
    public function getCarrierCode()
    {
        return isset($this->_code) ? $this->_code : null;
    }

    /**
     * Returns cache key for some request to Parcify service
     *
     * @param string|array $requestParams
     * @return string
     */
    protected function _getDataCacheKey($requestParams)
    {
        if (is_array($requestParams)) {
            $requestParams = implode(',', array_merge(
                array($this->getCarrierCode()),
                array_keys($requestParams),
                $requestParams)
            );
        }
        return crc32($requestParams);
    }

    /**
     * Checks whether some request to rates have already been done, so we have cache for it
     * Used to reduce number of same requests done to carrier service during one session
     *
     * Returns cached response or null
     *
     * @param string|array $requestParams
     * @return null|string
     */
    protected function _getCachedData($requestParams)
    {
        $key = $this->_getDataCacheKey($requestParams);
        return isset(self::$_parcifyCache[$key]) ? self::$_parcifyCache[$key] : null;
    }

    /**
     * Sets received carrier quotes to cache
     *
     * @param string|array $requestParams
     * @param string $response
     * @return Mage_Usa_Model_Shipping_Carrier_Abstract
     */
    protected function _setCachedData($requestParams, $response)
    {
        $key = $this->_getDataCacheKey($requestParams);
        self::$_parcifyCache[$key] = $response;
        return $this;
    }
}
