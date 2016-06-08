<?php

/**
 * EINetwork-specific adaptation of Sierra2 ILS driver
 */

namespace VuFind\ILS\Driver;

use VuFind\Exception\ILS as ILSException;
use Zend\Session\Container as SessionContainer;
use DateTime;

class EINetwork extends Sierra2 implements
    \VuFind\Db\Table\DbTableAwareInterface
{
    use \VuFind\Db\Table\DbTableAwareTrait;
    use \VuFind\ILS\Driver\OverDriveTrait;

    protected $session = null;

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
        parent::init();

        // create the session
        $this->session = new SessionContainer("EINetwork");
    }

    public function getConfigVar($section, $name) {
        return $this->config[$section][$name];
    }

    public function getSessionVar($name) {
        return $this->session[$name];
    }

    public function clearSessionVar($name) {
        unset($this->session[$name]);
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
/** BP => Client Credentials Grant **/
        // TODO: if username is a barcode, test to make sure it fits proper format
        if ($this->config['PATRONAPI']['enabled'] == 'true') {

            if( $cachedInfo = $this->session->patronLogin ) {
                return $cachedInfo;
            }

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

            $names = explode(',', $api_data['PATRNNAME']);
            $ret['firstname'] = $names[1];
            $ret['lastname'] = $names[0];
            $ret['email'] = $api_data['EMAILADDR'];
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

            $this->session->patronLogin = $ret;
            return $ret;
        } else {
            // TODO: use screen scrape
            return null;
        }
/** BP => Authorization Code Grant **
        if( substr($password, 0, 12) == "LOGINSUCCESS" ) {
            $this->authorizationCode = substr($password, 12);

            // get their profile data
            $profileData = $this->getMyProfile(array('barcode' => $username));
            echo "##" . json_encode($profileData) . "##<br>";
            return $profileData;
        }
        return null;
/** **/
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
    public function getMyProfile($patron, $forceReload=false)
    {
        if( !$forceReload && $this->session->patron ) {
            return $this->session->patron;
        }

        $patron = parent::getMyProfile($patron);

        $location = $this->getDbTable('Location')->getByCode($patron['homelibrarycode']);
        $patron['homelibrary'] = $location->displayName;

        // info from the database
        $user = $this->getDbTable('user')->getByUsername($patron['cat_username'], false);
        $patron['preferredlibrarycode'] = $user->preferred_library;
        $location = $this->getDbTable('location')->getByCode($patron['preferredlibrarycode']);
        $patron['preferredlibrary'] = ($location != null) ? $location->displayName : null;
        $patron['alternatelibrarycode'] = $user->alternate_library;
        $location = $this->getDbTable('location')->getByCode($patron['alternatelibrarycode']);
        $patron['alternatelibrary'] = ($location != null) ? $location->displayName : null;

        // overdrive info
        $lendingOptions = $this->getOverDriveLendingOptions($patron);
        $patron['OD_eBook'] = $lendingOptions["eBook"];
        $patron['OD_audiobook'] = $lendingOptions["Audiobook"];
        $patron['OD_video'] = $lendingOptions["Video"];
        $patron['OD_renewalInDays'] = $lendingOptions["renewalInDays"];

        $this->session->patron = $patron;
        return $patron;
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
        if( $this->session->pickup_locations ) {
            return $this->session->pickup_locations;
        }

        $locations = $this->getDbTable('Location')->getPickupLocations();
        $pickupLocations = [];
        foreach( $locations as $loc ) {
            $pickupLocations[] = ["locationID" => $loc->code, "locationDisplay" => $loc->displayName];
        }
        $this->session->pickup_locations = $pickupLocations;
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

    public function getStatus($id) {
        return $this->getHolding($id);
    }
	
    public function getHolding($id, array $patron = null)
    {
        if( ($overDriveId = $this->getOverDriveID($id)) ) {
            $availability = $this->getProductAvailability($overDriveId);
            return [[
                    "id" => $id,
                    "location" => "OverDrive",
                    "isOverDrive" => true,
                    "copiesOwned" => $availability->collections[0]->copiesOwned,
                    "copiesAvailable" => $availability->collections[0]->copiesAvailable,
                    "numberOfHolds" => $availability->collections[0]->numberOfHolds,
                    "availability" => ($availability->collections[0]->copiesAvailable > 0)
                   ]];
        }
        $results = parent::getHolding($id, $patron);

        // add in the extra details we need
        $results2 = [];
        for($i=0; $i<count($results); $i++) {
            // clean call number
            $pieces = explode("|f", $results[$i]['callnumber']);
            $results[$i]['callnumber'] = "";
            foreach( $pieces as $piece ) {
                $results[$i]['callnumber'] .= (($results[$i]['callnumber'] == "") ? "" : "<br>") . trim($piece);
            }

            // insert the display status
            if( $results[$i]['status'] == '-' ) {
                $results[$i]['displayStatus'] = ($results[$i]['duedate'] == null) ? "AVAILABLE" : "CHECKED OUT";
            } else if( $results[$i]['status'] == 'n' ) {
                $results[$i]['displayStatus'] = "BILLED";
            } else if( $results[$i]['status'] == 'q' ) {
                $results[$i]['displayStatus'] = "BINDERY";
            } else if( $results[$i]['status'] == 'z' ) {
                $results[$i]['displayStatus'] = "CLMS RETD";
            } else if( $results[$i]['status'] == 'd' ) {
                $results[$i]['displayStatus'] = "DAMAGED";
            } else if( $results[$i]['status'] == 'p' ) {
                $results[$i]['displayStatus'] = "DISPLAY";
            } else if( $results[$i]['status'] == '%' ) {
                $results[$i]['displayStatus'] = "ILL RETURNED";
            } else if( $results[$i]['status'] == 'i' ) {
                $results[$i]['displayStatus'] = "IN PROCESSING";
            } else if( $results[$i]['status'] == 't' ) {
                $results[$i]['displayStatus'] = "IN TRANSIT";
            } else if( $results[$i]['status'] == 'f' ) {
                $results[$i]['displayStatus'] = "LONG OVERDUE";
            } else if( $results[$i]['status'] == '$' ) {
                $results[$i]['displayStatus'] = "LOST AND PAID";
            } else if( $results[$i]['status'] == 'm' ) {
                $results[$i]['displayStatus'] = "MISSING";
            } else if( $results[$i]['status'] == 'o' ) {
                $results[$i]['displayStatus'] = "NONCIRCULATING";
            } else if( $results[$i]['status'] == '!' ) {
                $results[$i]['displayStatus'] = "ON HOLDSHELF";
            } else if( $results[$i]['status'] == 'v' ) {
                $results[$i]['displayStatus'] = "ONLINE";
            } else if( $results[$i]['status'] == 'y' ) {
                $results[$i]['displayStatus'] = "ONLINE REFERENCE";
            } else if( $results[$i]['status'] == '^' ) {
                $results[$i]['displayStatus'] = "RENOVATION";
            } else if( $results[$i]['status'] == 'r' ) {
                $results[$i]['displayStatus'] = "REPAIR";
            } else if( $results[$i]['status'] == 'u' ) {
                $results[$i]['displayStatus'] = "STAFF USE";
            } else if( $results[$i]['status'] == '?' ) {
                $results[$i]['displayStatus'] = "STORAGE";
            } else if( $results[$i]['status'] == 'w' ) {
                $results[$i]['displayStatus'] = "WITHDRAWN";
            } else {
                $results[$i]['displayStatus'] = "UNKNOWN";
            }

            // get shelving details
            $shelfLoc = $this->getDBTable('shelvinglocation')->getByCode($results[$i]['locationCode']);
            $location = (isset($shelfLoc) && $shelfLoc) ? $this->getDBTable('location')->getByLocationId($shelfLoc->locationId) : null;
            $results[$i]['branchName'] = $location ? $location->displayName : null;
            $results[$i]['shelvingLocation'] = $shelfLoc ? $shelfLoc->shortName : null;

            for($j=0; $j<count($results2) && $results[$i]['branchName'] > $results2[$j]['branchName']; $j++) {}
            array_splice($results2, $j, 0, [$results[$i]]);
        }
        return $results2;
    }

    public function updateMyProfile($patron, $updatedInfo){
        // update the phone, email, and/or notification setting
        if( isset($updatedInfo['phones']) || isset($updatedInfo['emails']) || isset($updatedInfo['pin']) || isset($updatedInfo['notices']) ) {
            // see whether they have given us the notification setting
            if( isset($updatedInfo['notices']) ) {
                //Login to the patron's account
                $cookieJar = tempnam ("/tmp", "CURLCOOKIE");
                $success = false;

                $curl_url = $this->config['Catalog']['classic_url'] . "/patroninfo";

                $curl_connection = curl_init($curl_url);
                curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
                curl_setopt($curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
                curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl_connection, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($curl_connection, CURLOPT_FOLLOWLOCATION, 1);
                curl_setopt($curl_connection, CURLOPT_UNRESTRICTED_AUTH, true);
                curl_setopt($curl_connection, CURLOPT_COOKIEJAR, $cookieJar );
                curl_setopt($curl_connection, CURLOPT_COOKIESESSION, false);
                curl_setopt($curl_connection, CURLOPT_POST, true);
                $post_string = 'code=' . $patron['cat_username'] . '&pin=' . $patron['cat_password']  . '&submit=submit';
                curl_setopt($curl_connection, CURLOPT_POSTFIELDS, $post_string);
                $sresult = curl_exec($curl_connection);

                $curl_url = $this->config['Catalog']['classic_url'] . "/patroninfo~S1/" . $patron['id'] ."/modpinfo";
                $post_string = 'notices=' . $updatedInfo['notices'];
                curl_setopt($curl_connection, CURLOPT_POSTFIELDS, $post_string);
                curl_setopt($curl_connection, CURLOPT_URL, $curl_url);
                $sresult = curl_exec($curl_connection);
                curl_close($curl_connection);
                unlink($cookieJar);
            }
            return parent::updateMyProfile($patron, $updatedInfo);
        }

        // see whether they have given us an updated preferred library
        if( isset($updatedInfo['preferred_library']) ) {
            $user = $this->getDbTable('user')->getByUsername($patron['cat_username'], false);
            $user->changePreferredLibrary($updatedInfo['preferred_library']);
        }

        // see whether they have given us an updated alternate library
        if( isset($updatedInfo['alternate_library']) ) {
            $user = $this->getDbTable('user')->getByUsername($patron['cat_username'], false);
            $user->changeAlternateLibrary($updatedInfo['alternate_library']);
        }

        // see whether they have updated their overdrive lending periods
        $formats = array("ebook", "audiobook", "video");
        foreach( $formats as $thisFormat ) {
            if( isset($updatedInfo[$thisFormat]) ) {
                $lendInfo = array("cat_username" => $patron['cat_username'],
                                  "cat_password" => $patron['cat_password'],
                                  "format" => $thisFormat,
                                  "days" => $updatedInfo[$thisFormat] );
                $this->setOverDriveLendingOption($lendInfo);
            }
        }

        // see whether they have given us the notification setting
        if( isset($updatedInfo['notices']) ) {
/*
            //Login to the patron's account
            $cookieJar = tempnam ("/tmp", "CURLCOOKIE");
            $success = false;

            $curl_url = $this->config['Catalog']['classic_url'] . "/patroninfo";

            $curl_connection = curl_init($curl_url);
            curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
            curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl_connection, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl_connection, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($curl_connection, CURLOPT_UNRESTRICTED_AUTH, true);
            curl_setopt($curl_connection, CURLOPT_COOKIEJAR, $cookieJar );
            curl_setopt($curl_connection, CURLOPT_COOKIESESSION, false);
            curl_setopt($curl_connection, CURLOPT_POST, true);
            $post_items = array('code=' . $patron['cat_username'], 'pin=' . $patron['cat_password']);
            $post_string = implode ('&', $post_items);
echo $curl_url. "<br>";
echo $post_string. "<br>";
            curl_setopt($curl_connection, CURLOPT_POSTFIELDS, $post_string);
            $sresult = curl_exec($curl_connection);
echo $sresult . "<br>";
echo (strpos("PATTON", $sresult) ? "TRUE" : "FALSE") . "<br>";

/*
            //Issue a post request to update the patron information
            $post_items = array('notices' => $updatedInfo['notices']);
            $patronUpdateParams = implode ('&', $post_items);
            curl_setopt($curl_connection, CURLOPT_POSTFIELDS, $patronUpdateParams);
            $scope = isset($scope) ? $scope : null;
            $curl_url = $this->config['Catalog']['classic_url'] . "/patroninfo~S1/" . $patron['id'] ."/modpinfo";
            curl_setopt($curl_connection, CURLOPT_URL, $curl_url);
            $sresult = curl_exec($curl_connection);
echo $curl_url . "<br>";
echo $patronUpdateParams . "<br>";
echo $sresult . "<br>";
            curl_close($curl_connection);
            unlink($cookieJar);

/*
        //$logger->log("After updating phone number = " . $patronDump['TELEPHONE']);

        //Should get Patron Information Updated on success
        if (preg_match('/Patron information updated/', $sresult)){
            $patronDump = $this->_getPatronDump($this->_getBarcode(), true);
            $user->phone = $_REQUEST['phone'];
            $user->email = $_REQUEST['email'];
            $user->update();
            //Update the serialized instance stored in the session
            $_SESSION['userinfo'] = serialize($user);
            return "Your information was updated successfully.  It may take a minute for changes to be reflected in the catalog.";
        }else{
            return "Your patron information could not be updated.";
        }
/*
        //Setup the call to Millennium
        $id2= $patronId;
        $patronDump = $this->_getPatronDump($this->_getBarcode());
        //$logger->log("Before updating patron info phone number = " . $patronDump['TELEPHONE'], PEAR_LOG_INFO);

        $this->_updateVuFindPatronInfo($patronId);

        //Update profile information
        $extraPostInfo = array();
        $extraPostInfo['tele1'] = $_REQUEST['phone'];
        $extraPostInfo['email'] = $_REQUEST['email'];
        if (isset($_REQUEST['notices'])){
            $extraPostInfo['notices'] = $_REQUEST['notices'];
        }

        //Login to the patron's account
        $cookieJar = tempnam ("/tmp", "CURLCOOKIE");
        $success = false;

        $curl_url = $this->config['Catalog']['url'] . "/patroninfo";

        $curl_connection = curl_init($curl_url);
        curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
        curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_connection, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl_connection, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl_connection, CURLOPT_UNRESTRICTED_AUTH, true);
        curl_setopt($curl_connection, CURLOPT_COOKIEJAR, $cookieJar );
        curl_setopt($curl_connection, CURLOPT_COOKIESESSION, false);
        curl_setopt($curl_connection, CURLOPT_POST, true);
        $post_data = $this->_getLoginFormValues($patronDump);
        foreach ($post_data as $key => $value) {
            $post_items[] = $key . '=' . urlencode($value);
        }
        $post_string = implode ('&', $post_items);
        curl_setopt($curl_connection, CURLOPT_POSTFIELDS, $post_string);
        $sresult = curl_exec($curl_connection);

        //Issue a post request to update the patron information
        $post_items = array();
        foreach ($extraPostInfo as $key => $value) {
            $post_items[] = $key . '=' . urlencode($value);
        }
        $patronUpdateParams = implode ('&', $post_items);
        curl_setopt($curl_connection, CURLOPT_POSTFIELDS, $patronUpdateParams);
        $scope = isset($scope) ? $scope : null;
        $curl_url = $configArray['Catalog']['url'] . "/patroninfo~S{$scope}/" . $patronDump['RECORD_#'] ."/modpinfo";
        curl_setopt($curl_connection, CURLOPT_URL, $curl_url);
        $sresult = curl_exec($curl_connection);

        curl_close($curl_connection);
        unlink($cookieJar);

        //$logger->log("After updating phone number = " . $patronDump['TELEPHONE']);

        //Should get Patron Information Updated on success
        if (preg_match('/Patron information updated/', $sresult)){
            $patronDump = $this->_getPatronDump($this->_getBarcode(), true);
            $user->phone = $_REQUEST['phone'];
            $user->email = $_REQUEST['email'];
            $user->update();
            //Update the serialized instance stored in the session
            $_SESSION['userinfo'] = serialize($user);
            return "Your information was updated successfully.  It may take a minute for changes to be reflected in the catalog.";
        }else{
            return "Your patron information could not be updated.";
        }
*/
        }

        unset($this->session->patron);
        $this->getMyProfile($patron);
    }

    /**
     * Convenience function to test whether a given Solr ID value corresponds to an OverDrive item
     *
     * @param  string $id a Solr ID value
     * 
     * @return mixed  OverDrive ID if the Solr ID maps to an OverDrive item, false if not
     */
    public function getOverDriveID($id) {
        // make sure it's an econtent item
        if( substr($id, 0, 14) == "econtentRecord" )
        {
            // grab a bit more information from Solr
            $curl_url = "http://localhost:8080/solr/biblio/select?q=*%3A*&fq=id%3A%22" . $id . "%22&fl=econtent_source,externalId&wt=csv";
            $curl_connection = curl_init($curl_url);
            curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
            curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
            $sresult = curl_exec($curl_connection);
            $values = explode("\n", $sresult);

            // is it an OverDrive item?
            if( explode(",", $values[1])[0] == "OverDrive" ) {
                return explode(",", $values[1])[1];
            }
        }

        // not OverDrive
        return false;
    }

    /**
     * Convenience function to get the Solr Record corresponding to a given externalId
     *
     * @param  string $id an externalId value
     * 
     * @return mixed  A Solr record if the externalId maps to a Solr item, false if not
     */
    public function getSolrRecordFromExternalId($id) {
        // grab a bit more information from Solr
        $curl_url = "http://localhost:8080/solr/biblio/select?q=*%3A*&fq=externalId%3A%22" . strtolower($id) . "%22&wt=csv";
        $curl_connection = curl_init($curl_url);
        curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
        curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
        $sresult = curl_exec($curl_connection);
        $values = explode("\n", $sresult);

        // is it a Solr item?
        if( count($values) > 2 ) {
            $item = array();
            $fieldNames = explode(",", $values[0]);

            // we have to do some hocus pocus here since the values can also include the  delimiter
            $fieldValues = explode(",", $values[1]);
            for($i=0;$i<count($fieldValues);$i++) {
                while( substr($fieldValues[$i], 0, 1) == "\"" && substr($fieldValues[$i], -1) != "\"") {
                    array_splice($fieldValues, $i, 2, $fieldValues[$i] . "\," . $fieldValues[$i+1]);
                }
            }

            for($i=0; $i<count($fieldNames); $i++) {
                $item[$fieldNames[$i]] = $fieldValues[$i];
            }
            return $item;
        }

        // not in Solr
        return false;
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
    public function getMyHolds($patron) {
        if( isset($this->session->holds) ) {
            return $this->session->holds;
        }

        $sierraHolds = parent::getMyHolds($patron);
        $overDriveHolds = $this->getOverDriveHolds((object)$patron);
        foreach($overDriveHolds as $hold) {
            $solrInfo = $this->getSolrRecordFromExternalId($hold["overDriveId"]);
            if($solrInfo) {
                foreach($solrInfo as $key => $value) {
                    $hold[$key] = $value;
                }
                $sierraHolds[] = $hold;
            }
        }
        $this->session->holds = $sierraHolds;
        return $this->session->holds;
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
        if( isset($this->session->checkouts) ) {
            return $this->session->checkouts;
        }

        $sierraTransactions = parent::getMyTransactions($patron);
        $overDriveTransactions = $this->getOverDriveCheckedOutItems((object)$patron);
        foreach($overDriveTransactions as $item) {
            $solrInfo = $this->getSolrRecordFromExternalId($item["overDriveId"]);
            if($solrInfo) {
                foreach($solrInfo as $key => $value) {
                    $item[$key] = $value;
                }
                $sierraTransactions[] = $item;
            }
        }
        $this->session->checkouts = $sierraTransactions;
        return $this->session->checkouts;
    }

    /**
     * Checkout
     *
     * This is responsible for checking out an item
     *
     * @param string $patron The patron's id
     *
     * @throws ILSException
     * @return mixed          Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
/*
    public function checkout($patron) {
        return true;
    }
*/

    /**
     * Get Default "Hold Required By" Date (as Unix timestamp) or null if unsupported
     *
     * @param array $patron   Patron information returned by the patronLogin method.
     * @param array $holdInfo Contains most of the same values passed to
     * placeHold, minus the patron data.
     *
     * @return int
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getHoldDefaultRequiredDate($patron, $holdInfo)
    {
        // 1 year in the future
        return mktime(0, 0, 0, date('m'), date('d'), date('Y')+1);
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
        // invalidate the cached data
        unset($this->session->holds);

        return parent::placeHold($details);
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
        // invalidate the cached data
        unset($this->session->holds);

        $success = true;
        $overDriveHolds = [];
        for($i=0; $i<count($holds["details"]); $i++ )
        {
            if( substr($holds["details"][$i], 0, 9) == "OverDrive" ) {
                $overDriveHolds[] = substr(array_splice($holds["details"], $i, 1)[0], 9);
                $i--;
            }
        }

        // process the overdrive holds
        foreach($overDriveHolds as $overDriveID ) {
            $overDriveResults = $this->cancelOverDriveHold($overDriveID, $holds["patron"]);
            $success &= $overDriveResults["result"];
        }

        // process the sierra holds
        if( count($holds["details"]) > 0 ) {
            $sierraResults = parent::cancelHolds($holds);
            $success &= $sierraResults["success"];
        }

        return ["success" => $success];
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
        // invalidate the cached data
        unset($this->session->holds);

        $success = true;
        $overDriveHolds = [];
        for($i=0; $i<count($holds["details"]); $i++ )
        {
            if( substr($holds["details"][$i], 0, 9) == "OverDrive" ) {
                $overDriveHolds[] = substr(array_splice($holds["details"], $i, 1)[0], 9);
                $i--;
            }
        }

        // process the overdrive holds
        foreach($overDriveHolds as $overDriveID ) {
            $overDriveResults = $this->freezeOverDriveHold($overDriveID, $holds["patron"], $doFreeze);
            $success &= $overDriveResults["result"];
        }

        // process the sierra holds
        if( count($holds["details"]) > 0 ) {
            $sierraResults = parent::freezeHolds($holds, $doFreeze);
            $success &= $sierraResults["success"];
        }

        return ["success" => $success];
    }

    /**
     * Update Holds
     *
     * Attempts to update the pickup location for an array of holds and returns
     * an array with result details or throws an exception on failure of support
     * classes
     *
     * @param array $holds The holds to update and the location to change them to
     *
     * @throws ILSException
     * @return mixed An array of data on the request including
     * whether or not it was successful and a system message (if available)
     */
    public function updateHolds($holds)
    {
        // invalidate the cached data
        unset($this->session->holds);

        // weed out overdrive holds, there's nothing we can do there
        for($i=0; $i<count($holds["details"]); $i++ )
        {
            if( substr($holds["details"][$i], 0, 9) == "OverDrive" ) {
                $overDriveHolds[] = substr(array_splice($holds["details"], $i, 1)[0], 9);
                $i--;
            }
        }

        if( count($holds["details"]) > 0 ) {
            return parent::updateHolds($holds);
        } else {
            return [];
        }
    }

    /**
     * Get notifications
     *
     * This is responsible for grabbing a few static notifications based on a patron's profile information.
     *
     * @param array  $profile  The patron's info
     *
     * @return array           Associative array of notifications
     */
    public function getNotifications($profile){
        $notifications = [];
        if( $profile["moneyOwed"] > 0 ) {
            $notifications[] = ["subject" => "You have fines!", "message" => "Your account currently has $" . number_format($profile["moneyOwed"],2) . " worth of fines applied to it.  Please pay this as soon as possible!"];
        }
        if( $profile["preferredlibrarycode"] == null ) {
            $notifications[] = ["subject" => "Choose a preferred library", "message" => "You have not yet chosen a preferred library.  Doing so will make requesting holds on physical " .
                                                                                        "items much easier, since your preferred library is used as the default pickup location.  You can " .
                                                                                        "assign a preferred library on the profile page."];
        }
        if( date_diff(date_create_from_format("m-d-y", $profile["expiration"]), date_create(date("Y-m-d")))->invert == 0 ) {
            $notifications[] = ["subject" => "Card expired", "message" => "Your library card is expired. Please visit your local library to renew your card to ensure access to all online services."];
        } else if( date_diff(date_create_from_format("m-d-y", $profile["expiration"]), date_create(date("Y-m-d")))->days <= 30 ) {
            $notifications[] = ["subject" => "Card expiration approaching", "message" => "Your library card is due to expire within the next 30 days. Please visit your local library to " .
                                                                                         "renew your card to ensure access to all online services."];
        }
        return $notifications;
    }



    /**
     * Screen Scraping functionality
     * 
     * The functions after this point leverage the screen scraping functionality from our previous iteration of the catalog.
     * These actions should eventually be available via the Sierra API (and as such be implemented in the Sierra2 driver), 
     * but our current version of the API does not have them available at this point.
     */



    public function selfRegister($params){
        $curl_url = $this->config['Catalog']['classic_url'] . "/selfreg~S1";

        $curl_connection = curl_init($curl_url);
        curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
        curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_connection, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl_connection, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl_connection, CURLOPT_UNRESTRICTED_AUTH, true);

        $post_data = array();
        $post_data['nfirst'] = $params["firstName"];
        $post_data['nlast'] = $params["lastName"];
        $post_data['stre_aaddress'] = $params["address1"];
        $post_data['city_aaddress'] = $params["cityStateZip"];
        $post_data['zemailaddr'] = $params["email"];
        $post_data['tphone1'] = $params["phone"];
        foreach ($post_data as $key => $value) {
            $post_items[] = $key . '=' . urlencode($value);
        }
        $post_string = implode ('&', $post_items);
        curl_setopt($curl_connection, CURLOPT_POSTFIELDS, $post_string);
        $sresult = curl_exec($curl_connection);

        curl_close($curl_connection);

        //Parse the library card number from the response
        if (preg_match('/Your temporary library card number is :.*?(\\d+)<\/(b|strong|span)>/si', $sresult, $matches)) {
            $barcode = $matches[1];

            // it worked, so now we should update their profile to include the pin
            $this->updateMyProfile(["id" => $barcode], ["pin" => $params["pin"]]);

            return array('success' => true, 'barcode' => $barcode);
        } else {
            return array('success' => false, 'barcode' => null);
        }
    }

    public function getReadingHistory($patron, $page = 1, $recordsPerPage = -1, $sortOption = "date") {
        $paging = 'readinghistory&page=' . $page . '&sort=' . $sortOption;
        $pageContents = $this->_fetchPatronInfoPage($patron, $paging);

        $sresult = preg_replace("/<[^<]+?><[^<]+?>Reading History.\(.\d*.\)<[^<]+?>\W<[^<]+?>/", "", $pageContents);
        $s = substr($sresult, stripos($sresult, 'patFunc'));
        $s = substr($s,strpos($s,">")+1);
        $s = substr($s,0,stripos($s,"</table"));

        $s = preg_replace ("/<br \/>/","", $s);

        $srows = preg_split("/<tr([^>]*)>/",$s);
        $scount = 0;
        $skeys = array_pad(array(),10,"");
        $readingHistoryTitles = array();
        $itemindex = 0;

        // check to see if paging is switched on. Increment scrape index
        if (strpos($pageContents, 'Result page:') > 0){
            $scrape_row_index = 5;
        } else {
            $scrape_row_index = 4;	
        }

        foreach ($srows as $srow) {
            $scols = preg_split("/<t(h|d)([^>]*)>/",$srow);
            $historyEntry = array();
            for ($i=0; $i < sizeof($scols); $i++) {
                $scols[$i] = str_replace("&nbsp;"," ",$scols[$i]);
                $scols[$i] = preg_replace ("/<br+?>/"," ", $scols[$i]);
                $scols[$i] = html_entity_decode(trim(substr($scols[$i],0,stripos($scols[$i],"</t"))));
                if ($scount < $scrape_row_index) {
                    $skeys[$i] = $scols[$i];

                    if (stripos($skeys[1],"Reading History") > -1) {

                        if (preg_match_all ("/.*?\\d+.*?\\d+.*?(\\d+)/is", $skeys[1], $matches)){
                            $total_records = $matches[1][0];
                          }
                    }

                } elseif ($scount >= 4){
                    if (stripos($skeys[$i],"Mark") > -1) {
                        if(preg_match('@id="([^"]*)"@', $scols[$i], $m)){
                            $historyEntry['rsh'] = $m[1];
                        }
                        $historyEntry['deletable'] = "BOX";
                    }

                    if (stripos($skeys[$i],"Title") > -1) {

                        if (strpos($scols[$i],'is no longer available') !== false){

                            if ($c=preg_match_all ("/.*?((?:[a-z][a-z]*[0-9]+[a-z0-9]*))/is", $scols[$i], $matches)){
                                $shortId = $matches[1][0];
                                $bibid = '.' . $matches[1][0];
                                $title = 'Title is no longer available';
                            }

                        } elseif (preg_match('/.*?<a href=\\"\/record=(.*?)(?:~S\\d{1,2})\\">(.*?)<\/a>.*/', $scols[$i], $matches)) {
                            $shortId = $matches[1];
                            $bibid = '.' . $matches[1];
                            $title = $matches[2];
                        }

                        $historyEntry['id'] = $bibid;
                        $historyEntry['shortId'] = $shortId;
                        $historyEntry['title'] = $title;
                    }

                    if (stripos($skeys[$i],"Author") > -1) {
                        $historyEntry['author'] = strip_tags($scols[$i]);
                    }

                    if (stripos($skeys[$i],"Checked Out") > -1) {
                        $checkoutTime = DateTime::createFromFormat('m-d-Y', strip_tags($scols[$i]));
                        $historyEntry['checkout'] = strftime('%m/%e/%y', $checkoutTime->getTimestamp());
                        $historyEntry['checkout'] = str_replace(" ","",$historyEntry['checkout']);
                        if( strpos($historyEntry['checkout'], "0") === 0 ) {
                            $historyEntry['checkout'] = substr($historyEntry['checkout'], 1);
                        }
                    }
                    if (stripos($skeys[$i],"Details") > -1) {
                        $historyEntry['details'] = strip_tags($scols[$i]);
                    }

                    $historyEntry['borrower_num'] = $patron['id']; 
                } //Done processing column
                
            } //Done processing row

            if ($scount > 2 && isset($historyEntry['title'])){

                $historyEntry['title_sort'] = strtolower($historyEntry['title']);

                $historyEntry['itemindex'] = $itemindex++;
                $titleKey = '';
                if ($sortOption == "title"){
                    $titleKey = $historyEntry['title_sort'];
                }elseif ($sortOption == "author"){
                    $titleKey = $historyEntry['author'] . "_" . $historyEntry['title_sort'];
                }elseif ($sortOption == "date" || $sortOption == "returned"){
                    $checkoutTime = DateTime::createFromFormat('m/d/y', $historyEntry['checkout']);
                    $titleKey = /*$checkoutTime->getTimestamp()*/ "1" . "_" . $historyEntry['title_sort'];
                }elseif ($sortOption == "format"){
                    $titleKey = $historyEntry['format'] . "_" . $historyEntry['title_sort'];
                }else{
                    $titleKey = $historyEntry['title_sort'];
                }
                $titleKey .= '_' . $scount;
                $readingHistoryTitles[$titleKey] = $historyEntry;
            }
            
            $scount++;
        }//processed all rows in the table

        $numTitles = count($readingHistoryTitles);

        //The history is active if there is an opt out link.
        $historyActive = (strpos($pageContents, 'OptOut') > 0);
        return array('historyActive'=>$historyActive, 'titles'=>$readingHistoryTitles, 'numTitles'=> $numTitles, 'total_records' => $total_records);
    }

    /**
     * Do an update or edit of reading history information.  Current actions are:
     * deleteMarked
     * deleteAll
     * exportList
     * optOut
     *
     * @param   array   $patron         The patron array
     * @param   string  $action         The action to perform
     * @param   array   $selectedTitles The titles to do the action on if applicable
     */
    function doReadingHistoryAction($patron, $action, $selectedTitles){
        $curl_url = $this->config['Catalog']['classic_url'] . "/patroninfo~S1/" . $patron['id'] ."/readinghistory";

        $cookie = tempnam ("/tmp", "CURLCOOKIE");
        $curl_connection = curl_init($curl_url);
        curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
        curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_connection, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl_connection, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl_connection, CURLOPT_UNRESTRICTED_AUTH, true);
        curl_setopt($curl_connection, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($curl_connection, CURLOPT_COOKIESESSION, true);
        curl_setopt($curl_connection, CURLOPT_POST, true);
        $post_string = 'code=' . $patron['cat_username'] . '&pin=' . $patron['cat_password'] . '&submit=submit';//implode ('&', $post_items);
        curl_setopt($curl_connection, CURLOPT_POSTFIELDS, $post_string);
        $sresult = curl_exec($curl_connection);

        if ($action == 'deleteMarked'){
            //Load patron page readinghistory/rsh with selected titles marked
            if (!isset($selectedTitles) || count($selectedTitles) == 0){
                return;
            }
            $titles = array();
            foreach ($selectedTitles as $titleId){
                $titles[] = $titleId . '=1';
            }
            $title_string = implode ('&', $titles);
            //Issue a get request to delete the item from the reading history.
            //Note: Millennium really does issue a malformed url, and it is required
            //to make the history delete properly.
            $curl_url = $this->config['Catalog']['classic_url'] . "/patroninfo~S1/" . $patron['id'] ."/readinghistory/rsh&" . $title_string;
            curl_setopt($curl_connection, CURLOPT_URL, $curl_url);
            curl_setopt($curl_connection, CURLOPT_HTTPGET, true);
            $sresult = curl_exec($curl_connection);
        }elseif ($action == 'deleteAll'){
            //load patron page readinghistory/rah
            $curl_url = $this->config['Catalog']['classic_url'] . "/patroninfo~S1/" . $patron['id'] ."/readinghistory/rah";
            curl_setopt($curl_connection, CURLOPT_URL, $curl_url);
            curl_setopt($curl_connection, CURLOPT_HTTPGET, true);
            $sresult = curl_exec($curl_connection);
        }elseif ($action == 'exportList'){
            //Leave this unimplemented for now.
        }elseif ($action == 'optOut'){
            //load patron page readinghistory/OptOut
            $curl_url = $this->config['Catalog']['classic_url'] . "/patroninfo~S1/" . $patron['id'] ."/readinghistory/OptOut";
            curl_setopt($curl_connection, CURLOPT_URL, $curl_url);
            curl_setopt($curl_connection, CURLOPT_HTTPGET, true);
            $sresult = curl_exec($curl_connection);
        }elseif ($action == 'optIn'){
            //load patron page readinghistory/OptIn
            $curl_url = $this->config['Catalog']['classic_url'] . "/patroninfo~S1}/" . $patron['id'] ."/readinghistory/OptIn";
            curl_setopt($curl_connection, CURLOPT_URL, $curl_url);
            curl_setopt($curl_connection, CURLOPT_HTTPGET, true);
            $sresult = curl_exec($curl_connection);
        }
        curl_close($curl_connection);

        return $sresult;
    }

    /**
     * Uses CURL to fetch a page from millenium and return the raw results
     * for further processing.
     *
     * Performs minimal processing on it's own to remove HTML comments.
     *
     * @param array  $patronInfo information about a patron fetched from millenium
     * @param string $page       The page to load within millenium
     *
     * @return string the result of the page load.
     */
    private function _fetchPatronInfoPage($patronInfo, $page, $additionalGetInfo = array(), $additionalPostInfo = array(), $cookieJar = null, $admin = false, $startNewSession = true, $closeSession = true, $forceReload = false)
    {
        $forceReload = true;
        if (isset($page)){
            $patronInfoDump = $this->session->{'patron_info_dump_' . $page};
        }
			
        if (!$patronInfoDump || $forceReload){
            $cookieJar = tempnam("/tmp", "CURLCOOKIE");

            $curl_url = $this->config['Catalog']['classic_url'] . "/patroninfo~S1/" . $patronInfo['id'] ."/$page";
            $this->curl_connection = curl_init($curl_url);

            curl_setopt($this->curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($this->curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
            curl_setopt($this->curl_connection, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->curl_connection, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($this->curl_connection, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($this->curl_connection, CURLOPT_UNRESTRICTED_AUTH, true);
            curl_setopt($this->curl_connection, CURLOPT_COOKIEJAR, $cookieJar );
            curl_setopt($this->curl_connection, CURLOPT_COOKIESESSION, !($cookieJar) ? true : false);

            $post_string = 'code=' . $patronInfo['cat_username'] . '&pin=' . $patronInfo['cat_password'] . '&submit=submit';
            curl_setopt($this->curl_connection, CURLOPT_POSTFIELDS, $post_string);
            $patronInfoDump = curl_exec($this->curl_connection);

            curl_close($this->curl_connection);

            $this->session->{'patron_info_dump_' . $page} = $patronInfoDump;
        }

        //Strip HTML comments
        $patronInfoDump = preg_replace("/<!--([^(-->)]*)-->/"," ",$patronInfoDump);
        return $patronInfoDump;
    }
}