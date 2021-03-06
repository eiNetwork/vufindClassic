<?php

/**
 * Factory for the default SOLR backend.
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2013.
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
 * @package  Search
 * @author   David Maus <maus@hab.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
namespace VuFind\Search\Factory;

use VuFindSearch\Backend\Solr\Response\Json\RecordCollectionFactory;
use VuFindSearch\Backend\Solr\Connector;
use VuFindSearch\Backend\Solr\Backend;

/**
 * Factory for the default SOLR backend.
 *
 * @category VuFind2
 * @package  Search
 * @author   David Maus <maus@hab.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org   Main Site
 */
class SolrDefaultBackendFactory extends AbstractSolrBackendFactory
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->searchConfig = 'searches';
        $this->searchYaml = 'searchspecs.yaml';
        $this->facetConfig = 'facets';
    }

    /**
     * Get the Solr core.
     *
     * @return string
     */
    protected function getSolrCore()
    {
        $config = $this->config->get('config');
        return isset($config->Index->default_core)
            ? $config->Index->default_core : 'biblio';
    }

    /**
     * Create the SOLR backend.
     *
     * @param Connector $connector Connector
     *
     * @return Backend
     */
    protected function createBackend(Connector $connector)
    {
        $backend = parent::createBackend($connector);
        //$manager = $this->serviceLocator->get('VuFind\RecordDriverPluginManager');
        //$factory = new RecordCollectionFactory([$manager, 'getSolrRecord']);
        //$backend->setRecordCollectionFactory($factory);
        //return $backend;

        $manager = $this->serviceLocator->get('VuFind\RecordDriverPluginManager');
        $factory = new RecordCollectionFactory(
            array($manager, 'getSolrRecord'),
            'VuFindSearch\Backend\Solr\Response\Json\RecordCollection',
            $this->serviceLocator->get('VuFind\RecordLoader')
        );
        $backend->setRecordCollectionFactory($factory);
        return $backend;
    }

    /**
     * Create the SOLR connector.
     *
     * @return Connector
     */
/* There's some weirdness here with multi-layered pagination resulting from Solr grouping.  If you turn this on, 
   you'll only be able to navigate through the first page of results.  Also, grouping by title is not nearly as 
   effective as we'd hoped.  Demian Katz suggests contacting Ere Maijala at the National Library of Finland
   regarding the deduplication listener they have developed. -- BJP
    protected function createConnector()
    {
        $connector = parent::createConnector();
        $map = $connector->getMap()->getParameters('select', 'defaults');
        $map->add('group', 'true');
        $map->add('group.field', 'title');
        $map->add('group.limit', 50);
        $map->add('group.ngroups', 'true');

        return $connector;
    }
*/
}