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
