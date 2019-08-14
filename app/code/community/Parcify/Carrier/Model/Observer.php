<?php

/**
 * Class Parcify_Carrier_Model_Observer
 */
class Parcify_Carrier_Model_Observer
{
    /**
     * Register Shipment as Parcify parcel
     * Magento event overwrite
     *
     * @param \Magento $observer
     */
    public function salesOrderShipmentSaveBefore(Magento $observer)
    {
        // Config data
        // $carrier = Mage::getStoreConfig('carriers/parcify_carrier');
        $origin = Mage::getStoreConfig('shipping/origin');

        // Get Shipment
        $shipment = $observer->getEvent()->getShipment();

        // Get order
        $order = Mage::getModel('sales/order')->load($shipment->getOrderId());

        // Get customer shipment address and contact info
        $shippingAddress = $order->getShippingAddress();
        // var_dump($order->getData());
        // var_dump($shippingAddress->getData());

        if (strpos($order->getShippingMethod(), 'parcify') !== false) {
            // New parcel
            $parcel = $this->newParcel();

            // Name
            $parcelNamePrefix = Mage::getStoreConfig('carriers/parcify_carrier/parcelname');
            $parcel->package->name = $parcelNamePrefix.$order->getIncrementId();

            // Receiver
            $parcel->delivery->receiver->email = $shippingAddress->getEmail();
            $parcel->delivery->receiver->mobileNumber = $shippingAddress->getTelephone();

            // Pickup address
            $pickupAddress = Mage::getStoreConfig('carriers/parcify_carrier/pickup_address');
            if (!empty($pickupAddress)) {
                // Use pick-up address from Config
                $parcel->pickup->address = $pickupAddress;
            } else {
                // Use Magento Origin as pick-up address
                $parcel->pickup->address = $this->getPickupAddress($origin);
            }

            // Delivery address
            $parcel->delivery->address = $this->getDeliveryAddress($shippingAddress);

            // Create parcel
            $response = $this->createParcifyParcel($parcel);

            if ($response['code'] == '200') {
                // Success parcel created
                if (isset($response['body'], $response['body']['parcelId'])) {
                    $track = Mage::getModel('sales/order_shipment_track')
                      ->setNumber($response['body']['parcelId'])//tracking number / awb number
                      ->setCarrierCode('Parcify')//carrier code
                      ->setTitle('Parcel'); //carrier title
                    $shipment->addTrack($track);
                }
                // Success notification
                Mage::getSingleton('core/session')->addSuccess('Successful registered shipment as Parcify parcel.');

            } else {
                $errorMsg = '';

                if (isset($response['body'], $response['body']['errors'])) {
                    foreach ($response['body']['errors'] as $error) {
                        $errorMsg .= $error;
                    }
                }
                // Error notification
                Mage::getSingleton('core/session')->addError('Error creating Parcify parcel: '.$errorMsg);

                // Prevent from saving the shipment
                Mage::app()->getResponse()->setRedirect($_SERVER['HTTP_REFERER']);
                Mage::app()->getResponse()->sendResponse();
                exit;
            }

        }
    }

    /**
     * Initiate new Parcel object skeleton
     *
     * @return \stdClass
     */
    public function newParcel()
    {
        // New parcel
        $parcel = new stdClass();

        // Set parcel info
        $parcel->package->imageId;
        $parcel->package->name = Mage::getStoreConfig('carriers/parcify_carrier/parcelname');
        $parcel->package->instructions;

        // Set sender info
        $parcel->pickup->sender->id = Mage::getStoreConfig('carriers/parcify_carrier/userid');

        // Set receiver info
        $parcel->delivery->receiver->mobileNumber;
        $parcel->delivery->receiver->email;

        // Set delivery address
        $parcel->delivery->address;

        // Set pickup address
        $parcel->pickup->address;

        return $parcel;
    }

    /**
     * Get full delivery address from Order shippingAddress
     *
     * @param $shippingAddress
     * @return string
     */
    public function getDeliveryAddress($shippingAddress)
    {
        // Street
        $streetAddress = $shippingAddress->getStreetFull();
        $street = (!empty($streetAddress) ? $streetAddress.', ' : '');
        $postCodeAddress = $shippingAddress->getPostcode();
        $postCode = (!empty($postCodeAddress) ? $postCodeAddress.' ' : '');
        $cityAddress = $shippingAddress->getCity();
        $city = (!empty($cityAddress) ? $cityAddress.', ' : '');
        $regionAddress = $shippingAddress->getRegion();
        $region = (!empty($regionAddress) ? $regionAddress.', ' : '');
        $countryCode = $shippingAddress->getCountry();
        $countryAddress = Mage::app()->getLocale()->getCountryTranslation($countryCode);
        $country = (!empty($countryAddress) ? $countryAddress : '');

        $fullAddress = $street.$postCode.$city.$region.$country;

        return $fullAddress;
    }

    /**
     * Get full pickup address from Store Shipping Origin settings
     *
     * @param $origin
     * @return string
     */
    public function getPickupAddress($origin)
    {
        // Street
        $streetAddress1 = $origin['street_line1'];
        $street1 = (!empty($streetAddress1) ? $streetAddress1.' ' : '');
        $streetAddress2 = $origin['street_line2'];
        $street2 = (!empty($streetAddress2) ? $streetAddress2 : '');
        $streetAddress = $street1.$street2;
        $street = (!empty($streetAddress) ? $streetAddress.', ' : '');
        $postCodeAddress = $origin['postcode'];
        $postCode = (!empty($postCodeAddress) ? $postCodeAddress.' ' : '');
        $cityAddress = $origin['city'];
        $city = (!empty($cityAddress) ? $cityAddress.', ' : '');
        // $regionAddress = $origin['street_line1'];
        // $region = (!empty($regionAddress) ? $regionAddress.', ' : '');
        $countryCode = $origin['country_id'];
        $countryAddress = Mage::app()->getLocale()->getCountryTranslation($countryCode);
        $country = (!empty($countryAddress) ? $countryAddress : '');

        $fullAddress = $street.$postCode.$city.$country;

        return $fullAddress;
    }

    /**
     * Register a Parcify Parcel
     * API call
     * @param $parcel
     * @return mixed
     */
    public function createParcifyParcel($parcel)
    {
        // Defaults
        $response['body'] = '';

        try {
            // API data
            $url = Mage::getStoreConfig('carriers/parcify_carrier/gateway_url');
            $username = Mage::getStoreConfig('carriers/parcify_carrier/userid');
            $passwordEncrypted = Mage::getStoreConfig('carriers/parcify_carrier/password');
            $password = Mage::helper('core')->decrypt($passwordEncrypted);

            // Check required gateway settings
            if (empty($url)) {
                $response['body']['errors'][] = 'Parcify Shipping - Missing Gateway URL';
            }
            if (empty($username)) {
                $response['body']['errors'][] = 'Parcify Shipping - Missing Gateway user ID';
            }
            if (empty($password)) {
                $response['body']['errors'][] = 'Parcify Shipping - Missing Gateway password';
            }

            // Cancel and report errors in case of missing required data
            if(isset($response['body'], $response['body']['errors'])) {
                return $response;
            }

            // data
            $data = array('parcel' => $parcel);
            $data = json_encode($data);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_USERPWD, $username.":".$password);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            $responseBody = curl_exec($ch);
            $responseHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($responseHttpCode == '200') {
                $debugData['code'] = $responseHttpCode;
                $debugData['body'] = $responseBody;
            } else {
                $debugData['code'] = $responseHttpCode;
                $debugData['body'] = $responseBody;

                $responseHttpCode = '-';
                $responseBody = array();
                $responseBody['errors'][] = 'Error Parcify parcel create API, check the "shipping_parcify.log" files for details.';
                $responseBody = json_encode($responseBody);
            }

        } catch (Exception $e) {

            $debugData['code'] = $responseBody;
            $debugData['body'] = array('error' => $e->getMessage(), 'code' => $e->getCode());

            Mage::getSingleton('core/session')->addError('Error Parcify parcel create API: '.$e->getMessage());

            $responseHttpCode = '-';
            $responseBody['errors'][] = 'Error Parcify parcel create API';
        }
        $this->_debug($debugData);

        // Response data
        $response['code'] = $responseHttpCode;
        $response['body'] = json_decode($responseBody, true);

        return $response;
    }

    /**
     * Log debug data to file
     *
     * @param mixed $debugData
     */
    public function _debug($debugData)
    {
        $_debugFlag = Mage::getStoreConfig('carriers/parcify_carrier/debug');

        if ($_debugFlag) {
            Mage::getModel('core/log_adapter', 'shipping_parcify.log')
              ->log($debugData);
        }
    }
}
