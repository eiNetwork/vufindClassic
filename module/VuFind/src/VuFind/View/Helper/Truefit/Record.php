<?php
/**
 * Record driver view helper
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
 * @package  View_Helpers
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:developer_manual Wiki
 */
namespace VuFind\View\Helper\Truefit;

/**
 * Record driver view helper
 *
 * @category VuFind2
 * @package  View_Helpers
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:developer_manual Wiki
 */
class Record extends \VuFind\View\Helper\Root\Record
{
    /**
     * Render an entry for this item being checked out.
     *
     * @param \VuFind\Db\Row\User     $user Current logged in user (false if none)
     *
     * @return string
     */
    public function getCheckoutEntry($checkout, $user = false)
    {
        return $this->renderTemplate(
            'checkout-entry.phtml',
            [
                'driver' => $this->driver,
                'checkout' => $checkout,
                'user' => $user
            ]
        );
    }

    /**
     * Render an entry for a hold on this item.
     *
     * @param Object                  Object containing hold information
     * @param \VuFind\Db\Row\User     $user Current logged in user (false if none)
     *
     * @return string
     */
    public function getHoldEntry($hold, $user = false)
    {
        return $this->renderTemplate(
            'hold-entry.phtml',
            [
                'driver' => $this->driver,
                'hold' => $hold,
                'user' => $user
            ]
        );
    }

    /**
     * Render an HTML checkbox control for the current record.
     *
     * @param string $idPrefix Prefix for checkbox HTML ids
     *
     * @return string
     */
    public function getHoldCheckbox($holdId)
    {
        static $checkboxCount = 0;
        $context
            = ['overruleId' => $holdId, 'count' => $checkboxCount++];
        return $this->contextHelper->renderInContext(
            'record/checkbox.phtml', $context
        );
    }


    /**
     * Generate a thumbnail URL (return false if unsupported).
     *
     * @param string $size Size of thumbnail (small, medium or large -- small is
     * default).
     *
     * @return string|bool
     */
    public function getThumbnail($size = 'small')
    {
        // Try to build thumbnail:
        $thumb = $this->driver->tryMethod('getThumbnail', [$size]);

        // No thumbnail?  Return false:
        if (empty($thumb)) {
            return false;
        }

        // Array?  It's parameters to send to the cover generator:
        if (is_array($thumb)) {
            // first let's see if it has an image in the links
            $urls = $this->driver->getURLs();
            foreach($urls as $thisUrl) {
                if( in_array(substr($thisUrl["url"], -4), [".jpg", ".png", ".gif"]) ) {
                    return $thisUrl["url"];
                }
            }

            $urlHelper = $this->getView()->plugin('url');
            return $urlHelper('cover-show') . '?' . http_build_query($thumb);
        }

        // Default case -- return fixed string:
        return $thumb;
    }
}
