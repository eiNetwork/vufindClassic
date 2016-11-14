<?php
/**
 * Record Controller
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
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
namespace VuFind\Controller;

/**
 * Record Controller
 *
 * @category VuFind2
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
class RecordController extends AbstractRecord
{
    use HoldsTrait;
    use ILLRequestsTrait;
    use StorageRetrievalRequestsTrait;

    /**
     * Constructor
     *
     * @param \Zend\Config\Config $config VuFind configuration
     */
    public function __construct(\Zend\Config\Config $config)
    {
        // Call standard record controller initialization:
        parent::__construct();

        // Load default tab setting:
        $this->fallbackDefaultTab = isset($config->Site->defaultRecordTab)
            ? $config->Site->defaultRecordTab : 'Holdings';
    }

    /**
     * Is the result scroller active?
     *
     * @return bool
     */
    protected function resultScrollerActive()
    {
        $config = $this->getServiceLocator()->get('VuFind\Config')->get('config');
        return (isset($config->Record->next_prev_navigation)
            && $config->Record->next_prev_navigation);
    }

    /**
     * Create a new ViewModel.
     *
     * @param array $params Parameters to pass to ViewModel constructor.
     *
     * @return \Zend\View\Model\ViewModel
     */
    protected function createViewModel($params = null)
    {
        $view = parent::createViewModel($params);

        // short version of this
        if( isset($params["skip"]) && $params["skip"] ) {
          return $view;
        }

        // load this up so we can check some things
        $driver = $this->loadRecord();
        $holdings = $this->driver->getRealTimeHoldings();
        $bib = $this->loadRecord()->getUniqueID();
        $view->holdings = $holdings;
        $catalog = $this->getILS();

        // see whether the driver can hold
        $holdingTitleHold = $driver->tryMethod('getRealTimeTitleHold');
        $canHold = (!empty($holdingTitleHold));
        $canCheckOut = false;
        $hasVolumes = false;

        // see whether or not this bib has different volumes
        foreach($holdings as $entry) {
          foreach($entry["items"] as $item) {
            if( isset($item["number"]) && $item["number"]) {
              $hasVolumes = true;
            }
          }
        }

        // see whether they already have a hold on it
        if($canHold && ($user = $this->getUser()) && !$hasVolumes) {
            $patron = $this->catalogLogin();
            $holds = $catalog->getMyHolds($patron);
            foreach($holds as $thisHold) {
                if($thisHold['id'] == $bib) {
                    $canHold = false;
                    $view->isTitleHeld = true;
                }
            }
        }

        // if not, see whether there is a holdable copy available
        if( $canHold ) {
            $args=array();
            foreach($holdings as $holding) {
                foreach($holding['items'] as $item) {
                    // look for a hold link
                    $marcHoldOK = isset($item['status']) && in_array($item['status'], ['-','t','!','i','order']);
                    $overdriveHoldOK = isset($item["isOverDrive"]) && $item["isOverDrive"] && ($item["copiesOwned"] > 0) && ($item["copiesAvailable"] == 0);
                    if(($marcHoldOK || $overdriveHoldOK) && $item['link']['action'] == "Hold") {
                        foreach(explode('&',$item['link']['query']) as $piece) {
                            $pieces = explode('=', $piece);
                            $args[$pieces[0]] = $pieces[1];
                        }
                        break 2;
                    }
                }
            }
            $view->holdArgs = str_replace("\"", "'", json_encode($args));
            if( count($args) == 0 ) {
                $canHold = false;
            }
        }

        // see if they can check this out
        if( !$canHold ) {
            foreach($holdings as $holding) {
                foreach($holding['items'] as $item) {
                    $canCheckOut |= isset($item["isOverDrive"]) && $item["isOverDrive"] && ($item["copiesOwned"] > 0) && ($item["copiesAvailable"] > 0);
                }
            }
        }

        // make sure they don't already have it checked out
        if( $user && ($canCheckOut || $canHold) ) {
            $patron = (isset($patron) ? $patron : $this->catalogLogin());
            $checkedOutItems = $catalog->getMyTransactions($patron);
            foreach($checkedOutItems as $thisItem) {
                if($thisItem['id'] == $bib) {
                    $canCheckOut = false;
                    // if this bib has volumes, they still still place holds on other volumes even if they have one checked out
                    $canHold = $hasVolumes; 
                    $view->isTitleCheckedOut = true;
                    if( isset($thisItem["overDriveId"]) ) {
                        $view->canReturn = isset($thisItem["earlyReturn"]) && $thisItem["earlyReturn"];
                        $view->availableFormats = $thisItem["format"];
                        if(isset($thisItem["overdriveRead"]) && $thisItem["overdriveRead"]) {
                            $view->ODread = $catalog->getDownloadLink($thisItem["overDriveId"], "ebook-overdrive", $user);
                        }
                        if(isset($thisItem["overdriveListen"]) && $thisItem["overdriveListen"]) {
                            $view->ODlisten = $catalog->getDownloadLink($thisItem["overDriveId"], "audiobook-overdrive", $user);
                        }
                        if(isset($thisItem["streamingVideo"]) && $thisItem["streamingVideo"]) {
                            $view->ODwatch = $thisItem["formatSelected"] ? $catalog->getDownloadLink($thisItem["overDriveId"], "video-streaming", $user) : "www.google.com";
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
                        $view->downloadFormats = $downloadableFormats;
                        $view->formatLocked = $thisItem["formatSelected"];
                    }
                }
            }
        }

        $view->canCheckOut = $canCheckOut;
        $view->canHold = $canHold;
        $view->idArgs = str_replace("\"", "'", json_encode(["id" => $bib]));

        // see whether they have this item in any lists
        if( $user ) {
            $lists = $user->getLists();
            $hasOnList = false;
            foreach($lists as $thisList) {
                if($thisList->contains($driver->getResourceSource() . "|" . $bib)) {
                    $hasOnList = true;
                    break;
                }
            }
            $view->hasOnList = $hasOnList;
            $view->myLists = $lists;
        }

        $view->currentLocation = $catalog->getDbTable("location")->getCurrentLocation();

        /***** For now, we're just showing the highlights on the search results page *****\
        $rawTerms = explode("lookfor", $this->getSearchMemory()->retrieve());
        unset($rawTerms[0]);
        $searchTerms = [];
        foreach($rawTerms as $key => $value) {
            $equals = strpos($value, "=") + 1;
            $ampersand = strpos($value, "&", $equals);
            $searchTerms = array_merge($searchTerms, explode("+", substr($value, $equals, $ampersand - $equals)));
        }
        $view->searchMemory = $searchTerms;
        \***** For now, we're just showing the highlights on the search results page *****/

        if( substr($bib, 0, 2) == ".b" ) {
            $view->classicLink = $this->getILS()->getConfigVar("Catalog","classic_url") . "/record=" . substr($bib, 1, -1);
        }

        return $view;
    }

    /**
     * Action for dealing with overdrive checkouts.
     *
     * @return mixed
     */
    public function checkoutAction()
    {
        // cut off overdrive requests
        $driver = $this->loadRecord();
        $catalog = $this->getILS();
        if( $overDriveId = $catalog->getOverDriveID($driver->getUniqueID()) )
        {
            // Retrieve user object and force login if necessary:
            if (!($user = $this->getUser())) {
                return $this->forceLogin();
            }

            $results = $catalog->checkoutOverDriveItem($overDriveId, $user);
            $this->flashMessenger()->setNamespace($results['result'] ? 'info' : 'error')->addMessage($results['message']);
            $view = $this->createViewModel(['skip' => true, 'title' => 'Checking Item Out', 'reloadParent' => true]);
            $view->setTemplate('blankModal');
            return $view;
        }
    }

    /**
     * Action for dealing with overdrive returns.
     *
     * @return mixed
     */
    public function returnAction()
    {
        // cut off overdrive requests
        $driver = $this->loadRecord();
        $catalog = $this->getILS();
        if( $overDriveId = $catalog->getOverDriveID($driver->getUniqueID()) )
        {
            // Retrieve user object and force login if necessary:
            if (!($user = $this->getUser())) {
                return $this->forceLogin();
            }

            $results = $catalog->returnOverDriveItem($overDriveId, $user);
            $this->flashMessenger()->setNamespace($results['result'] ? 'info' : 'error')->addMessage($results['message']);
            $view = $this->createViewModel(['skip' => true, 'title' => 'Returning Item', 'reloadParent' => true]);
            $view->setTemplate('blankModal');
            return $view;
        }
    }

    /**
     * Action for dealing with overdrive downloads.
     *
     * @return mixed
     */
    public function overdriveDownloadAction()
    {
        // cut off overdrive requests
        $driver = $this->loadRecord();
        $catalog = $this->getILS();
        if( $overDriveId = $catalog->getOverDriveID($driver->getUniqueID()) )
        {
            // Retrieve user object and force login if necessary:
            if (!($user = $this->getUser())) {
                return $this->forceLogin();
            }

            if($format = $this->params()->fromQuery('formatType', false)) {
              // select the format if necessary
              if($this->params()->fromQuery('lockIn')) {
                $formatInfo = $catalog->selectOverDriveDownloadFormat($overDriveId, $format, $user);
                if(!$formatInfo["result"]) {
                  $this->flashMessenger()->setNamespace('error')->addMessage($formatInfo["message"]);
                  $view = $this->createViewModel(['skip' => true, 'title' => 'No Result', 'reloadParent' => true]);
                  $view->setTemplate('blankModal');
                  return $view;
                }
              }

              // download it
              $downloadLink = $catalog->getDownloadLink($overDriveId, $format, $user, (($format == "periodicals-nook") ? $this->params()->fromQuery('parentURL') : null));
              if($downloadLink["result"]) {
                return $this->redirect()->toUrl($downloadLink["downloadUrl"]);
              }
              $this->flashMessenger()->setNamespace('error')->addMessage($downloadLink["message"]);
              return $this->redirect()->toUrl($this->params()->fromQuery('parentURL'));
            } else {
              $view = $this->createViewModel();
              $view->parentURL = $this->params()->fromQuery('parentURL');
              $view->setTemplate('record/overdriveDownload');
              return $view;
            }
        }
    }

    /**
     * Save action - Allows the save template to appear,
     *   passes containingLists & nonContainingLists
     *
     * @return mixed
     */
    public function saveAction() {
        try {
            // keep a hold of the referring page since we are skipping the submit step
            $referer = $this->getRequest()->getServer()->get('HTTP_REFERER');
            if (substr($referer, -5) != '/Save'
                && stripos($referer, 'MyResearch/EditList/NEW') === false
            ) {
                $this->setFollowupUrlToReferer();
            } else {
                $this->clearFollowupUrl();
            }

            return parent::saveAction();
        } catch (\Exception $e) {
            switch(get_class($e)) {
            case 'VuFind\Exception\ListSize':
                $this->flashMessenger()->addMessage($e->getMessage(), 'error');
                return $this->redirect()->toUrl($referer);
            case 'VuFind\Exception\LoginRequired':
                return $this->forceLogin();
            default:
                throw $e;
            }
        }
    }

    /**
     * Select Item action - Make patron choose a specific item (used for multi-volume bibs)
     *
     * @return mixed
     */
    public function selectItemAction() {
        // Retrieve user object and force login if necessary:
        if (!is_array($patron = $this->catalogLogin())) {
            $patron->followup = "['Record','SelectItem',{'id':'" . $this->params()->fromQuery('id') . "','hashKey':'" . $this->params()->fromQuery('hashKey') . "'}]";
            return $patron;
        }

        // grab the holdings, then split them into holdable and not holdable
        $driver = $this->loadRecord();
        $holdings = $driver->getRealTimeHoldings();
        $availableHoldings = [];
        $unavailableHoldings = [];
        $currentLocation = $this->getILS()->getDbTable('location')->getCurrentLocation();
        $canHold = (!empty($driver->tryMethod('getRealTimeTitleHold')));
        foreach($holdings as $thisBib) {
            foreach($thisBib["items"] as $item) {
                if( $canHold && ($currentLocation["code"] != $item["branchCode"] || !$item["availability"]) && (($item["status"] == '-') || ($item["status"] == 't') || ($item["status"] == '!')) ) {
                    $availableHoldings[] = $item;
                } else {
                    $unavailableHoldings[] = $item;
                }
            }
        }

        $view = $this->createViewModel();
        $view->id = $driver->getUniqueID();
        $view->hashKey = $this->params()->fromQuery('hashKey');
        $view->availableHoldings = $availableHoldings;
        $view->unavailableHoldings = $unavailableHoldings;
        $view->setTemplate('record/selectItem');
        return $view;
    }
}
