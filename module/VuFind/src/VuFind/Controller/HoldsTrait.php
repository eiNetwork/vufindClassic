<?php
/**
 * Holds trait (for subclasses of AbstractRecord)
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
 * Holds trait (for subclasses of AbstractRecord)
 *
 * @category VuFind2
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
trait HoldsTrait
{
    /**
     * Action for dealing with blocked holds.
     *
     * @return mixed
     */
    public function blockedholdAction()
    {
        $this->flashMessenger()->addMessage('hold_error_blocked', 'error');
        return $this->redirectToRecord('#top');
    }

    /**
     * Action for dealing with holds.
     *
     * @return mixed
     */
    public function holdAction()
    {
        $driver = $this->loadRecord();

        // Stop now if the user does not have valid catalog credentials available:
        if (!is_array($patron = $this->catalogLogin())) {
            $patron->followup = "['Record','Hold',{'id':'" . $this->params()->fromQuery('id') . "','hashKey':'" . $this->params()->fromQuery('hashKey') . "'}]";
            return $patron;
        }

        // If we're not supposed to be here, give up now!
        $catalog = $this->getILS();
        $checkHolds = $catalog->checkFunction(
            'Holds',
            [
                'id' => $driver->getUniqueID(),
                'patron' => $patron
            ]
        );
        if (!$checkHolds) {
            return $this->redirectToRecord();
        }

        // Do we have valid information?
        // Sets $this->logonURL and $this->gatheredDetails
        $gatheredDetails = $this->holds()->validateRequest($checkHolds['HMACKeys']);
        if (!$gatheredDetails) {
            return $this->redirectToRecord();
        }

        // Block invalid requests:
        if (!$catalog->checkRequestIsValid(
            $driver->getUniqueID(), $gatheredDetails, $patron
        )) {
            return $this->blockedholdAction();
        }

        // cut off overdrive hold requests
        if( $overDriveId = $catalog->getOverDriveID($gatheredDetails['id']) )
        {
            $results = $catalog->placeOverDriveHold($overDriveId, $patron);
            if( $results['result'] ) {
                $results['message']['tokens'] = ['%%url%%' => $this->url()->fromRoute('myresearch-holds')];

                // remove this item from the bookcart
                $catalog->removeFromBookCart($gatheredDetails['id']);
            }
            $this->flashMessenger()->setNamespace($results['result'] ? 'info' : 'error')->addMessage($results['message']);
            $view = $this->createViewModel(['skip' => true, 'title' => 'Hold Item', 'reloadParent' => true]);
            $view->setTemplate('blankModal');
            return $view;
        }

        // Send various values to the view so we can build the form:
        $pickup = $catalog->getPickUpLocations($patron, $gatheredDetails);
        $requestGroups = $catalog->checkCapability(
            'getRequestGroups', [$driver->getUniqueID(), $patron]
        ) ? $catalog->getRequestGroups($driver->getUniqueID(), $patron) : [];
        $extraHoldFields = isset($checkHolds['extraHoldFields'])
            ? explode(":", $checkHolds['extraHoldFields']) : [];

        // Process form submissions if necessary:
        if (!is_null($this->params()->fromPost('placeHold'))) {
            // If the form contained a pickup location or request group, make sure
            // they are valid:
            $valid = $this->holds()->validateRequestGroupInput(
                $gatheredDetails, $extraHoldFields, $requestGroups
            );
            if (!$valid) {
                $this->flashMessenger()
                    ->addMessage('hold_invalid_request_group', 'error');
            } elseif (!$this->holds()->validatePickUpInput(
                $gatheredDetails['pickUpLocation'], $extraHoldFields, $pickup
            )) {
                $this->flashMessenger()->addMessage('hold_invalid_pickup', 'error');
            } else {
                // If we made it this far, we're ready to place the hold;
                // if successful, we will redirect and can stop here.

                // BJP - need to hold onto bib ID for screen scrape.  when the API does local copy override holds properly, you can remove the next line
                $gatheredDetails['bibId'] = $gatheredDetails['id'];
                // see whether they are trying to hold a specific item instead of a bib
                if( isset($gatheredDetails['itemID']) && $gatheredDetails['itemID'] != "" ) {
                    // BJP - need to hold onto bib ID for screen scrape.  when the API allows us to do item level holds properly, you can remove the next line
                    $gatheredDetails['bibId'] = $gatheredDetails['id'];
                    $gatheredDetails['id'] = $gatheredDetails['itemID'];
                }

                // Add Patron Data to Submitted Data
                $holdDetails = $gatheredDetails + ['patron' => $patron];

                // Attempt to place the hold:
                $function = (string)$checkHolds['function'];
                $results = $catalog->$function($holdDetails);

                // Success: Go to Display Holds
                $msg = [
                    'html' => true,
                    'msg' => (isset($results['success']) && $results['success'] == true) ? 'hold_place_success_html' : (isset($results['message']) ? $results['message'] : 'hold_place_failure_html'),
                    'tokens' => [
                        '%%url%%' => $this->url()->fromRoute('myresearch-holds')
                    ],
                ];
                $this->flashMessenger()->addMessage($msg, (isset($results['success']) && $results['success'] == true) ? 'info' : 'error');
                $view = $this->createViewModel(['skip' => true, 'title' => 'Hold Item', 'reloadParent' => true]);
                $view->setTemplate('blankModal');
                return $view;
            }
        }

        // Find and format the default required date:
        $defaultRequired = $this->holds()->getDefaultRequiredDate(
            $checkHolds, $catalog, $patron, $gatheredDetails
        );
        $defaultRequired = $this->getServiceLocator()->get('VuFind\DateConverter')
            ->convertToDisplayDate("U", $defaultRequired);
        try {
            $defaultPickup
                = $catalog->getDefaultPickUpLocation($patron, $gatheredDetails);
        } catch (\Exception $e) {
            $defaultPickup = false;
        }
        try {
            $defaultRequestGroup = empty($requestGroups)
                ? false
                : $catalog->getDefaultRequestGroup($patron, $gatheredDetails);
        } catch (\Exception $e) {
            $defaultRequestGroup = false;
        }

        $requestGroupNeeded = in_array('requestGroup', $extraHoldFields)
            && !empty($requestGroups)
            && (empty($gatheredDetails['level'])
                || $gatheredDetails['level'] != 'copy');

        // make sure these are valid locations
        $lookForHome = $this->getUser()->home_library;
        $lookForAlt = $this->getUser()->alternate_library;
        $lookForPref = $this->getUser()->preferred_library;
        foreach( $pickup as $thisLocation ) {
            if( !isset($homeLib) && $thisLocation["locationID"] == $lookForHome ) {
                $homeLib = $thisLocation["locationID"];
            }
            if( !isset($alternateLib) && $thisLocation["locationID"] == $lookForAlt ) {
                $alternateLib = $thisLocation["locationID"];
            }
            if( !isset($preferredLib) && $thisLocation["locationID"] == $lookForPref ) {
                $preferredLib = $thisLocation["locationID"];
            }
        }

        $view = $this->createViewModel(
            [
                'skip' => true, 
                'gatheredDetails' => $gatheredDetails,
                'pickup' => $pickup,
                'defaultPickup' => $defaultPickup,
                'homeLibrary' => isset($homeLib) ? $homeLib : "",
                'preferredLibrary' => isset($preferredLib) ? $preferredLib : "",
                'alternateLibrary' => isset($alternateLib) ? $alternateLib : "",
                'extraHoldFields' => $extraHoldFields,
                'defaultRequiredDate' => $defaultRequired,
                'requestGroups' => $requestGroups,
                'defaultRequestGroup' => $defaultRequestGroup,
                'requestGroupNeeded' => $requestGroupNeeded,
                'helpText' => isset($checkHolds['helpText'])
                    ? $checkHolds['helpText'] : null
            ]
        );
        $view->setTemplate('record/hold');
        return $view;
    }
}
