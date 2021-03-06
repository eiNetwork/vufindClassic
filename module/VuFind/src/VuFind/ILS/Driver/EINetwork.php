<?php

/**
 * EINetwork-specific adaptation of Sierra2 ILS driver
 */

namespace VuFind\ILS\Driver;

use VuFind\Exception\ILS as ILSException;
use Zend\Session\Container as SessionContainer;
use DateTime;
use SoapClient;

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
        return isset($this->session[$name]) ? $this->session[$name] : null;
    }

    public function setSessionVar($name, $value) {
        $this->session[$name] = $value;
    }

    public function clearSessionVar($name) {
        unset($this->session[$name]);
    }

    public function getMemcachedVar($name) {
        return $this->memcached->get($name);
    }

    public function setMemcachedVar($name, $value, $time=null) {
        if( $time ) {
            $this->memcached->set($name, $value, $time);
        } else {
            $this->memcached->set($name, $value);
        }
    }

    public function clearMemcachedVar($name) {
        return $this->memcached->delete($name);
    }

    public function getCurrentLocation() {
        $myIP = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER['REMOTE_ADDR'];
        if( !$this->memcached->get("locationByIP" . $myIP) ) {
            $this->memcached->set("locationByIP" . $myIP, $this->getDbTable('Location')->getCurrentLocation($myIP));
        }
        return $this->memcached->get("locationByIP" . $myIP);
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

            $results = $this->sendAPIRequest($this->config['SIERRAAPI']['url'] . "/v5/patrons/validate", \Zend\Http\Request::METHOD_POST, json_encode(["barcode" => $username, "pin" => $password]));
            if( !$results ) {
                $ret = [];
                $ret['cat_username'] = urlencode($username);
                $ret['cat_password'] = urlencode($password);
                $ret = $this->getMyProfile($ret, true);
            } else {
                return null;
            }

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
        $this->testSession();

        if( !$forceReload && $this->session->patron ) {
            return $this->session->patron;
        }

        $patron = parent::getMyProfile($patron);

        $patron['showTemporaryClosureMessage'] = false;
        if( !$this->memcached->get("locationByCode" . $patron['homelibrarycode']) ) {
            $this->memcached->set("locationByCode" . $patron['homelibrarycode'], $this->getDbTable('Location')->getByCode($patron['homelibrarycode']));
        }
        $location = $this->memcached->get("locationByCode" . $patron['homelibrarycode']);
        $patron['homelibrary'] = ($location != null && $location->validHoldPickupBranch) ? $location->displayName : null;
        $patron['showTemporaryClosureMessage'] |= ($location != null && ($location->validHoldPickupBranch == 2));
        if( !$patron['homelibrary'] ) {
            $patron['homelibrarycode'] = null;
        }

        $user = $this->getDbTable('user')->getByUsername($patron['username'], false);

        $patron['preferredlibrarycode'] = $user->preferred_library;
        if( !$this->memcached->get("locationByCode" . $patron['preferredlibrarycode']) ) {
            $this->memcached->set("locationByCode" . $patron['preferredlibrarycode'], $this->getDbTable('Location')->getByCode($patron['preferredlibrarycode']));
        }
        $location = $this->memcached->get("locationByCode" . $patron['preferredlibrarycode']);
        $patron['preferredlibrary'] = ($location != null && ($location->validHoldPickupBranch == 1)) ? $location->displayName : null;
        $patron['showTemporaryClosureMessage'] |= ($location != null && ($location->validHoldPickupBranch == 2));
        if( !$patron['preferredlibrary'] ) {
            $patron['preferredlibrarycode'] = null;
        }

        $patron['alternatelibrarycode'] = $user->alternate_library;
        if( !$this->memcached->get("locationByCode" . $patron['alternatelibrarycode']) ) {
            $this->memcached->set("locationByCode" . $patron['alternatelibrarycode'], $this->getDbTable('Location')->getByCode($patron['alternatelibrarycode']));
        }
        $location = $this->memcached->get("locationByCode" . $patron['alternatelibrarycode'] );
        $patron['alternatelibrary'] = ($location != null && ($location->validHoldPickupBranch == 1)) ? $location->displayName : null;
        $patron['showTemporaryClosureMessage'] |= ($location != null && ($location->validHoldPickupBranch == 2));
        if( !$patron['alternatelibrary'] ) {
            $patron['alternatelibrarycode'] = null;
        }

        // overdrive info
        $lendingOptions = $this->getOverDriveLendingOptions($patron);
        $patron['OD_eBook'] = $lendingOptions["eBook"];
        $patron['OD_audiobook'] = $lendingOptions["Audiobook"];
        $patron['OD_video'] = $lendingOptions["Video"];
        $patron['OD_renewalInDays'] = $lendingOptions["renewalInDays"];
        $patron['splitEcontent'] = $user->splitEcontent;

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
        if( $this->memcached->get("pickup_locations") ) {
            return $this->memcached->get("pickup_locations");
        }

        $locations = $this->getDbTable('Location')->getPickupLocations();
        $pickupLocations = [];
        foreach( $locations as $loc ) {
            $pickupLocations[] = ["locationID" => $loc->code, "locationDisplay" => $loc->displayName];
        }
        $this->memcached->set("pickup_locations", $pickupLocations);

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
        return "";
    }

    public function getStatus($id) {
        return $this->getHolding($id);
    }
	
    public function getHolding($id, array $patron = null)
    {
        // see if it's there
        if( ($overDriveId = $this->getOverDriveID($id)) ) {
            $availability = $this->getProductAvailability($overDriveId);
            return [["id" => $id,
                     "location" => "OverDrive",
                     "isOverDrive" => true,
                     "isOneClick" => false,
                     "copiesOwned" => $availability->collections[0]->copiesOwned,
                     "copiesAvailable" => $availability->collections[0]->copiesAvailable,
                     "numberOfHolds" => $availability->collections[0]->numberOfHolds,
                     "availability" => ($availability->collections[0]->copiesAvailable > 0)
                   ]];
        }

        $cachedInfo = ($this->memcached->get("holdingID" . $id))["CACHED_INFO"] ?? null;

        if( $cachedInfo && !$cachedInfo["doUpdate"] && isset($cachedInfo["holding"]) ) {
            $results = $cachedInfo["holding"];

            // if we haven't processed these holdings yet, run through the order records
            if( !isset($cachedInfo["processedHoldings"]) && ($cachedJson = $this->memcached->get("cachedJson" . $id)) !== null ) {
                if( isset($cachedJson["orderRecords"]) ) {
                    foreach( $cachedJson["orderRecords"] as $locationCode => $details ) {
                        $results[] = [
                                         "id" => $id,
                                         "itemId" => null,
                                         "availability" => false,
                                         "status" => "order",
                                         "location" => $details["location"],
                                         "reserve" => "N",
                                         "callnumber" => null,
                                         "duedate" => null,
                                         "returnDate" => false,
                                         "number" => null,
                                         "barcode" => null,
                                         "locationCode" => $locationCode,
                                         "copiesOwned" => $details["copies"]
                                     ];
                    }
                }
            }
        } else {
            $results = parent::getHolding($id, $patron);
        }

        // make any status updates we are supposed to be making
        if( $changes = $this->memcached->get("updatesID" . $id) ) {
            foreach( $changes as $key => $thisChange ) {
                // if they've already been taken care of, ignore them
                if( !$thisChange["handled"] ) {
                    foreach( $results as $hKey => $thisHolding ) {
                        if( $thisHolding["itemId"] == $thisChange["inum"] ) {
                            if( isset($thisChange["status"]) ) {
                                $thisHolding["status"] = $thisChange["status"];
                                $thisHolding["availability"] = (in_array($thisChange["status"], ["-","o","p","v","y"]) && !$thisHolding["duedate"]);
                            }
                            if( isset($thisChange["duedate"]) ) {
                                $thisHolding["duedate"] = ($thisChange["duedate"] != "NULL") ? strftime("%m-%d-%y", strtotime($thisChange["duedate"])) : null;
                                $thisHolding["availability"] = (in_array($thisChange["status"], ["-","o","p","v","y"]) && !$thisHolding["duedate"]);
                            }
                            if( $thisChange["suppressed"] ?? false ) {
                                $thisHolding["suppressed"] = true;
                            }
                            $results[$hKey] = $thisHolding;
                        }
                    }
                }
            }
            if( ($cache = $this->memcached->get("holdingID" . $id)) && isset($cache["CACHED_INFO"]["holding"]) ) {
                $cache["CACHED_INFO"]["holding"] = $results;
                $time = strtotime(((date("H") < "06") ? "today" : "tomorrow") . " 6:00") - time();
                $this->memcached->set("holdingID" . $id, $cache, $time);
            }
        }

        // add in the extra details we need
        $results2 = [];
        for($i=0; $i<count($results); $i++) {
            // throw out online or suppressed items
            if( $results[$i]['locationCode'] == "xronl" || ($results[$i]["suppressed"] ?? false) ) {
                continue;
            }

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
            } else if( $results[$i]['status'] == 'order' ) {
                $results[$i]['displayStatus'] = "ON ORDER";    
            } else {
                $results[$i]['displayStatus'] = "UNKNOWN";
            }

            // get shelving details
            if( !$this->memcached->get("shelvingLocationByCode" . $results[$i]['locationCode']) ) {
                $this->memcached->set("shelvingLocationByCode" . $results[$i]['locationCode'], $this->getDBTable('shelvinglocation')->getByCode($results[$i]['locationCode']));
            }
            $shelfLoc = $this->memcached->get("shelvingLocationByCode" . $results[$i]['locationCode'] );
            $locationId = (isset($shelfLoc) && $shelfLoc) ? $shelfLoc->locationId : null;
            if( $locationId && !$this->memcached->get("locationByID" . $locationId) ) {
                $this->memcached->set("locationByID" . $locationId, $this->getDBTable('location')->getByLocationId($locationId));
            } else if( !$locationId && (strlen($results[$i]['locationCode']) == 2) && !$this->memcached->get("locationByCode" . $results[$i]['locationCode']) ) {
                $this->memcached->set("locationByCode" . $results[$i]['locationCode'], $this->getDBTable('location')->getByCode($results[$i]['locationCode']));
            }
            $location = $locationId ? $this->memcached->get("locationByID" . $locationId ) : ((strlen($results[$i]['locationCode']) == 2) ? $this->memcached->get("locationByCode" . $results[$i]['locationCode']) : null);
            $results[$i]['branchName'] = $location ? $location->displayName : (($results[$i]['status'] == 'order') ? $results[$i]['location'] : null);
            $results[$i]['branchCode'] = $location ? $location->code : null;
            $results[$i]['shelvingLocation'] = $shelfLoc ? $shelfLoc->shortName : null;

            for($j=0; $j<count($results2) && (($results[$i]['branchName'] > $results2[$j]['branchName']) || (($results[$i]['branchName'] == $results2[$j]['branchName']) && ($results[$i]['number'] > $results2[$j]['number']))); $j++) {}
            array_splice($results2, $j, 0, [$results[$i]]);
        }

        // if this is a magazine, we need to add the checkin records info
        if( $this->isSerial($id) ) {
            // get all of the locations we need to speak for
            $neededLocations = [];
            foreach( $results2 as $thisItem ) {
                if( !isset($neededLocations[$thisItem["locationCode"]]) ) {
                    $neededLocations[$thisItem["locationCode"]] = $thisItem["locationCode"];
                }
            }

            // grab the checkin records and store the location info
            $results3 = [];
            if( $cachedInfo && !$cachedInfo["doUpdate"] && isset($cachedInfo["checkinRecords"]) ) {
                $checkinRecords = $cachedInfo["checkinRecords"];
            } else {
                $checkinRecords = $this->getCheckinRecords($id);
            }

            foreach( array_keys($checkinRecords) as $key ) {
                // find this location in the database
                if( !$this->memcached->get("shelvingLocationBySierraName" . md5($checkinRecords[$key]["location"])) ) {
                    $this->memcached->set("shelvingLocationBySierraName" . md5($checkinRecords[$key]["location"]), $this->getDBTable('shelvinglocation')->getBySierraName($checkinRecords[$key]["location"])->toArray());
                }
                $checkinRecords[$key]["code"] = [];
                $checkinRecords[$key]["branchCode"] = [];
                foreach( $this->memcached->get("shelvingLocationBySierraName" . md5($checkinRecords[$key]["location"])) as $row ) {
                    $checkinRecords[$key]["code"][] = $row["code"];
                    $checkinRecords[$key]["branchCode"][] = $row["branchCode"];
                    unset($neededLocations[$row["code"]]);
                }
                $results3[] = $checkinRecords[$key];
            }

            // add details for locations with no checkin records but held items
            foreach( $neededLocations as $code ) {
                if( !$this->memcached->get("shelvingLocationByCode" . $code) ) {
                    $this->memcached->set("shelvingLocationByCode" . $code, $this->getDBTable('shelvinglocation')->getByCode($code));
                }
                $shelfLoc = $this->memcached->get("shelvingLocationByCode" . $code );
                if( $shelfLoc == null ) {
                    if( !$this->memcached->get("locationByCode" . $code) ) {
                        $this->memcached->set("locationByCode" . $code, $this->getDBTable('location')->getByCode($code));
                    }
                    $shelfLoc = $this->memcached->get("locationByCode" . $code );
                }
                $thisCode = [];
                $thisCode["location"] = isset($shelfLoc->sierraName) ? $shelfLoc->sierraName : $shelfLoc->displayName;
                $thisCode["code"][] = $code;
                $thisCode["branchCode"][] = isset($shelfLoc->branchCode) ? $shelfLoc->branchCode : $code;
                for( $j=0; $j<count($results3) && ($results3[$j]['location'] < $thisCode["location"]); $j++) {}
                array_splice($results3, $j, 0, [$thisCode]);
                unset($neededLocations[$code]);
            }

            array_splice($results2, 0, 0, [["id" => $id, "location" => "CHECKIN_RECORDS", "availability" => false, "status" => "?", "items" => [], "copiesOwned" => 0, "checkinRecords" => $results3]]);
        }
        return $results2;
    }

    public function updateMyProfile($patron, $updatedInfo){
        // update the phone, email, and/or notification setting
        if( isset($updatedInfo['phones']) || isset($updatedInfo['emails']) || isset($updatedInfo['pin']) || isset($updatedInfo['notices']) ) {
            /**
            * Screen Scraping functionality
            * 
            * The if block following this leverages the screen scraping functionality from our previous iteration of the catalog.
            * This action should eventually be available via the Sierra API (and as such be implemented in the Sierra2 driver), 
            * but our current version of the API does not have them available at this point.
            * 
            */
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
            $user = $this->getDbTable('user')->getByUsername($patron['username'], false);
            $user->changePreferredLibrary($updatedInfo['preferred_library']);
        }

        // see whether they have given us an updated alternate library
        if( isset($updatedInfo['alternate_library']) ) {
            $user = $this->getDbTable('user')->getByUsername($patron['username'], false);
            $user->changeAlternateLibrary($updatedInfo['alternate_library']);
        }

        // see whether they have given us a new splitEcontent preference
        if( isset($updatedInfo['splitEcontent']) ) {
            $user = $this->getDbTable('user')->getByUsername($patron['username'], false);
            $user->changeSplitEcontent($updatedInfo['splitEcontent']);
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
        // see if it's there
        if( !$this->memcached->get("overdriveID" . $id) ) {
            // grab a bit more information from Solr
            $solrBaseURL = $this->config['Solr']['url'];
            $curl_url = $solrBaseURL . "/biblio/select?q=*%3A*&fq=id%3A%22" . $id . "%22&fl=econtent_source,externalId&wt=csv";
            $curl_connection = curl_init($curl_url);
            curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
            curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
            $sresult = curl_exec($curl_connection);
            $values = explode("\n", $sresult);

            // is it an OverDrive item?
            if( count($values) > 1 && explode(",", $values[1])[0] == "OverDrive" ) {
                $this->memcached->set("overdriveID" . $id, explode(",", $values[1])[1]);
            }
        }

        // send it back
        return $this->memcached->get("overdriveID" . $id);
    }

    /**
     * Convenience function to get the Solr Record corresponding to a given externalId
     *
     * @param  string $id an externalId value
     * 
     * @return mixed  A Solr record if the externalId maps to a Solr item, false if not
     */
    public function getSolrRecordFromExternalId($id) {
        if( $this->memcached->get("solrRecordForID" . $id) ) {
            return $this->memcached->get("solrRecordForID" . $id);
        }

        // grab a bit more information from Solr
        $solrBaseURL = $this->config['Solr']['url'];
        $curl_url = $solrBaseURL . "/biblio/select?q=*%3A*&fq=externalId%3A%22" . strtolower($id) . "%22&wt=csv&csv.separator=%07&csv.encapsulator=%15";
        $curl_connection = curl_init($curl_url);
        curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
        curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
        $sresult = curl_exec($curl_connection);
        $values = explode("\n", $sresult);

        // sometimes OverDrive wants to break our system by stashing bonus \n characters in there. this puts them back together.
        while( count($values) > 3 ) {
            array_splice($values, 1, 2, $values[1] . "\n" . $values[2]);
        }

        // is it a Solr item?
        if( count($values) > 2 ) {
            $item = array();
            $fieldNames = explode(chr(7), $values[0]);

            // we have to do some hocus pocus here since the values can also include the delimiter if they are multi-valued
            $fieldValues = explode(chr(7), $values[1]);
            for($i=0;$i<count($fieldValues);$i++) {
                while( substr($fieldValues[$i], 0, 1) == chr(21) && substr($fieldValues[$i], -1) != chr(21) ) {
                    array_splice($fieldValues, $i, 2, $fieldValues[$i] . "\," . $fieldValues[$i+1]);
                }

                if( substr($fieldValues[$i], 0, 1) == chr(21) ) {
                    $fieldValues[$i] = substr($fieldValues[$i], 1, -1);
                }
            }

            for($i=0; $i<count($fieldNames); $i++) {
                $item[$fieldNames[$i]] = $fieldValues[$i];
            }
            $this->memcached->set("solrRecordForID" . $id, $item);
            return $this->memcached->get("solrRecordForID" . $id);
        }

        // not in Solr
        return false;
    }

    /**
     * Get Number Of My Holds
     *
     * This is responsible for returning just the raw count of a patron's holds.
     *
     * @param string $patron The patron's id
     *
     * @throws ILSException
     * @return mixed          Associative array of patron info on successful login,
     * null on unsuccessful login.
     */
    public function getNumberOfMyHolds($patron) {
        if( isset($this->session->holds) ) {
            return count($this->session->holds);
        }

        $numberOfSierraHolds = parent::getNumberOfMyHolds($patron);
        $numberOfOverDriveHolds = $this->getNumberOfOverDriveHolds((object)$patron);
        return $numberOfSierraHolds + $numberOfOverDriveHolds;
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
    public function getMyHolds($patron, $skipCache=false) {
        $this->testSession();

        if( isset($this->session->holds) && !isset($this->session->staleHoldsHash) && !$skipCache ) {
            return $this->session->holds;
        // clear out these intermediate cached API results
        } else if( $skipCache ) {
            $offset = 0;
            $hash = md5($this->config['SIERRAAPI']['url'] . "/v5/patrons/" . $patron['id'] . "/holds?limit=50&offset=" . $offset);
            while( $this->memcached->get($hash) ) {
                $this->memcached->set($hash, null);
                $offset += 50;
                $hash = md5($this->config['SIERRAAPI']['url'] . "/v5/patrons/" . $patron['id'] . "/holds?limit=50&offset=" . $offset);
            }
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
        if( isset($this->session->staleHoldsHash) ) {
            if( md5(json_encode($sierraHolds)) != $this->session->staleHoldsHash ) {
                unset( $this->session->staleHoldsHash );
            }
        }
        return $this->session->holds;
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
        if( isset($this->session->checkouts) ) {
            return count($this->session->checkouts);
        }

        $numberOfSierraTransactions = parent::getNumberOfMyTransactions($patron);
        $numberOfOverDriveTransactions = $this->getNumberOfOverDriveCheckedOutItems((object)$patron);
        return $numberOfSierraTransactions + $numberOfOverDriveTransactions;
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
    public function getMyTransactions($patron, $skipCache=false){
        $this->testSession();

        if( isset($this->session->checkouts) && !isset($this->session->staleCheckoutsHash) && !$skipCache ) {
            return $this->session->checkouts;
        // clear out these intermediate cached API results
        } else if( $skipCache ) {
            $offset = 0;
            $hash = md5($this->config['SIERRAAPI']['url'] . "/v5/patrons/" . $patron['id'] . "/checkouts?limit=50&offset=" . $offset);
            while( $this->memcached->get($hash) ) {
                $this->memcached->set($hash, null);
                $offset += 50;
                $hash = md5($this->config['SIERRAAPI']['url'] . "/v5/patrons/" . $patron['id'] . "/checkouts?limit=50&offset=" . $offset);
            }
        }

        $sierraTransactions = parent::getMyTransactions($patron);
        $overDriveTransactions = $this->getOverDriveCheckedOutItems((object)$patron);
        foreach($overDriveTransactions as $item) {
            $solrInfo = $this->getSolrRecordFromExternalId($item["overDriveId"]);
            if($solrInfo) {
                foreach($solrInfo as $key => $value) {
                    $item[$key] = $value;
                }
                $item['ILL'] = false;
                $sierraTransactions[] = $item;
            }
        }
        $this->session->checkouts = $sierraTransactions;
        if( isset($this->session->staleCheckoutsHash) ) {
            if( md5(json_encode($sierraTransactions)) != $this->session->staleCheckoutsHash ) {
                unset( $this->session->staleCheckoutsHash );
            }
        }
        return $this->session->checkouts;
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
        // invalidate the cached data
        $this->session->staleCheckoutsHash = md5(json_encode($this->session->checkouts));
        unset($this->session->patron);

        return parent::renewMyItems($items);
    }

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
        $this->session->staleHoldsHash = md5(json_encode($this->session->holds));

        // item level holds via the API don't work yet
        // BJP - neither do local copy overriding the hold
        if( true || substr($details["id"], 1, 1) == "i" ) {
            $holdsInfo = $this->placeItemLevelHold($details);
        } else {
            $holdsInfo = parent::placeHold($details);
        }

        // if they successfully placed the hold, check to see whether this item is in their book cart. If so, remove it.
        if( $holdsInfo['success'] ) {
            $this->removeFromBookCart([isset($details['bibId']) ? $details['bibId'] : $details['id']]);
        }

        return $holdsInfo;
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
        $this->session->staleHoldsHash = md5(json_encode($this->session->holds));

        $success = true;
        $overDriveHolds = [];
        for($i=0; $i<count($holds["details"]); $i++ )
        {
            if( substr($holds["details"][$i], 0, 9) == "OverDrive" ) {
                $overDriveHolds[] = substr(array_splice($holds["details"], $i, 1)[0], 9);
                $i--;
            }
        }

        // grab a copy of this because the OverDrive functionality can wipe it
        $cachedHolds = $this->session->holds;

        // process the overdrive holds
        foreach($overDriveHolds as $overDriveID ) {
            $overDriveResults = $this->cancelOverDriveHold($overDriveID, $holds["patron"]);
            $success &= $overDriveResults["result"];
        }

        // compare the sierra holds to my list of holds (workaround for item-level stuff)
        if( count($holds["details"]) > 0 ) {
            foreach( $holds["details"] as $key => $thisCancelId ) {
                foreach( $cachedHolds as $thisHold ) {
                    if( $thisHold["hold_id"] == $thisCancelId && isset( $thisHold["item_id"] ) ) {
                        $success &= $this->updateHoldDetailed($holds["patron"], "requestId", "patronId", "cancel", "title", $thisHold["item_id"], null);
                        unset($holds["details"][$key]);
                    }
                }
            }
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
        $this->session->staleHoldsHash = md5(json_encode($this->session->holds));

        $success = true;
        $overDriveHolds = [];
        for($i=0; $i<count($holds["details"]); $i++ )
        {
            if( substr($holds["details"][$i], 0, 9) == "OverDrive" ) {
                $overDriveHolds[] = substr(array_splice($holds["details"], $i, 1)[0], 9);
                $i--;
            }
        }

        // grab a copy of this because the OverDrive functionality can wipe it
        $cachedHolds = $this->session->holds;

        // process the overdrive holds
        foreach($overDriveHolds as $overDriveID ) {
            $overDriveResults = $this->freezeOverDriveHold($overDriveID, $holds["patron"], $doFreeze);
            $success &= $overDriveResults["result"];
        }

        // process the sierra holds
        if( count($holds["details"]) > 0 ) {
/** Go back to this whenever we can use API instead of screen scraping
            $sierraResults = parent::freezeHolds($holds, $doFreeze);
            $success &= $sierraResults["success"];
/**/

            foreach( $holds["details"] as $key => $thisFreezeId ) {
                foreach( $cachedHolds as $thisHold ) {
                    if( $thisHold["hold_id"] == $thisFreezeId ) { 
                        $success &= $this->updateHoldDetailed($holds["patron"], "requestId", "patronId", "freeze", "title", isset($thisHold["item_id"]) ? $thisHold["item_id"] : $thisHold["id"], null, ($doFreeze ? "on" : "off"));
                        unset($holds["details"][$key]);
                    }
                }
            }

            parent::freezeHolds([], $doFreeze);
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
        $this->session->staleHoldsHash = md5(json_encode($this->session->holds));

        // pull out overdrive holds, since we're updating their email
        $success = true;
        $overDriveHolds = [];
        for($i=0; $i<count($holds["details"]); $i++ )
        {
            if( substr($holds["details"][$i], 0, 9) == "OverDrive" ) {
                $overDriveHolds[] = substr(array_splice($holds["details"], $i, 1)[0], 9);
                $i--;
            }
        }

        // grab a copy of this because the OverDrive functionality can wipe it
        $cachedHolds = $this->session->holds;

        // process the overdrive holds
        foreach($overDriveHolds as $overDriveID ) {
            $overDriveResults = $this->updateOverDriveHold($overDriveID, $holds["patron"], $holds["newEmail"]);
            $success &= $overDriveResults["result"];
        }

        // compare the sierra holds to my list of holds (workaround for item-level stuff)
        if( count($holds["details"]) > 0 ) {
            foreach( $holds["details"] as $key => $thisUpdateId ) {
                foreach( $cachedHolds as $thisHold ) {
                    if( $thisHold["hold_id"] == $thisUpdateId && isset( $thisHold["item_id"] ) ) {
                        $success &= $this->updateHoldDetailed($holds["patron"], "requestId", "patronId", "update", "title", $thisHold["item_id"], $holds["newLocation"]);
                        unset($holds["details"][$key]);
                    }
                }
            }
        }

        // process the sierra holds
        if( count($holds["details"]) > 0 ) {
            $sierraResults = parent::updateHolds($holds);
            $success &= $sierraResults["success"];
        }

        return ["success" => $success];
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
            $domain = substr($this->config['SIERRAAPI']['url'], 0, strrpos($this->config['SIERRAAPI']['url'], "/"));
            $sc = new SoapClient($domain . "/wspatroninfo/patroninfo.wsdl", array("trace" => 1, "exception" => 0));
            $sc->__setLocation($domain . "/wspatroninfo/");
            // Call wsdl function 
            $result = $sc->patronInfo(array("request" => array( 
                "index"    => 'barcode', 
                "query"    => $profile["cat_username"],
                "username" => "milwspin",
                "password" => "milwspin"
            )));

            // build the message
            $msg = "<form name=\"creditForm\" method=\"post\" onsubmit=\"return checkFees()\" target=\"_blank\" action=\"https://payflowlink.paypal.com\">" . 
                   "<input type=\"hidden\" name=\"action\" value=\"confirmInfo\">" . 
                   "<input type=\"hidden\" name=\"key\" value=\"-3994241445885651921\">" . 
                   "<input type=\"hidden\" name=\"linkMode\" value=\"true\">" . 
                   "<input type=\"hidden\" name=\"payAmount\" value=\"200\">" . 
                   "<input type=\"hidden\" name=\"minFeeMsg\" value=\"Please visit the library to pay this amount\">" . 
                   "<input type=\"hidden\" name=\"partner\" value=\"PayPal\">" . 
                   "<input type=\"hidden\" name=\"type\" value=\"S\">" . 
                   "<input type=\"hidden\" name=\"orderForm\" value=\"TRUE\">" . 
                   "<input type=\"hidden\" name=\"echoData\" value=\"TRUE\">" . 
                   "<input type=\"hidden\" name=\"showConfirm\" value=\"TRUE\">" . 
                   "<input type=\"hidden\" name=\"method\" value=\"CC\">" . 
                   "<input type=\"hidden\" name=\"login\" value=\"einetworklink\">" . 
                   "<input type=\"hidden\" name=\"custId\" value=\"" . substr($result->response->recordNumber, 1) . "\">" . 
                   "<input type=\"hidden\" name=\"description\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"emailCustomer\" value=\"TRUE\">" . 
                   "<input type=\"hidden\" name=\"address\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"city\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"state\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"zip\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"phone\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"email\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"name\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"user2\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"user3\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"user4\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"user5\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"user6\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"user7\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"user8\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"user9\" value=\"\">" . 
                   "<input type=\"hidden\" name=\"user10\" value=\"ecom\">" . 
                   "<input type=\"hidden\" name=\"comment1\" value=\"f\">" . 
                   "<input type=\"hidden\" name=\"comment2\" value=\"" . $result->response->recordNumber . "\">" . 
                   "<input type=\"hidden\" name=\"parsedMoneyfmt\" value=\",.2\" id=\"moneyfmt\">" . 
                   "<input type=\"hidden\" name=\"currencySymbol\" value=\"$\" id=\"currencySymbol\">" . 
                   "<input type=\"hidden\" name=\"serviceCharge\" value=\"0\" id=\"serviceCharge\"><table style=\"border-collapse:separate;border-spacing:0 1em\">";
            $user1 = "";
            $total = 0;

            if( isset($result->response->fines) ) {
                $fines = is_array($result->response->fines) ? $result->response->fines : [$result->response->fines];
            } else {
                $fines = [];
            }
            foreach($fines as $i => $thisFine) {
                $adjustedValue = $thisFine->itemCharge + $thisFine->processingFee + $thisFine->billingFee - $thisFine->amountPaid;
                $msg .= "<tr><td style=\"padding:5px\"><input type=\"checkbox\" name=\"selectedFees\" value=\"" . $adjustedValue . "\" checked=\"checked\" onclick=\"checkFees()\" id=\"" . $thisFine->invoice . "\">" . 
                        "</td><td style=\"padding:5px\">" . sprintf("$%.2f", $adjustedValue * 0.01) . "</td><td style=\"padding:5px;line-height:1.23em\">";
                if( $thisFine->itemTitle && $thisFine->chargeType == "Overdue" ) {
                    $msg .= "<div class=\"bold\">Overdue Item Returned</div><div style=\"margin-left:20px;text-indent:-20px\">" . str_replace("'", "\'", $thisFine->itemTitle) . "</div>" .
                            "<div><span class=\"bold\">Date Due:</span> " . strftime("%a %b %e, %Y", strtotime(substr($thisFine->itemDueDate, 0, 10))) . "</div>" .
                            "<div><span class=\"bold\">Date Returned:</span> " . strftime("%a %b %e, %Y", strtotime(substr($thisFine->itemDateReturned, 0, 10))) . "</div>";
                } else if( $thisFine->itemTitle && $thisFine->chargeType == "OverdueRenewal" ) {
                    $msg .= "<div class=\"bold\">Overdue Item Renewed</div><div style=\"margin-left:20px;text-indent:-20px\">" . str_replace("'", "\'", $thisFine->itemTitle) . "</div>" .
                            "<div><span class=\"bold\">Date Due:</span> " . strftime("%a %b %e, %Y", strtotime(substr($thisFine->itemDueDate, 0, 10))) . "</div>" . 
                            "<div><span class=\"bold\">Date Renewed:</span> " . strftime("%a %b %e, %Y", strtotime(substr($thisFine->itemDateReturned, 0, 10))) . "</div>";
                } else if( $thisFine->description && $thisFine->chargeType == "Manual" ) {
                    $msg .= "<div class=\"bold\">Manually added fine</div><div style=\"margin-left:20px;text-indent:-20px\">" . str_replace("'", "\'", $thisFine->description) . "</div>";
                } else {
                    $msg .= "<div class=\"bold\">" . $thisFine->chargeType . "</div><div style=\"margin-left:20px;text-indent:-20px\">" . 
                            ($thisFine->itemTitle ? str_replace("'", "\'", $thisFine->itemTitle) : str_replace("'", "\'", $thisFine->description)) . "</div>";
                }
                $msg .= "</td></tr>";
                $user1 .= $thisFine->invoice . ":";
                $total += $adjustedValue;
            }

            $msg .= "</table><input type=\"hidden\" name=\"amount\" value=\"" . sprintf("%.2f", $total * 0.01) . "\">" . 
                    "<input type=\"hidden\" name=\"user1\" value=\"" . $user1 . "\"><div class=\"center\"><div id=\"minimumPayment\" style=\"color:#f00;font-weight:700\">For payments less than $2.00, please see library staff.</div><br><span class=\"bold\">Total Selected:</span><span id=\"finesTotal\">" . sprintf("%.2f", $total * 0.01) . "</span>" . 
                    "<button class=\"btn-default btn-wide\" id=\"paypalButton\" style=\"margin:15px;cursor:default\">Pay Online</button></div><div>Clicking this button will take you to Paypal\'s secure server to enter your payment info.</div>" . 
                    "</form><script type=\"text/javascript\">function checkFees() { var total = 0; var user1 = \"\";" . 
                    " $(\'input[name=selectedFees]\').each( function() { total += $(this).is(\":checked\") ? parseInt($(this).attr(\"value\")) : 0; user1 += $(this).is(\":checked\") ? ($(this).attr(\"id\") + \":\") : \"\" } ); " . 
                    "$(\"input[name=amount]\").attr(\"value\", total * 0.01); $(\"#finesTotal\").html(\"$\" + (total * 0.01).toFixed(2)); $(\"input[name=user1]\").attr(\"value\", user1); if( total >= parseInt($(\'input[name=payAmount]\').attr(\"value\")) ) { " . 
                    "$(\'#paypalButton\').prop(\"disabled\", false); $(\'#minimumPayment\').css(\"display\", \"none\"); } else " . 
                    "{ $(\'#paypalButton\').prop(\"disabled\", true); $(\'#minimumPayment\').css(\"display\", \"initial\"); } } setTimeout(checkFees, 10)</script>";

            // Echo the result 
            if( $total > 0 ) {
                $notifications[] = ["subject" => "<span class=\"messageWarning\">You have fines.</span>", 
                                    "message" => $msg, 
                                    "extra" => " (Total: $" . number_format($profile["moneyOwed"],2) . ") Click here for details and to pay."];
            } else {
                $profile["moneyOwed"] = 0;
            }
        }
        if( isset($profile["showTemporaryClosureMessage"]) && $profile["showTemporaryClosureMessage"] ) {
            $notifications[] = ["attnSubject" => "<span class=\"messageWarning\">Temporary library closure.</span> Click here to learn more.",
                                "subject" => "Temporary library closure",
                                "message" => "Your home library or one of your preferred libraries is temporarily closed. It will not show up as an option for picking up your requests until it has reopened, and it will not be an option " .
                                             "on the Preferred Libraries section of the <a class=\"messageLink\" href=\"/MyResearch/Profile\">profile page</a>. In the meantime, you can choose a different library location as a preferred " .
                                             "library there. If you would rather not change it, you can simply wait until that location reopens and it will once again appear in your preferred libraries."];
        }
        if( ($profile["preferredlibrarycode"] == null || $profile["preferredlibrarycode"] == "none") && ($profile["alternatelibrarycode"] == null || $profile["alternatelibrarycode"] == "none") ) {
            $notifications[] = ["attnSubject" => "<span class=\"messageWarning\">Please choose a preferred or alternate library.</span> Click here to learn how.",
                                "subject" => "Choose a preferred or alternate library",
                                "message" => "You have not yet chosen a preferred or alternate library. Doing so will make placing requests on physical items much easier, since your preferred libraries are used as the default pickup " .
                                             "location. You can assign a preferred or alternate library on the <a class=\"messageLink\" href=\"/MyResearch/Profile\">profile page</a>."];
        }
        if( date_diff(date_create_from_format("m-d-y", $profile["expiration"]), date_create(date("Y-m-d")))->invert == 0 ) {
            $notifications[] = ["subject" => "<span class=\"messageWarning\">Card expired</span>", 
                                "message" => "Your library card is expired. Please visit your local library to renew your card to ensure access to all online services."];
        } else if( date_diff(date_create_from_format("m-d-y", $profile["expiration"]), date_create(date("Y-m-d")))->days <= 30 ) {
            $notifications[] = ["subject" => "Card expiration approaching", 
                                "message" => "Your library card is due to expire within the next 30 days. Please visit your local library to renew your card to ensure access to all online services."];
        }
        return $notifications;
    }

    /**
     * Get announcements
     *
     * This is responsible for grabbing system-wide announcements that haven't been dismissed by the user.
     *
     * @param string  $ns      The namespace of the desired announcements
     *
     * @return array           Associative array of announcements
     */
    public function getAnnouncements($ns=null){
        $announcements = [];
        if( isset($this->config['Site']['announcement']) ) {
            foreach($this->config['Site']['announcement'] as $news) {
                $hash = md5($news);
                // see if we need to unblock this
                if( !$this->session->patronLogin && isset($this->session->dismissedAnnouncements[$hash]) && ($this->session->dismissedAnnouncements[$hash] + 300) < time() ) {
                    unset($this->session->dismissedAnnouncements[$hash]);
                }
                // add it to the array if they haven't dismissed it
                if( !isset($this->session->dismissedAnnouncements[$hash]) ) {
                    $announcements[] = ['html' => true, 'msg' => $news, 'announceHash' => $hash];
                }
            }
        }
        return $announcements;
    }

    /**
     * Dismiss announcement
     *
     * This is responsible for dismissing a system-wide announcement until the user changes.
     *
     * @param string  $hash    The hash of the desired announcement
     */
    public function dismissAnnouncement($hash){
        if( !isset($this->session->dismissedAnnouncements) ) {
            $this->session->dismissedAnnouncements = [];
        }
        $this->session->dismissedAnnouncements[$hash] = time();
    }

    /**
     * Remove from book cart
     *
     * This removes the given bib from the logged in user's book cart.
     *
     * @param string  $id    The record id to remove from the book cart
     */
    public function removeFromBookCart($id) {
        if( !isset($this->session->patron) ) {
            return;
        }

        // get the bookcart
        $user = $this->getDbTable('User')->getByCatUsername($this->session->patron["cat_username"]);
        $bookCart = $user->getBookCart();

        // remove this item from it
        $bookCart->removeResourcesById($user, [$id]);

        // clear the cached contents of the list
        $this->clearMemcachedVar("cachedList" . $bookCart->id);
    }

    /**
     * Test Serial
     *
     * This checks the API to see if this bib has a serial type.
     *
     * @param string $id The record id to test the bibLevel
     *
     * @return bool  Whether or not this bib is a serial type (used to determine if we need to look for checkin records)
     */
    public function isSerial($id)
    {
        // grab a bit more information from Solr
        $solrBaseURL = $this->config['Solr']['url'];
        $curl_url = $solrBaseURL . "/biblio/select?q=*%3A*&fq=id%3A%22" . strtolower($id) . "%22&fl=bib_level&wt=csv";
        $curl_connection = curl_init($curl_url);
        curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
        curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
        $sresult = curl_exec($curl_connection);
        $values = explode("\n", $sresult);

        // is it a Solr item?
        return (count($values) > 2) && ($values[1] == "s");
    }

    /**
     * Test Session
     *
     * This checks the session to ensure it isn't outdated, either from being too old or being generated by an older version of the code.
     *
     * @param string $id The record id to test the bibLevel
     *
     * @return bool  Whether or not this bib is a serial type (used to determine if we need to look for checkin records)
     */
    public function testSession()
    {
        if( (isset($this->session->sessionExpiration) && ($this->session->sessionExpiration < time())) || 
            (isset($this->session->memCacheRefreshTimer) && ($this->memcached->get("globalRefreshTimer") != $this->session->memCacheRefreshTimer)) ) {
            unset($this->session->checkouts);
            unset($this->session->holds);
            unset($this->session->patron);
            unset($this->session->memCacheRefreshTimer);
            unset($this->session->sessionExpiration);
        }

        // now fix these if they haven't been set yet
        if( !isset($this->session->memCacheRefreshTimer) ) {
            $this->session->memCacheRefreshTimer = $this->memcached->get("globalRefreshTimer");
        }
        if( !isset($this->session->sessionExpiration) ) {
            $this->session->sessionExpiration = time() + 1800;
        }
    }



    /**
     * Screen Scraping functionality
     * 
     * The functions after this point leverage the screen scraping functionality from our previous iteration of the catalog.
     * These actions should eventually be available via the Sierra API (and as such be implemented in the Sierra2 driver), 
     * but our current version of the API does not have them available at this point.
     * 
     */


    public function requestPINReset($barcode) {
        if (isset($barcode) && strlen($barcode) > 0) {
            //User has entered a barcode and requested a pin reset
            $header=array();
            $header[0] = "Accept: text/xml,application/xml,application/xhtml+xml,";
            $header[0] .= "text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5";
            $header[] = "Cache-Control: max-age=0";
            $header[] = "Connection: keep-alive";
            $header[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
            $header[] = "Accept-Language: en-us,en;q=0.5";
            $cookie = tempnam ("/tmp", "CURLCOOKIE");

            $curl_connection = curl_init();
            curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($curl_connection, CURLOPT_HTTPHEADER, $header);
            curl_setopt($curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
            curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl_connection, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl_connection, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curl_connection, CURLOPT_UNRESTRICTED_AUTH, true);
            curl_setopt($curl_connection, CURLOPT_COOKIEJAR, $cookie);
            curl_setopt($curl_connection, CURLOPT_COOKIESESSION, true);
            curl_setopt($curl_connection, CURLOPT_FORBID_REUSE, false);
            curl_setopt($curl_connection, CURLOPT_HEADER, false);

            //Go to the pin reset page
            $curl_url = $this->config['Catalog']['classic_url'] . "/pinreset";
            curl_setopt($curl_connection, CURLOPT_URL, $curl_url);
            curl_setopt($curl_connection, CURLOPT_HTTPGET, true);
            $sresult = curl_exec($curl_connection);

            //Post the barcode to request a PIN reset email
            $post_data = array();
            $post_data['submit.x']="35";
            $post_data['submit.y']="21";
            $post_data['code']= $barcode;
            curl_setopt($curl_connection, CURLOPT_POST, true);
            curl_setopt($curl_connection, CURLOPT_URL, $curl_url);
            foreach ($post_data as $key => $value) {
                $post_items[] = $key . '=' . $value;
            }
            $post_string = implode ('&', $post_items);
            curl_setopt($curl_connection, CURLOPT_POSTFIELDS, $post_string);
            $sresult = curl_exec($curl_connection);
            if (!preg_match('/A message has been sent./i', $sresult)) {
                //PEAR::raiseError('Unable to request PIN reset for this barcode');
                return false;
            } else {
                return true;
            }
        }
    }

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
                        } else {
                            preg_match('/.*?<span class=\\"patFuncTitleMain\\">(.*?)<\/span>.*/', $scols[$i], $matches);
                            $shortId = null;
                            $bibid = null;
                            $title = $matches[1];
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
        return array('historyActive'=>$historyActive, 'titles'=>$readingHistoryTitles, 'numTitles'=> $numTitles, 'total_records' => $total_records, 'page' => $page);
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

    /**
     * Place Item Hold
     *
     * This is responsible for both placing item level holds.
     *
     * @param   string  $recordId   The id of the bib record
     * @param   string  $itemId     The id of the item to hold
     * @param   string  $patronId   The id of the patron
     * @param   string  $comment    Any comment regarding the hold or recall
     * @param   string  $type       Whether to place a hold or recall
     * @param   string  $type       The date when the hold should be cancelled if any
     * @return  mixed               True if successful, false if unsuccessful
     *                              If an error occures, return a PEAR_Error
     * @access  public
     */
    private function placeItemLevelHold($details)
    {
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
        $post_string = 'code=' . $details["patron"]["cat_username"] . '&pin=' . $details["patron"]["cat_password"]  . '&submit=submit';
        curl_setopt($curl_connection, CURLOPT_POSTFIELDS, $post_string);
        $sresult = curl_exec($curl_connection);

        list($Month, $Day, $Year)=explode("-", $details["requiredBy"]);

        // now try to request the item
        $header=array();
        $header[0] = "Accept: text/xml,application/xml,application/xhtml+xml,";
        $header[0] .= "text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5";
        $header[] = "Cache-Control: max-age=0";
        $header[] = "Connection: keep-alive";
        $header[] = "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
        $header[] = "Accept-Language: en-us,en;q=0.5";

        curl_setopt($curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl_connection, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
        curl_setopt($curl_connection, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_connection, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl_connection, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl_connection, CURLOPT_UNRESTRICTED_AUTH, true);
        curl_setopt($curl_connection, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($curl_connection, CURLOPT_COOKIESESSION, true);
        curl_setopt($curl_connection, CURLOPT_FORBID_REUSE, false);
        curl_setopt($curl_connection, CURLOPT_HEADER, false);
        curl_setopt($curl_connection, CURLOPT_POST, true);

        $bib = substr($details["bibId"], 1, -1);
        $curl_url = $this->config['Catalog']['classic_url'] . "/search/." . $bib . "/." . $bib ."/1,1,1,B/request~" . $bib;
        curl_setopt($curl_connection, CURLOPT_URL, $curl_url);

        $post_data['needby_Month']= $Month;
        $post_data['needby_Day']= $Day;
        $post_data['needby_Year']=$Year;
        $post_data['submit.x']="35";
        $post_data['submit.y']="21";
        $post_data['submit']="submit";
        $post_data['locx00']= str_pad($details["pickUpLocation"], 5-strlen($details["pickUpLocation"]), '+');
        // BJP - we're temporarily running ALL holds through screen scraping. when that goes away, you can remove the if wrapper around the contents of this since 
        //       all holds coming through here will be item-level
        if( substr($details["id"], 1, 1) == "i" ) {
            $post_data['radio']=substr($details["id"], 1 , -1);
        }
        $post_data['submit']="REQUEST SELECTED ITEM";
        $post_data['x']="48";
        $post_data['y']="15";

        foreach ($post_data as $key => $value) {
            $post_items[] = $key . '=' . $value;
        }
        $post_string = implode ('&', $post_items);
        curl_setopt($curl_connection, CURLOPT_POSTFIELDS, $post_string);
        $sresult = curl_exec($curl_connection);

        $sresult = preg_replace("/<!--([^(-->)]*)-->/","",$sresult);
        curl_close($curl_connection);

        //Parse the response to get the status message
        $hold_result = $this->_getHoldResult($sresult);

        return $hold_result;
    }

    protected function _getHoldResult($holdResultPage){
        $hold_result = array();
        //Get rid of header and footer information and just get the main content
        $matches = array();

        $itemMatches = preg_match('/Choose one item from the list below/', $holdResultPage);

        if ($itemMatches == 0){
            //not prompting to select a specific item for a volume hold	
            //hold responses start after the form is closed
            $responseStart = strpos($holdResultPage,'</form>');
            if ($responseStart === false) {
                $hold_result['success'] = false;
                $hold_result['message'] = '<i class=\'fa fa-exclamation-triangle\'></i>Did not receive a response from the circulation system.  Please try again in a few minutes.';
                $reason = '';
            } else {
                //get the part of the response page that contains the response to placing the hold
                $responseText = substr($holdResultPage,$responseStart);

                //Hold was successful
                if (strpos($responseText,'was successful') > 1 && strpos($responseText,'You will be notified when the status of this item says Ready For Pickup') > 0) {
                    $hold_result['success'] = true;
                    $hold_result['message'] = '<i class=\'fa fa-info\'></i>Your request was placed successfully';
                    $reason = '';
                    //Check for reasons why a hold is not successful
                } else {
                    if (strpos($responseText,'Request denied - already requested or checked out to you') > 1) {
                        $hold_result['success'] = false;
                        $hold_result['message'] = '<i class=\'fa fa-exclamation-triangle\'></i>Already requested or checked out';
                    } elseif  (strpos($responseText,'No requestable items are available') > 1) {
                        $hold_result['success'] = false;
                        $hold_result['message'] = '<i class=\'fa fa-exclamation-triangle\'></i>There are no requestable items available';
                    } elseif  (strpos($responseText,'No items requestable, request denied') > 1) {
                        $hold_result['success'] = false;
                        $hold_result['message'] = '<i class=\'fa fa-exclamation-triangle\'></i>There are no requestable items available';		
                    } elseif  (strpos($responseText,'Sorry, request cannot be accepted. Local copy is available.') > 1) {
                        $hold_result['success'] = false;
                        $hold_result['message'] = '<i class=\'fa fa-exclamation-triangle\'></i>There is a copy available on the shelf at this location';
                    } elseif  (strpos($responseText,'There is a problem with your library record.  Please see a librarian') > 1) {
                        $hold_result['success'] = false;
                        $hold_result['message'] = '<i class=\'fa fa-exclamation-triangle\'></i>Your patron record is blocked or expired';
                    // generic error message
                    } else {
                        $hold_result['success'] = false;
                        $hold_result['message'] = '<i class=\'fa fa-exclamation-triangle\'></i>There was an error placing your request';
                    }
                }
            }	
        }else{
            $hold_result['success'] = false;
            $hold_result['message'] = '<i class=\'fa fa-exclamation-triangle\'></i>There was an error placing your request';
        }
        return $hold_result;
    }

    public function getNumberOfHoldsOnRecord($id) {
        if( $this->memcached->get("numberOfHoldsOnID" . $id) !== null ) {
            $cookieJar = tempnam("/tmp", "CURLCOOKIE");

            $bib = substr($id, 2, -1);
            $curl_url = $this->config['Catalog']['classic_url'] . "/search~S1/.b" . $bib ."/.b" . $bib . "/1,1,1,B/frameset~" . $bib;
            $this->curl_connection = curl_init($curl_url);

            curl_setopt($this->curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($this->curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
            curl_setopt($this->curl_connection, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->curl_connection, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($this->curl_connection, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($this->curl_connection, CURLOPT_UNRESTRICTED_AUTH, true);
            curl_setopt($this->curl_connection, CURLOPT_COOKIEJAR, $cookieJar );
            curl_setopt($this->curl_connection, CURLOPT_COOKIESESSION, !($cookieJar) ? true : false);

            $checkinText = curl_exec($this->curl_connection);
            curl_close($this->curl_connection);

            if (preg_match('/(\d+) hold(s?) on .*? of \d+ (copies|copy)/', $checkinText, $matches)){
                $holdQueueLength = $matches[1];
            }else{
                $holdQueueLength = 0;
            }

            $this->memcached->set("numberOfHoldsOnID" . $id, $holdQueueLength, 900);
        }
        return $this->memcached->get("numberOfHoldsOnID" . $id);
    }

    private function getCheckinRecords($id) {
        $cookieJar = tempnam("/tmp", "CURLCOOKIE");

        $bib = substr($id, 2, -1);
        $curl_url = $this->config['Catalog']['classic_url'] . "/search~S1/.b" . $bib ."/.b" . $bib . "/1,1,1,B/frameset~" . $bib;
        $this->curl_connection = curl_init($curl_url);

        curl_setopt($this->curl_connection, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($this->curl_connection, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
        curl_setopt($this->curl_connection, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->curl_connection, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($this->curl_connection, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($this->curl_connection, CURLOPT_UNRESTRICTED_AUTH, true);
        curl_setopt($this->curl_connection, CURLOPT_COOKIEJAR, $cookieJar );
        curl_setopt($this->curl_connection, CURLOPT_COOKIESESSION, !($cookieJar) ? true : false);

        $checkinText = curl_exec($this->curl_connection);
        curl_close($this->curl_connection);

        if (preg_match('/class\\s*=\\s*\\"bibHoldings\\"/s', $checkinText)){
            //There are issue summaries available
            //Extract the table with the holdings
            $issueSummaries = array();
            $matches = array();
            if (preg_match('/<table\\s.*?class=\\"bibHoldings\\">(.*?)<\/table>/s', $checkinText, $matches)) {
                $issueSummaryTable = trim($matches[1]);
                //Each holdingSummary begins with a holdingsDivider statement
                $summaryMatches = explode('<tr><td colspan="2"><hr  class="holdingsDivider" /></td></tr>', $issueSummaryTable);
                if (count($summaryMatches) > 1){
                    //Process each match independently
                    foreach ($summaryMatches as $summaryData){
                        $summaryData = trim($summaryData);
                        if (strlen($summaryData) > 0){
                            //Get each line within the summary
                            $issueSummary = array();
                            $issueSummary['type'] = 'issueSummary';
                            $summaryLines = array();
                            preg_match_all('/<tr\\s*>(.*?)<\/tr>/s', $summaryData, $summaryLines, PREG_SET_ORDER);
                            for ($matchi = 0; $matchi < count($summaryLines); $matchi++) {
                                $summaryLine = trim(str_replace('&nbsp;', ' ', $summaryLines[$matchi][1]));
                                $summaryCols = array();
                                if (preg_match('/<td.*?>(.*?)<\/td>.*?<td.*?>(.*?)<\/td>/s', $summaryLine, $summaryCols)) {
                                    $label = trim($summaryCols[1]);
                                    $value = trim(strip_tags($summaryCols[2]));
                                    //Convert to camel case
                                    $label = (preg_replace('/[^\\w]/', '', strip_tags($label)));
                                    $label = strtolower(substr($label, 0, 1)) . substr($label, 1);
                                    if ($label == 'location'){
                                        //Try to trim the courier code if any
                                        if (preg_match('/(.*?)\\sC\\d{3}\\w{0,2}$/', $value, $locationParts)){
                                            $value = $locationParts[1];
                                        }
                                    }
                                    $issueSummary[$label] = $value;
                                }
                            }
                            $issueSummaries[$issueSummary['location'] . count($issueSummaries)] = $issueSummary;
                        }
                    }
                }
            }
            return $issueSummaries;
        } else {
            return [];
        }
    }

    /**
     * Update a hold that was previously placed in the system.
     * Can cancel the hold or update pickup locations.
     */
    public function updateHoldDetailed($patron, $requestId, $patronId, $type, $title, $cancelId, $locationId, $freeze = null)
    {
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
        $post_string = 'code=' . $patron["cat_username"] . '&pin=' . $patron["cat_password"]  . '&submit=submit';
        curl_setopt($curl_connection, CURLOPT_POSTFIELDS, $post_string);
        $sresult = curl_exec($curl_connection);

        //go to the holds page and get the number of holds on the account
        $curl_url = $this->config['Catalog']['classic_url'] . "/patroninfo~S1/" . $patron['id'] ."/holds";
        curl_setopt($curl_connection, CURLOPT_URL, $curl_url);
        curl_setopt($curl_connection, CURLOPT_HTTPGET, true);
        $sresult = curl_exec($curl_connection);
        $holds = $this->parseHoldsPage($sresult);
        $numHoldsStart = count($holds);

        // put together the update args
        if (isset($locationId)){
            $paddedLocation = str_pad(trim($locationId), 5, "+");
        }else{
            $paddedLocation = null;
        }

        $cancelValue = ($type == 'cancel') ? 'on' : 'off';

        foreach( $holds as $thisHold ) {
            if ($thisHold["itemId"] == substr($cancelId, 1, -1)){
                $extraGetInfo = array(
                    'updateholdssome' => 'YES',
                    'cancel' . $thisHold["itemId"] . "x" . $thisHold["xnum"] => $cancelValue,
                    'currentsortorder' => 'current_pickup',
                );
                if ($paddedLocation && $thisHold['locationUpdateable']){
                    $success = true;
                    $extraGetInfo['loc' . $thisHold["itemId"] . "x" . $thisHold["xnum"]] = $paddedLocation;
                } else if ($paddedLocation && !$thisHold['locationUpdateable']){
                    $success = false;
                }
                if( $freeze != null ) {
                    $extraGetInfo['freeze' . $thisHold["itemId"] . "x" . $thisHold["xnum"]] = $freeze;
                }
            }
        }

        $get_items = array();
        foreach ($extraGetInfo as $key => $value) {
            $get_items[] = $key . '=' . urlencode($value);
        }
        $holdUpdateParams = implode ('&', $get_items);

        //Issue a get request with the information about what to do with the holds
        $curl_url = $this->config['Catalog']['classic_url'] . "/patroninfo~S{$scope}/" . $patron['id'] ."/holds";
        curl_setopt($curl_connection, CURLOPT_URL, $curl_url);
        curl_setopt($curl_connection, CURLOPT_POSTFIELDS, $holdUpdateParams);
        curl_setopt($curl_connection, CURLOPT_HTTPPOST, true);
        $sresult = curl_exec($curl_connection);
        if( $type == 'freeze' ) {
            //At this stage, we get messages if there were any errors freezing holds.
            $holds = $this->parseHoldsPage($sresult);
        } else {
            //Go back to the hold page to check make sure our hold was cancelled
            $curl_url = $this->config['Catalog']['classic_url'] . "/patroninfo~S{$scope}/" . $patron['id'] ."/holds";
            curl_setopt($curl_connection, CURLOPT_URL, $curl_url);
            curl_setopt($curl_connection, CURLOPT_HTTPGET, true);
            $sresult = curl_exec($curl_connection);
            $holds = $this->parseHoldsPage($sresult);
            $numHoldsEnd = count($holds);
        }

        curl_close($curl_connection);

        unlink($cookieJar);

        //Finally, check to see if the update was successful.
        if ($type == 'cancel'){
            if ($numHoldsEnd != $numHoldsStart){
                $success = true;
            }
        } else if ($type == 'freeze'){
            $success = true;
            foreach( $holds as $thisHold ) {
                if( $thisHold['freezeError'] ) {
                    $success = false;
                }
            }
        }

        return $success;
    }

    public function parseHoldsPage($sresult){
        $holds = array();

        $sresult = preg_replace("/<[^<]+?>\W<[^<]+?>\W\d* HOLD.?\W<[^<]+?>\W<[^<]+?>/", "", $sresult);
        $s = substr($sresult, stripos($sresult, 'patFunc'));
        $s = substr($s,strpos($s,">")+1);
        $s = substr($s,0,stripos($s,"</table"));
        $s = preg_replace ("/<br \/>/","", $s);

        $srows = preg_split("/<tr([^>]*)>/",$s);
        $scount = 0;
        $skeys = array_pad(array(),10,"");
        foreach ($srows as $srow) {
            $scols = preg_split("/<t(h|d)([^>]*)>/",$srow);
            $curHold= array();
            $curHold['create'] = null;
            $curHold['reqnum'] = null;

            //Holds page occassionally has a header with number of items checked out.
            for ($i=0; $i < sizeof($scols); $i++) {
                $scols[$i] = str_replace("&nbsp;"," ",$scols[$i]);
                $scols[$i] = preg_replace ("/<br+?>/"," ", $scols[$i]);
                $scols[$i] = html_entity_decode(trim(substr($scols[$i],0,stripos($scols[$i],"</t"))));
                if ($scount <= 2) {
                    $skeys[$i] = $scols[$i];
                } else if ($scount > 1) {
                    if ($skeys[$i] == "CANCEL") { //Only check Cancel key, not Cancel if not filled by
                        //Extract the id from the checkbox
                        $matches = array();
                        $numMatches = preg_match_all('/.*?cancel(.*?)x(\\d\\d).*/s', $scols[$i], $matches);
                        if ($numMatches > 0){
                            $curHold['renew'] = "BOX";
                            $curHold['cancelable'] = true;
                            $curHold['itemId'] = $matches[1][0];
                            $curHold['xnum'] = $matches[2][0];
                            $curHold['cancelId'] = $matches[1][0] . '~' . $matches[2][0];
                        }else{
                            $curHold['cancelable'] = false;
                        }
                    }
                    if (stripos($skeys[$i],"TITLE") > -1) {
                        if (preg_match('/.*?<a href=\\"\/record=(.*?)(?:~S\\d{1,2})\\">(.*?)<\/a>.*/', $scols[$i], $matches)) {
                            $shortId = $matches[1];
                            $bibid = '.' . $matches[1]; //Technically, this isn't corrcect since the check digit is missing
                            $title = $matches[2];
                        }else{
                            $bibid = '';
                            $shortId = '';
                            $title = trim($scols[$i]);
                        }
                        $curHold['id'] = $bibid;
                        $curHold['shortId'] = $shortId;
                        $curHold['title'] = $title;
                    }
                    if (stripos($skeys[$i],"Ratings") > -1) {
                        $curHold['request'] = "STARS";
                    }
                    if (stripos($skeys[$i],"PICKUP LOCATION") > -1) {
                        //Extract the current location for the hold if possible
                        $matches = array();
                        if (preg_match('/<select\s+name=loc(.*?)x(\d\d).*?<option\s+value="([\w]{1,5})[+ ]*"\s+selected="selected">.*/s', $scols[$i], $matches)){
                            $curHold['locationId'] = $matches[1];
                            $curHold['locationXnum'] = $matches[2];
                            $curHold['currentPickupId'] = $matches[3];
                            $curHold['currentPickupName'] = $matches[4];
                            $curHold['locationUpdateable'] = true;
                            //Return the full select box for reference.
                            //$curHold['locationSelect'] = $scols[$i];
                        }elseif (preg_match('/<select\s+name=loc(.*?)x(\d\d).*?<option\s+value="([\w]{1,5})[+ ]*"\s>.*/s', $scols[$i], $matches)){
                            //no library selected, and it wants a holding from a location
                            $curHold['location'] = "<font style='color:red'>No location selected</font>";
                            $curHold['locationUpdateable'] = true;
                        }else{
                            $curHold['location'] = $scols[$i];
                            $curHold['currentPickupName'] = $curHold['location'];
                            $curHold['locationUpdateable'] = false;
                        }
                    }
                    if (stripos($skeys[$i],"STATUS") > -1) {
                        $status = trim(strip_tags($scols[$i]));
                        $status = strtolower($status);
                        $status = ucwords($status);
                        if ($status !="&nbsp"){
                            $curHold['status'] = $status;
                            if (preg_match('/READY.*(\d{2}-\d{2}-\d{2})/i', $status, $matches)){
                                $curHold['status'] = 'Ready';
                                //Get expiration date
                                $exipirationDate = $matches[1];
                                $expireDate = DateTime::createFromFormat('m-d-y', $exipirationDate);
                                $curHold['expire'] = $expireDate->getTimestamp();
                            }elseif (preg_match('/READY\sFOR\sPICKUP/i', $status, $matches)){
                                $curHold['status'] = 'Ready';
                            }else{
                                $curHold['status'] = $status;
                            }
                        }else{
                            $curHold['status'] = "Pending $status";
                        }
                        $matches = array();
                        $curHold['renewError'] = false;
                        if (preg_match('/.*DUE\\s(\\d{2}-\\d{2}-\\d{2}).*(?:<font color="red">\\s*(.*)<\/font>).*/s', $scols[$i], $matches)){
                            //Renew error
                            $curHold['renewError'] = $matches[2];
                            $curHold['statusMessage'] = $matches[2];
                        }else{
                            if (preg_match('/.*DUE\\s(\\d{2}-\\d{2}-\\d{2})\\s(.*)?/s', $scols[$i], $matches)){
                                $curHold['statusMessage'] = $matches[2];
                            }
                        }
                    }
                    if (stripos($skeys[$i],"CANCEL IF NOT FILLED BY") > -1) {
                        //$curHold['expire'] = strip_tags($scols[$i]);
                    }
                    if (stripos($skeys[$i],"FREEZE") > -1){
                        $matches = array();
                        $curHold['frozen'] = false;
                        if (preg_match('/<input.*name="freeze(.*?)"\\s*(\\w*)\\s*\/>/', $scols[$i], $matches)){
                            $curHold['freezeable'] = true;
                            if (strlen($matches[2]) > 0){
                                $curHold['frozen'] = true;
                                $curHold['status'] = 'Frozen';
                            }
                        }elseif (preg_match('/This hold can\s?not be frozen/i', $scols[$i], $matches)){
                            //If we detect an error Freezing the hold, save it so we can report the error to the user later.
                            $curHold['freezeError'] = true;
                        }else{
                            $curHold['freezeable'] = false;
                        }
                    }
                }
            } //End of columns
            if ($scount > 2) {
                $holds[] = $curHold;
            }
            $scount++;
        }//End of the row
        return $holds;
    }
}