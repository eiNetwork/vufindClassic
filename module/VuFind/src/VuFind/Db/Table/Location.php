<?php
/**
 * Table Definition for location
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
 * @package  Db_Table
 * @author   Brad Patton <pattonb@einetwork.net>
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
namespace VuFind\Db\Table;

/**
 * Table Definition for location
 *
 * @category VuFind2
 * @package  Db_Table
 * @author   Brad Patton <pattonb@einetwork.net>
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
class Location extends Gateway
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct('location', 'VuFind\Db\Row\Location');
    }

    /**
     * Retrieve a location object from the database based on code
     *
     * @param string $code Code to use for retrieval.
     *
     * @return LocationRow
     */
    public function getByCode($code)
    {
        $callback = function ($select) use($code) {
            $select->where('code = "' . $code . '"');
        };
        $row = $this->select($callback);
        return $row->current();
    }

    /**
     * Get location rows that can be used as pickups
     *
     * @return mixed
     */
    public function getPickupLocations()
    {
        $callback = function ($select) {
            $select->where('validHoldPickupBranch=1')
                ->order('displayName');
        };
        return $this->select($callback);
    }
}
