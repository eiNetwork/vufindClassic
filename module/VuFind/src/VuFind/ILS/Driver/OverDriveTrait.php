<?php

//require_once 'sys/eContent/EContentRecord.php';

/**
 * Complete integration via APIs including availability and account informatino.
 *
 * Copyright (C) Douglas County Libraries 2011.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version 1.0
 * @author Mark Noble <mnoble@turningleaftech.com>
 * @copyright Copyright (C) Douglas County Libraries 2011.
 *
 *
 * Edited for use in VuFind 2.x by Brad Patton with eiNetwork.
 */

namespace VuFind\ILS\Driver;

trait OverDriveTrait {
    public $version = 4;

    protected $format_map = array(
        'ebook-epub-adobe' => 'Adobe EPUB eBook',
        'ebook-epub-open' => 'Open EPUB eBook',
        'ebook-pdf-adobe' => 'Adobe PDF eBook',
        'ebook-pdf-open' => 'Open PDF eBook',
        'ebook-kindle' => 'Kindle Book',
        'ebook-disney' => 'Disney Online Book',
        'ebook-overdrive' => 'OverDrive Read',
        'ebook-microsoft' => 'Microsoft eBook',
        'audiobook-wma' => 'OverDrive WMA Audiobook',
        'audiobook-mp3' => 'OverDrive MP3 Audiobook',
        'audiobook-streaming' => 'Streaming Audiobook',
        'music-wma' => 'OverDrive Music',
        'video-wmv' => 'OverDrive Video',
        'video-wmv-mobile' => 'OverDrive Video (mobile)',
        'video-streaming' => 'Streaming Video'
    );

    private $tokenData;
    private $patronTokenData;

    public function getOverDriveLendingOptions($userinfo){
        // overdrive info
        $lendingOptions = array("renewalInDays" => array());

        $url = $this->config['OverDrive']['patronApiUrl'] . '/v1/patrons/me';
        $profileData = $this->_callPatronUrl($userinfo['cat_username'], $userinfo['cat_password'], $url);

        foreach( $profileData->lendingPeriods as $period ) {
            $lendingOptions[$period->formatType] = $period->lendingPeriod . " " . $period->units;
        }

        foreach( $profileData->actions as $action ) {
            $type = null;
            $options = null;
            foreach( $action->editLendingPeriod->fields as $field ) {
                if( $field->name == "formatClass" ) {
                    $type = $field->value;
                } else if( $field->name == "lendingPeriodDays" ) {
                    $options = $field->options;
                }
            }
            if( $type != null && $options != null ) {
                $lendingOptions['renewalInDays'][$type] = $options;
            }
        }

        return $lendingOptions;
    }

    public function setOverDriveLendingOption($lendingInfo){
        // lending info
        $lendingOptions = array("formatClass" => $lendingInfo["format"],
                                "lendingPeriodDays" => $lendingInfo["days"]);

        $url = $this->config['OverDrive']['patronApiUrl'] . '/v1/patrons/me';
        $this->_callPatronUrl($lendingInfo['cat_username'], $lendingInfo['cat_password'], $url, $lendingOptions, 'PUT');
    }

    /**
     * Retrieves the URL for the cover of the record by screen scraping OverDrive.
     * ..
     * @param EContentRecord $record
     * @return string
     */
/*
    public function getCoverUrl($record){
        $overDriveId = $record->getOverDriveId();
        //Get metadata for the record
        $metadata = $this->getProductMetadata($overDriveId);
        if (isset($metadata->images) && isset($metadata->images->cover)){
            return $metadata->images->cover->href;
        }else{
            return "";
        }
    }
*/

    private function _connectToAPI($forceNewConnection = false){
        if( $forceNewConnection || $this->tokenData == null || time() >= $this->tokenData->expirationTime ) {
            $ch = curl_init("https://oauth.overdrive.com/token");
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded;charset=UTF-8'));
            curl_setopt($ch, CURLOPT_USERPWD, $this->config['OverDrive']['clientKey'] . ":" . $this->config['OverDrive']['clientSecret']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=client_credentials");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            $return = curl_exec($ch);
            curl_close($ch);
            $this->tokenData = json_decode($return);

            $this->tokenData->expirationTime = time() + $this->tokenData->expires_in;
        }
        return $this->tokenData;
    }

    private function _connectToPatronAPI($patronBarcode, $patronPin = 1234, $forceNewConnection = false){
        if( $forceNewConnection || $this->patronTokenData == null || time() >= $this->patronTokenData->expirationTime ) {
            $ch = curl_init("https://oauth-patron.overdrive.com/patrontoken");
            $websiteId = $this->config['OverDrive']['patronWebsiteId'];
            //$websiteId = 100300;
            $ilsname = $this->config['OverDrive']['LibraryCardILS'];
            //$ilsname = "default";
            $clientSecret = $this->config['OverDrive']['clientSecret'];
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            $encodedAuthValue = base64_encode($this->config['OverDrive']['clientKey'] . ":" . $clientSecret);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
                "Authorization: Basic " . $encodedAuthValue,
                "User-Agent: VuFind-Plus"
            ));
            //curl_setopt($ch, CURLOPT_USERPWD, "");
            //$clientSecret = $this->config['OverDrive']['clientSecret'];
            //curl_setopt($ch, CURLOPT_USERPWD, $this->config['OverDrive']['clientKey'] . ":" . $clientSecret);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_POST, 1);

            if ($patronPin == null){
                $postFields = "grant_type=password&username={$patronBarcode}&password=ignore&password_required=false&scope=websiteId:{$websiteId}%20ilsname:{$ilsname}";
            }else{
                $postFields = "grant_type=password&username={$patronBarcode}&password={$patronPin}&scope=websiteId:{$websiteId}%20ilsname:{$ilsname}";
            }
            //$postFields = "grant_type=client_credentials&scope=websiteid:{$websiteId}%20ilsname:{$ilsname}%20cardnumber:{$patronBarcode}";
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            $return = curl_exec($ch);
            $curlInfo = curl_getinfo($ch);
            curl_close($ch);
            $this->patronTokenData = json_decode($return);
            $this->patronTokenData->expirationTime = time() + $this->patronTokenData->expires_in;
        }
        return $this->patronTokenData;
    }

    private function _callUrl($url){
        if ( $this->_connectToAPI() ){
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: {$this->tokenData->token_type} {$this->tokenData->access_token}", "User-Agent: VuFind-Plus"));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            $return = curl_exec($ch);
            $curlInfo = curl_getinfo($ch);
            curl_close($ch);
            $returnVal = json_decode($return);
            //print_r($returnVal);
            if ($returnVal != null){

                if (!isset($returnVal->message) || $returnVal->message != 'An unexpected error has occurred.'){
                    return $returnVal;
                }
            }
        }
        return null;
    }

    private function _callPatronUrl($patronBarcode, $patronPin, $url, $params = null, $requestType = null){

        if ($this->_connectToPatronAPI($patronBarcode, $patronPin, false)){
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_USERAGENT,"Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)");
            $authorizationData = $this->patronTokenData->token_type . ' ' . $this->patronTokenData->access_token;
            $headers = array(
                "Authorization: $authorizationData",
                "User-Agent: VuFind-Plus",
                "Host: " . str_replace('http://', '', $this->config['OverDrive']['patronApiUrl'])
            );
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            if ($params != null){
                if( $requestType != null ) {
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $requestType);
                }
                curl_setopt($ch, CURLOPT_POST, 1);
                //Convert post fields to json
                $jsonData = array('fields' => array());
                foreach ($params as $key => $value){
                    $jsonData['fields'][] = array(
                        'name' => $key,
                        'value' => $value
                    );
                }
                $postData = json_encode($jsonData);
                //print_r($postData);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                $headers[] = 'Content-Type: application/vnd.overdrive.content.api+json';
            }else{
                curl_setopt($ch, CURLOPT_HTTPGET, true);
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $return = curl_exec($ch);
            $curlInfo = curl_getinfo($ch);
            if ($returnInfo['http_code'] == 204){
                $result = true;
            }else{
                $result = false;
            }
            curl_close($ch);
            $returnVal = json_decode($return);

            if ($returnVal != null){

                if (!isset($returnVal->message) || $returnVal->message != 'An unexpected error has occurred.'){
                    return $returnVal;
                }
            }else{
                return $result;
            }
        }
        return false;
    }

/*
    public function getLibraryAccountInformation(){
        $libraryId = $this->config['OverDrive']['accountId'];
        return $this->_callUrl("http://api.overdrive.com/v1/libraries/$libraryId");
    }
*/

/*
    public function getAdvantageAccountInformation(){
        $libraryId = $this->config['OverDrive']['accountId'];
        return $this->_callUrl("http://api.overdrive.com/v1/libraries/$libraryId/advantageAccounts");
    }
*/

/*
    public function getProductsInAccount($productsUrl = null, $start = 0, $limit = 25){
        if ($productsUrl == null){
            $libraryId = $this->config['OverDrive']['accountId'];
            $productsUrl = "http://api.overdrive.com/v1/collections/$libraryId/products";
        }
        $productsUrl .= "?offeset=$start&limit=$limit";
        return $this->_callUrl($productsUrl);
    }
*/

/*
    public function getProductMetadata($overDriveId, $productsKey = null){
        if ($productsKey == null){
            $productsKey = $this->config['OverDrive']['productsKey'];
        }
        $overDriveId= strtoupper($overDriveId);
        $metadataUrl = "http://api.overdrive.com/v1/collections/$productsKey/products/$overDriveId/metadata";
        return $this->_callUrl($metadataUrl);
    }
*/

    public function getProductAvailability($overDriveId, $productsKey = null){
        if ($productsKey == null){
            $productsKey = $this->config['OverDrive']['productsKey'];
        }
        $baseUrl = $this->config['OverDrive']['apiUrl'];
        $availabilityUrl = "$baseUrl/v1/collections/$productsKey/products/$overDriveId/availability";
        return $this->_callUrl($availabilityUrl);
    }

/*
    private $checkouts = array();
*/
    /**
     * Loads information about items that the user has checked out in OverDrive
     *
     * @param User $user
     * @param array $overDriveInfo optional array of information loaded from _loginToOverDrive to improve performance.
     *
     * @return array
     */
/*
    public function getOverDriveCheckedOutItems($user, $overDriveInfo = null){
        if (isset($this->checkouts[$user->id])){
            return $this->checkouts[$user->id];
        }
        $url = $this->config['OverDrive']['patronApiUrl'] . '/v1/patrons/me/checkouts';
        $response = $this->_callPatronUrl($user->cat_username, $user->cat_password, $url);
        //echo "<pre>patronUrl response ";
        //print_r($response);
        //echo "</pre>";
        $checkedOutTitles = array();
        if (isset($response->checkouts)){
            foreach ($response->checkouts as $curTitle){
                //echo "<pre>curtitle ";
                //print_r($curTitle);
                //echo "</pre>";
                
                $bookshelfItem = array();
                //Load data from api
                $bookshelfItem['overDriveId'] = $curTitle->reserveId;
                $bookshelfItem['expiresOn'] = $curTitle->expires;
                $bookshelfItem['overdriveListen'] = false;
                $bookshelfItem['overdriveRead'] = false;
                $bookshelfItem['streamingVideo'] = false;                
                $bookshelfItem['formatSelected'] = ($curTitle->isFormatLockedIn == 1);
                $bookshelfItem['formats'] = array();
                if (isset($curTitle->formats)){
                    foreach ($curTitle->formats as $id => $format){
                        if ($format->formatType == 'ebook-overdrive'){
                            $bookshelfItem['overdriveRead'] = true;
                        }elseif ($format->formatType == 'video-streaming'){
                            $bookshelfItem['streamingVideo'] = true;
                        }elseif ($format->formatType == 'audiobook-overdrive'){
                            $bookshelfItem['overdriveListen'] = true;
                        }else{
                            $bookshelfItem['selectedFormat'] = array(
                                'name' => $this->format_map[$format->formatType],
                                'format' => $format->formatType,
                            );
                        }
                        $curFormat = array();
                        $curFormat['id'] = $id;
                        $curFormat['format'] = $format;
                        $curFormat['name'] = $format->formatType;
                        if (isset($format->links->self)){
                            $curFormat['downloadUrl'] = $format->links->self->href . '/downloadlink';
                        }
                        // OverDrive Read - access online instead of download
                        if ($format->formatType == 'ebook-overdrive') {
                            if (isset($curFormat['downloadUrl'])){
                                $bookshelfItem['overdriveReadUrl'] = $curFormat['downloadUrl'];
                            }
                        // Streaming Video - access online instead of download
                        }elseif ($format->formatType == 'video-streaming') {
                            if (isset($curFormat['downloadUrl'])){
                                $bookshelfItem['streamingVideoUrl'] = $curFormat['downloadUrl'];
                            }
                        // Overdrive Listen - access online instead of download
                        }elseif ($format->formatType == 'audiobook-overdrive') {
                            if (isset($curFormat['downloadUrl'])){
                                $bookshelfItem['overdriveListenUrl'] = $curFormat['downloadUrl'];
                            }
                        // Downloadable formats
                        } else {
                            $bookshelfItem['formats'][] = $curFormat;
                        }
                    }
                    //echo "<pre>bookshelfitem ";
                    //print_r($bookshelfItem);
                    //echo "</pre>";
                }
                if (isset($curTitle->actions->format) && !$bookshelfItem['formatSelected']){
                    //Get the options for the format which includes the valid formats
                    $formatField = null;
                    foreach ($curTitle->actions->format->fields as $curFieldIndex => $curField){
                        if ($curField->name == 'formatType'){
                            $formatField = $curField;
                            break;
                        }
                    }
                    foreach ($formatField->options as $index => $format){
                        $curFormat = array();
                        $curFormat['id'] = $format;
                        $curFormat['name'] = $this->format_map[$format];
                        $bookshelfItem['formats'][] = $curFormat;
                    }
                }

                if (isset($curTitle->actions->earlyReturn)){
                    $bookshelfItem['earlyReturn']  = true;
                }
                //Figure out which eContent record this is for.
                $eContentRecord = new EContentRecord();
                $eContentRecord->externalId = $bookshelfItem['overDriveId'];
                $eContentRecord->source = 'OverDrive';
                $eContentRecord->status = 'active';
                if ($eContentRecord->find(true)){
                    $bookshelfItem['recordId'] = $eContentRecord->id;
                    $bookshelfItem['title'] = $eContentRecord->title;
                    $bookshelfItem['imageUrl'] = $eContentRecord->cover;

                    //Get Rating
                    require_once ROOT_DIR . '/sys/eContent/EContentRating.php';
                    $econtentRating = new EContentRating();
                    $econtentRating->recordId = $eContentRecord->id;
                    $bookshelfItem['ratingData'] = $econtentRating->getRatingData($user, false);
                }else{
                    $bookshelfItem['recordId'] = -1;
                }
                $checkedOutTitles[] = $bookshelfItem;
            }
        }
        $this->checkouts[$user->id] = $checkedOutTitles;
        return array(
            'items' => $checkedOutTitles
        );
    }
*/

    /**
     * @param User $user
     * @param null $overDriveInfo
     * @return array
     */
    public function getOverDriveHolds($user){
        $url = $this->config['OverDrive']['patronApiUrl'] . '/v1/patrons/me/holds';
        $response = $this->_callPatronUrl($user->cat_username, $user->cat_password, $url);
        $holds = array();
        if (isset($response->holds)){
            foreach ($response->holds as $curTitle){
                $hold = array();
                $hold['overDriveId'] = $curTitle->reserveId;
                $hold['notifyEmail'] = $curTitle->emailAddress;
                $hold['holdQueueLength'] = $curTitle->numberOfHolds;
                $hold['holdQueuePosition'] = $curTitle->holdListPosition;
                $hold['available'] = isset($curTitle->actions->checkout);
                if ($hold['available']){
                    $hold['expirationDate'] = strtotime($curTitle->holdExpires);
                }
                $availability = $this->getProductAvailability($curTitle->reserveId);
                $count = ceil($hold['holdQueuePosition'] / $availability->copiesOwned);
                $hold['position'] = $count . (($count == 1) ? " person" : " people") . " ahead of you (hold #" . 
                                    $hold['holdQueuePosition'] . " on " . $availability->copiesOwned . " copies)";

                $holds[count($holds)] = $hold;
            }
        }
        return $holds;
    }

    /**
     * Returns a summary of information about the user's account in OverDrive.
     *
     * @param User $user
     *
     * @return array
     */
/*
    public function getOverDriveSummary($user){
        // @var memcache $memcache
        global $memcache;
        global $timer;
        global $logger;

        $summary = $memcache->get('overdrive_summary_' . $user->id);
        if ($summary == false || isset($_REQUEST['reload'])){
            //Get account information from api

            //TODO: Optimize so we don't need to load all checkouts and holds
            $summary = array();
            $checkedOutItems = $this->getOverDriveCheckedOutItems($user);
            $summary['numCheckedOut'] = count($checkedOutItems['items']);

            $holds = $this->getOverDriveHolds($user);
            $summary['numAvailableHolds'] = count($holds['holds']['available']);
            $summary['numUnavailableHolds'] = count($holds['holds']['unavailable']);

            $summary['checkedOut'] = $checkedOutItems;
            $summary['holds'] = $holds['holds'];

            $timer->logTime("Finished loading titles from overdrive summary");
            $memcache->set('overdrive_summary_' . $user->id, $summary, 0, $this->config['Caching']['overdrive_summary']);
        }

        return $summary;
    }
*/

/*
    public function getLendingPeriods($user){
        //TODO: Replace this with an API when available
        require_once ROOT_DIR . '/Drivers/OverDriveDriver2.php';
        $overDriveDriver2 = new OverDriveDriver2();
        return $overDriveDriver2->getLendingPeriods($user);
    }
*/
    /**
     * Places a hold on an item within OverDrive
     *
     * @param string $overDriveId
     * @param int $format
     * @param User $user
     *
     * @return array (result, message)
     */
    public function placeOverDriveHold($overDriveId, $user){
        $url = $this->config['OverDrive']['patronApiUrl'] . '/v1/patrons/me/holds/' . $overDriveId;
        $params = array(
            'reserveId' => $overDriveId,
            'emailAddress' => $user["email"]
        );
        $response = $this->_callPatronUrl($user["cat_username"], $user["cat_password"], $url, $params);
        $holdResult = array();
        $holdResult['result'] = false;
        $holdResult['message'] = '';
        if (!empty($response)){

            if (isset($response->holdListPosition)){
                $holdResult['result'] = true;
                //$holdResult['message'] = 'Your hold was placed successfully.  You are number ' . $response->holdListPosition . ' on the wait list.';
                $holdResult['message'] = 'Your hold was placed successfully.';
            }else{
                $holdResult['message'] = 'Sorry, but we could not place a hold for you on this title.'; // . $response->message;
            }
        }

        return $holdResult;
    }

    /**
     * @param User $user
     * @param string $overDriveId
     * @param string $format
     * @return array
     */
/*
    public function cancelOverDriveHold($overDriveId, $user){
        global $memcache;

        $url = $this->config['OverDrive']['patronApiUrl'] . '/v1/patrons/me/holds/' . $overDriveId;
        $response = $this->_callPatronDeleteUrl($user->cat_username, $user->cat_password, $url);

        $cancelHoldResult = array();
        $cancelHoldResult['result'] = false;
        $cancelHoldResult['message'] = '';
        if ($response === true){
            $cancelHoldResult['result'] = true;
            $cancelHoldResult['message'] = 'Your hold was cancelled successfully.';
        }else{
            $cancelHoldResult['message'] = 'There was an error cancelling your hold.  ' . $response->message;
        }
        $memcache->delete('overdrive_summary_' . $user->id);
        return $cancelHoldResult;
    }
*/

    /**
     *
     * Add an item to the cart in overdrive and then process the cart so it is checked out.
     *
     * @param string $overDriveId
     * @param int $format
     * @param int $lendingPeriod  the number of days that the user would like to have the title chacked out. or -1 to use the default
     * @param User $user
     *
     * @return array results (result, message)
     */
/*
    public function checkoutOverDriveItem($overDriveId, $user){

        global $memcache;

        $url = $this->config['OverDrive']['patronApiUrl'] . '/v1/patrons/me/checkouts';
        $params = array(
            'reserveId' => $overDriveId,
        );
        if (isset($format)){
            $params['formatType'] = $format;
        }
        $response = $this->_callPatronUrl($user->cat_username, $user->cat_password, $url, $params);

        $result = array();
        $result['result'] = false;
        $result['message'] = '';

        if (!empty($response)){
            //print_r($response);
            if (isset($response->expires)){
                $result['result'] = true;
                $result['message'] = 'Your title was checked out successfully.';
            }else{
                $result['message'] = 'Sorry, we could not checkout this title to you.  ' . $response->message;
            }
        }

        $memcache->delete('overdrive_summary_' . $user->id);
        return $result;
    }
*/

/*
    public function getLoanPeriodsForFormat($formatId){
        //TODO: API for this?
        if ($formatId == 35){
            return array(3, 5, 7);
        }else{
            return array(7, 14, 21);
        }
    }
*/

/*
    public function returnOverDriveItem($overDriveId, $transactionId, $user){
        global $memcache;

        $url = $this->config['OverDrive']['patronApiUrl'] . '/v1/patrons/me/checkouts/' . $overDriveId;
        $response = $this->_callPatronDeleteUrl($user->cat_username, $user->cat_password, $url);

        $cancelHoldResult = array();
        $cancelHoldResult['result'] = false;
        $cancelHoldResult['message'] = '';
        if ($response === true){
            $cancelHoldResult['result'] = true;
            $cancelHoldResult['message'] = 'Your item was returned successfully.';
        }else{
            $cancelHoldResult['message'] = 'There was an error returning this item. ' . $response->message;
        }

        $memcache->delete('overdrive_summary_' . $user->id);
        return $cancelHoldResult;
    }
*/

/*
    public function selectOverDriveDownloadFormat($overDriveId, $formatId, $user){
        global $memcache;

        $url = $this->config['OverDrive']['patronApiUrl'] . '/v1/patrons/me/checkouts/' . $overDriveId . '/formats';
        $params = array(
            'reserveId' => $overDriveId,
            'formatType' => $formatId
        );
        $response = $this->_callPatronUrl($user->cat_username, $user->cat_password, $url, $params);

        $result = array();
        $result['result'] = false;
        $result['message'] = '';

        if (!empty($response)){
            if (isset($response->linkTemplates->downloadLink)){
                $result['result'] = true;
                $result['message'] = 'This format was locked in';
                $downloadLink = $this->getDownloadLink($overDriveId, $formatId, $user);
                $result = $downloadLink;
            }else{
                $result['message'] = 'Sorry, but we could not select a format for you. ' . $response->message;
                
            }
        }

        $memcache->delete('overdrive_summary_' . $user->id);

        return $result;
    }
*/

/*
    public function updateLendingOptions(){
        //TODO: Replace this with an API when available
        require_once ROOT_DIR . '/Drivers/OverDriveDriver2.php';
        $overDriveDriver2 = new OverDriveDriver2();
        return $overDriveDriver2->updateLendingOptions();
    }
*/

/*
    public function getDownloadLink($overDriveId, $format, $user){
        $url = $this->config['OverDrive']['patronApiUrl'] . "/v1/patrons/me/checkouts/{$overDriveId}/formats/{$format}/downloadlink";

        $url .= '?errorpageurl=' . urlencode($this->config['Site']['url'] . '/Help/OverDriveError');
        if ($format == 'ebook-overdrive'){
            $url .= '&odreadauthurl=' . urlencode($this->config['Site']['url'] . '/Help/OverDriveReadError');
        }
        if ($format == 'audiobook-overdrive'){
            $url .= '&odreadauthurl=' . urlencode($this->config['Site']['url'] . '/Help/OverDriveReadError');
        }
        if ($format == 'video-streaming'){
            $url .= '&streamingauthurl=' . urlencode($this->config['Site']['url'] . '/Help/OverDriveReadError');
        }
        
        $response = $this->_callPatronUrl($user->cat_username, $user->cat_password, $url);

        $result = array();
        $result['result'] = false;
        $result['message'] = '';

        if (!empty($response)){
            if (isset($response->links->contentlink)){
                $result['result'] = true;
                $result['message'] = 'Created Download Link';
                $result['downloadUrl'] = $response->links->contentlink->href;
            }else{
                $result['message'] = 'Sorry, but we could not get a download link for you.  ' . $response->message;
            }
        }

        return $result;
    }
*/
    public function getOverdriveHolding($id, array $patron = null)
    {
    }
}