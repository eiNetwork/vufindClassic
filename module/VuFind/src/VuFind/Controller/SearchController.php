<?php
/**
 * Default Controller
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

use VuFind\Exception\Mail as MailException;

/**
 * Redirects the user to the appropriate default VuFind action.
 *
 * @category VuFind2
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
class SearchController extends AbstractSearch
{
    /**
     * Handle an advanced search
     *
     * @return mixed
     */
    public function advancedAction()
    {
        // Standard setup from base class:
        $view = parent::advancedAction();

        // Set up facet information:
        $view->facetList = $this->processAdvancedFacets(
            $this->getAdvancedFacets()->getFacetList(), $view->saved
        );
        $specialFacets = $this->parseSpecialFacetsSetting(
            $view->options->getSpecialAdvancedFacets()
        );
        if (isset($specialFacets['illustrated'])) {
            $view->illustratedLimit
                = $this->getIllustrationSettings($view->saved);
        }
        if (isset($specialFacets['checkboxes'])) {
            $view->checkboxFacets = $this->processAdvancedCheckboxes(
                $specialFacets['checkboxes'], $view->saved
            );
        }
        $view->formatCategories = $this->getConfig()->FormatCategories;
        $view->ranges = $this->getAllRangeSettings($specialFacets, $view->saved);
        $view->hierarchicalFacets = $this->getHierarchicalFacets();

        return $view;
    }

    /**
     * Email action - Allows the email form to appear.
     *
     * @return mixed
     */
    public function emailAction()
    {
        // If a URL was explicitly passed in, use that; otherwise, try to
        // find the HTTP referrer.
        $mailer = $this->getServiceLocator()->get('VuFind\Mailer');
        $view = $this->createEmailViewModel(null, $mailer->getDefaultLinkSubject());
        $mailer->setMaxRecipients($view->maxRecipients);
        // Set up reCaptcha
        $view->useRecaptcha = $this->recaptcha()->active('email');
        $view->url = $this->params()->fromPost(
            'url', $this->params()->fromQuery(
                'url', $this->getRequest()->getServer()->get('HTTP_REFERER')
            )
        );

        // Force login if necessary:
        $config = $this->getConfig();
        if ((!isset($config->Mail->require_login) || $config->Mail->require_login)
            && !$this->getUser()
        ) {
            return $this->forceLogin(null, ['emailurl' => $view->url]);
        }

        // Check if we have a URL in login followup data -- this should override
        // any existing referer to avoid emailing a login-related URL!
        $followupUrl = $this->followup()->retrieveAndClear('emailurl');
        if (!empty($followupUrl)) {
            $view->url = $followupUrl;
        }

        // Fail if we can't figure out a URL to share:
        if (empty($view->url)) {
            throw new \Exception('Cannot determine URL to share.');
        }

        // Process form submission:
        if ($this->formWasSubmitted('submit', $view->useRecaptcha)) {
            // Attempt to send the email and show an appropriate flash message:
            try {
                // If we got this far, we're ready to send the email:
                $cc = $this->params()->fromPost('ccself') && $view->from != $view->to
                    ? $view->from : null;
                $mailer->sendLink(
                    $view->to, $view->from, $view->message,
                    $view->url, $this->getViewRenderer(), $view->subject, $cc
                );
                $this->flashMessenger()->addMessage('email_success', 'info');
                return $this->redirect()->toUrl($view->url);
            } catch (MailException $e) {
                $this->flashMessenger()->addMessage($e->getMessage(), 'error');
            }
        }
        return $view;
    }

    /**
     * Get the possible legal values for the illustration limit radio buttons.
     *
     * @param object $savedSearch Saved search object (false if none)
     *
     * @return array              Legal options, with selected value flagged.
     */
    protected function getIllustrationSettings($savedSearch = false)
    {
        $illYes = [
            'text' => 'Has Illustrations', 'value' => 1, 'selected' => false
        ];
        $illNo = [
            'text' => 'Not Illustrated', 'value' => 0, 'selected' => false
        ];
        $illAny = [
            'text' => 'No Preference', 'value' => -1, 'selected' => false
        ];

        // Find the selected value by analyzing facets -- if we find match, remove
        // the offending facet to avoid inappropriate items appearing in the
        // "applied filters" sidebar!
        if ($savedSearch
            && $savedSearch->getParams()->hasFilter('illustrated:Illustrated')
        ) {
            $illYes['selected'] = true;
            $savedSearch->getParams()->removeFilter('illustrated:Illustrated');
        } else if ($savedSearch
            && $savedSearch->getParams()->hasFilter('illustrated:"Not Illustrated"')
        ) {
            $illNo['selected'] = true;
            $savedSearch->getParams()->removeFilter('illustrated:"Not Illustrated"');
        } else {
            $illAny['selected'] = true;
        }
        return [$illYes, $illNo, $illAny];
    }

    /**
     * Process the facets to be used as limits on the Advanced Search screen.
     *
     * @param array  $facetList    The advanced facet values
     * @param object $searchObject Saved search object (false if none)
     *
     * @return array               Sorted facets, with selected values flagged.
     */
    protected function processAdvancedFacets($facetList, $searchObject = false)
    {
        // Process the facets
        $hierarchicalFacets = $this->getHierarchicalFacets();
        $facetHelper = null;
        if (!empty($hierarchicalFacets)) {
            $facetHelper = $this->getServiceLocator()
                ->get('VuFind\HierarchicalFacetHelper');
        }
        foreach ($facetList as $facet => &$list) {
            // Hierarchical facets: format display texts and sort facets
            // to a flat array according to the hierarchy
            if (in_array($facet, $hierarchicalFacets)) {
                $tmpList = $list['list'];
                $facetHelper->sortFacetList($tmpList, true);
                $tmpList = $facetHelper->buildFacetArray(
                    $facet,
                    $tmpList
                );
                $list['list'] = $facetHelper->flattenFacetHierarchy($tmpList);
            }

            foreach ($list['list'] as $key => $value) {
                // Build the filter string for the URL:
                $fullFilter = ($value['operator'] == 'OR' ? '~' : '')
                    . $facet . ':"' . $value['value'] . '"';

                // If we haven't already found a selected facet and the current
                // facet has been applied to the search, we should store it as
                // the selected facet for the current control.
                if ($searchObject
                    && $searchObject->getParams()->hasFilter($fullFilter)
                ) {
                    $list['list'][$key]['selected'] = true;
                    // Remove the filter from the search object -- we don't want
                    // it to show up in the "applied filters" sidebar since it
                    // will already be accounted for by being selected in the
                    // filter select list!
                    $searchObject->getParams()->removeFilter($fullFilter);
                }
            }
        }
        return $facetList;
    }

    /**
     * Handle search history display && purge
     *
     * @return mixed
     */
    public function historyAction()
    {
        // Force login if necessary
        $user = $this->getUser();
        if ($this->params()->fromQuery('require_login', 'no') !== 'no' && !$user) {
            return $this->forceLogin();
        }

        // Retrieve search history
        $search = $this->getTable('Search');
        $searchHistory = $search->getSearches(
            $this->getServiceLocator()->get('VuFind\SessionManager')->getId(),
            is_object($user) ? $user->id : null
        );

        // Build arrays of history entries
        $saved = $unsaved = [];

        // Loop through the history
        foreach ($searchHistory as $current) {
            $minSO = $current->getSearchObject();

            // Saved searches
            if ($current->saved == 1) {
                $saved[] = $minSO->deminify($this->getResultsManager());
            } else {
                // All the others...

                // If this was a purge request we don't need this
                if ($this->params()->fromQuery('purge') == 'true') {
                    $current->delete();

                    // We don't want to remember the last search after a purge:
                    $this->getSearchMemory()->forgetSearch();

                    // let them know about it
                    $this->flashMessenger()->addMessage('search_purge_success', 'info');
                } else {
                    // Otherwise add to the list
                    $unsaved[] = $minSO->deminify($this->getResultsManager());
                }
            }
        }

        return $this->createViewModel(
            ['saved' => $saved, 'unsaved' => $unsaved]
        );
    }

    /**
     * Home action
     *
     * @return mixed
     */
    public function homeAction()
    {
        // reset to retaining filters
        $this->getILS()->setSessionVar("retainFilters", true);

        $view = $this->createViewModel(
            [
                'BookResults' => $this->getNewItemsByFormatAction(["Print Book", "Large Print"]),
                'DVDResults' => $this->getNewItemsByFormatAction(["DVD"]),
                'eBookResults' => $this->getNewItemsByFormatAction(["OverDrive Read", "Adobe EPUB ebook", "Kindle Book", "Adobe PDF eBook", "Ebook Download"]),
                //'CDResults' => $this->getNewItemsByFormatAction(["Music CD", "Music Score"]),
                'request' => $this->request
            ]
        );

        return $view;
    }

    /**
     * New item search form
     *
     * @return mixed
     */
    public function newitemAction()
    {
        // Search parameters set?  Process results.
        if ($this->params()->fromQuery('range') !== null) {
            return $this->forwardTo('Search', 'NewItemResults');
        }

        return $this->createViewModel(
            [
                'fundList' => $this->newItems()->getFundList(),
                'ranges' => $this->newItems()->getRanges()
            ]
        );
    }

    /**
     * New item result list
     *
     * @return mixed
     */
    public function getNewItemsByFormatAction($format)
    {
        // Retrieve new item list:
        $range = 300;
        $dept = null;

        // Validate the range parameter -- it should not exceed the greatest
        // configured value:
        $maxAge = $this->newItems()->getMaxAge();
        if($range > $maxAge) {
            $range = $maxAge;
        }

        // use the formats they passed in
        $formatStr = "";
        foreach ($format as $type) {
            $formatStr .= (($formatStr == "") ? "" : " OR ") . 'format:"' . $type . '"';
        }
        $hiddenFilters = [$formatStr];
        
        // Depending on whether we're in ILS or Solr mode, we need to do some
        // different processing here to retrieve the correct items:
        if ($this->newItems()->getMethod() == 'ils') {
            // Use standard search action with override parameter to show results:
            $bibIDs = $this->newItems()->getBibIDsFromCatalog(
                $this->getILS(),
                $this->getResultsManager()->get('Solr')->getParams(),
                $range, $dept, $this->flashMessenger()
            );
            $this->getRequest()->getQuery()->set('overrideIds', $bibIDs);
        } else {
            // Use a Solr filter to show results:
            //$hiddenFilters[] = $this->newItems()->getSolrFilter($range);
            $hiddenFilters[] = 'date_added:["' . strftime("%Y-%m-%dT00:00:00Z", time() - $range * 86400) . '" TO *]';
        }

        // If we found hidden filters above, apply them now:
        if (!empty($hiddenFilters)) {
            $this->getRequest()->getQuery()->set('hiddenFilters', $hiddenFilters);
        }

        // sort by newest first
        $this->getRequest()->getQuery()->set('sort', 'date_added desc');

        // limit to only needed fields
        $this->getRequest()->getQuery()->set('fl', $this->getConfig()->LimitedSearchFields->shortList);

        // Don't save to history -- history page doesn't handle correctly:
        $this->saveToHistory = false;

        // Call rather than forward, so we can use custom template
        $view = $this->resultsAction();

        // Customize the URL helper to make sure it builds proper new item URLs
        // (check it's set first -- RSS feed will return a response model rather
        // than a view model):
        if (isset($view->results)) {
            $url = $view->results->getUrlQuery();
            $url->setDefaultParameter('range', $range);
            $url->setDefaultParameter('department', $dept);
            $url->setSuppressQuery(true);

            // reset the sort type
            $view->results->getOptions()->rememberLastSort("relevance");
        }

        return $view;
    }

    /**
     * New item result list
     *
     * @return mixed
     */
    public function newitemresultsAction()
    {
        // Retrieve new item list:
        $range = $this->params()->fromQuery('range');
        $dept = $this->params()->fromQuery('department');

        // Validate the range parameter -- it should not exceed the greatest
        // configured value:
        $maxAge = $this->newItems()->getMaxAge();
        if ($maxAge > 0 && $range > $maxAge) {
            $range = $maxAge;
        }

        // Are there "new item" filter queries specified in the config file?
        // If so, load them now; we may add more values. These will be applied
        // later after the whole list is collected.
        $hiddenFilters = $this->newItems()->getHiddenFilters();

        // Depending on whether we're in ILS or Solr mode, we need to do some
        // different processing here to retrieve the correct items:
        if ($this->newItems()->getMethod() == 'ils') {
            // Use standard search action with override parameter to show results:
            $bibIDs = $this->newItems()->getBibIDsFromCatalog(
                $this->getILS(),
                $this->getResultsManager()->get('Solr')->getParams(),
                $range, $dept, $this->flashMessenger()
            );
            $this->getRequest()->getQuery()->set('overrideIds', $bibIDs);
        } else {
            // Use a Solr filter to show results:
            $hiddenFilters[] = $this->newItems()->getSolrFilter($range);
        }

        // If we found hidden filters above, apply them now:
        if (!empty($hiddenFilters)) {
            $this->getRequest()->getQuery()->set('hiddenFilters', $hiddenFilters);
        }

        // Don't save to history -- history page doesn't handle correctly:
        $this->saveToHistory = false;

        // Call rather than forward, so we can use custom template
        $view = $this->resultsAction();

        // Customize the URL helper to make sure it builds proper new item URLs
        // (check it's set first -- RSS feed will return a response model rather
        // than a view model):
        if (isset($view->results)) {
            $url = $view->results->getUrlQuery();
            $url->setDefaultParameter('range', $range);
            $url->setDefaultParameter('department', $dept);
            $url->setSuppressQuery(true);
        }

        return $view;
    }

    /**
     * Course reserves
     *
     * @return mixed
     */
    public function reservesAction()
    {
        // Search parameters set?  Process results.
        if ($this->params()->fromQuery('inst') !== null
            || $this->params()->fromQuery('course') !== null
            || $this->params()->fromQuery('dept') !== null
        ) {
            return $this->forwardTo('Search', 'ReservesResults');
        }

        // No params?  Show appropriate form (varies depending on whether we're
        // using driver-based or Solr-based reserves searching).
        if ($this->reserves()->useIndex()) {
            return $this->forwardTo('Search', 'ReservesSearch');
        }

        // If we got this far, we're using driver-based searching and need to
        // send options to the view:
        $catalog = $this->getILS();
        return $this->createViewModel(
            [
                'deptList' => $catalog->getDepartments(),
                'instList' => $catalog->getInstructors(),
                'courseList' =>  $catalog->getCourses()
            ]
        );
    }

    /**
     * Show search form for Solr-driven reserves.
     *
     * @return mixed
     */
    public function reservessearchAction()
    {
        $request = new \Zend\Stdlib\Parameters(
            $this->getRequest()->getQuery()->toArray()
            + $this->getRequest()->getPost()->toArray()
        );
        $view = $this->createViewModel();
        $runner = $this->getServiceLocator()->get('VuFind\SearchRunner');
        $view->results = $runner->run(
            $request, 'SolrReserves', $this->getSearchSetupCallback()
        );
        $view->params = $view->results->getParams();
        return $view;
    }

    /**
     * Show results of reserves search.
     *
     * @return mixed
     */
    public function reservesresultsAction()
    {
        // Retrieve course reserves item list:
        $course = $this->params()->fromQuery('course');
        $inst = $this->params()->fromQuery('inst');
        $dept = $this->params()->fromQuery('dept');
        $result = $this->reserves()->findReserves($course, $inst, $dept);

        // Build a list of unique IDs
        $callback = function ($i) {
            return $i['BIB_ID'];
        };
        $bibIDs = array_unique(array_map($callback, $result));

        // Truncate the list if it is too long:
        $limit = $this->getResultsManager()->get('Solr')->getParams()
            ->getQueryIDLimit();
        if (count($bibIDs) > $limit) {
            $bibIDs = array_slice($bibIDs, 0, $limit);
            $this->flashMessenger()->addMessage('too_many_reserves', 'info');
        }

        // Use standard search action with override parameter to show results:
        $this->getRequest()->getQuery()->set('overrideIds', $bibIDs);

        // Don't save to history -- history page doesn't handle correctly:
        $this->saveToHistory = false;

        // Set up RSS feed title just in case:
        $this->getViewRenderer()->plugin('resultfeed')
            ->setOverrideTitle('Reserves Search Results');

        // Call rather than forward, so we can use custom template
        $view = $this->resultsAction();

        // Pass some key values to the view, if found:
        if (isset($result[0]['instructor']) && !empty($result[0]['instructor'])) {
            $view->instructor = $result[0]['instructor'];
        }
        if (isset($result[0]['course']) && !empty($result[0]['course'])) {
            $view->course = $result[0]['course'];
        }

        // Customize the URL helper to make sure it builds proper reserves URLs
        // (but only do this if we have access to a results object, which we
        // won't in RSS mode):
        if (isset($view->results)) {
            $url = $view->results->getUrlQuery();
            $url->setDefaultParameter('course', $course);
            $url->setDefaultParameter('inst', $inst);
            $url->setDefaultParameter('dept', $dept);
            $url->setSuppressQuery(true);
        }
        return $view;
    }

    /**
     * Results action.
     *
     * @return mixed
     */
    public function resultsAction()
    {
        // Special case -- redirect tag searches.
        $tag = $this->params()->fromQuery('tag');
        $query = $this->getRequest()->getQuery();
        if (!empty($tag)) {
            $query->set('lookfor', $tag);
            $query->set('type', 'tag');
        }
        if ($this->params()->fromQuery('type') == 'tag') {
            return $this->forwardTo('Tag', 'Home');
        }

        // set the retain filters flag
        if( isset($this->getRequest()->getQuery()->retainFilters) ) {
            $this->getILS()->setSessionVar("retainFilters", ($this->getRequest()->getQuery()->retainFilters == "true") ? true : false);
        }

        // suppress the search prompt
        if( $this->params()->fromQuery('lookfor') == "Search For..." ) {
            $this->getRequest()->getQuery()->set('lookfor', "");
        }

        // parse out advanced style lookfors
        $searchTypes = ["Keyword" => "Keyword", "AllFields" => "All Fields", "Title" => "Title", "Author" => "Author",
                        "Contributor" => "Author/Artist/Contributor", "Subject" => "Subject", "ISN" => "ISBN/ISSN/UPC",
                        "publisher" => "Publisher", "Series" => "Series", "year" => "Year of Publication", "toc" => "Table of Contents"];
        $regExStr = "";
        foreach($searchTypes as $thisType) {
            $regExStr .= (($regExStr == "") ? "/(" : "|") . str_replace("/", "\/", $thisType);
        }
        $regExStr .= "):/";
        if( isset($query->lookfor) && (substr($query->lookfor, 0, 1) == "(") && (substr($query->lookfor, -1) == ")") && 
            preg_match($regExStr, $query->lookfor) ) {
            $queryArgs = $query->toArray();

            // default groups to be ANDed together
            $queryArgs["join"] = "AND";
            $currentGroup = 0;

            // split this query into groups
            foreach( explode(")", $queryArgs['lookfor']) as $thisGroup ) {
                if(count($innerBits = explode("(", $thisGroup)) > 1) {
                    // default to AND
                    $queryArgs["bool" . $currentGroup][0] = "AND";

                    // test if this is a NOT group
                    if( trim($innerBits[0]) == "NOT" ) {
                        $queryArgs["bool" . $currentGroup][0] = "NOT";
                    // or if the groups are ORed together
                    } else if( trim($innerBits[0]) == "OR" ) {
                        $queryArgs["join"] = "OR";
                    }

                    // split the group into terms
                    $terms = [$innerBits[count($innerBits)-1]];
                    foreach( $searchTypes as $thisCode => $thisType ) {
                        for( $i=count($terms)-1; $i>=0; $i-- ) {
                            $splitTerms = explode($thisType . ":", $terms[$i]);
                            if( count($splitTerms) > 1 ) {
                                for( $j=count($splitTerms)-1; $j>0; $j-- ) {
                                    array_splice($splitTerms, $j, 0, $thisCode);
                                }
                                // peel off the empty guy at the beginning of the array, if present
                                if( $splitTerms[0] == "" ) {
                                    unset($splitTerms[0]);
                                }
                                array_splice($terms, $i, 1, $splitTerms);
                            }
                        }
                    }

                    // add these items to the query args
                    $queryArgs["type" . $currentGroup] = [];
                    $queryArgs["lookfor" . $currentGroup] = [];
                    for( $i=0; $i<count($terms); $i+=2 ) {
                        $queryArgs["type" . $currentGroup][] = $terms[$i];

                        // remove the bool operator from the search term
                        if( $i + 2 < count($terms) && substr($terms[$i+1], -4) == " OR ") {
                            // flip this to an OR
                            if( $queryArgs["bool" . $currentGroup][0] == "AND" ) {
                                $queryArgs["bool" . $currentGroup][0] = "OR";
                            }
                            $terms[$i+1] = substr($terms[$i+1], 0, -4);
                        } else if( $i + 2 < count($terms) && substr($terms[$i+1], -5) == " AND ") {
                            $terms[$i+1] = substr($terms[$i+1], 0, -5);
                        }
                        $queryArgs["lookfor" . $currentGroup][] = $terms[$i+1];
                    }
                    
                    $currentGroup++;
                }
            }
            unset($queryArgs["lookfor"]);
            unset($queryArgs["type"]);
            $query->fromArray($queryArgs);
        }

        // make everything case insensitive
        if( $thisLF = $this->params()->fromQuery('lookfor') ) {
          $bits = explode(" ", $thisLF);
          foreach( $bits as $index => $thisBit ) {
            if( !in_array($thisBit, ["AND", "OR", "NOT"]) ) {
              $bits[$index] = strtolower($thisBit);
            }
          }
          $queryArgs = $query->toArray();
          $queryArgs["lookfor"] = implode(" ", $bits);
          $query->fromArray($queryArgs);
        }
        $lfIndex = 0;
        while( $thisLF = $this->params()->fromQuery('lookfor' . $lfIndex) ) {
          $queryArgs = $query->toArray();
          foreach( $thisLF as $stIndex => $thisSearchTerm ) {
            $bits = explode(" ", $thisSearchTerm);
            foreach( $bits as $index => $thisBit ) {
              if( !in_array($thisBit, ["AND", "OR", "NOT"]) ) {
                $bits[$index] = strtolower($thisBit);
              }
            }
            $queryArgs["lookfor" . $lfIndex][$stIndex] = implode(" ", $bits);
          }
          $query->fromArray($queryArgs);
          $lfIndex++;
        }

        // limit to only needed fields
        if( $this->getRequest()->getQuery("fl") === null ) {
            $this->getRequest()->getQuery()->set('fl', $this->getConfig()->LimitedSearchFields->shortList);
        }

        $this->getRequest()->getQuery()->set('hl.snippets', '10');

        // Default case -- standard behavior.
        $view = parent::resultsAction();

        // redirect to the record if it's an ISBN search with only one result
        if( $this->getRequest()->getQuery("type") == "ISN" && $view->results->getResultTotal() == 1) {
            $details = $this->getRecordRouter()->getTabRouteDetails($view->results->getResults()[0]->getUniqueID());
            $target = $this->url()->fromRoute($details['route'], $details['params']);
            return $this->redirect()->toUrl($target);
        }
        $view->searchType = ($this->getRequest()->getQuery("type") != null) ? $this->getRequest()->getQuery("type") : "Advanced";
        $view->formatCategories = $this->getConfig()->FormatCategories;
        return $view;
    }

    /**
     * Return a Search Results object containing requested facet information.  This
     * data may come from the cache.
     *
     * @param string $initMethod Name of params method to use to request facets
     * @param string $cacheName  Cache key for facet data
     *
     * @return \VuFind\Search\Solr\Results
     */
    protected function getFacetResults($initMethod, $cacheName)
    {
        // Check if we have facet results cached, and build them if we don't.
        $cache = $this->getServiceLocator()->get('VuFind\CacheManager')
            ->getCache('object');
        if (!($results = $cache->getItem($cacheName))) {
            // Use advanced facet settings to get summary facets on the front page;
            // we may want to make this more flexible later.  Also keep in mind that
            // the template is currently looking for certain hard-coded fields; this
            // should also be made smarter.
            $results = $this->getResultsManager()->get('Solr');
            $params = $results->getParams();
            $params->$initMethod();

            // We only care about facet lists, so don't get any results (this helps
            // prevent problems with serialized File_MARC objects in the cache):
            $params->setLimit(0);

            $results->getResults();                     // force processing for cache

            $cache->setItem($cacheName, $results);
        }

        // Restore the real service locator to the object (it was lost during
        // serialization):
        $results->restoreServiceLocator($this->getServiceLocator());
        return $results;
    }

    /**
     * Return a Search Results object containing advanced facet information.  This
     * data may come from the cache.
     *
     * @return \VuFind\Search\Solr\Results
     */
    protected function getAdvancedFacets()
    {
        return $this->getFacetResults(
            'initAdvancedFacets', 'solrSearchAdvancedFacets'
        );
    }

    /**
     * Return a Search Results object containing homepage facet information.  This
     * data may come from the cache.
     *
     * @return \VuFind\Search\Solr\Results
     */
    protected function getHomePageFacets()
    {
        return $this->getFacetResults('initHomePageFacets', 'solrSearchHomeFacets');
    }

    /**
     * Handle OpenSearch.
     *
     * @return \Zend\Http\Response
     */
    public function opensearchAction()
    {
        switch ($this->params()->fromQuery('method')) {
        case 'describe':
            $config = $this->getConfig();
            $xml = $this->getViewRenderer()->render(
                'search/opensearch-describe.phtml', ['site' => $config->Site]
            );
            break;
        default:
            $xml = $this->getViewRenderer()->render('search/opensearch-error.phtml');
            break;
        }

        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-type', 'text/xml');
        $response->setContent($xml);
        return $response;
    }

    /**
     * Provide OpenSearch suggestions as specified at
     * http://www.opensearch.org/Specifications/OpenSearch/Extensions/Suggestions/1.0
     *
     * @return \Zend\Http\Response
     */
    public function suggestAction()
    {
        // Always use 'AllFields' as our autosuggest type:
        $query = $this->getRequest()->getQuery();
        $query->set('type', 'AllFields');

        // Get suggestions and make sure they are an array (we don't want to JSON
        // encode them into an object):
        $autocompleteManager = $this->getServiceLocator()
            ->get('VuFind\AutocompletePluginManager');
        $suggestions = $autocompleteManager->getSuggestions(
            $query, 'type', 'lookfor'
        );

        // Send the JSON response:
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-type', 'application/javascript');
        $response->setContent(
            json_encode([$query->get('lookfor', ''), $suggestions])
        );
        return $response;
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
     * Get an array of hierarchical facets
     *
     * @return array Facets
     */
    protected function getHierarchicalFacets()
    {
        $facetConfig = $this->getConfig('facets');
        return isset($facetConfig->SpecialFacets->hierarchical)
            ? $facetConfig->SpecialFacets->hierarchical->toArray()
            : [];
    }

    /**
     * Get hierarchical facet sort settings
     *
     * @return array Array of sort settings keyed by facet
     */
    protected function getHierarchicalFacetSortSettings()
    {
        $facetConfig = $this->getConfig('facets');
        return isset($facetConfig->SpecialFacets->hierarchicalFacetSortOptions)
            ? $facetConfig->SpecialFacets->hierarchicalFacetSortOptions->toArray()
            : [];
    }

}
