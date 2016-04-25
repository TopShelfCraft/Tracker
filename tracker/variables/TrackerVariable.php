<?php
namespace Craft;

/**
 * TrackerVariable
 *
 * @author    Top Shelf Craft <michael@michaelrog.com>
 * @copyright Copyright (c) 2016, Michael Rog
 * @license   http://topshelfcraft.com/license
 * @see       http://topshelfcraft.com
 * @package   craft.plugins.tracker
 * @since     1.0
 */
class TrackerVariable
{

    public function track($options)
    {
       TrackerHelper::track($options);
    }

}