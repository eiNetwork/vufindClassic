<?php
/**
 * VuFind Action Helper - Holds Support Methods
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
 * @package  Controller_Plugins
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://www.vufind.org  Main Page
 */
namespace VuFind\Controller\Plugin;

/**
 * Zend action helper to perform holds-related actions
 *
 * @category VuFind2
 * @package  Controller_Plugins
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://www.vufind.org  Main Page
 */
class Holds extends AbstractRequestBase
{
    /**
     * Update ILS details with cancellation-specific information, if appropriate.
     *
     * @param \VuFind\ILS\Connection $catalog      ILS connection object
     * @param array                  $ilsDetails   Hold details from ILS driver's
     * getMyHolds() method
     * @param array                  $cancelStatus Cancel settings from ILS driver's
     * checkFunction() method
     *
     * @return array $ilsDetails with cancellation info added
     */
    public function addCancelDetails($catalog, $ilsDetails, $cancelStatus)
    {
        // Generate Form Details for cancelling Holds if Cancelling Holds
        // is enabled
        if ($cancelStatus) {
            if ($cancelStatus['function'] == "getCancelHoldLink") {
                // Build OPAC URL
                $ilsDetails['cancel_link']
                    = $catalog->getCancelHoldLink($ilsDetails);
            } else {
                // Form Details
                $ilsDetails['cancel_details']
                    = $catalog->getCancelHoldDetails($ilsDetails);
                $this->rememberValidId($ilsDetails['cancel_details']);
            }
        }

        return $ilsDetails;
    }

    /**
     * Process cancellation requests.
     *
     * @param \VuFind\ILS\Connection $catalog ILS connection object
     * @param array                  $patron  Current logged in patron
     *
     * @return array                          The result of the cancellation, an
     * associative array keyed by item ID (empty if no cancellations performed)
     */
    public function cancelHolds($catalog, $patron)
    {
        // Retrieve the flashMessenger helper:
        $flashMsg = $this->getController()->flashMessenger();
        $params = $this->getController()->params();

        // Pick IDs to cancel based on which button was pressed:
        $all = $params->fromPost('cancelAll');
        $selected = $params->fromPost('cancelSelected');
        if (!empty($all)) {
            $details = $params->fromPost('cancelAllIDS');
        } else if (!empty($selected)) {
            $details = $params->fromPost('cancelSelectedIDS');
        } else {
            // No button pushed -- no action needed
            return [];
        }

        if (!empty($details)) {
            // Confirm?
            if ($params->fromPost('confirm') === "0") {
                if ($params->fromPost('cancelAll') !== null) {
                    return $this->getController()->confirm(
                        'hold_cancel_all',
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        'confirm_hold_cancel_all_text',
                        [
                            'cancelAll' => 1,
                            'cancelAllIDS' => $params->fromPost('cancelAllIDS')
                        ]
                    );
                } else {
                    $cancelIDs = $params->fromPost('cancelSelectedIDS');
                    $replacement = ((count($cancelIDs) > 1) ? (count($cancelIDs) . " holds") : "hold") . "?<br>";
                    foreach($params->fromPost('holdTitles') as $title) {
                        $replacement .= "<br><span class=\"bold\">Title: </span>" . urldecode($title);
                    }
                    $msg = [['msg' => 'confirm_hold_cancel_selected_text', 
                             'html' => true, 
                             'tokens' => ['%%holdData%%' => $replacement]]];
                    return $this->getController()->confirm(
                        'hold_cancel_selected',
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        $msg,
                        [
                            'cancelSelected' => 1,
                            'cancelSelectedIDS' => $cancelIDs
                        ]
                    );
                }
            }

            foreach ($details as $info) {
                // If the user input contains a value not found in the session
                // whitelist, something has been tampered with -- abort the process.
                if (!in_array($info, $this->getSession()->validIds)) {
                    $flashMsg->addMessage('error_inconsistent_parameters', 'error');
                    return [];
                }
            }

            // Add Patron Data to Submitted Data
            $cancelResults = $catalog->cancelHolds(
                ['details' => $details, 'patron' => $patron]
            );
            if ($cancelResults == false) {
                $flashMsg->addMessage('hold_cancel_fail', 'error');
            } else {
                if ($cancelResults['success']) {
                    $msg = $this->getController()
                        ->translate((count($details) == 1) ? 'hold_cancel_success_single' : 'hold_cancel_success_multiple');
                    $flashMsg->addMessage($msg, 'info');
                } else {
                    $msg = $this->getController()
                        ->translate((count($details) == 1) ? 'hold_cancel_fail_single' : 'hold_cancel_fail_multiple');
                    $flashMsg->addMessage($msg, 'error');
                }
                return $cancelResults;
            }
        } else {
             $flashMsg->addMessage('hold_empty_selection', 'error');
        }
        return [];
    }

    /**
     * Process freeze requests.
     *
     * @param \VuFind\ILS\Connection $catalog ILS connection object
     * @param array                  $patron  Current logged in patron
     *
     * @return array                          The result of the freeze, an
     * associative array keyed by item ID (empty if no freezes performed)
     */
    public function freezeHolds($catalog, $patron)
    {
        // Retrieve the flashMessenger helper:
        $flashMsg = $this->getController()->flashMessenger();
        $params = $this->getController()->params();

        // Pick IDs to freeze based on which button was pressed:
        $all = $params->fromPost('freezeAll');
        $selected = $params->fromPost('freezeSelected');
        if (!empty($all)) {
            $details = $params->fromPost('freezeAllIDS');
        } else if (!empty($selected)) {
            $details = $params->fromPost('freezeSelectedIDS');
        } else {
            // No button pushed -- no action needed
            return [];
        }

        if (!empty($details)) {
            // Confirm?
            if ($params->fromPost('confirm') === "0") {
                if ($params->fromPost('freezeAll') !== null) {
                    return $this->getController()->confirm(
                        'hold_cancel_all',
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        'confirm_hold_freeze_all_text',
                        [
                            'freezeAll' => 1,
                            'freezeAllIDS' => $params->fromPost('freezeAllIDS')
                        ]
                    );
                } else {
                    $freezeIDs = $params->fromPost('freezeSelectedIDS');
                    $replacement = ((count($freezeIDs) > 1) ? (count($freezeIDs) . " holds") : "hold") . "?<br>";
                    foreach($params->fromPost('holdTitles') as $title) {
                        $replacement .= "<br><span class=\"bold\">Title: </span>" . urldecode($title);
                    }
                    $msg = [['msg' => 'confirm_hold_freeze_selected_text', 
                             'html' => true, 
                             'tokens' => ['%%holdData%%' => $replacement]]];
                    return $this->getController()->confirm(
                        'hold_freeze_selected',
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        $msg,
                        [
                            'freezeSelected' => 1,
                            'freezeSelectedIDS' => $freezeIDs
                        ]
                    );
                }
            }

            foreach ($details as $info) {
                // If the user input contains a value not found in the session
                // whitelist, something has been tampered with -- abort the process.
                if (!in_array($info, $this->getSession()->validIds)) {
                    $flashMsg->addMessage('error_inconsistent_parameters', 'error');
                    return [];
                }
            }

            // Add Patron Data to Submitted Data
            $freezeResults = $catalog->freezeHolds(
                ['details' => $details, 'patron' => $patron], true
            );
            if ($freezeResults == false) {
                $flashMsg->addMessage('hold_freeze_fail', 'error');
            } else {
                if ($freezeResults['success']) {
                    $msg = $this->getController()
                        ->translate((count($details) == 1) ? 'hold_freeze_success_single' : 'hold_freeze_success_multiple');
                    $flashMsg->addMessage($msg, 'info');
                } else {
                    $msg = $this->getController()
                        ->translate((count($details) == 1) ? 'hold_freeze_fail_single' : 'hold_freeze_fail_multiple');
                    $flashMsg->addMessage($msg, 'error');
                }
                return $freezeResults;
            }
        } else {
             $flashMsg->addMessage('hold_empty_selection', 'error');
        }
        return [];
    }

    /**
     * Process unfreeze requests.
     *
     * @param \VuFind\ILS\Connection $catalog ILS connection object
     * @param array                  $patron  Current logged in patron
     *
     * @return array                          The result of the unfreeze, an
     * associative array keyed by item ID (empty if no unfreezes performed)
     */
    public function unfreezeHolds($catalog, $patron)
    {
        // Retrieve the flashMessenger helper:
        $flashMsg = $this->getController()->flashMessenger();
        $params = $this->getController()->params();

        // Pick IDs to unfreeze based on which button was pressed:
        $all = $params->fromPost('unfreezeAll');
        $selected = $params->fromPost('unfreezeSelected');
        if (!empty($all)) {
            $details = $params->fromPost('unfreezeAllIDS');
        } else if (!empty($selected)) {
            $details = $params->fromPost('unfreezeSelectedIDS');
        } else {
            // No button pushed -- no action needed
            return [];
        }

        if (!empty($details)) {
            // Confirm?
            if ($params->fromPost('confirm') === "0") {
                if ($params->fromPost('unfreezeAll') !== null) {
                    return $this->getController()->confirm(
                        'hold_cancel_all',
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        'confirm_hold_unfreeze_all_text',
                        [
                            'unfreezeAll' => 1,
                            'unfreezeAllIDS' => $params->fromPost('unfreezeAllIDS')
                        ]
                    );
                } else {
                    $unfreezeIDs = $params->fromPost('unfreezeSelectedIDS');
                    $replacement = ((count($unfreezeIDs) > 1) ? (count($unfreezeIDs) . " holds") : "hold") . "?<br>";
                    foreach($params->fromPost('holdTitles') as $title) {
                        $replacement .= "<br><span class=\"bold\">Title: </span>" . urldecode($title);
                    }
                    $msg = [['msg' => 'confirm_hold_unfreeze_selected_text', 
                             'html' => true, 
                             'tokens' => ['%%holdData%%' => $replacement]]];
                    return $this->getController()->confirm(
                        'hold_unfreeze_selected',
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        $this->getController()->url()->fromRoute('myresearch-holds'),
                        $msg,
                        [
                            'unfreezeSelected' => 1,
                            'unfreezeSelectedIDS' => $unfreezeIDs
                        ]
                    );
                }
            }

            foreach ($details as $info) {
                // If the user input contains a value not found in the session
                // whitelist, something has been tampered with -- abort the process.
                if (!in_array($info, $this->getSession()->validIds)) {
                    $flashMsg->addMessage('error_inconsistent_parameters', 'error');
                    return [];
                }
            }

            // Add Patron Data to Submitted Data
            $unfreezeResults = $catalog->freezeHolds(
                ['details' => $details, 'patron' => $patron], false
            );
            if ($unfreezeResults == false) {
                $flashMsg->addMessage('hold_unfreeze_fail', 'error');
            } else {
                if ($unfreezeResults['success']) {
                    $msg = $this->getController()
                        ->translate((count($details) == 1) ? 'hold_unfreeze_success_single' : 'hold_unfreeze_success_multiple');
                    $flashMsg->addMessage($msg, 'info');
                } else {
                    $msg = $this->getController()
                        ->translate((count($details) == 1) ? 'hold_unfreeze_fail_single' : 'hold_unfreeze_fail_multiple');
                    $flashMsg->addMessage($msg, 'error');
                }
                return $unfreezeResults;
            }
        } else {
             $flashMsg->addMessage('hold_empty_selection', 'error');
        }
        return [];
    }

    /**
     * Process update requests.
     *
     * @param \VuFind\ILS\Connection $catalog ILS connection object
     * @param array                  $patron  Current logged in patron
     *
     * @return array                          The result of the update, an
     * associative array keyed by item ID (empty if no updates performed)
     */
    public function updateHolds($catalog, $patron)
    {
        // Retrieve the flashMessenger helper:
        $flashMsg = $this->getController()->flashMessenger();
        $params = $this->getController()->params();

        // Pick IDs to update based on which button was pressed:
        $details = $params->fromPost('updateIDs');

        if (!empty($details)) {
            if( $params->fromPost('changePickup') ) {
                // Add Patron Data to Submitted Data
                $updateResults = $catalog->updateHolds(
                    ['details' => $details, 'patron' => $patron, 'newLocation' => $params->fromPost('gatheredDetails')["pickUpLocation"]]
                );
                if ($updateResults == false) {
                    $flashMsg->addMessage('hold_all_overdrive_fail', 'error');
                } else {
                    if ($updateResults['success']) {
                        $msg = $this->getController()
                            ->translate((count($details) == 1) ? 'hold_update_success_single' : 'hold_update_success_multiple');
                        $flashMsg->addMessage($msg, 'info');
                    } else {
                        $msg = $this->getController()
                            ->translate((count($details) == 1) ? 'hold_update_fail_single' : 'hold_update_fail_multiple');
                        $flashMsg->addMessage($msg, 'error');
                    }
                    return $updateResults;
                }
            } else if( $params->fromPost('changeEmail') ) {
                // Add Patron Data to Submitted Data
                $updateResults = $catalog->updateHolds(
                    ['details' => $details, 'patron' => $patron, 'newEmail' => $params->fromPost('updateODEmail')]
                );
                if ($updateResults == false) {
                    $flashMsg->addMessage('hold_all_overdrive_fail', 'error');
                } else {
                    if ($updateResults['success']) {
                        $msg = $this->getController()
                            ->translate((count($details) == 1) ? 'hold_update_success_single' : 'hold_update_success_multiple');
                        $flashMsg->addMessage($msg, 'info');
                    } else {
                        $msg = $this->getController()
                            ->translate((count($details) == 1) ? 'hold_update_fail_single' : 'hold_update_fail_multiple');
                        $flashMsg->addMessage($msg, 'error');
                    }
                    return $updateResults;
                }
            }
        } else {
             $flashMsg->addMessage('hold_empty_selection', 'error');
        }
        return [];
    }

    /**
     * Process bulk hold requests.
     *
     * @param \VuFind\ILS\Connection $catalog ILS connection object
     * @param array                  $patron  Current logged in patron
     *
     * @return array                          The result of the hold, an
     * associative array keyed by item ID (empty if no updates performed)
     */
    public function createHolds($catalog, $patron)
    {
        // Retrieve the flashMessenger helper:
        $flashMsg = $this->getController()->flashMessenger();
        $params = $this->getController()->params();

        // Pick IDs to update based on which button was pressed:
        $details = $params->fromPost('holdIDs');

        if (!empty($details)) {
            $successes = 0;
            $failures = 0;
            $successMsg = "";
            $failureMsg = "";

            foreach($details as $id) {
                $title = "";
                foreach($params->fromPost('holdTitles') as $thisTitle) {
                    if( substr($thisTitle, 0, strlen($id)) == $id ) {
                        $title = urldecode(substr($thisTitle, strlen($id) + 1));
                    }
                }

                // process overdrive holds
                if( $overDriveId = $catalog->getOverDriveID($id) )
                {
                    $results = $catalog->placeOverDriveHold($overDriveId, $patron);
                    if( $results['result'] ) {
                        $successes++;
                        $successMsg .= $title . "<br>";
                    } else {
                        $failures++;
                        $failureMsg .= $title . "<br>";
                    }
                // process physical item holds
                } else {
                    $defaultRequired = $this->getController()->holds()->getDefaultRequiredDate(
                        $checkHolds, $catalog, $patron, $gatheredDetails
                    );
                    $defaultRequired = $this->getController()->getServiceLocator()->get('VuFind\DateConverter')
                        ->convertToDisplayDate("U", $defaultRequired);
                    $holdResults = $catalog->placeHold(
                        ['id' => $id, 'patron' => $patron, 'pickUpLocation' => $params->fromPost('gatheredDetails')["pickUpLocation"], 'requiredBy' => $defaultRequired]
                    );
                    if( $holdResults['success'] ) {
                        $successes++;
                        $successMsg .= $title . "<br>";
                    } else {
                        $failures++;
                        $failureMsg .= $title . "<br>";
                    }
                }
            }

            if ($successes > 0) {
                $msg = ['msg' => ($successes == 1) ? 'hold_place_success_single' : 'hold_place_success_multiple', 
                        'html' => true, 
                        'tokens' => ['%%holdData%%' => $successMsg, '%%url%%' => $this->getController()->url()->fromRoute('myresearch-holds')]];
                $flashMsg->addMessage($msg, 'info');
            }
            if ($failures > 0) {
                $msg = ['msg' => ($failures == 1) ? 'hold_place_failure_single' : 'hold_place_failure_multiple', 
                        'html' => true, 
                        'tokens' => ['%%holdData%%' => $failureMsg]];
                $flashMsg->addMessage($msg, 'error');
            }
        } else {
             $flashMsg->addMessage('hold_empty_selection', 'error');
        }
        return [];
    }
}
