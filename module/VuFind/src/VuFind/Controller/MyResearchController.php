<?php
/**
 * MyResearch Controller
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

use VuFind\Exception\Auth as AuthException,
    VuFind\Exception\Mail as MailException,
    VuFind\Exception\ListPermission as ListPermissionException,
    VuFind\Exception\RecordMissing as RecordMissingException,
    VuFind\Search\RecommendListener, Zend\Stdlib\Parameters, 
    Zend\Session\Container as SessionContainer;

/**
 * Controller for the user account area.
 *
 * @category VuFind2
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
class MyResearchController extends AbstractBase
{
    /**
     * Session container
     *
     * @var SessionContainer
     */
    protected $session;
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->session = new SessionContainer('MyResearchController');
    }

    /**
     * Process an authentication error.
     *
     * @param AuthException $e Exception to process.
     *
     * @return void
     */
    protected function processAuthenticationException(AuthException $e)
    {
        $msg = $e->getMessage();
        // If a Shibboleth-style login has failed and the user just logged
        // out, we need to override the error message with a more relevant
        // one:
        if ($msg == 'authentication_error_admin'
            && $this->getAuthManager()->userHasLoggedOut()
            && $this->getSessionInitiator()
        ) {
            $msg = 'authentication_error_loggedout';
        }
        $this->flashMessenger()->addMessage($msg, 'error');
    }

    /**
     * Maintaining this method for backwards compatibility;
     * logic moved to parent and method re-named
     *
     * @return void
     */
    protected function storeRefererForPostLoginRedirect()
    {
        $this->setFollowupUrlToReferer();
    }

    /**
     * Prepare and direct the home page where it needs to go
     *
     * @return mixed
     */
    public function homeAction()
    {
        // Process login request, if necessary (either because a form has been
        // submitted or because we're using an external login provider):
        if ($this->params()->fromPost('processLogin')
            || $this->getSessionInitiator()
            || $this->params()->fromPost('auth_method')
            || $this->params()->fromQuery('auth_method')
        ) {
            try {
                if (!$this->getAuthManager()->isLoggedIn()) {
                    $this->getAuthManager()->login($this->getRequest());
                    // store their info to use again later
                    if( $this->getAuthManager()->isLoggedIn() ) {
                        $expiration = $this->getILS()->getCurrentLocation() ? 0 : (time() + 1209600);
                        setcookie("einStoredBarcode", $this->params()->fromPost('username'), $expiration, '/');
                        setcookie("einStoredPIN", $this->params()->fromPost('password'), $expiration, '/');

                        // dismiss the lightbox
                        if( $this->params()->fromPost('clearLightbox') ) {
                            $view = $this->createViewModel();
                            $view->setTemplate('blankModal');
                            $view->title = "Logging in...";
                            $view->reloadParent = true;
                            return $view;
                        }
                    }
                }
            } catch (AuthException $e) {
                $this->processAuthenticationException($e);
            }
        }

        // Not logged in?  Force user to log in:
        if (!$this->getAuthManager()->isLoggedIn()) {
            $this->setFollowupUrlToReferer();
            return $this->forwardTo('MyResearch', 'Login');
        }

        // if they gave us some extra info, stash it in the followup
        if( $this->params()->fromPost('lightboxFollowup') ) {
            $this->flashMessenger()->addMessage("<span id='lightboxFollowup'>" . $this->params()->fromPost('lightboxFollowup') . "</span>", "info");
        }

        // Logged in?  Forward user to followup action
        // or default action (if no followup provided):
        if ($url = $this->getFollowupUrl()) {
            $this->clearFollowupUrl();

            // If a user clicks on the "Your Account" link, we want to be sure
            // they get to their account rather than being redirected to an old
            // followup URL. We'll use a redirect=0 GET flag to indicate this:
            if ($this->params()->fromQuery('redirect', true)) {
                return $this->redirect()->toUrl($url);
            }
        }

        $config = $this->getConfig();
        $page = isset($config->Site->defaultAccountPage)
            ? $config->Site->defaultAccountPage : 'Favorites';

        // if they're coming from a library website, send them to the right place
        if( $this->params()->fromPost('externalSiteLogin') ) {
            return $this->forwardTo('MyResearch', $page);
        }

        // Default to search history if favorites are disabled:
        if ($page == 'Favorites' && !$this->listsEnabled()) {
            return $this->forwardTo('Search', 'History');
        }
        $this->flashMessenger()->addMessage("<span id='redirectMessage'>" . $page . "</span>", "info");        
        $view = $this->createViewModel();
        $view->setTemplate('myresearch/resetpin');
        return $view;
    }

    /**
     * "Create account" action
     *
     * @return mixed
     */
    public function accountAction()
    {
        // If the user is already logged in, don't let them create an account:
        if ($this->getAuthManager()->isLoggedIn()) {
            $this->flashMessenger()->addMessage('register_logged_in', 'error');
            return $this->createViewModel();
        }

        $catalog = $this->getILS();
        $params = ["firstName" => $this->params()->fromPost('firstname'),
                   "lastName" => $this->params()->fromPost('lastname'),
                   "email" => $this->params()->fromPost('email'),
                   "phone" => $this->params()->fromPost('phone'),
                   "address1" => $this->params()->fromPost('address1') . (($this->params()->fromPost('address2')!='') ? (" ".$this->params()->fromPost('address2')) : ""),
                   "cityStateZip" => $this->params()->fromPost('city') . ", " . $this->params()->fromPost('state') . " " . $this->params()->fromPost('zip'),
                   "pin" => $this->params()->fromPost('pin')];
        $result = $this->getILS()->selfRegister($params);

        if( $result["success"] ) {
            $this->flashMessenger()->setNamespace('info')->addMessage(["msg" => "register_success", "html" => true, "hideClose" => true, "tokens" => ["%%%barcode%%%" => $result["barcode"]]]);
        } else {
            $this->flashMessenger()->setNamespace('error')->addMessage("register_failure");
        }
        return $this->createViewModel();
    }

    /**
     * Reset PIN action
     *
     * @return mixed
     */
    public function resetPINAction()
    {
        $catalog = $this->getILS();
        $result = $catalog->requestPINReset($this->params()->fromPost('username'));

        $view = $this->createViewModel();
        if( $result ) {
            $this->flashMessenger()->setNamespace('info')->addMessage("reset_success");
        } else {
            $this->flashMessenger()->setNamespace('error')->addMessage("reset_failure");
        }
        return $view;
    }

    /**
     * Login Action
     *
     * @return mixed
     */
    public function loginAction()
    {
        // If this authentication method doesn't use a VuFind-generated login
        // form, force it through:
        if ($this->getSessionInitiator()) {
            // Don't get stuck in an infinite loop -- if processLogin is already
            // set, it probably means Home action is forwarding back here to
            // report an error!
            //
            // Also don't attempt to process a login that hasn't happened yet;
            // if we've just been forced here from another page, we need the user
            // to click the session initiator link before anything can happen.
            //
            // Finally, we don't want to auto-forward if we're in a lightbox, since
            // it may cause weird behavior -- better to display an error there!
            if (!$this->params()->fromPost('processLogin', false)
                && !$this->params()->fromPost('forcingLogin', false)
                && !$this->inLightbox()
            ) {
                $this->getRequest()->getPost()->set('processLogin', true);
                return $this->forwardTo('MyResearch', 'Home');
            }
        }

        // see whether they have just registered
        foreach($this->flashMessenger()->getInfoMessages() as $msg) {
            if( isset($msg["msg"]) && $msg["msg"] == "register_success" ) {
                // if so, don't try to force them to log in, they'll miss their temp barcode!  just redirect them to the splash page
                $this->flashMessenger()->clearCurrentMessages('error');
                return $this->forwardTo('Search', 'Home');
            }
        }

        // see if they have a stored cookie
        if( isset($_COOKIE["einStoredBarcode"]) && isset($_COOKIE["einStoredPIN"]) && !$this->params()->fromPost('clearLightbox', false) ) {
            $this->getRequest()->getPost()->set('username', $_COOKIE["einStoredBarcode"]);
            $this->getRequest()->getPost()->set('password', $_COOKIE["einStoredPIN"]);
            $this->getRequest()->getPost()->set('auth_method', "ILS");
            $this->getRequest()->getPost()->set('clearLightbox', true);
            return $this->forwardTo('MyResearch', 'Home');
        }

        // Make request available to view for form updating:
        $view = $this->createViewModel();
        $view->inLightbox = $this->inLightbox();
        $view->request = $this->getRequest()->getPost();
        return $view;
    }

    /**
     * User login action -- clear any previous follow-up information prior to
     * triggering a login process. This is used for explicit login links within
     * the UI to differentiate them from contextual login links that are triggered
     * by attempting to access protected actions.
     *
     * @return mixed
     */
    public function userloginAction()
    {
        // Don't log in if already logged in!
        if ($this->getAuthManager()->isLoggedIn()) {
            return $this->redirect()->toRoute('home');
        }
        $this->clearFollowupUrl();
        $this->setFollowupUrlToReferer();
        if ($si = $this->getSessionInitiator()) {
            return $this->redirect()->toUrl($si);
        }
        return $this->forwardTo('MyResearch', 'Login');
    }

    /**
     * Logout Action
     *
     * @return mixed
     */
    public function logoutWarningAction()
    {
        $view = $this->createViewModel();
        return $view;
    }

    /**
     * Logout Action
     *
     * @return mixed
     */
    public function logoutAction()
    {
        $config = $this->getConfig();
        if (isset($config->Site->logOutRoute)) {
            $logoutTarget = $this->getServerUrl($config->Site->logOutRoute);
        } else if ($targetRoute = $this->params()->fromQuery('target', false)) {
            $logoutTarget = $this->getServerUrl($targetRoute);
        } else {
            $logoutTarget = $this->getRequest()->getServer()->get('HTTP_REFERER');
            if (empty($logoutTarget)) {
                $logoutTarget = $this->getServerUrl('home');
            }

            // If there is an auth_method parameter in the query, we should strip
            // it out. Otherwise, the user may get stuck in an infinite loop of
            // logging out and getting logged back in when using environment-based
            // authentication methods like Shibboleth.
            $logoutTarget = preg_replace(
                '/([?&])auth_method=[^&]*&?/', '$1', $logoutTarget
            );
            $logoutTarget = rtrim($logoutTarget, '?');
        }

        // clear out the patron info
        $this->getILS()->clearSessionVar("patronLogin");
        $this->getILS()->clearSessionVar("patron");
        $this->getILS()->clearSessionVar("dismissedAnnouncements");
        setcookie("einStoredBarcode", "", time() - 1209600, '/');
        setcookie("einStoredPIN", "", time() - 1209600, '/');
        setcookie("checkoutTab", "", time() - 1209600, '/');
        setcookie("holdsTab", "", time() - 1209600, '/');
        setcookie("mostRecentList", "", time() - 1209600, '/');
        setcookie("lastProfileSection", "", time() - 1209600, '/');
        setcookie("itemDetailsTab", "", time() - 1209600, '/');
        setcookie("catalogCheckboxes", "", time() - 1209600, '/');

        return $this->redirect()
            ->toUrl($this->getAuthManager()->logout($logoutTarget));
    }

    /**
     * Support method for savesearchAction(): set the saved flag in a secure
     * fashion, throwing an exception if somebody attempts something invalid.
     *
     * @param int  $searchId The search ID to save/unsave
     * @param bool $saved    The new desired state of the saved flag
     * @param int  $userId   The user ID requesting the change
     *
     * @throws \Exception
     * @return void
     */
    protected function setSavedFlagSecurely($searchId, $saved, $userId)
    {
        $searchTable = $this->getTable('Search');
        $sessId = $this->getServiceLocator()->get('VuFind\SessionManager')->getId();
        $row = $searchTable->getOwnedRowById($searchId, $sessId, $userId);
        if (empty($row)) {
            throw new \Exception('Access denied.');
        }
        $row->saved = $saved ? 1 : 0;
        $row->user_id = $userId;
        $row->save();
    }

    /**
     * Handle 'save/unsave search' request
     *
     * @return mixed
     */
    public function savesearchAction()
    {
        $user = $this->getUser();
        if ($user == false) {
            return $this->forceLogin();
        }

        // Check for the save / delete parameters and process them appropriately:
        if (($id = $this->params()->fromQuery('save', $this->params()->fromPost('save', false))) !== false) {
            $this->setSavedFlagSecurely($id, true, $user->id);
            $this->flashMessenger()->addMessage('search_save_success', 'info');
        } else if (($id = $this->params()->fromQuery('delete', $this->params()->fromPost('delete', false))) !== false) {
            $this->setSavedFlagSecurely($id, false, $user->id);
            $this->flashMessenger()->addMessage('search_unsave_success', 'info');
        } else {
            throw new \Exception('Missing save and delete parameters.');
        }

        // Forward to the appropriate place:
        if ($this->params()->fromQuery('mode') == 'history') {
            return $this->redirect()->toRoute('search-history');
        } else {
            // Forward to the Search/Results action with the "saved" parameter set;
            // this will in turn redirect the user to the appropriate results screen.
            $this->getRequest()->getQuery()->set('saved', $id);
            return $this->forwardTo('Search', 'Results');
        }
    }

    /**
     * Gather user profile data
     *
     * @return mixed
     */
    public function profileAction()
    {
        // Stop now if the user does not have valid catalog credentials available:
        if (!is_array($patron = $this->catalogLogin())) {
            return $patron;
        }

        // User must be logged in at this point, so we can assume this is non-false:
        $user = $this->getUser();

        // Process update parameters (if present):
        $notification = $this->params()->fromPost('notification', false);
        $splitEcontent = $this->params()->fromPost('splitEcontent', false);
        $preferredLibrary = $this->params()->fromPost('preferred_library', false);
        $alternateLibrary = $this->params()->fromPost('alternate_library', false);
        $phone = $this->params()->fromPost('phone', false);
        $phone2 = $this->params()->fromPost('phone2', false);
        $email = $this->params()->fromPost('email', false);
        $pin = $this->params()->fromPost('pin', false);
        $OD_eBook = $this->params()->fromPost('OD_eBook', false);
        $OD_audiobook = $this->params()->fromPost('OD_audiobook', false);
        $OD_video = $this->params()->fromPost('OD_video', false);
        if( !empty($notification) || !empty($preferredLibrary) || !empty($alternateLibrary) || !empty($phone) || !empty($phone2) || !empty($pin) || 
            !empty($email) || !empty($OD_eBook) || !empty($OD_audiobook) || !empty($OD_video) ) {
            // grab this to compare it to what we've got now
            $catalog = $this->getILS();
            $profile = $catalog->getMyProfile($patron);

            // load this up, but only if they've changed those properties
            $updatedInfo = [];
            if( !empty($notification) && (!isset($profile["notificationCode"]) || ($profile["notificationCode"] != $notification)) ) {
                $updatedInfo["notices"] = $notification;
            }
            if( !empty($splitEcontent) && $profile["splitEcontent"] != $splitEcontent ) {
                $updatedInfo["splitEcontent"] = $splitEcontent;
            }
            if( !empty($preferredLibrary) && $profile["preferredlibrarycode"] != $preferredLibrary) {
                $updatedInfo["preferred_library"] = $preferredLibrary;
            }
            if( !empty($alternateLibrary) && $profile["alternatelibrarycode"] != $alternateLibrary) {
                $updatedInfo["alternate_library"] = $alternateLibrary;
            }
            if( !empty($phone) && (!isset($profile["phone"]) || ($profile["phone"] != $phone)) ) {
                $updatedInfo["phones"] = [["number" => $phone, "type" => "t"]];
                if( isset($profile["phone2"]) ) {
                    $updatedInfo["phones"][] = ["number" => $profile["phone2"], "type" => "p"];
                }
            }
            if( !empty($phone2) && (!isset($profile["phone2"]) || ($profile["phone2"] != $phone2)) ) {
                $updatedInfo["phones"] = [["number" => $phone2, "type" => "p"]];
                if( isset($profile["phone"]) ) {
                    $updatedInfo["phones"][] = ["number" => $profile["phone"], "type" => "t"];
                }
            }
            if( !empty($email) && (!isset($profile["email"]) || ($profile["email"] != $email)) ) {
                $updatedInfo["emails"] = [$email];
            }
            if( !empty($pin) ) {
                $updatedInfo["pin"] = $pin;
            }
            if( !empty($OD_eBook) && intval($profile["OD_eBook"]) != $OD_eBook ) {
                $updatedInfo["ebook"] = $OD_eBook;
            }
            if( !empty($OD_audiobook) && intval($profile["OD_audiobook"]) != $OD_audiobook ) {
                $updatedInfo["audiobook"] = $OD_audiobook;
            }
            if( !empty($OD_video) && intval($profile["OD_video"]) != $OD_video ) {
                $updatedInfo["video"] = $OD_video;
            }
            $results = $this->getILS()->updateMyProfile($patron, $updatedInfo);

            // look for error
            if( isset($results["success"]) && !$results["success"] && isset($updatedInfo["pin"]) ) {
                $this->flashMessenger()->addMessage('illegal_pin', 'error');
            } else {
                $post = $this->getRequest()->getPost();
                $post->username = $patron["cat_username"];
                $post->password = isset($updatedInfo["pin"]) ? $updatedInfo["pin"] : $patron["cat_password"];
                $patron["cat_password"] = isset($updatedInfo["pin"]) ? $updatedInfo["pin"] : $patron["cat_password"];
                // Login to grab the new info
                $this->getAuthManager()->login($this->getRequest());
                $profile = $catalog->getMyProfile($patron, true);
                $this->getAuthManager()->updateSession($user);
                $this->flashMessenger()->addMessage('profile_update', 'info');
            }
        }

        // Begin building view object:
        $view = $this->createViewModel();
        if( isset($_COOKIE["lastProfileSection"]) ) {
            $view->showProfileSection = $_COOKIE["lastProfileSection"];
        }
        if( $suppression = $this->params()->fromPost("suppressFlashMessages", false) ) {
            $view->suppressFlashMessages = $suppression;
        }
        if( $reloadParent = $this->params()->fromPost("reloadParent", false) ) {
            $view->reloadParent = $reloadParent;
        }

        // Obtain user information from ILS:
        $catalog = $this->getILS();
        $profile = $catalog->getMyProfile($patron);
        $profile['home_library'] = $user->home_library;

        // show extra messages
        if( isset($profile["phone"]) && (strlen($profile["phone"]) != 12 || preg_match('/\d{3}-\d{3}-\d{4}/', $profile["phone"]) != 1) ) {
            $this->flashMessenger()->addMessage('correct_phone_format', 'info');
        }
        if( isset($profile["phone2"]) && (strlen($profile["phone2"]) != 12 || preg_match('/\d{3}-\d{3}-\d{4}/', $profile["phone2"]) != 1) ) {
            $this->flashMessenger()->addMessage('correct_phone_format', 'info');
        }
        if( !isset($profile["preferredlibrarycode"]) || !$profile["preferredlibrarycode"] ) {
            $this->flashMessenger()->addMessage('set_preferred_library', 'info');
        }

        $view->overDriveURL = $this->getILS()->getConfigVar("OverDrive","url");
        $view->profile = $profile;
        try {
            $view->pickup = $catalog->getPickUpLocations($patron);
            $view->defaultPickupLocation = $catalog->getDefaultPickUpLocation($patron);
        } catch (\Exception $e) {
            // Do nothing; if we're unable to load information about pickup
            // locations, they are not supported and we should ignore them.
        }

        return $view;
    }

    /**
     * Catalog Login Action
     *
     * @return mixed
     */
    public function catalogloginAction()
    {
        // Connect to the ILS and check if multiple target support is available:
        $targets = null;
        $catalog = $this->getILS();
        if ($catalog->checkCapability('getLoginDrivers')) {
            $targets = $catalog->getLoginDrivers();
        }
        return $this->createViewModel(['targets' => $targets]);
    }

    /**
     * Action for sending all of a user's saved favorites to the view
     *
     * @return mixed
     */
/*
    public function favoritesAction()
    {
        // Favorites is the same as MyList, but without the list ID parameter.
        return $this->forwardTo('MyResearch', 'MyList');
    }
*/

    /**
     * Action for moving the facets information to a modal
     *
     * @return mixed
     */
    public function facetsAction()
    {
        // Favorites is the same as MyList, but without the list ID parameter.
        return $this->createViewModel();
    }

    /**
     * Temporary action to wall off things not yet implemented
     *
     * @return mixed
     */
    public function comingsoonAction()
    {
        return $this->redirect()->toUrl("/");
    }

    /**
     * Action for showing the SMS help (archival, some libraries linked directly here, so redirect)
     *
     * @return mixed
     */
    public function smshelpAction()
    {
        return $this->forwardTo('Search', 'Home');
    }

    /**
     * PIN Reset action
     * This is archival, some libraries are still linking to it for some reason, so we just redirect to the correct dialog.
     * 
     * @return mixed
     */
    public function PINresetAction()
    {
        return $this->forwardTo('Search', 'Home');
    }

    /**
     * Get Card action
     * This is archival, some libraries are still linking to it for some reason, so we just redirect to the correct dialog.
     * 
     * @return mixed
     */
    public function GetCardAction()
    {
        return $this->forwardTo('Search', 'Home');
    }

    /**
     * Action for sending all of a user's saved book cart items to the view
     *
     * @return mixed
     */
    public function bookCartAction()
    {
        // make sure they're logged in
        $user = $this->getUser();
        if (!$user) {
            return $this->forwardTo('MyResearch', 'Home');
        }

        // Book cart is the same as MyList, but with one specific list.  Also, make sure we
        // know that this is the book cart
        $this->getRequest()->getQuery()->set('id', $this->getUser()->getBookCart()['id']);
        return $this->forwardTo('MyResearch', 'MyList');
    }

    /**
     * Delete group of records from favorites.
     *
     * @return mixed
     */
    public function deleteAction()
    {
        // Force login:
        $user = $this->getUser();
        if (!$user) {
            return $this->forceLogin();
        }

        // Get target URL for after deletion:
        $listID = $this->params()->fromPost('listID');

        // Fail if we have nothing to delete:
        $ids = $this->params()->fromPost('ids');
        if (!is_array($ids) || empty($ids)) {
            $this->flashMessenger()->addMessage('bulk_noitems_advice', 'error');
            return $this->redirect()->toUrl($newUrl);
        }

        // clear the cached contents
        $this->getILS()->clearMemcachedVar("cachedList" . $listID);

        // Process the deletes if necessary:
        if ($this->formWasSubmitted('submit')) {
            $this->favorites()->delete($ids, $listID, $user);
            $this->flashMessenger()->addMessage((count($ids) == 1) ? 'single_delete_success' : 'multiple_delete_success', 'info');
            $view = $this->createViewModel();
            $view->setTemplate('blankModal');
            return $view;
        }

        // If we got this far, the operation has not been confirmed yet; show
        // the necessary dialog box:
        if (empty($listID)) {
            $list = false;
        } else {
            $table = $this->getTable('UserList');
            $list = $table->getExisting($listID);
        }
        return $this->createViewModel(
            [
                'list' => $list, 'deleteIDS' => $ids,
                'records' => $this->getRecordLoader()->loadBatch($ids)
            ]
        );
    }

    /**
     * Add group of records to favorites.
     *
     * @return mixed
     */
    public function addBulkAction()
    {
        // Force login:
        $user = $this->getUser();
        if (!$user) {
            return $this->forceLogin();
        }

        // Process form within a try..catch so we can handle errors appropriately:
        try {
            // Get target URL for after deletion:
            $listID = $this->params()->fromPost('addListID');

            // Fail if we have nothing to delete:
            $ids = $this->params()->fromPost('ids');
            if (!is_array($ids) || empty($ids)) {
                $this->flashMessenger()->addMessage('bulk_noitems_advice', 'error');
                return $this->redirect()->toUrl($newUrl);
            }

            // clear the cached contents
            $this->getILS()->clearMemcachedVar("cachedList" . $listID);

            // Process the adds:
            $this->favorites()->saveBulk(['ids' => $ids, 'list' => $listID], $user);
            $this->flashMessenger()->addMessage((count($ids) == 1) ? 'single_save_success' : 'multiple_save_success', 'info');
            $view = $this->createViewModel(['skip' => true, 'reloadParent' => true]);
            $view->setTemplate('blankModal');
            return $view;
        } catch (\Exception $e) {
            switch(get_class($e)) {
            case 'VuFind\Exception\ListSize':
                $this->flashMessenger()->addMessage($e->getMessage(), 'error');
                $view = $this->createViewModel(['skip' => true, 'reloadParent' => true]);
                $view->setTemplate('blankModal');
                return $view;
            default:
                throw $e;
            }
        }
    }

    /**
     * Delete record
     *
     * @param string $id     ID of record to delete
     * @param string $source Source of record to delete
     *
     * @return mixed         True on success; otherwise returns a value that can
     * be returned by the controller to forward to another action (i.e. force login)
     */
    public function performDeleteFavorite($id, $source)
    {
        // Force login:
        $user = $this->getUser();
        if (!$user) {
            return $this->forceLogin();
        }

        // Load/check incoming parameters:
        $listID = $this->params()->fromRoute('id');
        $listID = empty($listID) ? null : $listID;
        if (empty($id)) {
            throw new \Exception('Cannot delete empty ID!');
        }

        // Perform delete and send appropriate flash message:
        if (null !== $listID) {
            // ...Specific List
            $table = $this->getTable('UserList');
            $list = $table->getExisting($listID);
            $list->removeResourcesById($user, [$id], $source);
            $this->flashMessenger()->addMessage('single_delete_success', 'info');

            // clear the cached contents
            $this->getILS()->clearMemcachedVar("cachedList" . $listID);
        } else {
            // ...My Favorites
            $user->removeResourcesById([$id], $source);
            $this->flashMessenger()
                ->addMessage('single_delete_success', 'success');
        }

        // All done -- return from whence we came
        return $this->redirect()->toUrl($this->getRequest()->getServer()->get('HTTP_REFERER'));
    }

    /**
     * Process the submission of the edit favorite form.
     *
     * @param \VuFind\Db\Row\User               $user   Logged-in user
     * @param \VuFind\RecordDriver\AbstractBase $driver Record driver for favorite
     * @param int                               $listID List being edited (null
     * if editing all favorites)
     *
     * @return object
     */
    protected function processEditSubmit($user, $driver, $listID)
    {
        $lists = $this->params()->fromPost('lists');
        $tagParser = $this->getServiceLocator()->get('VuFind\Tags');
        foreach ($lists as $list) {
            $tags = $this->params()->fromPost('tags' . $list);
            $driver->saveToFavorites(
                [
                    'list'  => $list,
                    'mytags'  => $tagParser->parse($tags),
                    'notes' => $this->params()->fromPost('notes' . $list)
                ],
                $user
            );
        }
        // add to a new list?
        $addToList = $this->params()->fromPost('addToList');
        if ($addToList > -1) {
            $driver->saveToFavorites(['list' => $addToList], $user);
        }
        $this->flashMessenger()->addMessage('edit_list_success', 'info');

        $newUrl = is_null($listID)
            ? $this->url()->fromRoute('myresearch-favorites')
            : $this->url()->fromRoute('userList', ['id' => $listID]);
        return $this->redirect()->toUrl($newUrl);
    }

    /**
     * Edit record
     *
     * @return mixed
     */
    public function editAction()
    {
        // Force login:
        $user = $this->getUser();
        if (!$user) {
            return $this->forceLogin();
        }

        // Get current record (and, if applicable, selected list ID) for convenience:
        $id = $this->params()->fromPost('id', $this->params()->fromQuery('id'));
        $source = $this->params()->fromPost(
            'source', $this->params()->fromQuery('source', 'VuFind')
        );
        $driver = $this->getRecordLoader()->load($id, $source, true);
        $listID = $this->params()->fromPost(
            'list_id', $this->params()->fromQuery('list_id', null)
        );

        // Process save action if necessary:
        if ($this->formWasSubmitted('submit')) {
            return $this->processEditSubmit($user, $driver, $listID);
        }

        // Get saved favorites for selected list (or all lists if $listID is null)
        $userResources = $user->getSavedData($id, $listID, $source);
        $savedData = [];
        foreach ($userResources as $current) {
            $savedData[] = [
                'listId' => $current->list_id,
                'listTitle' => $current->list_title,
                'notes' => $current->notes,
                'tags' => $user->getTagString($id, $current->list_id, $source)
            ];
        }

        // In order to determine which lists contain the requested item, we may
        // need to do an extra database lookup if the previous lookup was limited
        // to a particular list ID:
        $containingLists = [];
        if (!empty($listID)) {
            $userResources = $user->getSavedData($id, null, $source);
        }
        foreach ($userResources as $current) {
            $containingLists[] = $current->list_id;
        }

        // Send non-containing lists to the view for user selection:
        $userLists = $user->getLists();
        $lists = [];
        foreach ($userLists as $userList) {
            if (!in_array($userList->id, $containingLists)) {
                $lists[$userList->id] = $userList->title;
            }
        }

        return $this->createViewModel(
            [
                'driver' => $driver, 'lists' => $lists, 'savedData' => $savedData
            ]
        );
    }

    /**
     * Confirm a request to delete a favorite item.
     *
     * @param string $id     ID of record to delete
     * @param string $source Source of record to delete
     *
     * @return mixed
     */
    protected function confirmDeleteFavorite($id, $source)
    {
        // Normally list ID is found in the route match, but in lightbox context it
        // may sometimes be a GET parameter.  We must cover both cases.
        $listID = $this->params()->fromRoute('id', $this->params()->fromQuery('id'));
        if (empty($listID)) {
            $url = $this->url()->fromRoute('myresearch-favorites');
        } else {
            $url = $this->url()->fromRoute('userList', ['id' => $listID]);
        }
        return $this->confirm(
            'confirm_delete_brief', $url, $url, 'confirm_delete',
            ['delete' => $id, 'source' => $source]
        );
    }

    /**
     * Send user's saved favorites from a particular list to the view
     *
     * @return mixed
     */
    public function mylistAction()
    {
        // Fail if lists are disabled:
        if (!$this->listsEnabled()) {
            throw new \Exception('Lists disabled');
        }

        // Check for "delete item" request; parameter may be in GET or POST depending
        // on calling context.
        $deleteId = $this->params()->fromPost(
            'delete', $this->params()->fromQuery('delete')
        );
        if ($deleteId) {
            $deleteSource = $this->params()->fromPost(
                'source', $this->params()->fromQuery('source', 'VuFind')
            );
            // If the user already confirmed the operation, perform the delete now;
            // otherwise prompt for confirmation:
            $confirm = $this->params()->fromPost(
                'confirm', $this->params()->fromQuery('confirm')
            );
            if ($confirm) {
                return $this->performDeleteFavorite($deleteId, $deleteSource);
            } else {
                return $this->confirmDeleteFavorite($deleteId, $deleteSource);
            }
        }

        // If we got this far, we just need to display the favorites:
        try {
            $runner = $this->getServiceLocator()->get('VuFind\SearchRunner');

            $lists = [];
            if( !$this->params()->fromRoute('id') && !$this->params()->fromQuery('id') ) {
                // make sure they are logged in
                if (!$this->getUser()) {
                    return $this->forceLogin();
                }

                foreach($this->getUser()->getLists() as $thisList) {
                    if( !$thisList->isBookCart() ) {
                        $lists[] = $thisList;
                    }
                }
            } else {
                $lists[] = $this->getTable('UserList')->getExisting($this->params()->fromRoute('id') ? $this->params()->fromRoute('id') : $this->params()->fromQuery('id'));
            }

            $results = [];
            $listFound = !isset($_COOKIE["mostRecentList"]);
            foreach( $lists as $thisList ) {
                // We want to merge together GET, POST and route parameters to
                // initialize our search object:
                $request = $this->getRequest()->getQuery()->toArray()
                    + $this->getRequest()->getPost()->toArray()
                    + ['id' => $thisList->id, 'limit' => 0, 'page' => 1, 'listContents' => true];

                // Set up listener for recommendations:
                $rManager = $this->getServiceLocator()
                    ->get('VuFind\RecommendPluginManager');
                $setupCallback = function ($runner, $params, $searchId) use ($rManager) {
                    $listener = new RecommendListener($rManager, $searchId);
                    $listener->setConfig(
                        $params->getOptions()->getRecommendationSettings()
                    );
                    $listener->attach($runner->getEventManager()->getSharedManager());
                };

                if( !$listFound && $_COOKIE["mostRecentList"] == $thisList->id ) {
                    $listFound = true;
                }

                $results[] = ['list' => $thisList, 'items' => ((!$this->params()->fromRoute('id') && !$this->params()->fromQuery('id')) ? [] : $runner->run($request, 'Favorites', $setupCallback))];
            }

            $args = $this->getRequest()->getQuery()->toArray();
            $listToShow = ($listFound && isset($_COOKIE["mostRecentList"])) ? $_COOKIE["mostRecentList"] : $lists[0]->id;
            $sort = isset($args["sort"]) ? $args["sort"] : "title";
            return $this->createViewModel(
                ['results' => $results, 'showList' => $listToShow, 'sort' => $sort]
            );
        } catch (ListPermissionException $e) {
            if (!$this->getUser()) {
                return $this->forceLogin();
            }
            throw $e;
        }
    }

    /**
     * Process the "edit list" submission.
     *
     * @param \VuFind\Db\Row\User     $user Logged in user
     * @param \VuFind\Db\Row\UserList $list List being created/edited
     *
     * @return object|bool                  Response object if redirect is
     * needed, false if form needs to be redisplayed.
     */
    protected function processEditList($user, $list, $isNew)
    {
        // Process form within a try..catch so we can handle errors appropriately:
        try {
            $finalId
                = $list->updateFromRequest($user, $this->getRequest()->getPost());

            // If the user is in the process of saving a record, send them back
            // to the save screen; otherwise, send them back to the list they
            // just edited.
            $recordId = $this->params()->fromQuery('recordId', $this->params()->fromPost('recordId'));
            $recordSource = $this->params()->fromQuery('recordSource', 'VuFind');
            if (!empty($recordId)) {
                $this->favorites()->saveBulk(['ids' => (is_array($recordId) ? $recordId : [$recordSource."|".$recordId]), 'list' => $finalId], $user);

                // success message
                $this->flashMessenger()->setNamespace('info')->addMessage(is_array($recordId) ? 'list_create_add_multiple' : 'list_create_add_single');
                $view = $this->createViewModel();
                $view->setTemplate('blankModal');
                return $view;
            }

            // Similarly, if the user is in the process of bulk-saving records,
            // send them back to the appropriate place in the cart.
            $bulkIds = $this->params()->fromPost(
                'ids', $this->params()->fromQuery('ids', [])
            );
            if (!empty($bulkIds)) {
                $params = [];
                foreach ($bulkIds as $id) {
                    $params[] = urlencode('ids[]') . '=' . urlencode($id);
                }
                $saveUrl = $this->getLightboxAwareUrl('cart-save');
                $saveUrl .= (strpos($saveUrl, '?') === false) ? '?' : '&';
                return $this->redirect()
                    ->toUrl($saveUrl . implode('&', $params));
            }

            $this->flashMessenger()->setNamespace('info')->addMessage($isNew ? 'list_create' : 'edit_list_success');
            $view = $this->createViewModel();
            $view->setTemplate('blankModal');
            return $view;
        } catch (\Exception $e) {
            switch(get_class($e)) {
            case 'VuFind\Exception\ListSize':
            case 'VuFind\Exception\ListPermission':
            case 'VuFind\Exception\MissingField':
                $this->flashMessenger()->addMessage($e->getMessage(), 'error');
                return false;
            case 'VuFind\Exception\LoginRequired':
                return $this->forceLogin();
            default:
                throw $e;
            }
        }
    }

    /**
     * Send user's saved favorites from a particular list to the edit view
     *
     * @return mixed
     */
    public function editlistAction()
    {
        // Fail if lists are disabled:
        if (!$this->listsEnabled()) {
            throw new \Exception('Lists disabled');
        }

        // User must be logged in to edit list:
        $user = $this->getUser();
        if ($user == false) {
            return $this->forceLogin();
        }

        // Is this a new list or an existing list?  Handle the special 'NEW' value
        // of the ID parameter:
        $id = $this->params()->fromRoute('id', $this->params()->fromQuery('id'));
        $recordId = $this->params()->fromRoute('recordId', $this->params()->fromQuery('recordId', $this->params()->fromPost('recordId')));
        $table = $this->getTable('UserList');
        $newList = ($id == 'NEW');
        $list = $newList ? $table->getNew($user) : $table->getExisting($id);

        // Make sure the user isn't fishing for other people's lists:
        if (!$newList && !$list->editAllowed($user)) {
            throw new ListPermissionException('Access denied.');
        }

        // Process form submission:
        if ($this->formWasSubmitted('submit')) {
            if ($redirect = $this->processEditList($user, $list, $newList)) {
                return $redirect;
            }
        }

        // Send the list to the view:
        $args = ['list' => $list, 'newList' => $newList, 'recordId' => $recordId];
        if( $this->params()->fromPost("createListBulk") != null ) {
            $args["bulkAction"] = "createListBulk";
        }
        return $this->createViewModel($args);
    }

    /**
     * Creates a confirmation box to delete or not delete the current list
     *
     * @return mixed
     */
    public function deletelistAction()
    {
        // Fail if lists are disabled:
        if (!$this->listsEnabled()) {
            throw new \Exception('Lists disabled');
        }

        // Get requested list ID:
        $listID = $this->params()->fromPost('id', $this->params()->fromQuery('id'));

        // Have we confirmed this?
        $confirm = $this->params()->fromPost(
            'confirm', $this->params()->fromQuery('confirm')
        );
        if ($confirm) {
            try {
                $table = $this->getTable('UserList');
                $list = $table->getExisting($listID);
                $list->delete($this->getUser());

                // Success Message
                $this->flashMessenger()->addMessage('fav_list_delete', 'info');
            } catch (\Exception $e) {
                switch(get_class($e)) {
                case 'VuFind\Exception\LoginRequired':
                case 'VuFind\Exception\ListPermission':
                    $user = $this->getUser();
                    if ($user == false) {
                        return $this->forceLogin();
                    }
                    // Logged in? Fall through to default case!
                default:
                    throw $e;
                }
            }
            // Redirect to MyResearch home
            setcookie("mostRecentList", "", time() - 1209600, '/');
            $view = $this->createViewModel();
            $view->setTemplate('blankModal');
            return $view;
        }

        // If we got this far, we must display a confirmation message:
        return $this->confirm(
            'confirm_delete_list_brief',
            $this->url()->fromRoute('myresearch-deletelist'),
            $this->url()->fromRoute('userList', ['id' => $listID]),
            'confirm_delete_list_text', ['id' => $listID]
        );
    }

    /**
     * Get a record driver object corresponding to an array returned by an ILS
     * driver's getMyHolds / getMyTransactions method.
     *
     * @param array $current Record information
     *
     * @return \VuFind\RecordDriver\AbstractBase
     */
    protected function getDriverForILSRecord($current)
    {
        $id = isset($current['id']) ? $current['id'] : null;
        $source = isset($current['source']) ? $current['source'] : 'VuFind';
        $record = $this->getServiceLocator()->get('VuFind\RecordLoader')
            ->load($id, $source, true);
        $record->setExtraDetail('ils_details', $current);
        return $record;
    }

    /**
     * Send list of holds to view
     *
     * @return mixed
     */
    public function holdsAction()
    {
        // Stop now if the user does not have valid catalog credentials available:
        if (!is_array($patron = $this->catalogLogin())) {
            return $patron;
        }

        // Connect to the ILS:
        $catalog = $this->getILS();
        $view = $this->createViewModel();

        // see if we are trying to change the pickup location
        if( $this->params()->fromPost('changePickup') ) {
            if( $this->params()->fromPost('placeHold') ) {
                $view->updateResults = $this->holds()->updateHolds($catalog, $patron);
                $view->setTemplate('blankModal');
                $view->suppressFlashMessages = true;
                $view->reloadParent = true;
                return $view;
            } else {
                $view->setTemplate('record/hold');
                $view->referrer = $this->params()->fromPost('referrer');
                $view->changePickup = true;
                $view->skip = true;
                $view->pickup = $catalog->getPickUpLocations($patron);
                $view->homeLibrary = $this->getUser()->home_library;
                $view->preferredLibrary = $this->getUser()->preferred_library;
                $view->alternateLibrary = $this->getUser()->alternate_library;
                $view->ids = $this->params()->fromPost('ids');
                return $view;
            }
        }

        // see if we are trying to change the notification email
        if( $this->params()->fromPost('changeEmail') ) {
            if( $this->params()->fromPost('placeHold') ) {
                $view->updateResults = $this->holds()->updateHolds($catalog, $patron);
                $view->setTemplate('blankModal');
                $view->suppressFlashMessages = true;
                $view->reloadParent = true;
                return $view;
            } else {
                $view->setTemplate('record/email');
                $view->referrer = $this->params()->fromPost('referrer');
                $view->skip = true;
                $view->ids = $this->params()->fromPost('ids');
                return $view;
            }
        }

        // see if we are trying to do a bulk hold
        if( $this->params()->fromPost('bulkHold') ) {
            if( $this->params()->fromPost('placeHold') ) {
                $view->updateResults = $this->holds()->createHolds($catalog, $patron);
                $view->setTemplate('blankModal');
                $view->suppressFlashMessages = true;
                $view->reloadParent = true;
                return $view;
            } else {
                $view->setTemplate('record/hold');
                $view->referrer = $this->params()->fromPost('referrer');
                $view->bulkHold = true;
                $view->skip = true;
                $view->pickup = $catalog->getPickUpLocations($patron);
                $view->homeLibrary = $this->getUser()->home_library;
                $view->preferredLibrary = $this->getUser()->preferred_library;
                $view->alternateLibrary = $this->getUser()->alternate_library;
                $rawIds = $this->params()->fromPost('ids');
                $ids = [];
                foreach($rawIds as $id) {
                    $ids[] = explode("|", $id)[1];
                }
                $view->ids = $ids;
                $rawTitles = $this->params()->fromPost('holdTitles');
                $titles = [];
                foreach($rawTitles as $title) {
                    $titles[] = explode("|", $title, 2)[1];
                }
                $view->titles = $titles;
                $rawHasVolumesTitles = $this->params()->fromPost('hasVolumesTitles');
                $hasVolumesTitles = [];
                foreach($rawHasVolumesTitles as $title) {
                    $hasVolumesTitles[] = explode("|", $title, 2)[1];
                }
                $view->hasVolumesTitles = $hasVolumesTitles;
                $rawLocalCopyTitles = $this->params()->fromPost('localCopyTitles');
                $localCopyTitles = [];
                foreach($rawLocalCopyTitles as $title) {
                    $localCopyTitles[] = explode("|", $title, 2)[1];
                }
                $view->localCopyTitles = $localCopyTitles;
                return $view;
            }
        }

        // Process cancel requests if necessary:
        $cancelStatus = $catalog->checkFunction('cancelHolds', compact('patron'));
        $view->cancelResults = $cancelStatus
            ? $this->holds()->cancelHolds($catalog, $patron) : [];
        // If we need to confirm
        if (!is_array($view->cancelResults)) {
            return $view->cancelResults;
        }

        // Process freeze requests if necessary:
        $freezeStatus = $catalog->checkFunction('freezeHolds', compact('patron'));
        $view->cancelResults = $freezeStatus
            ? $this->holds()->freezeHolds($catalog, $patron) : [];
        // If we need to confirm
        if (!is_array($view->cancelResults)) {
            return $view->cancelResults;
        }

        // Process unfreeze requests if necessary:
        $unfreezeStatus = $catalog->checkFunction('freezeHolds', compact('patron'));
        $view->cancelResults = $unfreezeStatus
            ? $this->holds()->unfreezeHolds($catalog, $patron) : [];
        // If we need to confirm
        if (!is_array($view->cancelResults)) {
            return $view->cancelResults;
        }

        // tell the view if it needs to hide the flash messages
        if( $this->params()->fromPost('suppressFlashMessages') ) {
            $view->suppressFlashMessages = $this->params()->fromPost('suppressFlashMessages');
        }

        // Get held item details:
        $result = $catalog->getMyHolds($patron, $this->params()->fromPost('reloadHolds'));
        $holdList = ['ready' => [], 'transit' => [], 'hold' => [], 'frozen' => []];
        $this->holds()->resetValidation();
        foreach ($result as $current) {
            // Add cancel details if appropriate:
            $current = $this->holds()->addCancelDetails(
                $catalog, $current, $cancelStatus
            );
            if ($cancelStatus && $cancelStatus['function'] != "getCancelHoldLink"
                && isset($current['cancel_details'])
            ) {
                // Enable cancel form if necessary:
                $view->cancelForm = true;
            }

            // Build record driver:
            $current["driver"] = $this->getDriverForILSRecord($current);
            $group = (($current["status"] == "i") || ($current["status"] == "b") || ($current["status"] == "j")) ? 'ready' : (($current["status"] == "t") ? 'transit' : ($current["frozen"] ? 'frozen' : 'hold'));
            $key = ((isset($current["ILL"]) && $current["ILL"]) ? $current["title"] : $current["driver"]->GetTitle()).$current["hold_id"];
            $holdList[$group][$key] = $current;
        }
        $allList = [];
        $allPhysical = [];
        $allEcontent = [];
        $user = $this->getUser();
        foreach($holdList as $name => $grouping) {
            // if they're splitting econtent, bubble those to the bottom
            if( $user['splitEcontent'] == "Y" ) {
                $physical = [];
                $econtent = [];
                foreach( $grouping as $key => $thisItem ) {
                    if( isset($thisItem["overDriveId"]) ) {
                        $econtent[$key] = $thisItem;
                    } else {
                        $physical[$key] = $thisItem;
                    }
                }
                ksort($physical);
                ksort($econtent);
                $allPhysical = array_merge($allPhysical, $physical);
                $allEcontent = array_merge($allEcontent, $econtent);
            } else {
                ksort($grouping);
                $holdList[$name] = $grouping;
                $allList = array_merge($allList, $holdList[$name]);
            }
        }

        // if they're splitting econtent, bubble those to the bottom
        if( $user['splitEcontent'] == "Y" ) {
            $allList = array_merge($allPhysical, $allEcontent);
        }
        $holdList['all'] = $allList;

        $view->splitEcontent = ($user['splitEcontent'] == "Y");

        // Get List of PickUp Libraries based on patron's home library
        try {
            $view->pickup = $catalog->getPickUpLocations($patron);
        } catch (\Exception $e) {
            // Do nothing; if we're unable to load information about pickup
            // locations, they are not supported and we should ignore them.
        }
        $view->holdList = $holdList;
        $view->showHoldType = isset($_COOKIE["holdsTab"]) ? $_COOKIE["holdsTab"] : "all";
        return $view;
    }

    /**
     * Send list of storage retrieval requests to view
     *
     * @return mixed
     */
    public function storageRetrievalRequestsAction()
    {
        // Stop now if the user does not have valid catalog credentials available:
        if (!is_array($patron = $this->catalogLogin())) {
            return $patron;
        }

        // Connect to the ILS:
        $catalog = $this->getILS();

        // Process cancel requests if necessary:
        $cancelSRR = $catalog->checkFunction(
            'cancelStorageRetrievalRequests', compact('patron')
        );
        $view = $this->createViewModel();
        $view->cancelResults = $cancelSRR
            ? $this->storageRetrievalRequests()->cancelStorageRetrievalRequests(
                $catalog, $patron
            )
            : [];
        // If we need to confirm
        if (!is_array($view->cancelResults)) {
            return $view->cancelResults;
        }

        // By default, assume we will not need to display a cancel form:
        $view->cancelForm = false;

        // Get request details:
        $result = $catalog->getMyStorageRetrievalRequests($patron);
        $recordList = [];
        $this->storageRetrievalRequests()->resetValidation();
        foreach ($result as $current) {
            // Add cancel details if appropriate:
            $current = $this->storageRetrievalRequests()->addCancelDetails(
                $catalog, $current, $cancelSRR, $patron
            );
            if ($cancelSRR
                && $cancelSRR['function'] != "getCancelStorageRetrievalRequestLink"
                && isset($current['cancel_details'])
            ) {
                // Enable cancel form if necessary:
                $view->cancelForm = true;
            }

            // Build record driver:
            $recordList[] = $this->getDriverForILSRecord($current);
        }

        // Get List of PickUp Libraries based on patron's home library
        try {
            $view->pickup = $catalog->getPickUpLocations($patron);
        } catch (\Exception $e) {
            // Do nothing; if we're unable to load information about pickup
            // locations, they are not supported and we should ignore them.
        }
        $view->recordList = $recordList;
        return $view;
    }

    /**
     * Send list of ill requests to view
     *
     * @return mixed
     */
    public function illRequestsAction()
    {
        // Stop now if the user does not have valid catalog credentials available:
        if (!is_array($patron = $this->catalogLogin())) {
            return $patron;
        }

        // Connect to the ILS:
        $catalog = $this->getILS();

        // Process cancel requests if necessary:
        $cancelStatus = $catalog->checkFunction(
            'cancelILLRequests', compact('patron')
        );
        $view = $this->createViewModel();
        $view->cancelResults = $cancelStatus
            ? $this->ILLRequests()->cancelILLRequests(
                $catalog, $patron
            )
            : [];
        // If we need to confirm
        if (!is_array($view->cancelResults)) {
            return $view->cancelResults;
        }

        // By default, assume we will not need to display a cancel form:
        $view->cancelForm = false;

        // Get request details:
        $result = $catalog->getMyILLRequests($patron);
        $recordList = [];
        $this->ILLRequests()->resetValidation();
        foreach ($result as $current) {
            // Add cancel details if appropriate:
            $current = $this->ILLRequests()->addCancelDetails(
                $catalog, $current, $cancelStatus, $patron
            );
            if ($cancelStatus
                && $cancelStatus['function'] != "getCancelILLRequestLink"
                && isset($current['cancel_details'])
            ) {
                // Enable cancel form if necessary:
                $view->cancelForm = true;
            }

            // Build record driver:
            $recordList[] = $this->getDriverForILSRecord($current);
        }

        $view->recordList = $recordList;
        return $view;
    }

    /**
     * Send list of checked out books to view
     *
     * @return mixed
     */
    public function checkedoutAction()
    {
        // Stop now if the user does not have valid catalog credentials available:
        if (!is_array($patron = $this->catalogLogin())) {
            return $patron;
        }

        // Connect to the ILS:
        $catalog = $this->getILS();

        // Get the current renewal status and process renewal form, if necessary:
        $view = $this->createViewModel();
        $renewStatus = $catalog->checkFunction('Renewals', compact('patron'));
        $renewResult = $renewStatus
            ? $this->renewals()->processRenewals(
                $this->getRequest()->getPost(), $catalog, $patron
            )
            : [];
        // If we need to confirm
        if (!is_array($renewResult)) {
            return $renewResult;
        // we processed some renewals
        } else if( count($renewResult) > 0 ) {
            // Get target URL for after deletion:
            $checkoutType = 'all';
            setcookie("checkoutTab", $checkoutType, time() + 3600, '/');

            // Process the renews:
            $view = $this->createViewModel(['results' => $renewResult]);
            $view->setTemplate('myresearch/renewResults');
            return $view;
        }

        // tell the view if it needs to hide the flash messages
        if( $this->params()->fromPost('suppressFlashMessages') ) {
            $view->suppressFlashMessages = $this->params()->fromPost('suppressFlashMessages');
        }

        // Get held item details:
        $result = $catalog->getMyTransactions($patron, $this->params()->fromPost('reloadCheckouts'));
        $checkoutList = ['overdue' => [], 'due_this_week' => [], 'other' => []];
        foreach ($result as $current) {
            $current["dateDiff"] = date_diff(date_create($current["duedate"]), date_create(date("Y-m-d")));

            // Build record driver:
            $current["driver"] = $this->getDriverForILSRecord($current);
            $checkoutList[(($current["dateDiff"]->invert == 0) && ($current["dateDiff"]->days != 0)) ? 'overdue' : (($current["dateDiff"]->days <= 7) ? 'due_this_week' : 'other')][] = $current;
        }

        // sort lists by due date, then title
        $allList = [];
        $user = $this->getUser();
        foreach( $checkoutList as $key => $thisList ) {
            // if they're splitting econtent, bubble those to the bottom
            if( $user['splitEcontent'] == "Y" ) {
                usort($checkoutList[$key], function($co1, $co2) {
                    if(!isset($co1["overDriveId"]) && isset($co2["overDriveId"])) {
                        return -1;
                    } else if(isset($co1["overDriveId"]) && !isset($co2["overDriveId"])) {
                        return 1;
                    } else if($co1["duedate"] > $co2["duedate"]) {
                        return 1;
                    } else if($co1["duedate"] < $co2["duedate"]) {
                        return -1;
                    } 
                    $t1 = isset($co1["title"]) ? $co1["title"] : $co1["driver"]->getTitle();
                    $t2 = isset($co2["title"]) ? $co2["title"] : $co2["driver"]->getTitle();
                    if($t1 > $t2) {
                        return 1;
                    } else if($t1 < $t2) {
                        return -1;
                    } else {
                        return 0;
                    }
                } );
            // otherwise, normal sort
            } else {
                usort($checkoutList[$key], function($co1, $co2) {
                    if($co1["duedate"] > $co2["duedate"]) {
                        return 1;
                    } else if($co1["duedate"] < $co2["duedate"]) {
                        return -1;
                    } 
                    $t1 = isset($co1["title"]) ? $co1["title"] : $co1["driver"]->getTitle();
                    $t2 = isset($co2["title"]) ? $co2["title"] : $co2["driver"]->getTitle();
                    if($t1 > $t2) {
                        return 1;
                    } else if($t1 < $t2) {
                        return -1;
                    } else {
                        return 0;
                    }
                } );
            }
            $allList = array_merge($allList, $checkoutList[$key]);
        }

        // if they're splitting econtent, bubble those to the bottom
        if( $user['splitEcontent'] == "Y" ) {
            usort($allList, function($co1, $co2) {
                if(!isset($co1["overDriveId"]) && isset($co2["overDriveId"])) {
                    return -1;
                } else if(isset($co1["overDriveId"]) && !isset($co2["overDriveId"])) {
                    return 1;
                } else if($co1["duedate"] > $co2["duedate"]) {
                    return 1;
                } else if($co1["duedate"] < $co2["duedate"]) {
                    return -1;
                } 
                $t1 = isset($co1["title"]) ? $co1["title"] : $co1["driver"]->getTitle();
                $t2 = isset($co2["title"]) ? $co2["title"] : $co2["driver"]->getTitle();
                if($t1 > $t2) {
                    return 1;
                } else if($t1 < $t2) {
                    return -1;
                } else {
                    return 0;
                }
            } );
        }
        $checkoutList['all'] = $allList;

        $view->splitEcontent = ($user['splitEcontent'] == "Y");
        $view->checkoutList = $checkoutList;
        $view->showCheckoutType = isset($_COOKIE["checkoutTab"]) ? $_COOKIE["checkoutTab"] : "all";
        return $view;
    }

    /**
     * Send list of fines to view
     *
     * @return mixed
     */
    public function finesAction()
    {
        // Stop now if the user does not have valid catalog credentials available:
        if (!is_array($patron = $this->catalogLogin())) {
            return $patron;
        }

        // Connect to the ILS:
        $catalog = $this->getILS();

        // Get fine details:
        $result = $catalog->getMyFines($patron);
        $fines = [];
        foreach ($result as $row) {
            // Attempt to look up and inject title:
            try {
                if (!isset($row['id']) || empty($row['id'])) {
                    throw new \Exception();
                }
                $source = isset($row['source']) ? $row['source'] : 'VuFind';
                $row['driver'] = $this->getServiceLocator()
                    ->get('VuFind\RecordLoader')->load($row['id'], $source);
                $row['title'] = $row['driver']->getShortTitle();
            } catch (\Exception $e) {
                if (!isset($row['title'])) {
                    $row['title'] = null;
                }
            }
            $fines[] = $row;
        }

        return $this->createViewModel(['fines' => $fines]);
    }

    /**
     * Convenience method to get a session initiator URL. Returns false if not
     * applicable.
     *
     * @return string|bool
     */
    protected function getSessionInitiator()
    {
        $url = $this->getServerUrl('myresearch-home');
        return $this->getAuthManager()->getSessionInitiator($url);
    }

    /**
     * Send account recovery email
     *
     * @return View object
     */
    public function recoverAction()
    {
        // Make sure we're configured to do this
        $this->setUpAuthenticationFromRequest();
        if (!$this->getAuthManager()->supportsRecovery()) {
            $this->flashMessenger()->addMessage('recovery_disabled', 'error');
            return $this->redirect()->toRoute('myresearch-home');
        }
        if ($this->getUser()) {
            return $this->redirect()->toRoute('myresearch-home');
        }
        // Database
        $table = $this->getTable('User');
        $user = false;
        // Check if we have a submitted form, and use the information
        // to get the user's information
        if ($email = $this->params()->fromPost('email')) {
            $user = $table->getByEmail($email);
        } elseif ($username = $this->params()->fromPost('username')) {
            $user = $table->getByCatUsername($username, false);
        }
        $view = $this->createViewModel();
        $view->useRecaptcha = $this->recaptcha()->active('passwordRecovery');
        // If we have a submitted form
        if ($this->formWasSubmitted('submit', $view->useRecaptcha)) {
            if ($user) {
                $this->sendRecoveryEmail($user, $this->getConfig());
            } else {
                $this->flashMessenger()
                    ->addMessage('recovery_user_not_found', 'error');
            }
        }
        return $view;
    }

    /**
     * Helper function for recoverAction
     *
     * @param \VuFind\Db\Row\User $user   User object we're recovering
     * @param \VuFind\Config      $config Configuration object
     *
     * @return void (sends email or adds error message)
     */
    protected function sendRecoveryEmail($user, $config)
    {
        // If we can't find a user
        if (null == $user) {
            $this->flashMessenger()->addMessage('recovery_user_not_found', 'error');
        } else {
            // Make sure we've waiting long enough
            $hashtime = $this->getHashAge($user->verify_hash);
            $recoveryInterval = isset($config->Authentication->recover_interval)
                ? $config->Authentication->recover_interval
                : 60;
            if (time() - $hashtime < $recoveryInterval) {
                $this->flashMessenger()->addMessage('recovery_too_soon', 'error');
            } else {
                // Attempt to send the email
                try {
                    // Create a fresh hash
                    $user->updateHash();
                    $config = $this->getConfig();
                    $renderer = $this->getViewRenderer();
                    $method = $this->getAuthManager()->getAuthMethod();
                    // Custom template for emails (text-only)
                    $message = $renderer->render(
                        'Email/recover-password.phtml',
                        [
                            'library' => $config->Site->title,
                            'url' => $this->getServerUrl('myresearch-verify')
                                . '?hash='
                                . $user->verify_hash . '&auth_method=' . $method
                        ]
                    );
                    $this->getServiceLocator()->get('VuFind\Mailer')->send(
                        $user->email,
                        $config->Site->email,
                        $this->translate('recovery_email_subject'),
                        $message
                    );
                    $this->flashMessenger()
                        ->addMessage('recovery_email_sent', 'success');
                } catch (MailException $e) {
                    $this->flashMessenger()->addMessage($e->getMessage(), 'error');
                }
            }
        }
    }

    /**
     * Receive a hash and display the new password form if it's valid
     *
     * @return view
     */
    public function verifyAction()
    {
        // If we have a submitted form
        if ($hash = $this->params()->fromQuery('hash')) {
            $hashtime = $this->getHashAge($hash);
            $config = $this->getConfig();
            // Check if hash is expired
            $hashLifetime = isset($config->Authentication->recover_hash_lifetime)
                ? $config->Authentication->recover_hash_lifetime
                : 1209600; // Two weeks
            if (time() - $hashtime > $hashLifetime) {
                $this->flashMessenger()
                    ->addMessage('recovery_expired_hash', 'error');
                return $this->forwardTo('MyResearch', 'Login');
            } else {
                $table = $this->getTable('User');
                $user = $table->getByVerifyHash($hash);
                // If the hash is valid, forward user to create new password
                if (null != $user) {
                    $this->setUpAuthenticationFromRequest();
                    $view = $this->createViewModel();
                    $view->auth_method
                        = $this->getAuthManager()->getAuthMethod();
                    $view->hash = $hash;
                    $view->username = $user->username;
                    $view->useRecaptcha
                        = $this->recaptcha()->active('passwordRecovery');
                    $view->setTemplate('myresearch/newpassword');
                    return $view;
                }
            }
        }
        $this->flashMessenger()->addMessage('recovery_invalid_hash', 'error');
        return $this->forwardTo('MyResearch', 'Login');
    }

    /**
     * Handling submission of a new password for a user.
     *
     * @return view
     */
    public function newPasswordAction()
    {
        // Have we submitted the form?
        if (!$this->formWasSubmitted('submit')) {
            return $this->redirect()->toRoute('home');
        }
        // Pull in from POST
        $request = $this->getRequest();
        $post = $request->getPost();
        // Verify hash
        $userFromHash = isset($post->hash)
            ? $this->getTable('User')->getByVerifyHash($post->hash)
            : false;
        // View, password policy and reCaptcha
        $view = $this->createViewModel($post);
        $view->passwordPolicy = $this->getAuthManager()
            ->getPasswordPolicy();
        $view->useRecaptcha = $this->recaptcha()->active('changePassword');
        // Check reCaptcha
        if (!$this->formWasSubmitted('submit', $view->useRecaptcha)) {
            return $view;
        }
        // Missing or invalid hash
        if (false == $userFromHash) {
            $this->flashMessenger()->addMessage('recovery_user_not_found', 'error');
            // Force login or restore hash
            $post->username = false;
            return $this->forwardTo('MyResearch', 'Recover');
        } elseif ($userFromHash->username !== $post->username) {
            $this->flashMessenger()
                ->addMessage('authentication_error_invalid', 'error');
            $userFromHash->updateHash();
            $view->username = $userFromHash->username;
            $view->hash = $userFromHash->verify_hash;
            return $view;
        }
        // Verify old password if we're logged in
        if ($this->getUser()) {
            if (isset($post->oldpwd)) {
                // Reassign oldpwd to password in the request so login works
                $tempPassword = $post->password;
                $post->password = $post->oldpwd;
                $valid = $this->getAuthManager()->validateCredentials($request);
                $post->password = $tempPassword;
            } else {
                $valid = false;
            }
            if (!$valid) {
                $this->flashMessenger()
                    ->addMessage('authentication_error_invalid', 'error');
                $view->verifyold = true;
                return $view;
            }
        }
        // Update password
        try {
            $user = $this->getAuthManager()->updatePassword($this->getRequest());
        } catch (AuthException $e) {
            $this->flashMessenger()->addMessage($e->getMessage(), 'error');
            return $view;
        }
        // Update hash to prevent reusing hash
        $user->updateHash();
        // Login
        $this->getAuthManager()->login($this->request);
        // Go to favorites
        $this->flashMessenger()->addMessage('new_password_success', 'success');
        return $this->redirect()->toRoute('myresearch-home');
    }

    /**
     * Handling submission of a new password for a user.
     *
     * @return view
     */
    public function changePasswordAction()
    {
        if (!$this->getAuthManager()->isLoggedIn()) {
            return $this->forceLogin();
        }
        // If not submitted, are we logged in?
        if (!$this->getAuthManager()->supportsPasswordChange()) {
            $this->flashMessenger()->addMessage('recovery_new_disabled', 'error');
            return $this->redirect()->toRoute('home');
        }
        $view = $this->createViewModel($this->params()->fromPost());
        // Verify user password
        $view->verifyold = true;
        // Display username
        $user = $this->getUser();
        $view->username = $user->username;
        // Password policy
        $view->passwordPolicy = $this->getAuthManager()
            ->getPasswordPolicy();
        // Identification
        $user->updateHash();
        $view->hash = $user->verify_hash;
        $view->setTemplate('myresearch/newpassword');
        $view->useRecaptcha = $this->recaptcha()->active('changePassword');
        return $view;
    }

    /**
     * Helper function for verification hashes
     *
     * @param string $hash User-unique hash string from request
     *
     * @return int age in seconds
     */
    protected function getHashAge($hash)
    {
        return intval(substr($hash, -10));
    }

    /**
     * Configure the authentication manager to use a user-specified method.
     *
     * @return void
     */
    protected function setUpAuthenticationFromRequest()
    {
        $method = trim($this->params()->fromQuery('auth_method'));
        if (!empty($method)) {
            $this->getAuthManager()->setAuthMethod($method);
        }
    }

    /**
     * Show patron a list of notifications
     *
     * @return view
     */
    public function notificationsAction()
    {
        // Stop now if the user does not have valid catalog credentials available:
        if (!is_array($patron = $this->catalogLogin())) {
            return $patron;
        }

        // see whether they want to see a single message or not
        $catalog = $this->getILS();
        $profile = $catalog->getMyProfile($patron);
        $view = $this->createViewModel();
        if( $this->params()->fromPost('showMessage') ) {
            $view->setTemplate('myresearch/showMessage');
            $view->subject = $this->params()->fromPost('subject');
            $view->message = $this->params()->fromPost('message');
        } else {
            $view->notifications = $catalog->getNotifications($profile);
        }
        return $view;
    }

    /**
     * Show patron their reading history
     *
     * @return view
     */
    public function readingHistoryAction()
    {
        // Stop now if the user does not have valid catalog credentials available:
        if (!is_array($patron = $this->catalogLogin())) {
            return $patron;
        }

        // see if they're trying to submit an action
        $catalog = $this->getILS();
        if( $action = $this->params()->fromPost('readingHistoryAction') ) {
            $selectedIDs = $this->params()->fromPost('ids');
            if( $action == "deleteMarked" && !$this->params()->fromPost('confirm') ) {
                $replacement = ((count($selectedIDs) > 1) ? (count($selectedIDs) . " items") : "item") . " from your reading history?<br>";
                foreach($this->params()->fromPost('holdTitles') as $title) {
                    $replacement .= "<br><span class=\"bold\">Title: </span>" . urldecode($title);
                }
                $msg = [['msg' => 'confirm_history_delete_selected_text', 
                         'html' => true, 
                         'tokens' => ['%%deleteData%%' => $replacement]]];
                return $this->confirm(
                    'reading_history_delete_selected',
                    $this->url()->fromRoute('myresearch-readinghistory'),
                    $this->url()->fromRoute('myresearch-readinghistory'),
                    $msg,
                    [
                        'history' => 'History',
                        'readingHistoryAction' => 'deleteMarked',
                        'ids' => $selectedIDs
                    ]
                );
            }
            $result = $catalog->doReadingHistoryAction($patron, $action, $selectedIDs);
            if( $action == "optIn" ) {
                $this->flashMessenger()->addMessage('reading_history_enabled_success', 'info');
            } else if( $action == "optOut" ) {
                if( strpos( $result, "You cannot optout while there is reading history" ) !== false ) {
                    $this->flashMessenger()->addMessage('reading_history_disabled_failure_delete_all', 'error');
                } else {
                    $this->flashMessenger()->addMessage('reading_history_disabled_success', 'info');
                }
            } else if( $action == "deleteMarked" ) {
                $this->flashMessenger()->addMessage((count($this->params()->fromPost('ids')) > 1) ? 'reading_history_delete_success_multiple' : 'reading_history_delete_success_single', 'info');
                $view = $this->createViewModel();
                $view->setTemplate('blankModal');
                $view->suppressFlashMessages = true;
                $view->reloadParent = true;
                return $view;
            }
        }

        $readingHistory = $catalog->getReadingHistory($patron, ($this->params()->fromQuery("pageNum") ? $this->params()->fromQuery("pageNum") : 1), -1, ($this->params()->fromQuery("sort") ? $this->params()->fromQuery("sort") : "date"));
        foreach( $readingHistory["titles"] as $key => $item ) {
            try{
                $item["driver"] = $this->getServiceLocator()->get('VuFind\RecordLoader')->load($item['id'] . $catalog->getCheckDigit(substr($item['id'], 2)), 'VuFind');
            } catch(RecordMissingException $e) {
            }
            $readingHistory["titles"][$key] = $item;
        }
        
        $view = $this->createViewModel();
        $view->sort = $this->params()->fromQuery("sort");
        $view->readingHistory = $readingHistory;
        $view->indexOffset = ($this->params()->fromQuery("pageNum") ? (($this->params()->fromQuery("pageNum") - 1) * 50) : 0) + 1;
        return $view;
    }

    /**
     * Handle the response from the OverDrive API
     *
     * @return view
     */
    public function overdriveHandlerAction()
    {
        if( $this->params()->fromQuery('ErrorCode') ) {
            $this->flashMessenger()->addMessage('overdrive_request_error', 'error');
        } else {
            $this->flashMessenger()->addMessage('overdrive_request_success', 'info');
            $this->getILS()->clearSessionVar("checkouts");
        }
        $targetURL = $this->getILS()->getSessionVar("parentURL");
        $this->getILS()->clearSessionVar("parentURL");
        return $this->redirect()->toUrl($targetURL);
    }

    /**
     * Load content in the background.  Can't do this via Ajax because they kill the session.
     */
    public function backgroundLoaderAction()
    {
        $patron = $this->catalogLogin();
        // Stop now if the user does not have valid catalog credentials available:
        if( is_array($patron) ) {
            // they want us to load holds
            if ( $this->params()->fromQuery('content') == "holds" ) {
                // Connect to the ILS:
                $catalog = $this->getILS();
                $holds = $catalog->getMyHolds($patron);
            // they want us to load checkouts
            } else if ( $this->params()->fromQuery('content') == "checkouts" ) {
                // Connect to the ILS:
                $catalog = $this->getILS();
                $holds = $catalog->getMyTransactions($patron);
            }
        }
        $view = $this->createViewModel();
        $view->setTemplate('blankModal');
        $view->suppressFlashMessages = true;
        return $view;
    }

    /**
     * Flag this announcement to not appear anymore until they log out
     */
    public function dismissAnnouncementAction()
    {
        // Connect to the ILS:
        $this->getILS()->dismissAnnouncement($this->params()->fromQuery('hash'));

        // return a blank
        $view = $this->createViewModel();
        $view->setTemplate('blankModal');
        $view->suppressFlashMessages = true;
        return $view;
    }
}
