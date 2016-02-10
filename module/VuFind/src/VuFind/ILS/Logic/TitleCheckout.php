<?php
/**
 * Title Checkout Logic Class
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2007.
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
 * @package  ILS_Logic
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Luke O'Sullivan <l.osullivan@swansea.ac.uk>
 * @author   brad Patton <pattonb@einetwork.net>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:developer_manual Wiki
 */
namespace VuFind\ILS\Logic;
use VuFind\ILS\Connection as ILSConnection;

/**
 * Title Checkout Logic Class
 *
 * @category VuFind2
 * @package  ILS_Logic
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Luke O'Sullivan <l.osullivan@swansea.ac.uk>
 * @author   Brad Patton <pattonb@einetwork.net>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:developer_manual Wiki
 */
class TitleCheckout
{
    /**
     * ILS authenticator
     *
     * @var \VuFind\Auth\ILSAuthenticator
     */
    protected $ilsAuth;

    /**
     * Catalog connection object
     *
     * @var ILSConnection
     */
    protected $catalog;

    /**
     * HMAC generator
     *
     * @var \VuFind\Crypt\HMAC
     */
    protected $hmac;

    /**
     * VuFind configuration
     *
     * @var \Zend\Config\Config
     */
    protected $config;

    /**
     * Constructor
     *
     * @param \VuFind\Auth\ILSAuthenticator $ilsAuth ILS authenticator
     * @param ILSConnection                 $ils     A catalog connection
     * @param \VuFind\Crypt\HMAC            $hmac    HMAC generator
     * @param \Zend\Config\Config           $config  VuFind configuration
     */
    public function __construct(\VuFind\Auth\ILSAuthenticator $ilsAuth,
        ILSConnection $ils, \VuFind\Crypt\HMAC $hmac, \Zend\Config\Config $config
    ) {
        $this->ilsAuth = $ilsAuth;
        $this->hmac = $hmac;
        $this->config = $config;

        $this->catalog = $ils;
    }

    /**
     * Public method for getting title level holds
     *
     * @param string $id A Bib ID
     *
     * @return string|bool URL to check out an item, or false if checkout unavailable
     */
    public function getCheckout($id)
    {
        // Get Holdings Data
        if ($this->catalog) {
            $patron = $this->ilsAuth->storedCatalogLogin();
            return $this->generateCheckout($id, $patron);
        }
        return false;
    }

    /**
     * Get holdings for a particular record.
     *
     * @param string $id ID to retrieve
     *
     * @return array
     */
    protected function getHoldings($id)
    {
        // Cache results in a static array since the same holdings may be requested
        // multiple times during a run through the class:
        static $holdings = [];

        if (!isset($holdings[$id])) {
            $holdings[$id] = $this->catalog->getHolding($id);
        }
        return $holdings[$id];
    }

    /**
     * Support method for getHold to determine if we should override the configured
     * holds mode.
     *
     * @param string $id   Record ID to check
     * @param string $mode Current mode
     *
     * @return string
     */
    protected function checkOverrideMode($id, $mode)
    {
        if (isset($this->config->Catalog->allow_holds_override)
            && $this->config->Catalog->allow_holds_override
        ) {
            $holdings = $this->getHoldings($id);

            // For title holds, the most important override feature to handle
            // is to prevent displaying a link if all items are disabled.  We
            // may eventually want to address other scenarios as well.
            $allDisabled = true;
            foreach ($holdings as $holding) {
                if (!isset($holding['holdOverride'])
                    || 'disabled' != $holding['holdOverride']
                ) {
                    $allDisabled = false;
                }
            }
            $mode = (true == $allDisabled) ? 'disabled' : $mode;
        }
        return $mode;
    }

    /**
     * Protected method for driver defined title holds
     *
     * @param string $id     A Bib ID
     * @param array  $patron An Array of patron data
     *
     * @return mixed A url on success, boolean false on failure
     */
    protected function driverHold($id, $patron)
    {
        // Get Hold Details
        $checkHolds = $this->catalog->checkFunction(
            'Checkout', compact('id', 'patron')
        );
        $data = [
            'id' => $id,
            'level' => 'title'
        ];

        if ($checkHolds != false) {
            $valid = $this->catalog->checkRequestIsValid($id, $data, $patron);
            if ($valid) {
                return $this->getHoldDetails($data, $checkHolds['HMACKeys']);
            }
        }
        return false;
    }

    /**
     * Protected method for vufind (i.e. User) defined holds
     *
     * @param string $id     A Bib ID
     * @param array  $patron Patron
     *
     * @return mixed A url on success, boolean false on failure
     */
    protected function generateCheckout($id, $patron)
    {
        $any_available = false;
        $addlink = false;

        $data = [
            'id' => $id
        ];

        // Are holds allows?
        $checkCheckout = $this->catalog->checkFunction(
            'Checkout', compact('id', 'patron')
        );

        if ($checkCheckout != false) {
            if ($checkCheckout['function'] == 'getCheckoutLink') {
                // Return opac link
                return $this->catalog->getCheckoutLink($id, $data);
            } else {
                // Return non-opac link
                return $this->getCheckoutDetails($data, $checkCheckout['HMACKeys']);
            }
        }

        return false;
    }

    /**
     * Get Checkout Link
     *
     * Supplies the form details required to check out a bib
     *
     * @param array $data     An array of item data
     * @param array $HMACKeys An array of keys to hash
     *
     * @return array          Details for generating URL
     */
    protected function getCheckoutDetails($data, $HMACKeys)
    {
        // Generate HMAC
        $HMACkey = $this->hmac->generate($HMACKeys, $data);

        // Add Params
        foreach ($data as $key => $param) {
            $needle = in_array($key, $HMACKeys);
            if ($needle) {
                $queryString[] = $key . '=' . urlencode($param);
            }
        }

        // Add HMAC
        $queryString[] = 'hashKey=' . urlencode($HMACkey);
        $queryString = implode('&', $queryString);

        // Build Params
        return [
            'action' => 'Checkout', 'record' => $data['id'], 'query' => $queryString,
            'anchor' => '#tabnav'
        ];
    }
}
