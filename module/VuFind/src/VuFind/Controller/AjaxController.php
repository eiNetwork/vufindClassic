<?php
/**
 * Ajax Controller Module
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2010.
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
 * @category VuFind2
 * @package  Controller
 * @author   Chris Hallberg <challber@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:building_a_controller Wiki
 */
namespace VuFind\Controller;
use VuFind\Exception\Auth as AuthException,
    VuFind\Search\RecommendListener;

/**
 * This controller handles global AJAX functionality
 *
 * @category VuFind2
 * @package  Controller
 * @author   Chris Hallberg <challber@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:building_a_controller Wiki
 */
class AjaxController extends AbstractBase
{
    // define some status constants
    const STATUS_OK = 'OK';                  // good
    const STATUS_ERROR = 'ERROR';            // bad
    const STATUS_NEED_AUTH = 'NEED_AUTH';    // must login first

    /**
     * Type of output to use
     *
     * @var string
     */
    protected $outputMode;

    /**
     * Array of PHP errors captured during execution
     *
     * @var array
     */
    protected static $php_errors = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        // Add notices to a key in the output
        set_error_handler(['VuFind\Controller\AjaxController', "storeError"]);
    }

    /**
     * Handles passing data to the class
     *
     * @return mixed
     */
    public function jsonAction()
    {
        // Set the output mode to JSON:
        $this->outputMode = 'json';

        // Call the method specified by the 'method' parameter; append Ajax to
        // the end to avoid access to arbitrary inappropriate methods.
        $callback = [$this, $this->params()->fromQuery('method') . 'Ajax'];
        if (is_callable($callback)) {
            try {
                return call_user_func($callback);
            } catch (\Exception $e) {
                $debugMsg = (true || ('development' == APPLICATION_ENV))
                    ? (': ' . $e->getMessage() . (($e->getMessage() == "Read timed out after 10 seconds") ? ". Your action may not have successfully completed." : "")) : '';
                return $this->output(
                    ["msg" => $this->translate('An error has occurred') . $debugMsg,
                     "id" => $ids = $this->params()->fromQuery('id')],
                    self::STATUS_ERROR
                );
            }
        } else {
            return $this->output(
                $this->translate('Invalid Method'), self::STATUS_ERROR
            );
        }
    }

    /**
     * Load a recommendation module via AJAX.
     *
     * @return \Zend\Http\Response
     */
    public function recommendAction()
    {
        $this->writeSession();  // avoid session write timing bug
        // Process recommendations -- for now, we assume Solr-based search objects,
        // since deferred recommendations work best for modules that don't care about
        // the details of the search objects anyway:
        $rm = $this->getServiceLocator()->get('VuFind\RecommendPluginManager');
        $module = $rm->get($this->params()->fromQuery('mod'));
        $module->setConfig($this->params()->fromQuery('params'));
        $results = $this->getResultsManager()->get('Solr');
        $params = $results->getParams();
        $module->init($params, $this->getRequest()->getQuery());
        $module->process($results);

        // Set headers:
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-type', 'text/html');
        $headers->addHeaderLine('Cache-Control', 'no-cache, must-revalidate');
        $headers->addHeaderLine('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT');

        // Render recommendations:
        $recommend = $this->getViewRenderer()->plugin('recommend');
        $response->setContent($recommend($module));
        return $response;
    }

    /**
     * Get the contents of a lightbox; note that unlike most methods, this
     * one actually returns HTML rather than JSON.
     *
     * @return mixed
     */
    protected function getLightboxAjax()
    {
        // Turn layouts on for this action since we want to render the
        // page inside a lightbox:
        $this->layout()->setTemplate('layout/lightbox');

        // Call the requested action:
        return $this->forwardTo(
            $this->params()->fromQuery('submodule'),
            $this->params()->fromQuery('subaction')
        );
    }

    /**
     * Support method for getItemStatuses() -- filter suppressed locations from the
     * array of item information for a particular bib record.
     *
     * @param array $record Information on items linked to a single bib record
     *
     * @return array        Filtered version of $record
     */
    protected function filterSuppressedLocations($record)
    {
        static $hideHoldings = false;
        if ($hideHoldings === false) {
            $logic = $this->getServiceLocator()->get('VuFind\ILSHoldLogic');
            $hideHoldings = $logic->getSuppressedLocations();
        }

        $filtered = [];
        foreach ($record as $current) {
            if (!in_array($current['location'], $hideHoldings)) {
                $filtered[] = $current;
            }
        }
        return $filtered;
    }

    /**
     * Get Description
     *
     * This is responsible for printing the description for a
     * record in JSON format.
     *
     * @return \Zend\Http\Response
     * @author Chris Delis <cedelis@uillinois.edu>
     * @author Tuan Nguyen <tuan@yorku.ca>
     */
    protected function getDescriptionAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $catalog = $this->getILS();
        $id = $this->params()->fromQuery('id');
        $driver = $this->getRecordLoader()->load( $id );

        $desc = "";

        $request = $this->getRequest();
        $tabConfig = $this->getServiceLocator()->get('Config')["vufind"]["recorddriver_tabs"];
        $allTabs = $this->getServiceLocator()->get('VuFind\RecordTabPluginManager')->getTabsForRecord($driver, $tabConfig, $request);
        $summarySources = (isset($allTabs['Summaries']) ? $allTabs['Summaries']->getContent($driver->getCleanISBN()) : []);
        foreach( $summarySources as $thisSource ) {
            foreach( $thisSource as $thisSummary ) {
                $summary = $thisSummary["Content"];
                if( strncasecmp($summary, "<fld520", 7) == 0 ) {
                    $summary = substr($summary, strpos($summary, ">") + 1);
                    $summary = substr($summary, 0, strrpos($summary, "<"));
                }
                if( strpos($desc, $summary) === false ) {
                    $desc .= (($desc != "") ? "<br><br>" : "") . $summary;
                }
            }
        }

        // check marc record if no description yet
        if( $desc == "" ) {
            foreach( $driver->getSummary() as $i => $thisDesc ) {
                if( strpos($desc, $thisDesc) === false ) {
                    $desc .= ($i ? "<br><br>" : "") . $thisDesc;
                }
            }
        }

        // Done
        return $this->output(["description" => $desc], self::STATUS_OK);
    }


    /**
     * Get Item Statuses
     *
     * This is responsible for printing the holdings information for a
     * collection of records in JSON format.
     *
     * @return \Zend\Http\Response
     * @author Chris Delis <cedelis@uillinois.edu>
     * @author Tuan Nguyen <tuan@yorku.ca>
     */
    protected function getItemStatusesAjax()
    {
        $statuses = [];

        $this->writeSession();  // avoid session write timing bug
        $catalog = $this->getILS();
        $ids = $this->params()->fromQuery('id');
        $results = [];

        foreach($ids as $thisID) {
            $driver = $this->getRecordLoader()->load( $thisID );
            // see if we have cached holdings already. if not, grab them.
            if( !($cache = $catalog->getMemcachedVar("holdingID" . $thisID)) || !isset($cache["CACHED_INFO"]["holding"]) ) {
                $cachedItems = $driver->getCachedItems();
                if( $cache && isset($cache["CACHED_INFO"]) && isset($cache["CACHED_INFO"]["CHANGES_TO_MAKE"]) ) {
                    $cachedItems["CHANGES_TO_MAKE"] = $cache["CACHED_INFO"]["CHANGES_TO_MAKE"];
                }
                $cache = ["CACHED_INFO" => $cachedItems];
                $time = strtotime(((date("H") < "06") ? "today" : "tomorrow") . " 6:00") - time();
                $catalog->setMemcachedVar("holdingID" . $thisID, $cache, $time);
            }
            $cache = $catalog->getMemcachedVar("holdingID" . $thisID);
            if( !isset($cache["CACHED_INFO"]["processedHoldings"]) ) {
                $holdings = $driver->getRealTimeHoldings();
                $cache["CACHED_INFO"]["processedHoldings"] = $holdings;
                $time = strtotime(((date("H") < "06") ? "today" : "tomorrow") . " 6:00") - time();
                $catalog->setMemcachedVar("holdingID" . $thisID, $cache, $time);
            }
            $holdings = $cache["CACHED_INFO"]["processedHoldings"];
            $items = [];
            foreach($holdings as $holding) {
                $items = array_merge($items, $holding["items"]);
            }
            $results[] = $items;
        }

        $holds = [];
        $checkedOutItems = [];
        if($user = $this->getUser()) {
            $patron = $this->catalogLogin();
            $holds = $catalog->getMyHolds($patron);
            $checkedOutItems = $catalog->getMyTransactions($patron);
        }

        // In order to detect IDs missing from the status response, create an
        // array with a key for every requested ID.  We will clear keys as we
        // encounter IDs in the response -- anything left will be problems that
        // need special handling.
        $missingIds = array_flip($ids);

        // Get access to PHP template renderer for partials:
        $renderer = $this->getViewRenderer();

        // Load messages for response:
        $messages = [
            'inlibrary' => $renderer->render('ajax/status-inlibrary.phtml'),
            'itshere' => $renderer->render('ajax/status-itshere.phtml'),
            'available' => $renderer->render('ajax/status-available.phtml'),
            'oneclick' => $renderer->render('ajax/status-oneclick.phtml'),
            'online' => $renderer->render('ajax/status-online.phtml'),
            'unavailable' => $renderer->render('ajax/status-unavailable.phtml'),
            'order' => $renderer->render('ajax/status-order.phtml'),
            'unknown' => $renderer->render('ajax/status-unknown.phtml')
        ];

        // Load callnumber and location settings:
        $config = $this->getConfig();
        $callnumberSetting = isset($config->Item_Status->multiple_call_nos)
            ? $config->Item_Status->multiple_call_nos : 'msg';
        $locationSetting = isset($config->Item_Status->multiple_locations)
            ? $config->Item_Status->multiple_locations : 'msg';
        $showFullStatus = isset($config->Item_Status->show_full_status)
            ? $config->Item_Status->show_full_status : false;

        // Loop through all the status information that came back
        foreach ($results as $recordNumber => $record) {
            // Filter out suppressed locations:
            $record = $this->filterSuppressedLocations($record);

            // Skip empty records:
            if (count($record)) {
                if ($locationSetting == "group") {
                    $current = $this->getItemStatusGroup(
                        $record, $messages, $callnumberSetting
                    );
                } else {
                    $current = $this->getItemStatus(
                        $record, $messages, $holds, $checkedOutItems, $locationSetting, $callnumberSetting
                    );
                }
                // If a full status display has been requested, append the HTML:
                if ($showFullStatus) {
                    $current['full_status'] = $renderer->render(
                        'ajax/status-full.phtml', ['statusItems' => $record]
                    );
                }
                $current['record_number'] = array_search($current['id'], $ids);
                $statuses[] = $current;

                // The current ID is not missing -- remove it from the missing list.
                unset($missingIds[$current['id']]);
            }
        }

        // If any IDs were missing, send back appropriate dummy data
        foreach ($missingIds as $missingId => $recordNumber) {
            // see if we have any urls we should show
            $driver = $this->getRecordLoader()->load( $missingId );
            $urls = $driver->getURLs();
            foreach($urls as $key => $thisUrl) {
                if( strpos($thisUrl["url"], "http://carnegielbyofpittpa.oneclickdigital.com") !== false ):
                    $isOneClick = true;
                elseif( strpos($thisUrl["url"], "http://www.carnegielibrary.org/research/music/pittsburgh/pghlps.html") !== false ):
                    unset($urls[$key]);
                endif;
            }
            $accessOnline = $driver->hasOnlineAccess();

            $statuses[] = [
                'id'                   => $missingId,
                'availability'         => 'false',
                'availability_message' => str_replace("<countText>", "0 copies", $messages[$isOneClick ? 'oneclick' : ($accessOnline ? 'online' : 'unavailable')]),
                'availability_details' => false,
                'location'             => $this->translate('Unknown'),
                'locationList'         => false,
                'reserve'              => 'false',
                'reserve_message'      => $this->translate('Not On Reserve'),
                'callnumber'           => '',
                'hasVolumes'           => false,
                'volume_number'        => '',
                'missing_data'         => true,
                'record_number'        => $recordNumber,
                'isHolding'            => false,
                'checkinRecords'       => false,
                'itsHere'              => false,
                'holdableCopyHere'     => false,
                'holdArgs'             => '',
                'accessOnline'         => $accessOnline,
                'libraryOnly'          => false,
                'heldVolumes'          => '[]',
                'urls'                 => json_encode($urls)
            ];
        }

        // Done
        return $this->output($statuses, self::STATUS_OK);
    }


    /**
     * Get Hold Statuses
     *
     * This is responsible for printing the holdings information for a
     * collection of records in JSON format.
     *
     * @return \Zend\Http\Response
     * @author Chris Delis <cedelis@uillinois.edu>
     * @author Tuan Nguyen <tuan@yorku.ca>
     */
    protected function getHoldStatusesAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $catalog = $this->getILS();
        $ids = $this->params()->fromQuery('id');
        $results = [];
        foreach($ids as $thisID) {
            $driver = $this->getRecordLoader()->load( $thisID );
            $holdings = $driver->getRealTimeHoldings();
            $items = [];
            foreach($holdings as $holding) {
                $items = array_merge($items, $holding["items"]);
            }
            $results[] = $items;
        }
        $holds = [];
        if($user = $this->getUser()) {
            $patron = $this->catalogLogin();
            $holds = $catalog->getMyHolds($patron);
        }

        // In order to detect IDs missing from the status response, create an
        // array with a key for every requested ID.  We will clear keys as we
        // encounter IDs in the response -- anything left will be problems that
        // need special handling.
        $missingIds = array_flip($ids);

        // Loop through all the status information that came back
        $statuses = [];
        foreach ($results as $recordNumber => $record) {
            // Filter out suppressed locations:
            $record = $this->filterSuppressedLocations($record);

            // Skip empty records:
            if (count($record)) {
                $copyCount = count($record);

                // get this hold
                $thisHold = [];
                foreach( $holds as $holdIterator ) {
                    if( $holdIterator["id"] == $record[0]["id"] ) {
                        $thisHold = $holdIterator;
                    }
                }
                
                // fix the hold message
                $holdMessage = "You are hold #" . ($thisHold["position"] + 1) . " on " . $copyCount . " cop" . (($copyCount == 1) ? "y" : "ies");

                $statuses[] = [
                    'id'                   => $record[0]['id'],
                    'hold_status_message'  => $holdMessage
                ];

                // The current ID is not missing -- remove it from the missing list.
                unset($missingIds[$record[0]['id']]);
            }
        }

        // If any IDs were missing, send back appropriate dummy data
        foreach ($missingIds as $missingId => $recordNumber) {
            // see if we have any urls we should show
            $driver = $this->getRecordLoader()->load( $missingID );
            $urls = $driver->getURLs();
            foreach($urls as $key => $thisUrl) {
                if( strpos($thisUrl["url"], "http://carnegielbyofpittpa.oneclickdigital.com") !== false ):
                    $isOneClick = true;
                elseif( strpos($thisUrl["url"], "http://www.carnegielibrary.org/research/music/pittsburgh/pghlps.html") !== false ):
                    unset($urls[$key]);
                endif;
            }

            $statuses[] = [
                'id'                   => $missingId,
                'hold_status_message'  => "Unable to load hold status"
            ];
        }

        // Done
        return $this->output($statuses, self::STATUS_OK);
    }


    /**
     * Get List Contents
     *
     * This is responsible for printing the contents information for a
     * patron list in JSON format.
     *
     * @return \Zend\Http\Response
     * @author Chris Delis <cedelis@uillinois.edu>
     * @author Tuan Nguyen <tuan@yorku.ca>
     * @author Brad Patton <pattonb@einetwork.net>
     */
    protected function getListContentsAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $catalog = $this->getILS();
        if($user = $this->getUser()) {
            $id = $this->params()->fromQuery('id')[0];
            $page = $this->params()->fromQuery('page')[0];
            $path = $this->params()->fromQuery('path')[0];
            $sort = $this->params()->fromQuery('sort')[0];

            $continue = true;
            $items = [];
            $results = [];
            $cachedListContents = $catalog->getMemcachedVar("cachedList" . $id);
            $sortHtml = "";
            $bulkHtml = "";
            if( !$cachedListContents ) {
                $cachedListContents = [];
            }
            if( !isset($cachedListContents[$sort]) ) {
                $cachedListContents[$sort] = ["done" => false, "items" => [], "sortList" => []];
            }
            if( !$cachedListContents[$sort]["done"] ) {
                $limit = 20;
                $request = ['id' => $id, 'limit' => $limit, 'page' => $page, 'listContents' => true, 'sort' => $sort];

                // limit to only needed fields
                $request["fl"] = $this->getConfig()->LimitedSearchFields->shortList;

                // Set up listener for recommendations:
                $runner = $this->getServiceLocator()->get('VuFind\SearchRunner');
                $rManager = $this->getServiceLocator()->get('VuFind\RecommendPluginManager');
                $setupCallback = function ($runner, $params, $searchId) use ($rManager) {
                    $listener = new RecommendListener($rManager, $searchId);
                    $listener->setConfig(
                        $params->getOptions()->getRecommendationSettings()
                    );
                    $listener->attach($runner->getEventManager()->getSharedManager());
                };

                $runnerItems = $runner->run($request, 'Favorites', $setupCallback);
                foreach($runnerItems->getResults() as $i => $thisResult) {
                    $newItem = ["ID" => $thisResult->getUniqueID(), "source" => $thisResult->getResourceSource()];
                    $items[] = $newItem;
                    $cachedListContents[$sort]["items"][(($page - 1) * $limit) + $i] = $newItem;
                }

                $continue = (($page * $limit) < $runnerItems->getResultTotal());
                $cachedListContents[$sort]["done"] = !$continue;
                $cachedListContents[$sort]["sortArgs"] = ['sortList' => $runnerItems->getParams()->getSortList(), 'id' => $id, 'list' => $id, 'path' => $path];
                $cachedListContents[$sort]["bulkArgs"] = ['idPrefix' => '', 'list' => $id];

                $catalog->setMemcachedVar("cachedList" . $id, $cachedListContents, 300);
            } else {
                $items = $cachedListContents[$sort]["items"];

                $continue = false;
            }

            $sortHtml = $this->getViewRenderer()->render('search/controls/sort.phtml', $cachedListContents[$sort]["sortArgs"]);
            $bulkArgs = $cachedListContents[$sort]["bulkArgs"];
            $bulkArgs["list"] = $this->getTable('UserList')->getExisting($bulkArgs["list"]);
            $bulkHtml = $this->getViewRenderer()->render('myresearch/bulk-action-buttons.phtml', $bulkArgs);

            foreach($items as $i => $thisResult) {
                $record = $this->getRecordLoader()->load($thisResult["ID"], $thisResult["source"], true);
                if( !($record instanceof \VuFind\RecordDriver\Missing) ) {
                    $results[] = $record;
                }
            }

            $html = $this->getViewRenderer()->render('myresearch/listContents.phtml', ['results' => $results, 'list' => $this->getTable('UserList')->getExisting($id), 'cachedResults' => $cachedResults]);

            return $this->output(['html' => $html, 'id' => $id, 'page' => $page, 'continue' => $continue, 'sortHtml' => $sortHtml, 'bulkHtml' => $bulkHtml], self::STATUS_OK);
        } else {
            return $this->output($this->translate('You must be logged in first'), self::STATUS_NEED_AUTH);
        }
    }


    /**
     * Support method for getItemStatuses() -- when presented with multiple values,
     * pick which one(s) to send back via AJAX.
     *
     * @param array  $list        Array of values to choose from.
     * @param string $mode        config.ini setting -- first, all or msg
     * @param string $msg         Message to display if $mode == "msg"
     * @param string $transPrefix Translator prefix to apply to values (false to
     * omit translation of values)
     *
     * @return string
     */
    protected function pickValue($list, $mode, $msg, $transPrefix = false)
    {
        // Make sure array contains only unique values:
        $list = array_unique($list);

        // If there is only one value in the list, or if we're in "first" mode,
        // send back the first list value:
        if ($mode == 'first' || count($list) == 1) {
            if (!$transPrefix) {
                return $list[0];
            } else {
                return $this->translate($transPrefix . $list[0], [], $list[0]);
            }
        } else if (count($list) == 0) {
            // Empty list?  Return a blank string:
            return '';
        } else if ($mode == 'all') {
            // Translate values if necessary:
            if ($transPrefix) {
                $transList = [];
                foreach ($list as $current) {
                    $transList[] = $this->translate(
                        $transPrefix . $current, [], $current
                    );
                }
                $list = $transList;
            }
            // All values mode?  Return comma-separated values:
            return implode(', ', $list);
        } else {
            // Message mode?  Return the specified message, translated to the
            // appropriate language.
            return $this->translate($msg);
        }
    }

    /**
     * Support method for getItemStatuses() -- process a single bibliographic record
     * for location settings other than "group".
     *
     * @param array  $record            Information on items linked to a single bib
     *                                  record
     * @param array  $messages          Custom status HTML
     *                                  (keys = available/unavailable)
     * @param array  $holds             This users's current holds
     *                                  
     * @param array  $checkedOutItems   This users's checked out items
     *                                  
     * @param string $locationSetting   The location mode setting used for
     *                                  pickValue()
     * @param string $callnumberSetting The callnumber mode setting used for
     *                                  pickValue()
     *
     * @return array                    Summarized availability information
     */
    protected function getItemStatus($record, $messages, $holds, $checkedOutItems, $locationSetting,
        $callnumberSetting
    ) {
        // grab the driver
        $catalog = $this->getILS();
        $user = $this->getUser();
        $bib = $record[0]['id'];
        $driver = $this->getRecordLoader()->load( $bib );
        $canHold = (!empty($driver->tryMethod('getRealTimeTitleHold')));
        $isHolding = false;
        $isOverDrive = false;
        $isOneClick = false;
        $accessOnline = $driver->hasOnlineAccess();
        $overDriveInfo = ["canCheckOut" => false];
        $holdArgs = "";
        $hasVolumes = false;

        // see whether or not this bib has different volumes
        foreach($record as $item) {
            if( isset($item["number"]) && $item["number"]) {
                $hasVolumes = true;
            }
            if( isset($item["isOverDrive"]) && $item["isOverDrive"] ) {
              $isOverDrive = true;
            }
        }

        // see if they already have a hold on it
        if($canHold && $user && !$hasVolumes) {
            foreach($holds as $thisHold) {
                if($thisHold['id'] == $bib) {
                    $canHold = false;
                    $isHolding = true;
                }
            }
        }

        // see which volumes they have a hold on
        $heldVolumes = array();
        if( $hasVolumes ) {
            foreach($record as $item) {
                if( isset($item["number"]) && $item["number"]) {
                    foreach($holds as $thisHold) {
                        if(substr($thisHold["item_id"], 2, -1) == $item["itemId"]) {
                            $heldVolumes[$item["itemId"]] = $item["number"];
                        }
                    }
                }
            }
        }

        // if not, see whether there is a holdable copy available
        if( $canHold ) {
            $args=array();
            foreach($record as $item) {
                // look for a hold link
                $marcHoldOK = isset($item['status']) && in_array(trim($item['status']), ['-','t','!','i','p','order']);
                $overdriveHoldOK = isset($item["isOverDrive"]) && $item["isOverDrive"] && ($item["copiesOwned"] > 0) && ($item["copiesAvailable"] == 0);
                if(($marcHoldOK || $overdriveHoldOK) && $item['link']['action'] == "Hold") {
                    foreach(explode('&',$item['link']['query']) as $piece) {
                        $pieces = explode('=', $piece);
                        $args[$pieces[0]] = $pieces[1];
                    }
                    if( $overdriveHoldOK ) {
                        $overDriveInfo["canHold"] = true;
                    }
                    break;
                }
            }
            $holdArgs = str_replace("\"", "'", json_encode($args));
            if( count($args) == 0 ) {
                $canHold = false;
                $holdArgs = "";
            }
        }

        // see if they can check this out
        if( !$canHold ) {
            foreach($record as $item) {
                $overDriveInfo["canCheckOut"] |= isset($item["isOverDrive"]) && $item["isOverDrive"] && ($item["copiesOwned"] > 0) && ($item["copiesAvailable"] > 0);
            }
        }

        // make sure they don't already have it checked out
        if( $user && ($overDriveInfo["canCheckOut"] || $canHold) ) {
            foreach($checkedOutItems as $thisItem) {
                if($thisItem['id'] == $bib) {
                    $overDriveInfo["canCheckOut"] = false;
                    $canHold = $hasVolumes;
                    if( isset($thisItem["overDriveId"]) ) {
                        $overDriveInfo["isCheckedOut"] = true;
                        $overDriveInfo["canReturn"] = isset($thisItem["earlyReturn"]) && $thisItem["earlyReturn"];
                        $overDriveInfo["availableFormats"] = $thisItem["format"];
                        if(isset($thisItem["overdriveRead"]) && $thisItem["overdriveRead"]) {
                            $overDriveInfo["ODread"] = $catalog->getDownloadLink($thisItem["overDriveId"], "ebook-overdrive", $user);
                        }
                        if(isset($thisItem["mediaDo"]) && $thisItem["mediaDo"]) {
                            $overDriveInfo["mediaDo"] = $catalog->getDownloadLink($thisItem["overDriveId"], "ebook-mediado", $user);
                        }
                        if(isset($thisItem["overdriveListen"]) && $thisItem["overdriveListen"]) {
                            $overDriveInfo["ODlisten"] = $catalog->getDownloadLink($thisItem["overDriveId"], "audiobook-overdrive", $user);
                        }
                        if(isset($thisItem["streamingVideo"]) && $thisItem["streamingVideo"]) {
                            $overDriveInfo["ODwatch"] = $thisItem["formatSelected"] ? $catalog->getDownloadLink($thisItem["overDriveId"], "video-streaming", $user) : "www.google.com";
                        }
                        // get the download links
                        $downloadableFormats = [];
                        foreach($thisItem["formats"] as $possibleFormat) {
                            if($possibleFormat["id"] == "0") {
                                $downloadableFormats[] = ["id" => $possibleFormat["format"]->formatType, "name" => $catalog->getOverdriveFormatName($possibleFormat["format"]->formatType)];
                            } else {
                                $downloadableFormats[] = $possibleFormat;
                            }
                        }
                        $overDriveInfo["downloadFormats"] = $downloadableFormats;
                        $overDriveInfo["formatLocked"] = $thisItem["formatSelected"];
                    }
                }
            }
        }

        // Summarize call number, location and availability info across all items:
        $currentLocation = $catalog->getCurrentLocation();
        $callNumbers = $locations = $volumeNumbers = [];
        $use_unknown_status = $available = false;
        $totalItems = 0;
        $availableItems = 0;
        $libraryOnly = false;
        $availableLocations = [];
        $unavailableLocations = [];
        foreach ($record as $info) {
            // Find an available copy
            if ($info['availability']) {
                $available = true;
                $availableItems += (isset($info["copiesAvailable"])) ? $info["copiesAvailable"] : 1;
                if( !$isOverDrive ) {
                    if( !isset($availableLocations[$info['branchName']]) ) {
                        $availableLocations[$info['branchName']] = 0;
                    }
                    $availableLocations[$info['branchName']] += (isset($info["copiesAvailable"])) ? $info["copiesAvailable"] : 1;
                }
            } else if( !$isOverDrive ) {
                if( !isset($unavailableLocations[$info['branchName']]) ) {
                    $unavailableLocations[$info['branchName']] = 0;
                }
                $unavailableLocations[$info['branchName']] += (isset($info["copiesOwned"])) ? $info["copiesOwned"] : 1;
            }
            if (isset($info['status']) && ((trim($info['status']) == 'order') || (trim($info['status']) == 'i'))) {
                $onOrder = true;
            }
            //$totalItems += ((isset($item["isOverDrive"]) && $item["isOverDrive"]) | ($onOrder)) ? $item["copiesOwned"] : 1;
            $totalItems += (isset($info["copiesOwned"])) ? $info["copiesOwned"] : 1;
            // Check for a use_unknown_message flag
            if (isset($info['use_unknown_message']) && $info['use_unknown_message'] == true) {
                $use_unknown_status = true;
            }
            // Store call number/location info:
            $callNumbers[] = isset($info['callnumber']) ? $info['callnumber'] : null;
            $volumeNumbers[] = isset($info['number']) ? $info['number'] : null;
            $locations[] = isset($info['location']) ? $info['location'] : null;
            if( (!isset($itsHere) || (trim($itsHere['status']) == 'o')) && $currentLocation && $info['availability'] && ($currentLocation['code'] == $info['branchCode']) ) {
                $itsHere = $info;
            } else if( $user && !isset($atPreferred) && $info['availability'] && (($info['branchCode'] == $user->preferred_library) || ($info['branchCode'] == $user->alternate_library) || ($info['branchCode'] == $user->home_library)) ) {
                $atPreferred = true;
            }
            if( !isset($holdableCopyHere) && $currentLocation && $info['availability'] && ($currentLocation["code"] == $info['branchCode']) && (trim($info['status']) != 'o') && (trim($info['status']) != 'order')) {
                $holdableCopyHere = $info;
            }
            if( !$canHold && $item["status"] == "o" ) {
                $libraryOnly = true;
            }
        }

        // Determine call number string based on findings:
        $callNumber = $this->pickValue(
            $callNumbers, $callnumberSetting, 'Multiple Call Numbers'
        );

        // Determine volume number string based on findings:
        $volumeNumber = $this->pickValue(
            $volumeNumbers, $callnumberSetting, 'Multiple Volumes'
        );

        // Determine location string based on findings:
        $location = $this->pickValue(
            $locations, $locationSetting, 'Multiple Locations', 'location_'
        );

        $checkinRecords = ($record[0]['location'] == "CHECKIN_RECORDS");
        if ($checkinRecords) {
            $checkinRecords = false;
            foreach( $record[0]["checkinRecords"] as $thisRecord ) {
                $checkinRecords |= isset($thisRecord["libHas"]);
            }
        }
        $availability_message = $accessOnline ? ($messages['online'] . (($totalItems > 0) ? "<br><div style=\"height:5px\"></div>" : "")) : "";
        if( !$accessOnline || ($totalItems > 0) ) {
            $availability_message .= $use_unknown_status
                ? $messages['unknown']
                : $messages[($itsHere ? 'itshere' :
                             ($libraryonly ? 'inlibrary' : 
                              ($available ? 'available' : 
                               ($onOrder ? 'order' : 
                                ($isOneClick ? 'oneclick' : 'unavailable')))))];
            $cache = $catalog->getMemcachedVar("holdingID" . $bib)["CACHED_INFO"];
            $numberOfHolds = ($cache && !$cache["doUpdate"]) ? $cache["numberOfHolds"] : ($item["isOverDrive"] ? $item["numberOfHolds"] : 0);
            $waitlistText = $numberOfHolds ? ("<br><i class=\"fa fa-clock-o\" style=\"padding-right:6px\"></i>" . (($numberOfHolds > 1) ? ($numberOfHolds . " people") : "1 person") . " on waitlist") : "";
            if ($checkinRecords) {
                $inLibMessage = str_replace("<countText>", (count($record[0]["checkinRecords"]) . " location" . ((count($record[0]["checkinRecords"]) == 1) ? "" : "s")) , $messages['inlibrary']);
                $serialCheckinRecords = false;
                foreach( $record[0]["checkinRecords"] as $thisRecord ) {
                    if( $currentLocation && in_array($currentLocation["code"], $thisRecord["branchCode"]) ) {
                        $inLibMessage .= "<div class=\"availableCopyText\">It's here at " . $thisRecord["location"] . "</div>";
                        break;
                    }
                    $serialCheckinRecords |= isset($thisRecord["libHas"]);
                }
                if( $totalItems > 0 ) {
                    $inLibMessage = [$inLibMessage, str_replace("<countText>", (($totalItems > 0) ? ($availableItems . " of ") : "") . $totalItems . " cop" . (($totalItems == 1) ? "y" : "ies") . $waitlistText, $availability_message)];
                }
                $availability_message = $inLibMessage;
            } else if (isset($onOrder) && $onOrder && ($availableItems == 0)) {
                $availability_message = str_replace("<countText>", ($totalItems . " cop" . (($totalItems == 1) ? "y" : "ies")) . $waitlistText, $availability_message);
            } else if( isset($item["isOverDrive"]) && $item["isOverDrive"] && $item["copiesOwned"] == 999999 ) {
                $availability_message = str_replace("<countText>", "Always Available", $availability_message);
            } else {
                $availability_message = str_replace("<countText>", (($totalItems > 0) ? ($availableItems . " of ") : "") . $totalItems . " cop" . (($totalItems == 1) ? "y" : "ies") . $waitlistText, $availability_message);
                if( isset($itsHere) ) {
                    $availability_message = str_replace("<itsHereText>", $itsHere["shelvingLocation"] . ((isset($itsHere["shelvingLocation"]) && isset($itsHere["callnumber"])) ? "<br>" : "") . $itsHere["callnumber"] . (isset($itsHere["number"]) ? (" " . $itsHere["number"]) : ""), $availability_message);
                }
            }
            if( !isset($itsHere) ) {
                if( $isOverDrive ) {
                    $availability_message = str_replace("<modifyAvailableText>", " from OverDrive", $availability_message);
                } else if( isset($atPreferred) ) {
                    $availability_message = str_replace("<modifyAvailableText>", " at your preferred Libraries!", $availability_message);
                } else if( $currentLocation ) {
                    $availability_message = str_replace("<modifyAvailableText>", " at " . count($availableLocations) . " other Librar" . ((count($availableLocations) == 1) ? "y" : "ies"), $availability_message);
                } else {
                    $availability_message = str_replace("<modifyAvailableText>", " at " . count($availableLocations) . " Librar" . ((count($availableLocations) == 1) ? "y" : "ies"), $availability_message);
                }
            } else {
                $availability_message = str_replace("<modifyAvailableText>", " at " . count($availableLocations) . " Librar" . ((count($availableLocations) == 1) ? "y" : "ies"), $availability_message);
            }
        }

        // see if we have any urls we should show
        $urls = $driver->getURLs();
        foreach($urls as $key => $thisUrl) {
          if( $isOverDrive && (strpos($thisUrl["url"], "http://excerpts.contentreserve.com") === false) ):
            unset($urls[$key]);
          elseif( strpos($thisUrl["url"], "http://www.carnegielibrary.org/research/music/pittsburgh/pghlps.html") !== false ):
            unset($urls[$key]);
          elseif( strpos($thisUrl["url"], "http://carnegielbyofpittpa.oneclickdigital.com") !== false ):
            $isOneClick = true;
          endif;
        }

        // Collect the details:
        $details = [
            'id' => $record[0]['id'],
            'availability' => ($available ? 'true' : 'false'),
            'availability_message' => $availability_message,
            'availability_details' => ($availableLocations || $unavailableLocations) ? json_encode(["available" => $availableLocations, "unavailable" => $unavailableLocations]) : null,
            'location' => htmlentities($location, ENT_COMPAT, 'UTF-8'),
            'locationList' => false,
            'reserve' =>
                ($record[0]['reserve'] == 'Y' ? 'true' : 'false'),
            'reserve_message' => $record[0]['reserve'] == 'Y'
                ? $this->translate('on_reserve')
                : $this->translate('Not On Reserve'),
            'callnumber' => htmlentities($callNumber, ENT_COMPAT, 'UTF-8'),
            'hasVolumes' => $hasVolumes,
            'volume_number' => htmlentities($volumeNumber, ENT_COMPAT, 'UTF-8'),
            'isHolding' => $isHolding,
            'checkinRecords' => $checkinRecords,
            'itsHere' => isset($itsHere),
            'holdableCopyHere' => isset($holdableCopyHere),
            'holdArgs' => $holdArgs,
            'libraryOnly' => ($libraryOnly || $checkinRecords),
            'accessOnline' => $accessOnline,
            'heldVolumes' => json_encode($heldVolumes),
            'urls' => json_encode($urls)
        ];

        // add the info URL if we need it for overdrive
        if( isset($item["isOverDrive"]) && $item["isOverDrive"] && ($totalItems == 0) ) {
          $overDriveInfo["learnMoreURL"] = $driver->getURLs()[0]["url"];
        }

        // add in the overdrive info if needed
        if( $overDriveInfo["canCheckOut"] || count($overDriveInfo) > 1 ) {
            $renderer = $this->getViewRenderer();
            if( $overDriveInfo["canCheckOut"] ) {
                $overDriveInfo["checkoutLink"] = $renderer->recordLink()->getActionUrl($driver, 'Checkout');
            }
            if( $overDriveInfo["canReturn"] ) {
                $overDriveInfo["returnLink"] = $renderer->recordLink()->getActionUrl($driver, 'Return');
            }
            if( $canHold ) {
                $overDriveInfo["holdLink"] = $renderer->recordLink()->getActionUrl($driver, 'Hold') . "?hashKey=" . json_decode(str_replace("'", "\"", $holdArgs))->hashKey;
            }
            $overDriveInfo["idArgs"] = str_replace("\"", "'", json_encode(["id" => $bib]));
            $details = array_merge($details, $overDriveInfo);
        }

        // Send back the collected details:
        return $details;
    }

    /**
     * Support method for getItemStatuses() -- process a single bibliographic record
     * for "group" location setting.
     *
     * @param array  $record            Information on items linked to a single
     *                                  bib record
     * @param array  $messages          Custom status HTML
     *                                  (keys = available/unavailable)
     * @param string $callnumberSetting The callnumber mode setting used for
     *                                  pickValue()
     *
     * @return array                    Summarized availability information
     */
    protected function getItemStatusGroup($record, $messages, $callnumberSetting)
    {
        // Summarize call number, location and availability info across all items:
        $locations =  [];
        $use_unknown_status = $available = false;
        foreach ($record as $info) {
            // Find an available copy
            if ($info['availability']) {
                $available = $locations[$info['location']]['available'] = true;
            }
            // Check for a use_unknown_message flag
            if (isset($info['use_unknown_message'])
                && $info['use_unknown_message'] == true
            ) {
                $use_unknown_status = true;
            }
            // Store call number/location info:
            $locations[$info['location']]['callnumbers'][] = $info['callnumber'];
        }

        // Build list split out by location:
        $locationList = false;
        foreach ($locations as $location => $details) {
            $locationCallnumbers = array_unique($details['callnumbers']);
            // Determine call number string based on findings:
            $locationCallnumbers = $this->pickValue(
                $locationCallnumbers, $callnumberSetting, 'Multiple Call Numbers'
            );
            $locationInfo = [
                'availability' =>
                    isset($details['available']) ? $details['available'] : false,
                'location' => htmlentities(
                    $this->translate('location_' . $location, [], $location),
                    ENT_COMPAT, 'UTF-8'
                ),
                'callnumbers' =>
                    htmlentities($locationCallnumbers, ENT_COMPAT, 'UTF-8')
            ];
            $locationList[] = $locationInfo;
        }

        $availability_message = $use_unknown_status
            ? $messages['unknown']
            : $messages[$available ? 'available' : 'unavailable'];

        // Send back the collected details:
        return [
            'id' => $record[0]['id'],
            'availability' => ($available ? 'true' : 'false'),
            'availability_message' => $availability_message,
            'location' => false,
            'locationList' => $locationList,
            'reserve' =>
                ($record[0]['reserve'] == 'Y' ? 'true' : 'false'),
            'reserve_message' => $record[0]['reserve'] == 'Y'
                ? $this->translate('on_reserve')
                : $this->translate('Not On Reserve'),
            'callnumber' => false
        ];
    }

    /**
     * Check one or more records to see if they are saved in one of the user's list.
     *
     * @return \Zend\Http\Response
     */
    protected function getSaveStatusesAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        // check if user is logged in
        $user = $this->getUser();
        if (!$user) {
            return $this->output(
                $this->translate('You must be logged in first'),
                self::STATUS_NEED_AUTH
            );
        }

        // loop through each ID check if it is saved to any of the user's lists
        $result = [];
        $ids = $this->params()->fromQuery('id', []);
        $sources = $this->params()->fromQuery('source', []);
        if (!is_array($ids) || !is_array($sources)) {
            return $this->output(
                $this->translate('Argument must be array.'),
                self::STATUS_ERROR
            );
        }
        foreach ($ids as $i => $id) {
            $source = isset($sources[$i]) ? $sources[$i] : 'VuFind';
            $data = $user->getSavedData($id, null, $source);
            if ($data) {
                // if this item was saved, add it to the list of saved items.
                foreach ($data as $list) {
                    $result[] = [
                        'record_id' => $id,
                        'record_source' => $source,
                        'resource_id' => $list->id,
                        'list_id' => $list->list_id,
                        'list_title' => $list->list_title,
                        'record_number' => $i
                    ];
                }
            }
        }
        return $this->output($result, self::STATUS_OK);
    }

    /**
     * Send output data and exit.
     *
     * @param mixed  $data     The response data
     * @param string $status   Status of the request
     * @param int    $httpCode A custom HTTP Status Code
     *
     * @return \Zend\Http\Response
     * @throws \Exception
     */
    protected function output($data, $status, $httpCode = null)
    {
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Cache-Control', 'no-cache, must-revalidate');
        $headers->addHeaderLine('Expires', 'Mon, 26 Jul 1997 05:00:00 GMT');
        if ($httpCode !== null) {
            $response->setStatusCode($httpCode);
        }
        if ($this->outputMode == 'json') {
            $headers->addHeaderLine('Content-type', 'application/javascript');
            $output = ['data' => $data, 'status' => $status];
            if ('development' == APPLICATION_ENV && count(self::$php_errors) > 0) {
                $output['php_errors'] = self::$php_errors;
            }
            $response->setContent(json_encode($output));
            return $response;
        } else if ($this->outputMode == 'plaintext') {
            $headers->addHeaderLine('Content-type', 'text/plain');
            $response->setContent($data ? $status . " $data" : $status);
            return $response;
        } else {
            throw new \Exception('Unsupported output mode: ' . $this->outputMode);
        }
    }

    /**
     * Store the errors for later, to be added to the output
     *
     * @param string $errno   Error code number
     * @param string $errstr  Error message
     * @param string $errfile File where error occurred
     * @param string $errline Line number of error
     *
     * @return bool           Always true to cancel default error handling
     */
    public static function storeError($errno, $errstr, $errfile, $errline)
    {
        self::$php_errors[] = "ERROR [$errno] - " . $errstr . "<br />\n"
            . " Occurred in " . $errfile . " on line " . $errline . ".";
        return true;
    }

    /**
     * Generate the "salt" used in the salt'ed login request.
     *
     * @return string
     */
    protected function generateSalt()
    {
        return str_replace(
            '.', '', $this->getRequest()->getServer()->get('REMOTE_ADDR')
        );
    }

    /**
     * Send the "salt" to be used in the salt'ed login request.
     *
     * @return \Zend\Http\Response
     */
    protected function getSaltAjax()
    {
        return $this->output($this->generateSalt(), self::STATUS_OK);
    }

    /**
     * Login with post'ed username and encrypted password.
     *
     * @return \Zend\Http\Response
     */
    protected function loginAjax()
    {
        // Fetch Salt
        $salt = $this->generateSalt();

        // HexDecode Password
        $password = pack('H*', $this->params()->fromPost('password'));

        // Decrypt Password
        $password = base64_decode(\VuFind\Crypt\RC4::encrypt($salt, $password));

        // Update the request with the decrypted password:
        $this->getRequest()->getPost()->set('password', $password);

        // Authenticate the user:
        try {
            $this->getAuthManager()->login($this->getRequest());
        } catch (AuthException $e) {
            return $this->output(
                $this->translate($e->getMessage()),
                self::STATUS_ERROR
            );
        }

        return $this->output(true, self::STATUS_OK);
    }

    /**
     * Tag a record.
     *
     * @return \Zend\Http\Response
     */
    protected function tagRecordAjax()
    {
        $user = $this->getUser();
        if ($user === false) {
            return $this->output(
                $this->translate('You must be logged in first'),
                self::STATUS_NEED_AUTH
            );
        }
        // empty tag
        try {
            $driver = $this->getRecordLoader()->load(
                $this->params()->fromPost('id'),
                $this->params()->fromPost('source', 'VuFind')
            );
            $tag = $this->params()->fromPost('tag', '');
            $tagParser = $this->getServiceLocator()->get('VuFind\Tags');
            if (strlen($tag) > 0) { // don't add empty tags
                if ('false' === $this->params()->fromPost('remove', 'false')) {
                    $driver->addTags($user, $tagParser->parse($tag));
                } else {
                    $driver->deleteTags($user, $tagParser->parse($tag));
                }
            }
        } catch (\Exception $e) {
            return $this->output(
                ('development' == APPLICATION_ENV) ? $e->getMessage() : 'Failed',
                self::STATUS_ERROR
            );
        }

        return $this->output($this->translate('Done'), self::STATUS_OK);
    }

    /**
     * Get all tags for a record.
     *
     * @return \Zend\Http\Response
     */
    protected function getRecordTagsAjax()
    {
        $user = $this->getUser();
        $is_me_id = null === $user ? null : $user->id;
        // Retrieve from database:
        $tagTable = $this->getTable('Tags');
        $tags = $tagTable->getForResource(
            $this->params()->fromQuery('id'),
            $this->params()->fromQuery('source', 'VuFind'),
            0, null, null, 'count', $is_me_id
        );

        // Build data structure for return:
        $tagList = [];
        foreach ($tags as $tag) {
            $tagList[] = [
                'tag'   => $tag->tag,
                'cnt'   => $tag->cnt,
                'is_me' => $tag->is_me == 1 ? true : false
            ];
        }

        // Set layout to render the page inside a lightbox:
        $this->layout()->setTemplate('layout/lightbox');
        $view = $this->createViewModel(
            [
                'tagList' => $tagList,
                'loggedin' => null !== $user
            ]
        );
        $view->setTemplate('record/taglist');
        return $view;
    }

    /**
     * Get map data on search results and output in JSON
     *
     * @param array $fields Solr fields to retrieve data from
     *
     * @author Chris Hallberg <crhallberg@gmail.com>
     * @author Lutz Biedinger <lutz.biedinger@gmail.com>
     *
     * @return \Zend\Http\Response
     */
    protected function getMapDataAjax($fields = ['long_lat'])
    {
        $this->writeSession();  // avoid session write timing bug
        $results = $this->getResultsManager()->get('Solr');
        $params = $results->getParams();
        $params->initFromRequest($this->getRequest()->getQuery());

        $facets = $results->getFullFieldFacets($fields, false);

        $markers = [];
        $i = 0;
        $list = isset($facets['long_lat']['data']['list'])
            ? $facets['long_lat']['data']['list'] : [];
        foreach ($list as $location) {
            $longLat = explode(',', $location['value']);
            $markers[$i] = [
                'title' => (string)$location['count'], //needs to be a string
                'location_facet' =>
                    $location['value'], //needed to load in the location
                'lon' => $longLat[0],
                'lat' => $longLat[1]
            ];
            $i++;
        }
        return $this->output($markers, self::STATUS_OK);
    }

    /**
     * Get entry information on entries tied to a specific map location
     *
     * @author Chris Hallberg <crhallberg@gmail.com>
     * @author Lutz Biedinger <lutz.biedinger@gmail.com>
     *
     * @return mixed
     */
    public function resultgooglemapinfoAction()
    {
        $this->writeSession();  // avoid session write timing bug
        // Set layout to render the page inside a lightbox:
        $this->layout()->setTemplate('layout/lightbox');

        $results = $this->getResultsManager()->get('Solr');
        $params = $results->getParams();
        $params->initFromRequest($this->getRequest()->getQuery());

        return $this->createViewModel(
            [
                'results' => $results,
                'recordSet' => $results->getResults(),
                'recordCount' => $results->getResultTotal(),
                'completeListUrl' => $results->getUrlQuery()->getParams()
            ]
        );
    }

    /**
     * AJAX for timeline feature (PubDateVisAjax)
     *
     * @param array $fields Solr fields to retrieve data from
     *
     * @author Chris Hallberg <crhallberg@gmail.com>
     * @author Till Kinstler <kinstler@gbv.de>
     *
     * @return \Zend\Http\Response
     */
    protected function getVisDataAjax($fields = ['publishDate'])
    {
        $this->writeSession();  // avoid session write timing bug
        $results = $this->getResultsManager()->get('Solr');
        $params = $results->getParams();
        $params->initFromRequest($this->getRequest()->getQuery());
        foreach ($this->params()->fromQuery('hf', []) as $hf) {
            $params->getOptions()->addHiddenFilter($hf);
        }
        $params->getOptions()->disableHighlighting();
        $params->getOptions()->spellcheckEnabled(false);
        $filters = $params->getFilters();
        $dateFacets = $this->params()->fromQuery('facetFields');
        $dateFacets = empty($dateFacets) ? [] : explode(':', $dateFacets);
        $fields = $this->processDateFacets($filters, $dateFacets, $results);
        $facets = $this->processFacetValues($fields, $results);
        foreach ($fields as $field => $val) {
            $facets[$field]['min'] = $val[0] > 0 ? $val[0] : 0;
            $facets[$field]['max'] = $val[1] > 0 ? $val[1] : 0;
            $facets[$field]['removalURL']
                = $results->getUrlQuery()->removeFacet(
                    $field,
                    isset($filters[$field][0]) ? $filters[$field][0] : null,
                    false
                );
        }
        return $this->output($facets, self::STATUS_OK);
    }

    /**
     * Support method for getVisData() -- extract details from applied filters.
     *
     * @param array                       $filters    Current filter list
     * @param array                       $dateFacets Objects containing the date
     * ranges
     * @param \VuFind\Search\Solr\Results $results    Search results object
     *
     * @return array
     */
    protected function processDateFacets($filters, $dateFacets, $results)
    {
        $result = [];
        foreach ($dateFacets as $current) {
            $from = $to = '';
            if (isset($filters[$current])) {
                foreach ($filters[$current] as $filter) {
                    if (preg_match('/\[[\d\*]+ TO [\d\*]+\]/', $filter)) {
                        $range = explode(' TO ', trim($filter, '[]'));
                        $from = $range[0] == '*' ? '' : $range[0];
                        $to = $range[1] == '*' ? '' : $range[1];
                        break;
                    }
                }
            }
            $result[$current] = [$from, $to];
            $result[$current]['label']
                = $results->getParams()->getFacetLabel($current);
        }
        return $result;
    }

    /**
     * Support method for getVisData() -- filter bad values from facet lists.
     *
     * @param array                       $fields  Processed date information from
     * processDateFacets
     * @param \VuFind\Search\Solr\Results $results Search results object
     *
     * @return array
     */
    protected function processFacetValues($fields, $results)
    {
        $facets = $results->getFullFieldFacets(array_keys($fields));
        $retVal = [];
        foreach ($facets as $field => $values) {
            $newValues = ['data' => []];
            foreach ($values['data']['list'] as $current) {
                // Only retain numeric values!
                if (preg_match("/^[0-9]+$/", $current['value'])) {
                    $newValues['data'][]
                        = [$current['value'], $current['count']];
                }
            }
            $retVal[$field] = $newValues;
        }
        return $retVal;
    }

    /**
     * Get Autocomplete suggestions.
     *
     * @return \Zend\Http\Response
     */
    protected function getACSuggestionsAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $query = $this->getRequest()->getQuery();
        $autocompleteManager = $this->getServiceLocator()
            ->get('VuFind\AutocompletePluginManager');
        return $this->output(
            $autocompleteManager->getSuggestions($query), self::STATUS_OK
        );
    }

    /**
     * Check Request is Valid
     *
     * @return \Zend\Http\Response
     */
    protected function checkRequestIsValidAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $id = $this->params()->fromQuery('id');
        $data = $this->params()->fromQuery('data');
        $requestType = $this->params()->fromQuery('requestType');
        if (!empty($id) && !empty($data)) {
            // check if user is logged in
            $user = $this->getUser();
            if (!$user) {
                return $this->output(
                    [
                        'status' => false,
                        'msg' => $this->translate('You must be logged in first')
                    ],
                    self::STATUS_NEED_AUTH
                );
            }

            try {
                $catalog = $this->getILS();
                $patron = $this->getILSAuthenticator()->storedCatalogLogin();
                if ($patron) {
                    switch ($requestType) {
                    case 'ILLRequest':
                        $results = $catalog->checkILLRequestIsValid(
                            $id, $data, $patron
                        );

                        $msg = $results
                            ? $this->translate(
                                'ill_request_place_text'
                            )
                            : $this->translate(
                                'ill_request_error_blocked'
                            );
                        break;
                    case 'StorageRetrievalRequest':
                        $results = $catalog->checkStorageRetrievalRequestIsValid(
                            $id, $data, $patron
                        );

                        $msg = $results
                            ? $this->translate(
                                'storage_retrieval_request_place_text'
                            )
                            : $this->translate(
                                'storage_retrieval_request_error_blocked'
                            );
                        break;
                    default:
                        $results = $catalog->checkRequestIsValid(
                            $id, $data, $patron
                        );

                        $msg = $results
                            ? $this->translate('request_place_text')
                            : $this->translate('hold_error_blocked');
                        break;
                    }
                    return $this->output(
                        ['status' => $results, 'msg' => $msg], self::STATUS_OK
                    );
                }
            } catch (\Exception $e) {
                // Do nothing -- just fail through to the error message below.
            }
        }

        return $this->output(
            $this->translate('An error has occurred'), self::STATUS_ERROR
        );
    }

    /**
     * Comment on a record.
     *
     * @return \Zend\Http\Response
     */
    protected function commentRecordAjax()
    {
        $user = $this->getUser();
        if ($user === false) {
            return $this->output(
                $this->translate('You must be logged in first'),
                self::STATUS_NEED_AUTH
            );
        }

        $id = $this->params()->fromPost('id');
        $comment = $this->params()->fromPost('comment');
        if (empty($id) || empty($comment)) {
            return $this->output(
                $this->translate('An error has occurred'), self::STATUS_ERROR
            );
        }

        $table = $this->getTable('Resource');
        $resource = $table->findResource(
            $id, $this->params()->fromPost('source', 'VuFind')
        );
        $id = $resource->addComment($comment, $user);

        return $this->output($id, self::STATUS_OK);
    }

    /**
     * Delete a comment on a record.
     *
     * @return \Zend\Http\Response
     */
    protected function deleteRecordCommentAjax()
    {
        $user = $this->getUser();
        if ($user === false) {
            return $this->output(
                $this->translate('You must be logged in first'),
                self::STATUS_NEED_AUTH
            );
        }

        $id = $this->params()->fromQuery('id');
        $table = $this->getTable('Comments');
        if (empty($id) || !$table->deleteIfOwnedByUser($id, $user)) {
            return $this->output(
                $this->translate('An error has occurred'), self::STATUS_ERROR
            );
        }

        return $this->output($this->translate('Done'), self::STATUS_OK);
    }

    /**
     * Get list of comments for a record as HTML.
     *
     * @return \Zend\Http\Response
     */
    protected function getRecordCommentsAsHTMLAjax()
    {
        $driver = $this->getRecordLoader()->load(
            $this->params()->fromQuery('id'),
            $this->params()->fromQuery('source', 'VuFind')
        );
        $html = $this->getViewRenderer()
            ->render('record/comments-list.phtml', ['driver' => $driver]);
        return $this->output($html, self::STATUS_OK);
    }

    /**
     * Process an export request
     *
     * @return \Zend\Http\Response
     */
    protected function exportFavoritesAjax()
    {
        $format = $this->params()->fromPost('format');
        $export = $this->getServiceLocator()->get('VuFind\Export');
        $url = $export->getBulkUrl(
            $this->getViewRenderer(), $format,
            $this->params()->fromPost('ids', [])
        );
        $html = $this->getViewRenderer()->render(
            'ajax/export-favorites.phtml',
            ['url' => $url, 'format' => $format]
        );
        return $this->output(
            [
                'result' => $this->translate('Done'),
                'result_additional' => $html,
                'needs_redirect' => $export->needsRedirect($format),
                'export_type' => $export->getBulkExportType($format),
                'result_url' => $url
            ], self::STATUS_OK
        );
    }

    /**
     * Fetch Links from resolver given an OpenURL and format as HTML
     * and output the HTML content in JSON object.
     *
     * @return \Zend\Http\Response
     * @author Graham Seaman <Graham.Seaman@rhul.ac.uk>
     */
    protected function getResolverLinksAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $openUrl = $this->params()->fromQuery('openurl', '');

        $config = $this->getConfig();
        $resolverType = isset($config->OpenURL->resolver)
            ? $config->OpenURL->resolver : 'other';
        $pluginManager = $this->getServiceLocator()
            ->get('VuFind\ResolverDriverPluginManager');
        if (!$pluginManager->has($resolverType)) {
            return $this->output(
                $this->translate("Could not load driver for $resolverType"),
                self::STATUS_ERROR
            );
        }
        $resolver = new \VuFind\Resolver\Connection(
            $pluginManager->get($resolverType)
        );
        if (isset($config->OpenURL->resolver_cache)) {
            $resolver->enableCache($config->OpenURL->resolver_cache);
        }
        $result = $resolver->fetchLinks($openUrl);

        // Sort the returned links into categories based on service type:
        $electronic = $print = $services = [];
        foreach ($result as $link) {
            switch (isset($link['service_type']) ? $link['service_type'] : '') {
            case 'getHolding':
                $print[] = $link;
                break;
            case 'getWebService':
                $services[] = $link;
                break;
            case 'getDOI':
                // Special case -- modify DOI text for special display:
                $link['title'] = $this->translate('Get full text');
                $link['coverage'] = '';
            case 'getFullTxt':
            default:
                $electronic[] = $link;
                break;
            }
        }

        // Get the OpenURL base:
        if (isset($config->OpenURL) && isset($config->OpenURL->url)) {
            // Trim off any parameters (for legacy compatibility -- default config
            // used to include extraneous parameters):
            list($base) = explode('?', $config->OpenURL->url);
        } else {
            $base = false;
        }

        // Render the links using the view:
        $view = [
            'openUrlBase' => $base, 'openUrl' => $openUrl, 'print' => $print,
            'electronic' => $electronic, 'services' => $services
        ];
        $html = $this->getViewRenderer()->render('ajax/resolverLinks.phtml', $view);

        // output HTML encoded in JSON object
        return $this->output($html, self::STATUS_OK);
    }

    /**
     * Keep Alive
     *
     * This is responsible for keeping the session alive whenever called
     * (via JavaScript)
     *
     * @return \Zend\Http\Response
     */
    protected function keepAliveAjax()
    {
        return $this->output(true, self::STATUS_OK);
    }

    /**
     * Get pick up locations for a library
     *
     * @return \Zend\Http\Response
     */
    protected function getLibraryPickupLocationsAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $id = $this->params()->fromQuery('id');
        $pickupLib = $this->params()->fromQuery('pickupLib');
        if (!empty($id) && !empty($pickupLib)) {
            // check if user is logged in
            $user = $this->getUser();
            if (!$user) {
                return $this->output(
                    [
                        'status' => false,
                        'msg' => $this->translate('You must be logged in first')
                    ],
                    self::STATUS_NEED_AUTH
                );
            }

            try {
                $catalog = $this->getILS();
                $patron = $this->getILSAuthenticator()->storedCatalogLogin();
                if ($patron) {
                    $results = $catalog->getILLPickupLocations(
                        $id, $pickupLib, $patron
                    );
                    foreach ($results as &$result) {
                        if (isset($result['name'])) {
                            $result['name'] = $this->translate(
                                'location_' . $result['name'],
                                [],
                                $result['name']
                            );
                        }
                    }
                    return $this->output(
                        ['locations' => $results], self::STATUS_OK
                    );
                }
            } catch (\Exception $e) {
                // Do nothing -- just fail through to the error message below.
            }
        }

        return $this->output(
            $this->translate('An error has occurred'), self::STATUS_ERROR
        );
    }

    /**
     * Get pick up locations for a request group
     *
     * @return \Zend\Http\Response
     */
    protected function getRequestGroupPickupLocationsAjax()
    {
        $this->writeSession();  // avoid session write timing bug
        $id = $this->params()->fromQuery('id');
        $requestGroupId = $this->params()->fromQuery('requestGroupId');
        if (!empty($id) && !empty($requestGroupId)) {
            // check if user is logged in
            $user = $this->getUser();
            if (!$user) {
                return $this->output(
                    [
                        'status' => false,
                        'msg' => $this->translate('You must be logged in first')
                    ],
                    self::STATUS_NEED_AUTH
                );
            }

            try {
                $catalog = $this->getILS();
                $patron = $this->getILSAuthenticator()->storedCatalogLogin();
                if ($patron) {
                    $details = [
                        'id' => $id,
                        'requestGroupId' => $requestGroupId
                    ];
                    $results = $catalog->getPickupLocations(
                        $patron, $details
                    );
                    foreach ($results as &$result) {
                        if (isset($result['locationDisplay'])) {
                            $result['locationDisplay'] = $this->translate(
                                'location_' . $result['locationDisplay'],
                                [],
                                $result['locationDisplay']
                            );
                        }
                    }
                    return $this->output(
                        ['locations' => $results], self::STATUS_OK
                    );
                }
            } catch (\Exception $e) {
                // Do nothing -- just fail through to the error message below.
            }
        }

        return $this->output(
            $this->translate('An error has occurred'), self::STATUS_ERROR
        );
    }

    /**
     * Get hierarchical facet data for jsTree
     *
     * Parameters:
     * facetName  The facet to retrieve
     * facetSort  By default all facets are sorted by count. Two values are available
     * for alternative sorting:
     *   top = sort the top level alphabetically, rest by count
     *   all = sort all levels alphabetically
     *
     * @return \Zend\Http\Response
     */
    protected function getFacetDataAjax()
    {
        $this->writeSession();  // avoid session write timing bug

        $facet = $this->params()->fromQuery('facetName');
        $sort = $this->params()->fromQuery('facetSort');
        $operator = $this->params()->fromQuery('facetOperator');

        $results = $this->getResultsManager()->get('Solr');
        $params = $results->getParams();
        $params->addFacet($facet, null, $operator === 'OR');
        $params->initFromRequest($this->getRequest()->getQuery());

        $facets = $results->getFullFieldFacets([$facet], false, -1, 'count');
        if (empty($facets[$facet]['data']['list'])) {
            return $this->output([], self::STATUS_OK);
        }

        $facetList = $facets[$facet]['data']['list'];

        $facetHelper = $this->getServiceLocator()
            ->get('VuFind\HierarchicalFacetHelper');
        if (!empty($sort)) {
            $facetHelper->sortFacetList($facetList, $sort == 'top');
        }

        return $this->output(
            $facetHelper->buildFacetArray(
                $facet, $facetList, $results->getUrlQuery()
            ),
            self::STATUS_OK
        );
    }

    /**
     * Check status and return a status message for e.g. a load balancer.
     *
     * A simple OK as text/plain is returned if everything works properly.
     *
     * @return \Zend\Http\Response
     */
    protected function systemStatusAction()
    {
        $this->outputMode = 'plaintext';

        // Check system status
        $config = $this->getConfig();
        if (!empty($config->System->healthCheckFile)
            && file_exists($config->System->healthCheckFile)
        ) {
            return $this->output(
                'Health check file exists', self::STATUS_ERROR, 503
            );
        }

        // Test search index
        try {
            $results = $this->getResultsManager()->get('Solr');
            $params = $results->getParams();
            $params->setQueryIDs(['healthcheck']);
            $results->performAndProcessSearch();
        } catch (\Exception $e) {
            return $this->output(
                'Search index error: ' . $e->getMessage(), self::STATUS_ERROR, 500
            );
        }

        // Test database connection
        try {
            $sessionTable = $this->getTable('Session');
            $sessionTable->getBySessionId('healthcheck', false);
        } catch (\Exception $e) {
            return $this->output(
                'Database error: ' . $e->getMessage(), self::STATUS_ERROR, 500
            );
        }

        // This may be called frequently, don't leave sessions dangling
        $this->getServiceLocator()->get('VuFind\SessionManager')->destroy();

        return $this->output('', self::STATUS_OK);
    }

    /**
     * Convenience method for accessing results
     *
     * @return \VuFind\Search\Results\PluginManager
     */
    protected function getResultsManager()
    {
        return $this->getServiceLocator()->get('VuFind\SearchResultsPluginManager');
    }
}
