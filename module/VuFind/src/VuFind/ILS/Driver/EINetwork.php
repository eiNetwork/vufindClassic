<?php

/**
 * EINetwork-specific adaptation of Sierra ILS driver taking advantage of Sierra API v2.
 */

/**
 * Sierra (III) ILS Driver for Vufind2
 *
 * PHP version 5
 *
 * Copyright (C) 2013 Julia Bauder
 *
 *   This program is free software: you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation, either version 3 of the License, or
 *   (at your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @category VuFind2
 * @package  ILS_Drivers
 * @author   Julia Bauder <bauderj@grinnell.edu>
 * @license  http://opensource.org/licenses/GPL-3.0 GNU General Public License
 * @link     http://vufind.org/wiki/building_an_ils_driver Wiki
 */
namespace VuFind\ILS\Driver;

use VuFind\Exception\ILS as ILSException;

/**
 * Sierra (III) ILS Driver for Vufind2
 *
 * @category VuFind2
 * @package  ILS_Drivers
 * @author   Julia Bauder <bauderj@grinnell.edu>
 * @license  http://opensource.org/licenses/GPL-3.0 GNU General Public License
 * @link     http://vufind.org/wiki/building_an_ils_driver Wiki
 */
class EINetwork extends Sierra implements
    \VuFindHttp\HttpServiceAwareInterface,
    \VuFind\Db\Table\DbTableAwareInterface
{
    use \VuFindHttp\HttpServiceAwareTrait;
    use \VuFind\Db\Table\DbTableAwareTrait;

    /**
     * Make an HTTP request
     *
     * @param string $url URL to request
     *
     * @return string
     */
    protected function sendRequest($url)
    {
        // Make the NCIP request:
        try {
            $result = $this->httpService->get($url);
        } catch (\Exception $e) {
            throw new ILSException($e->getMessage());
        }

        if (!$result->isSuccess()) {
            throw new ILSException('HTTP error');
        }

        return $result->getBody();
    }

    /**
     * Make an HTTP Sierra API request
     *
     * @param string $url URL to request
     *
     * @return string
     */
    protected function sendAPIRequest($url)
    {
        // make sure we have an access token
        if( $this->connectToSierraAPI(false) )
        {
            // Make the NCIP request:
            try {
                $client = $this->httpService->createClient($url, \Zend\Http\Request::METHOD_GET);
                $client->setHeaders(
                    array('Accept' => 'application/json; charset=UTF-8',
                          'Authorization' => ('Bearer ' . $_SESSION["SIERRA_API_TOKEN"])));
                $result = $client->send();
            } catch (\Exception $e) {
                throw new ILSException($e->getMessage());
            }

            if (!$result->isSuccess()) {
                throw new ILSException('HTTP error');
            }

            return $result->getBody();
        }
    }

    /**
     * Make an HTTP Sierra API POST request
     *
     * @param string $url  URL to request
     * @param string $body requestBody
     *
     * @return string
     */
    protected function postAPIRequest($url, $body)
    {
        // make sure we have an access token
        if( $this->connectToSierraAPI(false) )
        {
            // Make the NCIP request:
            try {
                $client = $this->httpService->createClient($url, \Zend\Http\Request::METHOD_POST);
                $client->setHeaders(
                    array('Accept' => 'application/json; charset=UTF-8',
                          'Authorization' => ('Bearer ' . $_SESSION["SIERRA_API_TOKEN"])));
                $client->setRawBody($body);
                $result = $client->send();
            } catch (\Exception $e) {
                throw new ILSException($e->getMessage());
            }

            if (!$result->isSuccess()) {
                throw new ILSException('HTTP error' . $result->getBody());
            }

            return $result->getBody();
        }
    }

    /**
     * Make an HTTP Sierra API PUT request
     *
     * @param string $url  URL to request
     * @param string $body requestBody
     *
     * @return string
     */
    protected function putAPIRequest($url, $body)
    {
        // make sure we have an access token
        if( $this->connectToSierraAPI(false) )
        {
            // Make the NCIP request:
            try {
                $client = $this->httpService->createClient($url, \Zend\Http\Request::METHOD_PUT);
                $client->setHeaders(
                    array('Accept' => 'application/json; charset=UTF-8',
                          'Authorization' => ('Bearer ' . $_SESSION["SIERRA_API_TOKEN"])));
                $client->setRawBody($body);
                $result = $client->send();
            } catch (\Exception $e) {
                throw new ILSException($e->getMessage());
            }

            if (!$result->isSuccess()) {
                throw new ILSException('HTTP error' . $result->getBody());
            }

            return $result->getBody();
        }
    }

    /**
     * Make an HTTP Sierra API DELETE request
     *
     * @param string $url  URL to request
     *
     * @return string
     */
    protected function deleteAPIRequest($url)
    {
        // make sure we have an access token
        if( $this->connectToSierraAPI(false) )
        {
            // Make the NCIP request:
            try {
                $client = $this->httpService->createClient($url, \Zend\Http\Request::METHOD_DELETE);
                $client->setHeaders(
                    array('Accept' => 'application/json; charset=UTF-8',
                          'Authorization' => ('Bearer ' . $_SESSION["SIERRA_API_TOKEN"])));
                $result = $client->send();
            } catch (\Exception $e) {
                throw new ILSException($e->getMessage());
            }

            if (!$result->isSuccess()) {
                throw new ILSException('HTTP error');
            }

            return ""; //$result->getBody();
        }
    }

    /**
     * Ensure we have a connection to the Sierra API
     *
     * @param boolean $renewConnection whether or not to force a refresh of our connection
     *
     * @return boolean
     */
    protected function connectToSierraAPI($refreshToken)
    {
        // see if we already have a valid token
        if( isset($_SESSION["SIERRA_API_TOKEN"]) && !$refreshToken ) 
        {
            if( isset($_SESSION["SIERRA_API_TOKEN_EXPIRATION"]) && (time() < $_SESSION["SIERRA_API_TOKEN_EXPIRATION"]) ) 
            {
                return true;
            }
        }

        // request a new token
        $client = $this->httpService->createClient($this->config['SIERRAAPI']['url'] . "/v2/token", \Zend\Http\Request::METHOD_POST);
        $client->setHeaders(
                array('Accept' => 'application/json; charset=UTF-8',
                      'Authorization' => ('Basic ' . base64_encode($this->config['SIERRAAPI']['apiKey'] . ':' . $this->config['SIERRAAPI']['apiSecret']))));
        $result = $client->send();
        $result = json_decode($result->getBody(), true);

        if( isset($result["access_token"]) && isset($result["expires_in"]) )
        {
            $_SESSION["SIERRA_API_TOKEN"] = $result["access_token"];
            $_SESSION["SIERRA_API_TOKEN_EXPIRATION"] = time() + $result["expires_in"];
            return true;
        }
        else
        {
            return false;
        }
    }

    /**
     * Get Patron Profile
     *
     * This is responsible for retrieving the profile for a specific patron.
     *
     * @param array $userinfo The patron array
     *
     * @throws ILSException
     * @return array          Array of the patron's profile data on success.
     */
    public function getMyProfile($userinfo)
    {
        $profile = json_decode( $this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v2/patrons/" . $userinfo['id'] . 
                                                      "?fields=names,addresses,fixedFields,phones,emails"), true );
        if(isset($profile['names'])) {
            $names = explode(',', $profile['names'][0]);
            $userinfo['firstname'] = $names[1];
            $userinfo['lastname'] = $names[0];
        }
        if(isset($profile['emails'])) {
            $userinfo['email'] = $profile['emails'][0];
        }
        if(isset($profile['universityId'])) {
            $userinfo['college'] = $profile['universityId'];
        }
        if(isset($profile['homeLibraryCode'])) {
            $userinfo['homelib'] = $profile['homeLibraryCode'];
        }
        if(isset($profile['addresses'])) {
            foreach( $profile['addresses'] as $address ) {
                $userinfo['address' . ($i + 1)] = "";
                for($j=0; $j<count($address['lines']); $j++ ) {
                    $userinfo['address' . ($i + 1)] .= (($j > 0) ? ", " : "") . $address['lines'][$j];
                }
            }
        }
        if(isset($profile['phones'])) {
            if(count($profile['phones']) > 0) {
                $userinfo['phone'] = $profile['phones'][0]['number'];
            }
            if(count($profile['phones']) > 1) {
                $userinfo['phone2'] = $profile['phones'][0]['number'];
            }
        }
        if(isset($profile['emails'])) {
            if(count($profile['emails']) > 0) {
                $userinfo['email'] = $profile['emails'][0];
            }
            if(count($profile['emails']) > 1) {
                $userinfo['email2'] = $profile['emails'][1];
            }
        }
        if(isset($profile['patronType'])) {
            $userinfo['group'] = $profile['patronType'];
        }
        if(isset($profile['expirationDate'])) {
            $userinfo['expiration'] = substr($profile['expirationDate'], 5) . "-" . substr($profile['expirationDate'], 2, 2);
        }
        if(isset($profile['fixedFields']['268'])) {
            if($profile['fixedFields']['268']['value'] == 'p') {
                $userinfo['notification'] = "Phone";
            } else if($profile['fixedFields']['268']['value'] == 'z') {
                $userinfo['notification'] = "Email";
            }
        }
        if(isset($profile['fixedFields']['53'])) {
            $userinfo['homelibrarycode'] = trim($profile['fixedFields']['53']['value']);
            $location = $this->getDbTable('Location')->getByCode($userinfo['homelibrarycode']);
            $userinfo['homelibrary'] = $location->displayName;
        }
        // info from the database
        $user = $this->getDbTable('user')->getByUsername($userinfo['cat_username'], false);
        $userinfo['preferred_library'] = $user->preferred_library;
        $userinfo['alternate_library'] = $user->alternate_library;
        $userinfo['CLEAN_cat_username'] = $user->cat_username;

        return $userinfo;
    }

    /**
     * Patron Login
     *
     * This is responsible for authenticating a patron against the catalog.
     *
     * @param string $username The patron username
     * @param string $password The patron's password
     *
     * @throws ILSException
     * @return mixed          Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function patronLogin($username, $password)
    {
        // TODO: if username is a barcode, test to make sure it fits proper format
        if ($this->config['PATRONAPI']['enabled'] == 'true') {
            // use patronAPI to authenticate customer
            $url = $this->config['PATRONAPI']['url'];
            // build patronapi pin test request
            $result = $this->sendRequest( $url . urlencode($username) . '/' . urlencode($password) . '/pintest' );

            // search for successful response of "RETCOD=0"
            if (stripos($result, "RETCOD=0") === false) {
                // pin did not match, can look up specific error to return
                // more useful info.
                return null;
            }

            // Pin did match, get patron information
            $result = $this->sendRequest($url . urlencode($username) . '/dump');

            // The following is taken and modified from patronapi.php by John Blyberg
            // released under the GPL
            $api_contents = trim(strip_tags($result));
            $api_array_lines = explode("\n", $api_contents);
            $api_data = ['PBARCODE' => false];

            foreach ($api_array_lines as $api_line) {
                $api_line = str_replace("p=", "peq", $api_line);
                $api_line_arr = explode("=", $api_line);
                $regex_match = ["/\[(.*?)\]/","/\s/","/#/"];
                $regex_replace = ['','','NUM'];
                $key = trim(
                    preg_replace($regex_match, $regex_replace, $api_line_arr[0])
                );
                $api_data[$key] = trim($api_line_arr[1]);                
            }

            if (!$api_data['PBARCODE']) {
                // no barcode found, can look up specific error to return more
                // useful info.  this check needs to be modified to handle using
                // III patron ids also.
                return null;
            }

            // return patron info
            $ret = [];
            $ret['id'] = $api_data['RECORDNUM']; // or should I return patron id num?
            $ret['cat_username'] = urlencode($username);
            $ret['cat_password'] = urlencode($password);
            $ret['CLEAN_cat_username'] = urlencode($username);

            $names = explode(',', $api_data['PATRNNAME']);
            $ret['firstname'] = $names[1];
            $ret['lastname'] = $names[0];
            //HIDDEN//$ret['email'] = $api_data['EMAILADDR'];
            $ret['major'] = null;
            $ret['college'] = $api_data['HOMELIBR'];
            $ret['homelib'] = $api_data['HOMELIBR'];
            // replace $ separator in III addresses with newline
            $ret['address1'] = str_replace("$", ", ", $api_data['ADDRESS']);
            //HIDDEN//$ret['address2'] = str_replace("$", ", ", $api_data['ADDRESS2']);
            preg_match(
                "/([0-9]{5}|[0-9]{5}-[0-9]{4})[ ]*$/", $api_data['ADDRESS'],
                $zipmatch
            );
            $ret['zip'] = $zipmatch[1]; //retrieve from address
            //HIDDEN//$ret['phone'] = $api_data['TELEPHONE'];
            //HIDDEN//$ret['phone2'] = $api_data['TELEPHONE2'];
            // Should probably have a translation table for patron type
            $ret['group'] = $api_data['PTYPE'];
            $ret['expiration'] = $api_data['EXPDATE'];
            // Only if agency module is enabled.
            $ret['region'] = $api_data['AGENCY'];

            return $ret;
        } else {
            // TODO: use screen scrape
            return null;
        }
    }

    /**
     * Get My Transactions
     *
     * This is responsible for returning a patron's checked out items.
     *
     * @param string $patron The patron's id
     *
     * @throws ILSException
     * @return array         Associative array of checked out items.
     */
    public function getMyTransactions($patron){
        $jsonVals = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v2/patrons/" . $patron['id'] . "/checkouts"));

        $checkedOutItems = [];
        for( $i=0; $i<$jsonVals->total; $i++ ) {
            $thisItem = [];

            // fill in properties
            $thisItem['source'] = "Solr";
            $thisItem['renewable'] = true;
            $thisItem['duedate'] = $jsonVals->entries[$i]->dueDate;

            // get the bib id
            $arr = explode("/", $jsonVals->entries[$i]->item);
            $itemId = $arr[count($arr)-1];
            $thisItem['item_id'] = ".i" . $itemId . $this->getCheckDigit($itemId);
            $itemInfo = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v2/items/" . $itemId));
            $thisItem['id'] = ".b" . $itemInfo->bibIds[0] . $this->getCheckDigit($itemInfo->bibIds[0]);
            $thisItem['institution_name'] = $itemInfo->location->name;
            $thisItem['borrowingLocation'] = $itemInfo->location->name;

            // get the bib info
            $bibInfo = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v2/bibs/" . $itemInfo->bibIds[0]));
            $thisItem['title'] = $bibInfo->title;
            $thisItem['publication_year'] = $bibInfo->publishYear;
            $thisItem['author'] = $bibInfo->author;

            $checkedOutItems[$i] = $thisItem;
        }
        return $checkedOutItems;
    }

    /**
     * Get My Fines
     *
     * This is responsible for returning a patron's fines.
     *
     * @param string $patron The patron's id
     *
     * @throws ILSException
     * @return mixed          Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function getMyFines($patron){
        $jsonVals = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v2/patrons/" . $patron['id'] . "/fines"));
        $fines = [];
        for( $i=0; $i<$jsonVals->total; $i++ ) {
            $thisItem = [];

            // get the bib id
            $arr = explode("/", $jsonVals->entries[$i]->item);
            $itemId = $arr[count($arr)-1];
            if( $itemId != "" ) {
                $itemInfo = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v2/items/" . $itemId));

                $thisItem['id'] = ".b" . $itemInfo->bibIds[0] . $this->getCheckDigit($itemInfo->bibIds[0]);
                $thisItem['item_id'] = ".i" . $itemId . $this->getCheckDigit($itemId);
                $thisItem['source'] = "Solr";
            }
            else
            {
                $thisItem['title'] = $jsonVals->entries[$i]->description;
            }
            $thisItem['fine'] = $jsonVals->entries[$i]->itemCharge;
            $thisItem['amount'] = $jsonVals->entries[$i]->billingFee + $jsonVals->entries[$i]->processingFee;
            $thisItem['balance'] = $jsonVals->entries[$i]->itemCharge - $jsonVals->entries[$i]->paidAmount;
            $fines[$i] = $thisItem;
        }
        return $fines;
    }

    /**
     * Get My Holds
     *
     * This is responsible for returning a patron's holds.
     *
     * @param string $patron The patron's id
     *
     * @throws ILSException
     * @return mixed          Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function getMyHolds($patron){
        $jsonVals = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v2/patrons/" . $patron['id'] . "/holds"));
        $holds = [];
        for( $i=0; $i<$jsonVals->total; $i++ ) {
            $thisItem = [];

            // get the hold id
            $arr = explode("/", $jsonVals->entries[$i]->id);
            $thisItem['hold_id'] = $arr[count($arr)-1];
            $thisItem['source'] = "Solr";
            $thisItem['location'] = $jsonVals->entries[$i]->pickupLocation->name;
            $thisItem['create'] = $jsonVals->entries[$i]->placed;
            $thisItem['expire'] = $jsonVals->entries[$i]->notNeededAfterDate;
            if( $jsonVals->entries[$i]->status->code == "i" ) {
                $thisItem['available'] = true;
            } else {
                $thisItem['position'] = $jsonVals->entries[$i]->priority;
            }
            // get the bib id
            $arr = explode("/", $jsonVals->entries[$i]->record);
            $id = $arr[count($arr)-1];
            // it's an item-level hold
            if( $arr[count($arr)-2] == "items" ) {
                $thisItem['item_id'] = ".i" . $id . $this->getCheckDigit($id);
                $itemInfo = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v2/items/" . $id));
                $thisItem['id'] = ".b" . $itemInfo->bibIds[0] . $this->getCheckDigit($itemInfo->bibIds[0]);
            // it's bib level
            } else {
                $thisItem['id'] = ".b" . $id . $this->getCheckDigit($id);
            }

            // get the bib info
            $bibInfo = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v2/bibs/" . $itemInfo->bibIds[0]));
            $thisItem['publication_year'] = $bibInfo->publishYear;

            $holds[$i] = $thisItem;
        }
        return $holds;
    }

    /**
     * Get Pick Up Locations
     *
     * This is responsible for getting a list of valid library locations for
     * holds / recall retrieval
     *
     * @param array $patron   Patron information returned by the patronLogin method.
     * @param array $holdInfo Optional array, only passed in when getting a list
     * in the context of placing a hold; contains most of the same values passed to
     * placeHold, minus the patron data.  May be used to limit the pickup options
     * or may be ignored.  The driver must not add new options to the return array
     * based on this data or other areas of VuFind may behave incorrectly.
     *
     * @throws ILSException
     * @return array        An array of associative arrays with locationID and
     * locationDisplay keys
     */
    public function getPickUpLocations($patron = false, $holdInfo = null)
    {
        $locations = $this->getDbTable('Location')->getPickupLocations();
        $pickupLocations = [];
        foreach( $locations as $loc ) {
            $pickupLocations[] = ["locationID" => $loc->code, "locationDisplay" => $loc->displayName];
        }
        return $pickupLocations;
    }

    /**
     * Get Default Pick Up Location
     *
     * Returns the default pick up location set in VoyagerRestful.ini
     *
     * @param array $patron   Patron information returned by the patronLogin method.
     * @param array $holdInfo Optional array, only passed in when getting a list
     * in the context of placing a hold; contains most of the same values passed to
     * placeHold, minus the patron data.  May be used to limit the pickup options
     * or may be ignored.
     *
     * @return string       The default pickup location for the patron.
     */
    public function getDefaultPickUpLocation($patron, $holdInfo = null)
    {
/*
        if ($holdInfo != null) {
            $details = $this->getHoldingInfoForItem(
                $patron['id'], $holdInfo['id'], $holdInfo['item_id']
            );
            $pickupLocations = $details['pickup-locations'];
            if (isset($this->preferredPickUpLocations)) {
                foreach (array_keys($details['pickup-locations']) as $locationID) {
                    if (in_array($locationID, $this->preferredPickUpLocations)) {
                        return $locationID;
                    }
                }
            }
            // nothing found or preferredPickUpLocations is empty? Return the first
            // locationId in pickupLocations array
            reset($pickupLocations);
            return key($pickupLocations);
        } else if (isset($this->preferredPickUpLocations)) {
            return $this->preferredPickUpLocations[0];
        } else {
            throw new ILSException(
                'Missing Catalog/preferredPickUpLocations config setting.'
            );
        }
*/
        return "xa";
    }

    /**
     * Renew My Items
     *
     * This is responsible for renewing a patron's items.
     *
     * @param array  $items  The items to renew
     *
     * @throws ILSException
     * @return mixed          Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function renewMyItems($items){
        return [];
    }

    /**
     * Get Renew Details
     *
     * This is responsible for providing details for an item's renewal.
     *
     * @param string $itemInfo   The item's info
     *
     * @throws ILSException
     * @return mixed          Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function getRenewDetails($itemInfo){
        return $itemInfo['item_id'];
    }

    /**
     * Place Hold
     *
     * Attempts to place a hold or recall on a particular item and returns
     * an array with result details or throws an exception on failure of support
     * classes
     *
     * @param array $details An array of item and patron data
     *
     * @throws ILSException
     * @return mixed An array of data on the request including
     * whether or not it was successful and a system message (if available)
     */
    public function placeHold($details)
    {
        $body = array('recordType' => substr($details["id"], 1, 1), 
                      'recordNumber' => (int)substr($details["id"], 2, -1), 
                      'pickupLocation' => $details['pickUpLocation'], 
                      'neededBy' => (substr($details['requiredBy'],6) . "-" . substr($details['requiredBy'],0,5)));
        $reply = json_encode( $this->postAPIRequest($this->config['SIERRAAPI']['url'] . "/v2/patrons/" . $details['patron']['id'] . "/holds/requests", json_encode($body)) );
        return ['success' => true];
    }

    /**
     * Cancel Holds
     *
     * This is responsible for cancelling a patron's holds.
     *
     * @param array  $holds  The holds to cancel
     *
     * @throws ILSException
     * @return mixed          Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function cancelHolds($holds){
        for($i=0; $i<count($holds["details"]); $i++ )
        {
            $jsonVals = json_decode($this->deleteAPIRequest($this->config['SIERRAAPI']['url'] . "/v2/patrons/holds/" . $holds["details"][$i]));
        }
        return ['success' => true];
    }

    /**
     * Freeze Holds
     *
     * This is responsible for (un)freezing a patron's holds.
     *
     * @param array  $holds  The holds to freeze
     *
     * @throws ILSException
     * @return mixed          Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function freezeHolds($holds){
        for($i=0; $i<count($holds["details"]); $i++ )
        {
            $body = array('freeze' => 'true');
            $jsonVals = json_decode($this->putAPIRequest($this->config['SIERRAAPI']['url'] . "/v2/patrons/holds/" . $holds["details"][$i]));
        }
        return ['success' => true];
    }

    /**
     * Get Cancel Hold Details
     *
     * This is responsible for providing details for an hold's cancellation.
     *
     * @param string $holdInfo   The hold's info
     *
     * @throws ILSException
     * @return mixed          Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function getCancelHoldDetails($holdInfo){
        return $holdInfo['hold_id'];
    }

    /**
     * Public Function which specifies renew, hold and cancel settings.
     *
     * @param string $function The name of the feature to be checked
     * @param array  $params   Optional feature-specific parameters (array)
     *
     * @return array An array with key-value pairs.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getConfig($function, $params = null)
    {
        if ($function == 'Holds') {
            return [
                'HMACKeys' => 'id:item_id:level',
                'extraHoldFields' =>
                    'comments:requestGroup:pickUpLocation:requiredByDate',
                'defaultRequiredDate' => 'driver:0:2:0',
            ];
        }
/*
        if ($function == 'StorageRetrievalRequests'
            && $this->storageRetrievalRequests
        ) {
            return [
                'HMACKeys' => 'id',
                'extraFields' => 'comments:pickUpLocation:requiredByDate:item-issue',
                'helpText' => 'This is a storage retrieval request help text'
                    . ' with some <span style="color: red">styling</span>.'
            ];
        }
        if ($function == 'ILLRequests' && $this->ILLRequests) {
            return [
                'enabled' => true,
                'HMACKeys' => 'number',
                'extraFields' =>
                    'comments:pickUpLibrary:pickUpLibraryLocation:requiredByDate',
                'defaultRequiredDate' => '0:1:0',
                'helpText' => 'This is an ILL request help text'
                    . ' with some <span style="color: red">styling</span>.'
            ];
        }
        if ($function == 'changePassword') {
            return [
                'minLength' => 4,
                'maxLength' => 20
            ];
        }
*/
        return [];
    }

    /**
     * Support method for placeHold -- get holding info for an item.
     *
     * @param string $patronId Patron ID
     * @param string $id       Bib ID
     * @param string $group    Item ID
     *
     * @return array
     */
    public function getHoldingInfoForItem($patronId, $id, $group)
    {
        return ['pickup-locations' => ['xa']];
/**
        $jsonVals = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v2/branches"));
        $locations = [];
        for( $i=0; $i<$jsonVals->total; $i++ ) {
            $thisLocation = $jsonVals->entries[$i];
            //$locations[$thisLocation->
        }
        return ['pickup-locations' => $locations];
/**
        list($bib, $sys_no) = $this->parseId($id);
        $resource = $bib . $sys_no;
        $xml = $this->doRestDLFRequest(
            ['patron', $patronId, 'record', $resource, 'items', $group]
        );
        $locations = [];
        $part = $xml->xpath('//pickup-locations');
        if ($part) {
            foreach ($part[0]->children() as $node) {
                $arr = $node->attributes();
                $code = (string) $arr['code'];
                $loc_name = (string) $node;
                $locations[$code] = $loc_name;
            }
        } else {
            throw new ILSException('No pickup locations');
        }
        $requests = 0;
        $str = $xml->xpath('//item/queue/text()');
        if ($str != null) {
            list($requests) = explode(' ', trim($str[0]));
        }
        $date = $xml->xpath('//last-interest-date/text()');
        $date = $date[0];
        $date = "" . substr($date, 6, 2) . "." . substr($date, 4, 2) . "."
            . substr($date, 0, 4);
        return [
            'pickup-locations' => $locations, 'last-interest-date' => $date,
            'order' => $requests + 1
        ];
/**
        return [
            'pickup-locations' => [''], 'last-interest-date' => '08.11.1982',
            'order' => 1
        ];
/**/
    }

    /**
     * Utility method to calculate a check digit for a given id.
     *
     * @param string $id       Record ID
     *
     * @return character
     */
    protected function getCheckDigit($id)
    {
        // pull off the item type if they included it
        if( !is_numeric($id) ) {
            $id = substr($id, 1);
        }
        // make sure it's a number
        if( !is_numeric($id) ) {
            return null;
        }

        // calculate it
        $checkDigit = 0;
        $multiple = 2;
        while( $id > 0 ) {
            $digit = $id % 10;
            $checkDigit += $multiple * $digit;
            $id = ($id - $digit) / 10;
            $multiple++;
        }
        $checkDigit = $checkDigit % 11;
        return ($checkDigit == 10) ? "x" : $checkDigit;
    }
}