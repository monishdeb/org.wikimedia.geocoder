<?php
/*
  +--------------------------------------------------------------------+
  | CiviCRM version 4.7                                                |
  +--------------------------------------------------------------------+
  | Copyright CiviCRM LLC (c) 2004-2017                                |
  +--------------------------------------------------------------------+
  | This file is a part of CiviCRM.                                    |
  |                                                                    |
  | CiviCRM is free software; you can copy, modify, and distribute it  |
  | under the terms of the GNU Affero General Public License           |
  | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
  |                                                                    |
  | CiviCRM is distributed in the hope that it will be useful, but     |
  | WITHOUT ANY WARRANTY; without even the implied warranty of         |
  | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
  | See the GNU Affero General Public License for more details.        |
  |                                                                    |
  | You should have received a copy of the GNU Affero General Public   |
  | License and the CiviCRM Licensing Exception along                  |
  | with this program; if not, contact CiviCRM LLC                     |
  | at info[AT]civicrm[DOT]org. If you have questions about the        |
  | GNU Affero General Public License or the licensing of CiviCRM,     |
  | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
  +--------------------------------------------------------------------+
 */

use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Geocoder\Model\AddressCollection;
use CRM_Geocoder_ExtensionUtil as E;

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2017
 */

/**
 * Geocoder class.
 */
class CRM_Utils_Geocode_Geocoder {

  /**
   * @var \Http\Adapter\Guzzle6\Client
   */
  protected static $client;

  /**
   * Get client.
   *
   * @return \Http\Adapter\Guzzle6\Client
   */
  public static function getClient() {
    return self::$client;
  }

  /**
   * Set client.
   *
   * @param \Http\Adapter\Guzzle6\Client $client
   */
  public static function setClient($client) {
    self::$client = $client;
  }

  protected static $geoCoders;

  /**
   * Function that takes an address object and gets the latitude / longitude for this
   * address. Note that at a later stage, we could make this function also clean up
   * the address into a more valid format
   *
   * @param array $values
   * @param bool $stateName
   *
   * @return bool
   *   true if we modified the address, false otherwise
   */
  public static function format(&$values, $stateName = FALSE) {
    if (!self::getClient()) {
      self::setClient(new \Http\Adapter\Guzzle6\Client());
    }
    if (!is_array(self::$geoCoders)) {
      $geocoders = civicrm_api3('Geocoder', 'get', [
        'sequential' => 1,
        'is_active' => 1,
        'options' => ['sort' => 'weight'],
      ]);
      $metadata = self::getEntitiesMetadata();
      foreach ($geocoders['values'] as $geocoder) {
        self::$geoCoders[$geocoder['name']] = array_merge($geocoder, $metadata[$geocoder['name']]);
      }
    }
    // AFAIK only 2 char string accepted - from the examples.
    $locale = substr(CRM_Utils_System::getUFLocale(), 0, 2);
    $messageOnFail = ts('No usable geocoding providers');

    foreach (self::$geoCoders as $geocoder) {
      if (!self::isUsable($geocoder)) {
        continue;
      }
      $classString = '\\Geocoder\\Provider\\' . $geocoder['class'];
      try {
        self::fillMissingAddressData($values, $geocoder);
        if (!self::hasRequiredFieldsForGeocoder($values, $geocoder)) {
          continue;
        }
        $geocodableAddress = self::getGeocodableAddress($values, $geocoder);
        if (empty($geocodableAddress)) {
          continue;
        }

        // Sadly not all geocoders take the same argument - what to do what to pass?
        // currently starting with obviously likely things & then overwriting if set in metadata.
        $argument = CRM_Utils_Array::value('url', $geocoder, CRM_Utils_Array::value('api_key', $geocoder));
        if (isset($geocoder['additional_metadata'])) {
          $additionalMetaData = $geocoder['additional_metadata'];
          $argumentName = $additionalMetaData['args'][0];
          $argument = $additionalMetaData[$argumentName];
        }
        $provider = new $classString(self::$client, $argument);

        $geocoderObj = new \Geocoder\StatefulGeocoder($provider, $locale);
        $result = $geocoderObj->geocodeQuery(GeocodeQuery::create($geocodableAddress));

        foreach (json_decode($geocoder['retained_response_fields'], TRUE) as $fieldName) {
          $values[$fieldName] = self::getValueFromResult($fieldName, $result, $values);
        }
        foreach (json_decode($geocoder['datafill_response_fields'], TRUE) as $fieldName) {
          if (empty($values[$fieldName]) || $values[$fieldName] ===  'null') {
            $values[$fieldName] = self::getValueFromResult($fieldName, $result, $values);
          }
        }

      return TRUE;
    }
    catch (Geocoder\Exception\CollectionIsEmpty $e) {
        $values['geo_code_1'] = 'null';
        $values['geo_code_2'] = 'null';
        $messageOnFail = ts('Failed to geocode address, no co-ordinates saved');
        continue;
      }
      catch (Geocoder\Exception\QuotaExceeded $e) {

        if (CRM_Core_Permission::check('access CiviCRM')) {
          CRM_Core_Session::setStatus(ts('Geocoder quota exceeded. No further geocoding attempts will be made for %1 seconds', array(
            $geocoder['threshold_standdown'],
            'int'
          )));
        }
        civicrm_api3('Geocoder', 'create', [
          'id' => $geocoder['id'],
          'threshold_last_hit' => 'now'
        ]);
        // Unset it so we reload next instance & recheck properly.
        self::$geoCoders = NULL;
        return FALSE;
      }
      catch (Exception $e) {
        $messageOnFail = ts('Unknown geocoding error :') . $e->getMessage();
        continue;
      }
    }

    // We went threw all the geocoders & couldn't make it stick :-(.
    self::setMessage($messageOnFail);
    return FALSE;
  }

  /**
   * Check if the geocoder is usable.
   *
   * @param string $geocoder
   *
   * @return bool
   */
  public static function isUsable($geocoder) {
    if (empty($geocoder['threshold_last_hit']) || $geocoder['threshold_last_hit'] === '0000-00-00 00:00:00' || empty($geocoder['threshold_standdown'])) {
      return TRUE;
    }
    $standDownEnds = strtotime('+ ' . $geocoder['threshold_standdown'] . ' seconds', strtotime($geocoder['threshold_last_hit']));
    if ($standDownEnds <= strtotime('now')) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Set a message if the geocoding failed.
   *
   * @param string $message
   */
  public static function setMessage($message) {
    if (CRM_Core_Permission::check('access CiviCRM')) {
      CRM_Core_Session::setStatus($message);
    }
  }

  /**
   * Get address values with additional fields fetched & irrelevant filtered.
   *
   * @param array $addressValues
   * @param array $geocoder
   *
   * @return array
   */
  protected static function getAddressValuesArray($addressValues, $geocoder) {
    $addressFields = array_keys(self::getAddressFields());

    // This merge will do an ordering for us.
    $addressValues = array_merge(array_fill_keys($addressFields, NULL), $addressValues);
    // filter out unrelated keys
    $keysToRetain = array_fill_keys($addressFields, 1);
    unset($keysToRetain['country_id'], $keysToRetain['state_province_id'], $keysToRetain['county_id']);
    $keysToRetain['country'] = $keysToRetain['state_province_id'] = $keysToRetain['county'];
    $addressValues = array_intersect_key($addressValues, $keysToRetain);

    return $addressValues;
  }

  /**
   * Retrieve additional data if we are dealing with an update that may be incomplete.
   *
   * @param array $inputValues
   * @param array $geocoder
   */
  public static function fillMissingAddressData(&$inputValues, $geocoder) {

    foreach (['county', 'state_province', 'country'] as $locationField) {
      if (empty($addressValues[$locationField]) && !empty($addressValues[$locationField . '_id'])) {
        $inputValues[$locationField] = CRM_Core_PseudoConstant::getLabel(
          'CRM_Core_BAO_Address',
          $locationField . '_id',
          $inputValues[$locationField . '_id']
        );
      }
    }
    if (empty($values['id'])) {
      return;
    }
    $addressFields = array_keys(self::getAddressFields());

    if (isset($geocoder['required_fields'])) {
      $requiredFields = array_fill_keys(json_decode($geocoder['required_fields'], TRUE), 1);
    }
    else {
      $requiredFields = array_fill_keys($addressFields, 1);
    }

    $missingFields = array_diff_key($requiredFields, $values);
    if (empty($missingFields)) {
      return;
    }

    $existingAddress = civicrm_api3('Address', 'getsingle', [
      'id' => $values['id'],
      'return' => array_keys($missingFields)
    ]);
    $inputValues = array_merge($existingAddress, $inputValues);
  }

  /**
   * Is there sufficient information to pass this address to the geocoder.
   *
   * @param array $inputValues
   * @param array $geocoder
   *
   * @return bool
   */
  public static function hasRequiredFieldsForGeocoder($inputValues, $geocoder) {
    if (empty($geocoder['required_fields'])) {
      return TRUE;
    }
    $requiredFields = array_fill_keys(json_decode($geocoder['required_fields'], TRUE), 1);
    $missingFields = array_diff_key($requiredFields, $inputValues);
    return empty($missingFields) ? TRUE : FALSE;
  }

  /**
   * Get the list of fields that are used for addresses.
   *
   * @return array
   */
  public static function getAddressFields() {
    return [
      'street_address' => E::ts('Street Address'),
      'supplemental_address_1' => E::ts('Supplemental Address 1'),
      'supplemental_address_2' => E::ts('Supplemental Address 2'),
      'supplemental_address_3' => E::ts('Supplemental Address 4'),
      'city' => E::ts('City'),
      'postal_code' => ts('Postal code'),
      'county_id' => E::ts('County'),
      'state_province_id' => E::ts('State / Province'),
      'country_id' => E::ts('Country'),
    ];
  }

  public static function getSendableFields($geocoder) {
    if (empty($geocoder['required_fields'])) {
      $keysToRetain = self::getAddressFields();
      unset($keysToRetain['country_id'], $keysToRetain['state_province_id'], $keysToRetain['county_id']);
      $keysToRetain['country'] = $keysToRetain['state_province_id'] = $keysToRetain['county'] = 1;
      return array_keys($keysToRetain);

    }
    return json_decode($geocoder['required_fields'], TRUE);
  }

  /**
   * Get a geocodable address.
   *
   * This is an address string.
   *
   * @param array $addressValues
   * @return string
   */
  protected static function getGeocodableAddress($addressValues, $geocoder) {
    $addressFields = self::getSendableFields($geocoder);
    // This merge will do an ordering for us.
    $addressValues = array_merge(array_fill_keys($addressFields, NULL), $addressValues);

    // filter out unrelated keys

    $addressValues = array_intersect_key($addressValues, array_fill_keys($addressFields, 1));
    $geocodableAddress = implode(',', array_filter($addressValues));
    return $geocodableAddress;
  }

  /**
   * Get the value for the specified field.
   *
   * @param string $fieldName
   * @param AddressCollection $result
   * @param array $values
   *   Address values to be saved
   *
   * @return string
   */
  protected static function getValueFromResult($fieldName, AddressCollection $result, $values) {
    $firstResult = $result->first();

    switch ($fieldName) {
      case 'geo_code_1':
        return $firstResult->getCoordinates()->getLatitude();

      case 'geo_code_2':
        return $firstResult->getCoordinates()->getLongitude();

      case 'timezone':
        return $firstResult->getTimezone();

      case 'city':
        return $firstResult->getLocality();

      case 'state_province_id':
        if (empty($values['country_id'])) {
          // not possible to determine state without the country.
          return 'null';
        }
        $state = $firstResult->getAdminLevels()->get(1)->getCode();
        if (!isset(\Civi::$statics[__CLASS__]['country_id'][$state])) {
          try {
            // Build our own static array as the core pseudoconstant does country limiting in a weird way.
            \Civi::$statics[__CLASS__]['country_id'][$state] = civicrm_api3('StateProvince', 'getvalue', [
              'return' => 'id',
              'abbreviation' => $state,
              'country_id' => $values['country_id'],
            ]);
          }
          catch (CiviCRM_API3_Exception $e) {
            // We just won't worry about the state.
            return 'null';
          }
        }
        return \Civi::$statics[__CLASS__]['country_id'][$state];

    }


  }


  /**
   * Get metadata about entities.
   *
   * @return array
   */
  static protected function getEntitiesMetadata() {
    $entities = array();
    geocoder_civicrm_managed($entities);
    $rekeyed = [];
    foreach ($entities as $entity) {
      $rekeyed[$entity['name']] = CRM_Utils_Array::value('metadata', $entity, []);
    }
    return $rekeyed;
  }

}
