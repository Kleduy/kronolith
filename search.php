<?php
/**
 * Copyright 2004-2009 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @author Meilof Veeningen <meilof@gmail.com>
 */

@define('KRONOLITH_BASE', dirname(__FILE__));
require_once KRONOLITH_BASE . '/lib/base.php';

/* Get search parameters. */
$search_mode = Util::getFormData('search_mode', 'basic');
$search_calendar = explode('|', Util::getFormData('calendar', '|__any'), 2);
$events = null;

if ($search_mode == 'basic') {
    $desc = Util::getFormData('pattern_desc');
    $title = Util::getFormData('pattern_title');
    if (strlen($desc) || strlen($title)) {
        $event = Kronolith::getDriver()->getEvent();
        $event->setDescription($desc);
        $event->setTitle($title);
        $event->status = null;

        $time1 = $_SERVER['REQUEST_TIME'];
        $range = Util::getFormData('range');
        if ($range == '+') {
            $event->start = new Horde_Date($time1);
            $event->end = null;
        } elseif ($range == '-') {
            $event->start = null;
            $event->end = new Horde_Date($time1);
        } else {
            $time2 = $time1 + $range;
            $event->start = new Horde_Date(min($time1, $time2));
            $event->end = new Horde_Date(max($time1, $time2));
        }
        $events = Kronolith::search($event);
    }
} else {
    /* Make a new empty event object with default values. */
    $event = Kronolith::getDriver($search_calendar[0], $search_calendar[1])->getEvent();
    $event->title = $event->location = $event->status = $event->description = null;

    /* Set start on today, stop on tomorrow. */
    $event->start = new Horde_Date(mktime(0, 0, 0));
    $event->end = new Horde_Date($event->start);
    $event->end->mday++;

    /* We need to set the event to initialized, otherwise we will end up with
     * a default end date. */
    $event->initialized = true;

    $q_title = Util::getFormData('title');
    if (strlen($q_title)) {
        $event->readForm();
        if (Util::getFormData('status') == Kronolith::STATUS_NONE) {
            $event->status = null;
        }

        $events = Kronolith::search($event, $search_calendar[1] == '__any' ? null : $search_calendar[0] . '|' . $search_calendar[1]);
    }

    $optgroup = $GLOBALS['browser']->hasFeature('optgroup');
    $current_user = Auth::getAuth();
    $calendars = array();
    foreach (Kronolith::listCalendars(false, PERMS_READ) as $id => $cal) {
        if ($cal->get('owner') == $current_user) {
            $calendars[_("My Calendars:")]['|' . $id] = $cal->get('name');
        } else {
            $calendars[_("Shared Calendars:")]['|' . $id] = $cal->get('name');
        }
    }
    foreach ($GLOBALS['all_external_calendars'] as $api => $categories) {
        $app = $GLOBALS['registry']->get('name', $GLOBALS['registry']->hasInterface($api));
        foreach ($categories as $id => $name) {
            $calendars[$app . ':']['Horde|external_' . $api . '/' . $id] = $name;
        }
    }
    foreach ($GLOBALS['all_remote_calendars'] as $cal) {
        $calendars[_("Remote Calendars:")]['Ical|' . $cal['url']] = $cal['name'];
    }
    if (!empty($GLOBALS['conf']['holidays']['enable'])) {
        foreach (unserialize($GLOBALS['prefs']->getValue('holiday_drivers')) as $holiday) {
            $calendars[_("Holidays:")]['Holidays|' . $holiday] = $holiday;
        }
    }
}

$title = _("Search");
Horde::addScriptFile('tooltip.js', 'horde', true);
require KRONOLITH_TEMPLATES . '/common-header.inc';
require KRONOLITH_TEMPLATES . '/menu.inc';

echo '<div id="page">';
if ($search_mode == 'basic') {
    require KRONOLITH_TEMPLATES . '/search/search.inc';
    $notification->push('document.eventform.pattern_title.focus()', 'javascript');
} else {
    require KRONOLITH_TEMPLATES . '/search/search_advanced.inc';
    $notification->push('document.eventform.title.focus()', 'javascript');
}

/* Display search results. */
if (!is_null($events)) {
    if (count($events)) {
        require KRONOLITH_TEMPLATES . '/search/header.inc';
        require KRONOLITH_TEMPLATES . '/search/event_headers.inc';
        foreach ($events as $day => $day_events) {
            foreach ($day_events as $event) {
                require KRONOLITH_TEMPLATES . '/search/event_summaries.inc';
            }
        }
        require KRONOLITH_TEMPLATES . '/search/event_footers.inc';
    } else {
        require KRONOLITH_TEMPLATES . '/search/empty.inc';
    }
}

echo '</div>';
require KRONOLITH_TEMPLATES . '/panel.inc';
require $registry->get('templates', 'horde') . '/common-footer.inc';
