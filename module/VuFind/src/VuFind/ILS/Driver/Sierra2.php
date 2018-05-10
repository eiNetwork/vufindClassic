<?php

/**
 * Adaptation of Sierra ILS driver taking advantage of Sierra API v2.
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
use Memcached;

/**
 * Sierra (III) ILS Driver for Vufind2
 *
 * @category VuFind2
 * @package  ILS_Drivers
 * @author   Julia Bauder <bauderj@grinnell.edu>
 * @license  http://opensource.org/licenses/GPL-3.0 GNU General Public License
 * @link     http://vufind.org/wiki/building_an_ils_driver Wiki
 */
class Sierra2 extends Sierra implements
    \VuFindHttp\HttpServiceAwareInterface
{
    use \VuFindHttp\HttpServiceAwareTrait;

    protected $authorizationCode = null;
    protected $memcached = null;

    /**
     * Initialize the driver.
     *
     * Validate configuration and perform all resource-intensive tasks needed to
     * make the driver active.
     *
     * @throws ILSException
     * @return void
     */
    public function init()
    {
        // this function does nothing, but it's necessary to override the Sierra init() function that creates a postgres connection

        // start memcached
        $this->memcached = new Memcached();
        $this->memcached->addServer('localhost', 11211);
        if( !$this->memcached->get("globalRefreshTimer") ) {
            $this->memcached->set("globalRefreshTimer", time());
        }
    }

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
    protected function sendAPIRequest($url, $method=\Zend\Http\Request::METHOD_GET, $body=null)
    {
        if( !$this->memcached->get(md5($url . ($body ? ("###" . $body) : ""))) ) {
            // make sure we have an access token
            if( $this->connectToSierraAPI(false) )
            {
                // Make the NCIP request:
                try {
                    $client = $this->httpService->createClient($url, $method, 45);
                    $client->setHeaders(
                        array('Accept' => 'application/json; charset=UTF-8',
                              'Authorization' => ('Bearer ' . $this->memcached->get("SIERRA_API_TOKEN")),
                              'Content-Type' => 'application/json',
                              'Connection' => 'close'));
                    if( $body != null ) 
                    {
                        $client->setRawBody($body);
                    }
                    $result = $client->send();
                } catch (\Exception $e) {
                    throw new ILSException($e->getMessage());
                }

                if (!$result->isSuccess()) {
                    //throw new ILSException('HTTP error<br>' . $url . '<br>' . $body . "<br>" . $result->toString());
                }

                $this->memcached->set(md5($url . ($body ? ("###" . $body) : "")), $result->getBody(), 30);
            }
        } 

        return $this->memcached->get(md5($url . ($body ? ("###" . $body) : "")));
    }

    /**
     * Ensure we have a connection to the Sierra API.
     *
     * @param boolean $renewConnection whether or not to force a refresh of our connection
     *
     * @return boolean
     */
    protected function connectToSierraAPI($refreshToken)
    {
        // see if we already have a valid token
        if( $this->memcached->get("SIERRA_API_TOKEN") && !$refreshToken ) 
        {
            if( $this->memcached->get("SIERRA_API_TOKEN_EXPIRATION") && (time() < $this->memcached->get("SIERRA_API_TOKEN_EXPIRATION")) ) 
            {
                return true;
            }
        }

        // request a new token
        $client = $this->httpService->createClient($this->config['SIERRAAPI']['url'] . "/v4/token", \Zend\Http\Request::METHOD_POST, 45);
        $client->setHeaders(
                array('Accept' => 'application/json; charset=UTF-8',
                      'Authorization' => ('Basic ' . base64_encode($this->config['SIERRAAPI']['apiKey'] . ':' . $this->config['SIERRAAPI']['apiSecret']))));
        if( $this->authorizationCode != null ) {
            $client->setRawBody( json_encode( array('grant_type' => 'authorization_code', 
                                                    'code' => $this->authorizationCode, 
                                                    'redirect_uri' => $this->config['SIERRAAPI']['redirect_url']) ) );
        }
        $result = $client->send();
        $result = json_decode($result->getBody(), true);

        if( isset($result["access_token"]) && isset($result["expires_in"]) )
        {
            $this->memcached->set("SIERRA_API_TOKEN", $result["access_token"]);
            $this->memcached->set("SIERRA_API_TOKEN_EXPIRATION", time() + $result["expires_in"]);
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
     * @param array $patron The patron array
     *
     * @throws ILSException
     * @return array          Array of the patron's profile data on success.
     */
    public function getMyProfile($patron)
    {
/** BP => Client Credentials Grant **/
        if( isset($patrons['id']) ) {
            $profile = json_decode( $this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $patron['id'] . 
                                                          "?fields=names,addresses,fixedFields,phones,emails,moneyOwed,expirationDate"), true );
        } else {
            $profile = json_decode( $this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/patrons/find?barcode=" . $patron['cat_username'] . 
                                                          "&fields=names,addresses,fixedFields,phones,emails,moneyOwed,expirationDate"), true );
        }
/** BP => Authorization Code Grant **
        $profile = json_decode( $this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/patrons/find?barcode=" . $patron['barcode'] . 
                                                      "&fields=names,addresses,fixedFields,phones,emails,moneyOwed"), true );
/** **/
        if( isset($profile['id']) ) {
            $patron['id'] = $profile['id'];
            $patron['username'] = $profile['id'];
        }
        if(isset($profile['names'])) {
            $names = explode(',', $profile['names'][0]);
            $patron['firstname'] = $names[1];
            $patron['lastname'] = $names[0];
        }
        if(isset($profile['moneyOwed'])) {
            $patron['moneyOwed'] = $profile['moneyOwed'];
        }
        if(isset($profile['emails'])) {
            $patron['email'] = $profile['emails'][0];
        }
        if(isset($profile['universityId'])) {
            $patron['college'] = $profile['universityId'];
        }
        if(isset($profile['homeLibraryCode'])) {
            $patron['homelib'] = $profile['homeLibraryCode'];
        }
        if(isset($profile['addresses'])) {
            foreach( $profile['addresses'] as $i => $address ) {
                $patron['address' . ($i + 1)] = "";
                for($j=0; $j<count($address['lines']); $j++ ) {
                    $patron['address' . ($i + 1)] .= (($j > 0) ? ", " : "") . $address['lines'][$j];
                }
            }
        }
        if(isset($profile['phones'])) {
            if(count($profile['phones']) > 0) {
                $patron['phone'] = $profile['phones'][0]['number'];
            }
            if(count($profile['phones']) > 1) {
                $patron['phone2'] = $profile['phones'][0]['number'];
            }
        }
        if(isset($profile['emails'])) {
            if(count($profile['emails']) > 0) {
                $patron['email'] = $profile['emails'][0];
            }
            if(count($profile['emails']) > 1) {
                $patron['email2'] = $profile['emails'][1];
            }
        }
        if(isset($profile['patronType'])) {
            $patron['group'] = $profile['patronType'];
        }
        if(isset($profile['expirationDate'])) {
            $patron['expiration'] = substr($profile['expirationDate'], 5) . "-" . substr($profile['expirationDate'], 2, 2);
        }
        if(isset($profile['fixedFields']['268'])) {
            if($profile['fixedFields']['268']['value'] == 'p') {
                $patron['notificationCode'] = "p";
                $patron['notification'] = "Phone";
            } else if($profile['fixedFields']['268']['value'] == 'z') {
                $patron['notificationCode'] = "z";
                $patron['notification'] = "Email";
            }
        }
        if(isset($profile['fixedFields']['53'])) {
            $patron['homelibrarycode'] = trim($profile['fixedFields']['53']['value']);
        }

        return $patron;
    }

    /**
     * Get Number of My Transactions
     *
     * This is responsible for returning the raw count of a patron's checked out items.
     *
     * @param string $patron The patron's id
     *
     * @throws ILSException
     * @return int           Count of checked out items.
     */
    public function getNumberOfMyTransactions($patron){
        $jsonVals = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $patron['id'] . "/checkouts?limit=1&offset=0"));
        return $jsonVals ? $jsonVals->total : 0;
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
        $checkedOutItems = [];
        $offset = count($checkedOutItems);

        while( !isset($jsonVals) || (count($checkedOutItems) < $jsonVals->total) ) {
            $jsonVals = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $patron['id'] . "/checkouts?limit=50&offset=" . $offset));

            // make the initial items API call to get base info
            $itemIDList = "";
            for( $i=0; $jsonVals && $i<count($jsonVals->entries); $i++ ) {
                // get the item id
                $arr = explode("/", $jsonVals->entries[$i]->item);
                $itemId = $arr[count($arr)-1];

                $itemIDList .= "," . $itemId;
            }
            if( strlen($itemIDList) > 0 ) {
                $itemInfoJson = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/items?fields=bibIds,location,varFields,callNumber&limit=50&id=" . substr($itemIDList,1)));
            } else {
                $itemInfoJson = json_decode("{\"entries\":[]}");
            }

            // parse this response into a dictionary and create the bibID list
            $bibIDList = "";
            $itemInfoList = [];
            for( $i=0; $i<count($itemInfoJson->entries); $i++ ) {
                // put this item info into the parsed dictionary
                $itemInfoList[$itemInfoJson->entries[$i]->id] = $itemInfoJson->entries[$i];
            }

            for( $i=0; $jsonVals && $i<count($jsonVals->entries); $i++ ) {
                $thisItem = [];

                // fill in properties
                $thisItem['source'] = "Solr";
                $thisItem['renewable'] = true;
                $thisItem['numberOfRenewals'] = $jsonVals->entries[$i]->numberOfRenewals;
                $thisItem['duedate'] = $jsonVals->entries[$i]->dueDate;
                $arr = explode("/", $jsonVals->entries[$i]->id);
                $thisItem['checkout_id'] = $arr[count($arr)-1];

                // get the bib id
                $arr = explode("/", $jsonVals->entries[$i]->item);
                $itemId = $arr[count($arr)-1];
                $thisItem['item_id'] = ".i" . $itemId . $this->getCheckDigit($itemId);
                if( isset($itemInfoList[$itemId]) ) {
                    $itemInfo = $itemInfoList[$itemId];
                    $thisItem['id'] = ".b" . $itemInfo->bibIds[0] . $this->getCheckDigit($itemInfo->bibIds[0]);
                    $thisItem['institution_name'] = $itemInfo->location->name;
                    $thisItem['borrowingLocation'] = $itemInfo->location->name;
                    foreach( $itemInfo->varFields as $thisVarField ) {
                        if( $thisVarField->fieldTag == "v" ) {
                            $thisItem['volumeInfo'] = $thisVarField->content;
                        }
                    }

                    // see if it's ILL
                    if( $itemInfo->location->code == "xzill" ) {
                        $thisItem['title'] = $itemInfo->callNumber;
                        $thisItem['author'] = "InterLibrary Loan";
                        $thisItem['ILL'] = true;
                    } else {
                        $thisItem['ILL'] = false;
                    }
                }

                $checkedOutItems[$i + $offset] = $thisItem;
            }

            $offset = count($checkedOutItems);
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
        $fines = [];
        $offset = count($fines);

        while( !isset($jsonVals) || (count($fines) < $jsonVals->total) ) {
            $jsonVals = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $patron['id'] . "/fines?limit=50&offset=" . $offset));

            for( $i=0; $i<count($jsonVals->entries); $i++ ) {
                $thisItem = [];

                // get the bib id
                $arr = explode("/", $jsonVals->entries[$i]->item);
                $itemId = $arr[count($arr)-1];
                if( $itemId != "" ) {
                    $itemInfo = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/items/" . $itemId));

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
                $fines[$offset + $i] = $thisItem;
            }

            $offset = count($fines);
        }
        return $fines;
    }

    /**
     * Get Number Of My Holds
     *
     * This is responsible for returning the raw count of a patron's holds.
     *
     * @param string $patron The patron's id
     *
     * @throws ILSException
     * @return int           Number of holds that this patron currently has.
     */
    public function getNumberOfMyHolds($patron){
        $jsonVals = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $patron['id'] . "/holds?limit=1&offset=0"));
        return $jsonVals ? $jsonVals->total : 0;
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
        $holds = [];
        $offset = count($holds);

        while( !isset($jsonVals) || (count($holds) < $jsonVals->total) ) {
            $jsonVals = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $patron['id'] . "/holds?limit=50&offset=" . $offset));

            // make the initial items API call to get bib info
            $itemIDList = "";
            $bibIDList = "";
            for( $i=0; $jsonVals && $i<count($jsonVals->entries); $i++ ) {
                // get the item id
                $arr = explode("/", $jsonVals->entries[$i]->record);
                $id = $arr[count($arr)-1];
                $itemId = $id;
                // it's an item-level hold
                if( count($arr) >= 2 && ($arr[count($arr)-2] == "items") ) {
                    $itemIDList .= "," . $itemId;
                } else {
                    $bibIDList .= "," . $itemId;
                }
            }
            $itemInfoList = [];
            if( strlen($itemIDList) > 0 ) {
                $itemInfoJson = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/items?fields=bibIds,varFields,location,callNumber&limit=50&id=" . substr($itemIDList,1)));

                // parse this response into a dictionary and create the bibID list
                for( $i=0; $itemInfoJson && $i<count($itemInfoJson->entries); $i++ ) {
                    // put this item info into the parsed dictionary
                    $itemInfoList[$itemInfoJson->entries[$i]->id] = $itemInfoJson->entries[$i];
                }
            }

            for( $i=0; $jsonVals && $i<count($jsonVals->entries); $i++ ) {
                $thisItem = [];

                // get the hold id
                $arr = explode("/", $jsonVals->entries[$i]->id);
                $thisItem['hold_id'] = $arr[count($arr)-1];
                $thisItem['source'] = "Solr";
                $thisItem['location'] = isset($jsonVals->entries[$i]->pickupLocation->name) ? $jsonVals->entries[$i]->pickupLocation->name : null;
                $thisItem['create'] = $jsonVals->entries[$i]->placed;
                $thisItem['expire'] = $jsonVals->entries[$i]->notNeededAfterDate;
                $thisItem['status'] = isset($jsonVals->entries[$i]->status->code) ? $jsonVals->entries[$i]->status->code : null;
                $thisItem['frozen'] = $jsonVals->entries[$i]->frozen;
                if( isset($jsonVals->entries[$i]->status->code) && in_array($jsonVals->entries[$i]->status->code, ["i", "b", "j"]) ) {
                    $thisItem['available'] = true;
                } else {
                    $thisItem['position'] = $jsonVals->entries[$i]->priority;
                }
                // get the bib id
                $arr = explode("/", $jsonVals->entries[$i]->record);
                $id = $arr[count($arr)-1];
                $bibId = $id;
                // it's an item-level hold
                if( count($arr) >= 2 && ($arr[count($arr)-2] == "items") ) {
                    $thisItem['item_id'] = ".i" . $id . $this->getCheckDigit($id);
                    if( isset($itemInfoList[$id]) ) {
                        $itemInfo = $itemInfoList[$id];
                        $thisItem['id'] = ".b" . $itemInfo->bibIds[0] . $this->getCheckDigit($itemInfo->bibIds[0]);
                        $bibId = $itemInfo->bibIds[0];
                        foreach( $itemInfo->varFields as $thisVarField ) {
                            if( $thisVarField->fieldTag == "v" ) {
                                $thisItem['volumeInfo'] = $thisVarField->content;
                            }
                        }
                    }
                    // see if it's ILL
                    if( $itemInfo->location->code == "xzill" ) {
                        $thisItem['title'] = $itemInfo->callNumber;
                        $thisItem['author'] = "InterLibrary Loan";
                        $thisItem['ILL'] = true;
                    }
                // it's bib level
                } else {
                    $thisItem['id'] = ".b" . $id . $this->getCheckDigit($id);
                }

                $holds[$offset + $i] = $thisItem;
            }

            $offset = count($holds);
        }
        return $holds;
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
        $responses = [];
        foreach($items["details"] as $checkoutID) {
            $reply = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/patrons/checkouts/" . $checkoutID . "/renewal", 
                                                       \Zend\Http\Request::METHOD_POST));
            $responses[$checkoutID] = (isset($reply->code) && isset($reply->specificCode)) ? $reply->description : true;
        }

        // clear out these intermediate cached API results
        $offset = 0;
        $hash = md5($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $items['patron']['id'] . "/checkouts?limit=50&offset=" . $offset);
        while( $this->memcached->get($hash) ) {
            $this->memcached->set($hash, null);
            $offset += 50;
            $hash = md5($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $items['patron']['id'] . "/checkouts?limit=50&offset=" . $offset);
        }

        return ["details" => $responses];
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
        $success = true;
        $body = array('recordType' => substr($details["id"], 1, 1), 
                      'recordNumber' => (int)substr($details["id"], 2, -1), 
                      'pickupLocation' => $details['pickUpLocation'], 
                      'neededBy' => (substr($details['requiredBy'],6) . "-" . substr($details['requiredBy'],0,5)));
        $jsonVals = json_decode( $this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $details['patron']['id'] . "/holds/requests", 
                                                       \Zend\Http\Request::METHOD_POST, 
                                                       json_encode($body)) );

        // clear out these intermediate cached API results
        $offset = 0;
        $hash = md5($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $details['patron']['id'] . "/holds?limit=50&offset=" . $offset);
        while( $this->memcached->get($hash) ) {
            $this->memcached->set($hash, null);
            $offset += 50;
            $hash = md5($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $details['patron']['id'] . "/holds?limit=50&offset=" . $offset);
        }

        $success &= !(isset($jsonVals->httpStatus) && ($jsonVals->httpStatus != 200));
        return ['success' => $success];
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
        $success = true;
        foreach( $holds["details"] as $thisHold )
        {
            $jsonVals = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/patrons/holds/" . $thisHold,
                                                          \Zend\Http\Request::METHOD_DELETE));
            $success &= !(isset($jsonVals->httpStatus) && ($jsonVals->httpStatus != 200));
        }

        // clear out these intermediate cached API results
        $offset = 0;
        $hash = md5($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $details['patron']['id'] . "/holds?limit=50&offset=" . $offset);
        while( $this->memcached->get($hash) ) {
            $this->memcached->set($hash, null);
            $offset += 50;
            $hash = md5($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $details['patron']['id'] . "/holds?limit=50&offset=" . $offset);
        }

        return ['success' => $success];
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
    public function freezeHolds($holds, $doFreeze){
        $success = true;
        foreach( $holds["details"] as $thisHold )
        {
            $body = json_encode(array('freeze' => $doFreeze));
            $jsonVals = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/patrons/holds/" . $thisHold, 
                                                          \Zend\Http\Request::METHOD_PUT, 
                                                          $body));
            $success &= !(isset($jsonVals->httpStatus) && ($jsonVals->httpStatus != 200));
        }

        // clear out these intermediate cached API results
        $offset = 0;
        $hash = md5($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $details['patron']['id'] . "/holds?limit=50&offset=" . $offset);
        while( $this->memcached->get($hash) ) {
            $this->memcached->set($hash, null);
            $offset += 50;
            $hash = md5($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $details['patron']['id'] . "/holds?limit=50&offset=" . $offset);
        }

        return ['success' => $success];
    }

    /**
     * Update Holds
     *
     * This is responsible for changing the location of a patron's holds.
     *
     * @param array  $holds  The holds to update
     *
     * @throws ILSException
     * @return mixed          Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function updateHolds($holds){
        $success = true;
        foreach( $holds["details"] as $thisHold )
        {
            $body = json_encode(array('pickupLocation' => $holds["newLocation"]));
            $jsonVals = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/patrons/holds/" . $thisHold, 
                                                          \Zend\Http\Request::METHOD_PUT, 
                                                          $body));
            $success &= !(isset($jsonVals->httpStatus) && ($jsonVals->httpStatus != 200));
        }

        // clear out these intermediate cached API results
        $offset = 0;
        $hash = md5($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $details['patron']['id'] . "/holds?limit=50&offset=" . $offset);
        while( $this->memcached->get($hash) ) {
            $this->memcached->set($hash, null);
            $offset += 50;
            $hash = md5($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $details['patron']['id'] . "/holds?limit=50&offset=" . $offset);
        }

        return ['success' => $success];
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

        return [];
    }

    /**
     * Public Function which updates the specified patron settings.
     *
     * @param array $patron     The patron to be updated
     * @param array $updateBody Specific parameters to change.  Consists of an array containing only keys in the following set: 
     *                            ["emails", "names", "addresses", "phones", "pin"]
     */
    public function updateMyProfile($patron, $updateBody){
        $result = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/patrons/" . $patron['id'], 
                                                    \Zend\Http\Request::METHOD_PUT, 
                                                    json_encode($updateBody)));
        return ["success" => (!isset($result->code) && !isset($result->specificCode))];
    }

    /**
     * Utility method to calculate a check digit for a given id.
     *
     * @param string $id       Record ID
     *
     * @return character
     */
    public function getCheckDigit($id)
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

    /**
     * Get Holding
     *
     * This is responsible for retrieving the holding information of a certain
     * record.
     *
     * @param string $id     The record id to retrieve the holdings for
     * @param array  $patron Patron data
     *
     * @throws DateException
     * @throws ILSException
     * @return array         On success, an associative array with the following
     * keys: id, availability (boolean), status, location, reserve, callnumber,
     * duedate, number, barcode.
     */
    public function getHolding($id, array $patron = null)
    {
        try {
            $holdings = [];
            $pageSize = 100;
            $currentOffset = 0;
            $itemIDlist = ($this->memcached->get("items" . $id) !== null) ? $this->memcached->get("items" . $id) : null;
            do {
                $processed = 0;
                $idList = "";
                for( $i=$currentOffset; $i<($currentOffset + $pageSize) && ($itemIDlist !== null) && $i<count($itemIDlist); $i++ ) {
                  $itemID = substr($itemIDlist[$i],1);
                  if( $this->memcached->get("itemInfo" . $itemID) ) {
                    $holdings[] = $this->memcached->get("itemInfo" . $itemID);
                  } else {
                    $idList .= ($idList ? "," : "") . $itemID;
                  }
                  $processed++;
                }
                $apiHoldings = null;
                if( $idList ) {
                  $url = $this->config['SIERRAAPI']['url'] . "/v4/items/?fields=id,status,location,callNumber,barcode,varFields,fixedFields&suppressed=false&id=" . $idList . "&limit=" . $pageSize;
                  $apiHoldings = json_decode($this->sendAPIRequest($url));
                }
                if( ($itemIDlist == null) && !isset($apiHoldings->entries) ) {
                    break;
/***** BJP => This is commented out for now, due to the API not giving us this info anymore.
              It is supposed to return sometime soon, so we can just uncomment it and remove 
              the orders functionality added to the EINetwork driver.  At that point, we also
              need to remove the break above.
                    //If there are no attached items, check to see if it is on order
                    $apiOrders = json_decode($this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v4/bibs?fields=id,orders&suppressed=false&id=" . substr($id,2,-1) . "&limit=" . $pageSize . "&offset=" . $currentOffset));
                    if( !isset($apiOrders->entries) ) {
                        break;
                    }
                    foreach($apiOrders->entries as $thisEntry) {
                        foreach($thisEntry->orders as $thisItem) {
                            //log(print_r(array(json_encode($thisItem))));
                            $itemInfo = [
                                "id" => $id,
                                "itemId" => null,
                                "availability" => false,
                                "status" => "order",
                                "location" => $thisItem->location->name,
                                "reserve" => "N",
                                "callnumber" => null,
                                "duedate" => null,
                                "returnDate" => false,
                                "number" => null,
                                "barcode" => null,
                                "locationCode" => $thisItem->location->code,
                                "copiesOwned" => $thisItem->copies
                            ];
                            $holdings[] = $itemInfo;
                        }
                    }
*****/
                } else if( $apiHoldings ) {
                    foreach($apiHoldings->entries as $thisItem) {
                        $number = null;
                        if( isset($thisItem->varFields) ) {
                            foreach($thisItem->varFields as $thisVarField) {
                                if( $thisVarField->fieldTag == "v" ) {
                                    $number = $thisVarField->content;
                                }
                            }
                        }
                        $opacMsg = isset($thisItem->fixedFields) ? json_decode(json_encode($thisItem->fixedFields), true)["108"] : null;
                        $noRequests = $opacMsg && ($opacMsg["value"] == "n");
                        $itemInfo = [
                            "id" => $id,
                            "itemId" => $thisItem->id,
                            "availability" => (isset($thisItem->status->code) && in_array(trim($thisItem->status->code), ["-","o","p","v","y"])) && !isset($thisItem->status->duedate),
                            "status" => isset($thisItem->status->code) ? trim($thisItem->status->code) : null,
                            "location" => isset($thisItem->location->name) ? $thisItem->location->name : null,
                            "reserve" => "N",
                            "callnumber" => (isset($thisItem->status->code) && ($thisItem->status->code == "p")) ? "Display - Please ask librarian" : (isset($thisItem->callNumber) ? (str_replace("|a", " ", $thisItem->callNumber) . ($noRequests ? "<br>NO REQUESTS" : "")) : null),
                            "duedate" => isset($thisItem->status->duedate) ? $thisItem->status->duedate : null,
                            "returnDate" => false,
                            "number" => $number,
                            "barcode" => $thisItem->barcode,
                            "locationCode" => isset($thisItem->location->code) ? $thisItem->location->code : null,
                            "copiesOwned" => 1
                            ];
                        $holdings[] = $itemInfo;
                        $this->memcached->set("itemInfo" . $thisItem->id, $itemInfo, 900);
                        $processed += ($itemIDlist === null) ? 1 : 0;
                    }
                }
                $currentOffset += $pageSize;
            } while( $processed == $pageSize || (($itemIDlist != null) && ($currentOffset < count($itemIDlist)) && ($currentOffset == 0)) );

            return $holdings;
        } catch (\Exception $e) {
            throw new ILSException($e->getMessage());
        }
    }

    /**
     * Preload Items
     *
     * This is responsible for retrieving the holding information of a list of items.
     *
     * @param array $ids     The item ids to retrieve the holdings for
     *
     * @throws DateException
     * @throws ILSException
     * @return bool Success
     */
    public function preloadItems(array $ids)
    {
        try {
            $pageSize = 100;
            $currentOffset = 0;
            do {
                $processed = 0;
                $idList = "";
                for( $i=$currentOffset; $i<($currentOffset + $pageSize) && $i<count($ids); $i++ ) {
                    $itemID = substr($ids[$i],1);
                    if( !$this->memcached->get("itemInfo" . $itemID) ) {
                        $idList .= (($idList == "") ? "" : ",") . $itemID;
                    } else {
                        $processed++;
                    }
                }

                if( $idList ) {
                    $url = $this->config['SIERRAAPI']['url'] . "/v4/items/?fields=id,bibIds,status,location,callNumber,barcode,varFields,fixedFields&suppressed=false&id=" . $idList . "&limit=" . $pageSize;
                    $apiHoldings = json_decode($this->sendAPIRequest($url));

                    foreach($apiHoldings->entries as $thisItem) {
                        $number = null;
                        if( isset($thisItem->varFields) ) {
                            foreach($thisItem->varFields as $thisVarField) {
                                if( $thisVarField->fieldTag == "v" ) {
                                    $number = $thisVarField->content;
                                }
                            }
                        }
                        $opacMsg = isset($thisItem->fixedFields) ? json_decode(json_encode($thisItem->fixedFields), true)["108"] : null;
                        $noRequests = $opacMsg && ($opacMsg["value"] == "n");
                        $itemInfo = [
                          "id" => ".b" . $thisItem->bibIds[0] . $this->getCheckDigit($thisItem->bibIds[0]),
                          "itemId" => $thisItem->id,
                          "availability" => ((trim($thisItem->status->code) == "-") || (trim($thisItem->status->code) == "o") || (trim($thisItem->status->code) == "p")) && !isset($thisItem->status->duedate),
                          "status" => trim($thisItem->status->code),
                          "location" => $thisItem->location->name,
                          "reserve" => "N",
                          "callnumber" => isset($thisItem->callNumber) ? (str_replace("|a", " ", $thisItem->callNumber) . ($noRequests ? "<br>NO REQUESTS" : "")) : null,
                          "duedate" => isset($thisItem->status->duedate) ? $thisItem->status->duedate : null,
                          "returnDate" => false,
                          "number" => $number,
                          "barcode" => $thisItem->barcode,
                          "locationCode" => $thisItem->location->code,
                          "copiesOwned" => 1
                        ];
                        $holdings[] = $itemInfo;
                        $this->memcached->set("itemInfo" . $thisItem->id, $itemInfo, 900);
                        $processed++;
                    }
                }
                $currentOffset += $pageSize;
            } while( $processed == $pageSize || (($ids != null) && ($currentOffset < count($ids)) && ($currentOffset == 0)) );

            return true;
        } catch (\Exception $e) {
            throw new ILSException($e->getMessage());
        }
    }

    /**
     * Get Status
     *
     * This is responsible for retrieving the status information of a certain
     * record.
     *
     * @param string $id The record id to retrieve the holdings for
     *
     * @throws ILSException
     * @return mixed     On success, an associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber.
     */
    public function getStatus($id)
    {
        return $this->getHolding($id);
    }
}