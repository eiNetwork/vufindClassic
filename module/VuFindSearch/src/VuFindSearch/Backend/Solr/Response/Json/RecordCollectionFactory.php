<?php

/**
 * Simple JSON-based factory for record collection.
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
 * @package  Search
 * @author   David Maus <maus@hab.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org
 */
namespace VuFindSearch\Backend\Solr\Response\Json;

use VuFindSearch\Response\RecordCollectionFactoryInterface;
use VuFindSearch\Exception\InvalidArgumentException;
use VuFind\Record\Loader as RecordLoader;
use Zend\Session\Container as SessionContainer;

/**
 * Simple JSON-based factory for record collection.
 *
 * @category VuFind2
 * @package  Search
 * @author   David Maus <maus@hab.de>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org
 */
class RecordCollectionFactory implements RecordCollectionFactoryInterface
{
    /**
     * Factory to turn data into a record object.
     *
     * @var Callable
     */
    protected $recordFactory;

    /**
     * Class of collection.
     *
     * @var string
     */
    protected $collectionClass;

    /**
     * Constructor.
     *
     * @param Callable $recordFactory   Callback to construct records
     * @param string   $collectionClass Class of collection
     *
     * @return void
     */
    public function __construct($recordFactory = null,
        $collectionClass = 'VuFindSearch\Backend\Solr\Response\Json\RecordCollection',
        RecordLoader $recordLoader = null
    ) {
        if (null === $recordFactory) {
            $this->recordFactory = function ($data) {
                return new Record($data);
            };
        } else {
            $this->recordFactory = $recordFactory;
        }
        $this->collectionClass = $collectionClass;
        $this->recordLoader = $recordLoader;
    }

    /**
     * Return record collection.
     *
     * @param array $response Deserialized JSON response
     *
     * @return RecordCollection
     */
    public function factory($response)
    {
        if (!is_array($response)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unexpected type of value: Expected array, got %s',
                    gettype($response)
                )
            );
        }

        // switcher
        if( !isset($response['grouped']) ) {
            $collection = new $this->collectionClass($response);
            if (isset($response['response']['docs'])) {
                foreach ($response['response']['docs'] as $doc) {
                    $collection->add(call_user_func($this->recordFactory, $doc));
                }
            }
        } else {
            // get the session container
            $container = new SessionContainer('OtherFormats');
            $formatDict = (isset($container->dictionary) ? $container->dictionary : array());

            if( !isset($response['response']) ) {
                $response['response'] = array();
            }
            $response['response']['numFound'] = count($response['grouped']['title']['groups']);
            $collection = new $this->collectionClass($response);
            if (isset($response['grouped']['title']['groups'])) {
                foreach ($response['grouped']['title']['groups'] as $group) {
                    if ($group['groupValue'] == NULL) {
                        continue;
                    }

                    // reorder this group's elements by prevalence of format type
                    $formatOrder = array('Print Book','Electronic Resource','DVD','Music CD','Music Score','OverDrive Read','Adobe EPUB ebook', 
                                         'Kindle Book','Book on CD','Large Print','Adobe PDF eBook','Ebook Download','OverDrive MP3 Audionook',
                                         'OverDrive Listen','Print Magazine/Newspaper','Video Cassette','Microfilm and Microfiche','Book on MP3 Disc',
                                         'Book on Tape','Maps and Atlases','Blu-ray','Other Kits','Music LP/Cassette','Other Objects','Print Image',
                                         'Streaming Video','Digital Image','Music on Media Player','Audio Book Download','Video Download');
                    $reordered = array();
                    $ranks = array();
                    foreach($group['doclist']['docs'] as $doc) {
                        $lowest = count($formatOrder);
                        foreach($doc['format'] as $format) {
                            $thisIndex = array_search($format, $formatOrder);
                            $lowest = (($thisIndex !== false) && ($lowest > $thisIndex)) ? $thisIndex : $lowest;
                        }
                        for($i=0; $i<=count($ranks); $i++) {
                            if($i == count($ranks) || $lowest < $ranks[$i]) {
                                array_splice($ranks, $i, 0, $lowest);
                                array_splice($reordered, $i, 0, array($doc));
                                $i = count($ranks) + 1;
                            }
                        }
                    }
                    
                    $record = call_user_func($this->recordFactory, $reordered[0]);
                    if( count($reordered) > 1 ) {
                        $record->SetExtraDetail('otherFormats', array_slice($reordered, 1));
                        $formatDict[$record->getUniqueId()] = $record->getExtraDetail('otherFormats');
                    }
                    $collection->add($record);
                }
            }
            $container->dictionary = $formatDict;
        }
        return $collection;
    }

}