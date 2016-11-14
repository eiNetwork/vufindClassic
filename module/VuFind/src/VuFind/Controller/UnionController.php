<?php
/**
 * Union Controller
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
 * Union Controller
 *
 * @category VuFind2
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @author   Brad Patton <pattonb@einetwork.net>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
class UnionController extends AbstractSearch
{
    /**
     * Search action
     *
     * @return mixed
     */
    public function searchAction()
    {
        //$this->getRequest()->getQuery()->set('type', $this->getRequest()->getQuery()->basicType); 
        //return $this->forwardTo('Search', 'Results');

        return $this->redirect()->toUrl('/Search/Results?type=' . $this->getRequest()->getQuery()->basicType . '&lookfor=' . $this->getRequest()->getQuery()->lookfor);
        //return $this->redirect()->toUrl($this->url()->fromRoute('search-results', ['type' => $this->getRequest()->getQuery()->basicType, 'lookfor' => $this->getRequest()->getQuery()->lookfor]));
    }
}

