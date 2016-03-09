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
     * Create a new ViewModel.
     *
     * @param array $params Parameters to pass to ViewModel constructor.
     *
     * @return \Zend\View\Model\ViewModel
     */
    protected function createViewModel($params = null)
    {
        $view = parent::createViewModel($params);

        // load this up so we can check some things
        $driver = $this->loadRecord();
        $holdings = $this->driver->getRealTimeHoldings();
        $bib = $this->loadRecord()->getUniqueID();
        $view->holdings = $holdings;
        $catalog = $this->getILS();

        // see whether the driver can hold
        $holdingTitleHold = $driver->tryMethod('getRealTimeTitleHold');
        $canHold = (!empty($holdingTitleHold) && !isset($this->holdings["OverDrive"]));

        // see whether they already have a hold on it
        if($canHold && ($user = $this->getUser())) {
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
                    if(in_array($item['status'], ['-','t','!']) && $item['link']['action'] == "Hold") {
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

        $view->canHold = $canHold;
        $view->saveArgs = str_replace("\"", "'", json_encode(["id" => $bib]));

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

        return $view;
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
     * Action for dealing with overdrive checkouts.
     *
     * @return mixed
     */
    public function checkoutAction()
    {
        // cut off overdrive hold requests
        $driver = $this->loadRecord();
        $catalog = $this->getILS();
        if( $overDriveId = $catalog->getOverDriveID($driver->getUniqueID()) )
        {
            // Retrieve user object and force login if necessary:
            if (!($user = $this->getUser())) {
                return $this->forceLogin();
            }

            $results = $catalog->placeOverDriveHold($overDriveId, $patron);
            $this->flashMessenger()->setNamespace($results['result'] ? 'info' : 'error')->addMessage($results['message']);
            $view = $this->createViewModel();
            $view->setTemplate('blank');
            return $view;
        }
    }
}
