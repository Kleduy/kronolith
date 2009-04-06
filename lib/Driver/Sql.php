<?php
/**
 * The Kronolith_Driver_Sql class implements the Kronolith_Driver API for a
 * SQL backend.
 *
 * Copyright 1999-2009 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @author  Luc Saillard <luc.saillard@fr.alcove.com>
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Jan Schneider <jan@horde.org>
 * @package Kronolith
 */
class Kronolith_Driver_Sql extends Kronolith_Driver
{
    /**
     * The object handle for the current database connection.
     *
     * @var DB
     */
    private $_db;

    /**
     * Handle for the current database connection, used for writing. Defaults
     * to the same handle as $_db if a separate write database is not required.
     *
     * @var DB
     */
    private $_write_db;

    /**
     * Cache events as we fetch them to avoid fetching the same event from the
     * DB twice.
     *
     * @var array
     */
    private $_cache = array();

    public function listAlarms($date, $fullevent = false)
    {
        $allevents = $this->listEvents($date, null, false, true);
        if (is_a($allevents, 'PEAR_Error')) {
            return $allevents;
        }

        $events = array();
        foreach ($allevents as $dayevents) {
            foreach ($dayevents as $event) {
                if (!$event->recurs()) {
                    $start = new Horde_Date($event->start);
                    $start->min -= $event->getAlarm();
                    if ($start->compareDateTime($date) <= 0 &&
                        $date->compareDateTime($event->end) <= -1) {
                        $events[] = $fullevent ? $event : $event->getId();
                    }
                } else {
                    if ($next = $event->recurrence->nextRecurrence($date)) {
                        if ($event->recurrence->hasException($next->year, $next->month, $next->mday)) {
                            continue;
                        }
                        $start = new Horde_Date($next);
                        $start->min -= $event->getAlarm();
                        $diff = Date_Calc::dateDiff($event->start->mday,
                                                    $event->start->month,
                                                    $event->start->year,
                                                    $event->end->mday,
                                                    $event->end->month,
                                                    $event->end->year);
                        if ($diff == -1) {
                            $diff = 0;
                        }
                        $end = new Horde_Date(array('year' => $next->year,
                                                    'month' => $next->month,
                                                    'mday' => $next->mday + $diff,
                                                    'hour' => $event->end->hour,
                                                    'min' => $event->end->min,
                                                    'sec' => $event->end->sec));
                        if ($start->compareDateTime($date) <= 0 &&
                            $date->compareDateTime($end) <= -1) {
                            if ($fullevent) {
                                $event->start = $start;
                                $event->end = $end;
                                $events[] = $event;
                            } else {
                                $events[] = $event->getId();
                            }
                        }
                    }
                }
            }
        }

        return $events;
    }

    public function search($query)
    {
        /* Build SQL conditions based on the query string. */
        $cond = '((';
        $values = array();

        if (!empty($query->title)) {
            $binds = Horde_SQL::buildClause($this->_db, 'event_title', 'LIKE', $this->convertToDriver($query->title), true);
            if (is_array($binds)) {
                $cond .= $binds[0] . ' AND ';
                $values = array_merge($values, $binds[1]);
            } else {
                $cond .= $binds;
            }
        }
        if (!empty($query->location)) {
            $binds = Horde_SQL::buildClause($this->_db, 'event_location', 'LIKE', $this->convertToDriver($query->location), true);
            if (is_array($binds)) {
                $cond .= $binds[0] . ' AND ';
                $values = array_merge($values, $binds[1]);
            } else {
                $cond .= $binds;
            }
        }
        if (!empty($query->description)) {
            $binds = Horde_SQL::buildClause($this->_db, 'event_description', 'LIKE', $this->convertToDriver($query->description), true);
            if (is_array($binds)) {
                $cond .= $binds[0] . ' AND ';
                $values = array_merge($values, $binds[1]);
            } else {
                $cond .= $binds;
            }
        }
        if (isset($query->status)) {
            $binds = Horde_SQL::buildClause($this->_db, 'event_status', '=', $query->status, true);
            if (is_array($binds)) {
                $cond .= $binds[0] . ' AND ';
                $values = array_merge($values, $binds[1]);
            } else {
                $cond .= $binds;
            }
        }

        if (!empty($query->creatorID)) {
            $binds = Horde_SQL::buildClause($this->_db, 'event_creator_id', '=', $query->creatorID, true);
            if (is_array($binds)) {
                $cond .= $binds[0] . ' AND ';
                $values = array_merge($values, $binds[1]);
            } else {
                $cond .= $binds;
            }
        }

        if ($cond == '((') {
            $cond = '';
        } else {
            $cond = substr($cond, 0, strlen($cond) - 5) . '))';
        }

        $eventIds = $this->_listEventsConditional($query->start,
                                                  empty($query->end)
                                                      ? new Horde_Date(array('mday' => 31, 'month' => 12, 'year' => 9999))
                                                      : $query->end,
                                                  $cond,
                                                  $values);
        if (is_a($eventIds, 'PEAR_Error')) {
            return $eventIds;
        }

        $events = array();
        foreach ($eventIds as $eventId) {
            $event = $this->getEvent($eventId);
            if (is_a($event, 'PEAR_Error')) {
                return $event;
            }
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Checks if the event's UID already exists and returns all event
     * ids with that UID.
     *
     * @param string $uid          The event's uid.
     * @param string $calendar_id  Calendar to search in.
     *
     * @return string|boolean  Returns a string with event_id or false if
     *                         not found.
     */
    public function exists($uid, $calendar_id = null)
    {
        $query = 'SELECT event_id  FROM ' . $this->_params['table'] . ' WHERE event_uid = ?';
        $values = array($uid);

        if (!is_null($calendar_id)) {
            $query .= ' AND calendar_id = ?';
            $values[] = $calendar_id;
        }

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::exists(): user = "%s"; query = "%s"',
                                  Auth::getAuth(), $query),
                          __FILE__, __LINE__, PEAR_LOG_DEBUG);

        $event = $this->_db->getRow($query, $values, DB_FETCHMODE_ASSOC);
        if (is_a($event, 'PEAR_Error')) {
            Horde::logMessage($event, __FILE__, __LINE__, PEAR_LOG_ERR);
            return $event;
        }

        if ($event) {
            return $event['event_id'];
        } else {
            return false;
        }
    }

    /**
     * Lists all events in the time range, optionally restricting results to
     * only events with alarms.
     *
     * @param Horde_Date $startInterval  Start of range date object.
     * @param Horde_Date $endInterval    End of range data object.
     * @param boolean $showRecurrence    Return every instance of a recurring
     *                                   event? If false, will only return
     *                                   recurring events once inside the
     *                                   $startDate - $endDate range.
     * @param boolean $hasAlarm          Only return events with alarms?
     * @param boolean $json              Store the results of the events'
     *                                   toJSON() method?
     *
     * @return array  Events in the given time range.
     */
    public function listEvents($startDate = null, $endDate = null,
                               $showRecurrence = false, $hasAlarm = false,
                               $json = false)
    {
        if (is_null($startDate)) {
            $startDate = new Horde_Date(array('mday' => 1,
                                              'month' => 1,
                                              'year' => 0000));
        }
        if (is_null($endDate)) {
            $endDate = new Horde_Date(array('mday' => 31,
                                            'month' => 12,
                                            'year' => 9999));
        }

        $startDate = clone $startDate;
        $startDate->hour = $startDate->min = $startDate->sec = 0;
        $endDate = clone $endDate;
        $endDate->hour = 23;
        $endDate->min = $endDate->sec = 59;

        $events = $this->_listEventsConditional($startDate, $endDate,
                                                $hasAlarm ? 'event_alarm > ?' : '',
                                                $hasAlarm ? array(0) : array());
        if (is_a($events, 'PEAR_Error')) {
            return $events;
        }
        $results = array();
        foreach ($events as $id) {
            Kronolith::addEvents($results, $this->getEvent($id), $startDate,
                                 $endDate, $showRecurrence, $json);
        }

        return $results;
    }

    /**
     * Lists all events that satisfy the given conditions.
     *
     * @param Horde_Date $startInterval  Start of range date object.
     * @param Horde_Date $endInterval    End of range data object.
     * @param string $conditions         Conditions, given as SQL clauses.
     * @param array $vals                SQL bind variables for use with
     *                                   $conditions clauses.
     *
     * @return array  Events in the given time range satisfying the given
     *                conditions.
     */
    private function _listEventsConditional($startInterval, $endInterval,
                                            $conditions = '', $vals = array())
    {
        $q = 'SELECT event_id, event_uid, event_description, event_location,' .
            ' event_private, event_status, event_attendees,' .
            ' event_title, event_recurcount,' .
            ' event_recurtype, event_recurenddate, event_recurinterval,' .
            ' event_recurdays, event_start, event_end, event_allday,' .
            ' event_alarm, event_alarm_methods, event_modified,' .
            ' event_exceptions, event_creator_id' .
            ' FROM ' . $this->_params['table'] .
            ' WHERE calendar_id = ? AND ((';
        $values = array($this->_calendar);

        if ($conditions) {
            $q .= $conditions . ')) AND ((';
            $values = array_merge($values, $vals);
        }

        $etime = $endInterval->format('Y-m-d H:i:s');
        $stime = null;
        if (isset($startInterval)) {
            $stime = $startInterval->format('Y-m-d H:i:s');
            $q .= 'event_end >= ? AND ';
            $values[] = $stime;
        }
        $q .= 'event_start <= ?) OR (';
        $values[] = $etime;
        if (isset($stime)) {
            $q .= 'event_recurenddate >= ? AND ';
            $values[] = $stime;
        }
        $q .= 'event_start <= ?' .
            ' AND event_recurtype <> ?))';
        array_push($values, $etime, Horde_Date_Recurrence::RECUR_NONE);

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::_listEventsConditional(): user = "%s"; query = "%s"; values = "%s"',
                                  Auth::getAuth(), $q, implode(',', $values)),
                          __FILE__, __LINE__, PEAR_LOG_DEBUG);

        /* Run the query. */
        $qr = $this->_db->query($q, $values);
        if (is_a($qr, 'PEAR_Error')) {
            Horde::logMessage($qr, __FILE__, __LINE__, PEAR_LOG_ERR);
            return $qr;
        }

        $events = array();
        $row = $qr->fetchRow(DB_FETCHMODE_ASSOC);
        while ($row && !is_a($row, 'PEAR_Error')) {
            /* If the event did not have a UID before, we need to give
             * it one. */
            if (empty($row['event_uid'])) {
                $row['event_uid'] = $this->generateUID();

                /* Save the new UID for data integrity. */
                $query = 'UPDATE ' . $this->_params['table'] . ' SET event_uid = ? WHERE event_id = ?';
                $values = array($row['event_uid'], $row['event_id']);

                /* Log the query at a DEBUG log level. */
                Horde::logMessage(sprintf('Kronolith_Driver_Sql::_listEventsConditional(): user = %s; query = "%s"; values = "%s"',
                                          Auth::getAuth(), $query, implode(',', $values)),
                                  __FILE__, __LINE__, PEAR_LOG_DEBUG);

                $result = $this->_write_db->query($query, $values);
                if (is_a($result, 'PEAR_Error')) {
                    Horde::logMessage($result, __FILE__, __LINE__, PEAR_LOG_ERR);
                }
            }

            /* We have all the information we need to create an event object
             * for this event, so go ahead and cache it. */
            $this->_cache[$this->_calendar][$row['event_id']] = new Kronolith_Event_Sql($this, $row);
            if ($row['event_recurtype'] == Horde_Date_Recurrence::RECUR_NONE) {
                $events[$row['event_uid']] = $row['event_id'];
            } else {
                $next = $this->nextRecurrence($row['event_id'], $startInterval);
                if ($next && $next->compareDateTime($endInterval) < 0) {
                    $events[$row['event_uid']] = $row['event_id'];
                }
            }

            $row = $qr->fetchRow(DB_FETCHMODE_ASSOC);
        }

        return $events;
    }

    /**
     * Returns the number of events in the current calendar.
     *
     * @return integer  The number of events.
     */
    public function countEvents()
    {
        $query = sprintf('SELECT count(*) FROM %s WHERE calendar_id = ?',
                         $this->_params['table']);
        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::_countEvents(): user = "%s"; query = "%s"; values = "%s"',
                                  Auth::getAuth(), $query, $this->_calendar),
                          __FILE__, __LINE__, PEAR_LOG_DEBUG);

        /* Run the query. */
        return $this->_db->getOne($query, array($this->_calendar));
    }

    public function getEvent($eventId = null)
    {
        if (!strlen($eventId)) {
            return new Kronolith_Event_Sql($this);
        }

        if (isset($this->_cache[$this->_calendar][$eventId])) {
            return $this->_cache[$this->_calendar][$eventId];
        }

        $query = 'SELECT event_id, event_uid, event_description,' .
            ' event_location, event_private, event_status, event_attendees,' .
            ' event_title, event_recurcount,' .
            ' event_recurtype, event_recurenddate, event_recurinterval,' .
            ' event_recurdays, event_start, event_end, event_allday,' .
            ' event_alarm, event_alarm_methods, event_modified,' .
            ' event_exceptions, event_creator_id' .
            ' FROM ' . $this->_params['table'] . ' WHERE event_id = ? AND calendar_id = ?';
        $values = array($eventId, $this->_calendar);

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::getEvent(): user = "%s"; query = "%s"; values = "%s"',
                                  Auth::getAuth(), $query, implode(',', $values)),
                          __FILE__, __LINE__, PEAR_LOG_DEBUG);

        $event = $this->_db->getRow($query, $values, DB_FETCHMODE_ASSOC);
        if (is_a($event, 'PEAR_Error')) {
            Horde::logMessage($event, __FILE__, __LINE__, PEAR_LOG_ERR);
            return $event;
        }

        if ($event) {
            $this->_cache[$this->_calendar][$eventId] = new Kronolith_Event_Sql($this, $event);
            return $this->_cache[$this->_calendar][$eventId];
        } else {
            return PEAR::raiseError(_("Event not found"));
        }
    }

    /**
     * Get an event or events with the given UID value.
     *
     * @param string $uid The UID to match
     * @param array $calendars A restricted array of calendar ids to search
     * @param boolean $getAll Return all matching events? If this is false,
     * an error will be returned if more than one event is found.
     *
     * @return Kronolith_Event
     */
    public function getByUID($uid, $calendars = null, $getAll = false)
    {
        $query = 'SELECT event_id, event_uid, calendar_id, event_description,' .
            ' event_location, event_private, event_status, event_attendees,' .
            ' event_title, event_recurcount,' .
            ' event_recurtype, event_recurenddate, event_recurinterval,' .
            ' event_recurdays, event_start, event_end, event_allday,' .
            ' event_alarm, event_alarm_methods, event_modified,' .
            ' event_exceptions, event_creator_id' .
            ' FROM ' . $this->_params['table'] . ' WHERE event_uid = ?';
        $values = array($uid);

        /* Optionally filter by calendar */
        if (!is_null($calendars)) {
            if (!count($calendars)) {
                return PEAR::raiseError(_("No calendars to search"));
            }
            $query .= ' AND calendar_id IN (?' . str_repeat(', ?', count($calendars) - 1) . ')';
            $values = array_merge($values, $calendars);
        }

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::getByUID(): user = "%s"; query = "%s"; values = "%s"',
                                  Auth::getAuth(), $query, implode(',', $values)),
                          __FILE__, __LINE__, PEAR_LOG_DEBUG);

        $events = $this->_db->getAll($query, $values, DB_FETCHMODE_ASSOC);
        if (is_a($events, 'PEAR_Error')) {
            Horde::logMessage($events, __FILE__, __LINE__, PEAR_LOG_ERR);
            return $events;
        }
        if (!count($events)) {
            return PEAR::raiseError($uid . ' not found');
        }

        $eventArray = array();
        foreach ($events as $event) {
            $this->open($event['calendar_id']);
            $this->_cache[$this->_calendar][$event['event_id']] = new Kronolith_Event_Sql($this, $event);
            $eventArray[] = $this->_cache[$this->_calendar][$event['event_id']];
        }

        if ($getAll) {
            return $eventArray;
        }

        /* First try the user's own calendars. */
        $ownerCalendars = Kronolith::listCalendars(true, PERMS_READ);
        $event = null;
        foreach ($eventArray as $ev) {
            if (isset($ownerCalendars[$ev->getCalendar()])) {
                $event = $ev;
                break;
            }
        }

        /* If not successful, try all calendars the user has access too. */
        if (empty($event)) {
            $readableCalendars = Kronolith::listCalendars(false, PERMS_READ);
            foreach ($eventArray as $ev) {
                if (isset($readableCalendars[$ev->getCalendar()])) {
                    $event = $ev;
                    break;
                }
            }
        }

        if (empty($event)) {
            $event = $eventArray[0];
        }

        return $event;
    }

    /**
     * Saves an event in the backend.
     * If it is a new event, it is added, otherwise the event is updated.
     *
     * @param Kronolith_Event $event  The event to save.
     */
    public function saveEvent($event)
    {
        if ($event->isStored() || $event->exists()) {
            $values = array();

            $query = 'UPDATE ' . $this->_params['table'] . ' SET ';

            foreach ($event->getProperties() as $key => $val) {
                $query .= " $key = ?,";
                $values[] = $val;
            }
            $query = substr($query, 0, -1);
            $query .= ' WHERE event_id = ?';
            $values[] = $event->getId();

            /* Log the query at a DEBUG log level. */
            Horde::logMessage(sprintf('Kronolith_Driver_Sql::saveEvent(): user = "%s"; query = "%s"; values = "%s"',
                                      Auth::getAuth(), $query, implode(',', $values)),
                              __FILE__, __LINE__, PEAR_LOG_DEBUG);

            $result = $this->_write_db->query($query, $values);
            if (is_a($result, 'PEAR_Error')) {
                Horde::logMessage($result, __FILE__, __LINE__, PEAR_LOG_ERR);
                return $result;
            }

            /* Log the modification of this item in the history log. */
            if ($event->getUID()) {
                $history = Horde_History::singleton();
                $history->log('kronolith:' . $this->_calendar . ':' . $event->getUID(), array('action' => 'modify'), true);
            }

            /* Update tags */
            $tagger = Kronolith::getTagger();
            $tagger->replaceTags($event->getUID(), $event->tags, 'event');

            /* Notify users about the changed event. */
            $result = Kronolith::sendNotification($event, 'edit');
            if (is_a($result, 'PEAR_Error')) {
                Horde::logMessage($result, __FILE__, __LINE__, PEAR_LOG_ERR);
            }

            return $event->getId();
        } else {
            if ($event->getId()) {
                $id = $event->getId();
            } else {
                $id = hash('md5', uniqid(mt_rand(), true));
                $event->setId($id);
            }

            if ($event->getUID()) {
                $uid = $event->getUID();
            } else {
                $uid = $this->generateUID();
                $event->setUID($uid);
            }

            $query = 'INSERT INTO ' . $this->_params['table'];
            $cols_name = ' (event_id, event_uid,';
            $cols_values = ' VALUES (?, ?,';
            $values = array($id, $uid);

            foreach ($event->getProperties() as $key => $val) {
                $cols_name .= " $key,";
                $cols_values .= ' ?,';
                $values[] = $val;
            }

            $cols_name .= ' calendar_id)';
            $cols_values .= ' ?)';
            $values[] = $this->_calendar;

            $query .= $cols_name . $cols_values;

            /* Log the query at a DEBUG log level. */
            Horde::logMessage(sprintf('Kronolith_Driver_Sql::saveEvent(): user = "%s"; query = "%s"; values = "%s"',
                                Auth::getAuth(), $query, implode(',', $values)),
                                __FILE__, __LINE__, PEAR_LOG_DEBUG);

            $result = $this->_write_db->query($query, $values);
            if (is_a($result, 'PEAR_Error')) {
                Horde::logMessage($result, __FILE__, __LINE__, PEAR_LOG_ERR);
                return $result;
            }

            /* Log the creation of this item in the history log. */
            $history = Horde_History::singleton();
            $history->log('kronolith:' . $this->_calendar . ':' . $uid, array('action' => 'add'), true);

            /* Deal with any tags */
            $tagger = Kronolith::getTagger();
            $tagger->tag($event->getUID(), $event->tags, 'event');

            /* Notify users about the new event. */
            $result = Kronolith::sendNotification($event, 'add');
            if (is_a($result, 'PEAR_Error')) {
                Horde::logMessage($result, __FILE__, __LINE__, PEAR_LOG_ERR);
            }

            return $id;
        }
    }

    /**
     * Move an event to a new calendar.
     *
     * @param string $eventId      The event to move.
     * @param string $newCalendar  The new calendar.
     */
    public function move($eventId, $newCalendar)
    {
        /* Fetch the event for later use. */
        $event = $this->getEvent($eventId);
        if (is_a($event, 'PEAR_Error')) {
            return $event;
        }

        $query = 'UPDATE ' . $this->_params['table'] . ' SET calendar_id = ? WHERE calendar_id = ? AND event_id = ?';
        $values = array($newCalendar, $this->_calendar, $eventId);

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::move(): %s; values = "%s"',
                                  $query, implode(',', $values)),
                          __FILE__, __LINE__, PEAR_LOG_DEBUG);

        /* Attempt the move query. */
        $result = $this->_write_db->query($query, $values);
        if (is_a($result, 'PEAR_Error')) {
            Horde::logMessage($result, __FILE__, __LINE__, PEAR_LOG_ERR);
            return $result;
        }

        /* Log the moving of this item in the history log. */
        $uid = $event->getUID();
        if ($uid) {
            $history = Horde_History::singleton();
            $history->log('kronolith:' . $this->_calendar . ':' . $uid, array('action' => 'delete'), true);
            $history->log('kronolith:' . $newCalendar . ':' . $uid, array('action' => 'add'), true);
        }

        return true;
    }

    /**
     * Delete a calendar and all its events.
     *
     * @param string $calendar  The name of the calendar to delete.
     *
     * @return mixed  True or a PEAR_Error on failure.
     */
    public function delete($calendar)
    {
        $query = 'DELETE FROM ' . $this->_params['table'] . ' WHERE calendar_id = ?';
        $values = array($calendar);

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::delete(): user = "%s"; query = "%s"; values = "%s"',
                                  Auth::getAuth(), $query, implode(',', $values)),
                          __FILE__, __LINE__, PEAR_LOG_DEBUG);

        return $this->_write_db->query($query, $values);
    }

    /**
     * Delete an event.
     *
     * @param string $eventId  The ID of the event to delete.
     * @param boolean $silent  Don't send notifications, used when deleting
     *                         events in bulk from maintenance tasks.
     *
     * @return mixed  True or a PEAR_Error on failure.
     */
    public function deleteEvent($eventId, $silent = false)
    {
        /* Fetch the event for later use. */
        $event = $this->getEvent($eventId);
        if (is_a($event, 'PEAR_Error')) {
            return $event;
        }

        $query = 'DELETE FROM ' . $this->_params['table'] . ' WHERE event_id = ? AND calendar_id = ?';
        $values = array($eventId, $this->_calendar);

        /* Log the query at a DEBUG log level. */
        Horde::logMessage(sprintf('Kronolith_Driver_Sql::deleteEvent(): user = "%s"; query = "%s"; values = "%s"',
                                  Auth::getAuth(), $query, implode(',', $values)),
                          __FILE__, __LINE__, PEAR_LOG_DEBUG);

        $result = $this->_write_db->query($query, $values);
        if (is_a($result, 'PEAR_Error')) {
            Horde::logMessage($result, __FILE__, __LINE__, PEAR_LOG_ERR);
            return $result;
        }

        /* Log the deletion of this item in the history log. */
        if ($event->getUID()) {
            $history = Horde_History::singleton();
            $history->log('kronolith:' . $this->_calendar . ':' . $event->getUID(), array('action' => 'delete'), true);
        }

        /* Remove any pending alarms. */
        if (@include_once 'Horde/Alarm.php') {
            $alarm = Horde_Alarm::factory();
            $alarm->delete($event->getUID());
        }

        /* Remove any tags */
        $tagger = Kronolith::getTagger();
        $tagger->replaceTags($event->getUID(), array(), 'event');

        /* Notify about the deleted event. */
        if (!$silent) {
            $result = Kronolith::sendNotification($event, 'delete');
            if (is_a($result, 'PEAR_Error')) {
                Horde::logMessage($result, __FILE__, __LINE__, PEAR_LOG_ERR);
            }
        }
        return true;
    }

    /**
     * Attempts to open a connection to the SQL server.
     *
     * @return boolean True.
     */
    public function initialize()
    {
        Horde::assertDriverConfig($this->_params, 'calendar',
            array('phptype'));

        if (!isset($this->_params['database'])) {
            $this->_params['database'] = '';
        }
        if (!isset($this->_params['username'])) {
            $this->_params['username'] = '';
        }
        if (!isset($this->_params['hostspec'])) {
            $this->_params['hostspec'] = '';
        }
        if (!isset($this->_params['table'])) {
            $this->_params['table'] = 'kronolith_events';
        }

        /* Connect to the SQL server using the supplied parameters. */
        $this->_write_db = DB::connect($this->_params,
                                       array('persistent' => !empty($this->_params['persistent']),
                                             'ssl' => !empty($this->_params['ssl'])));
        if (is_a($this->_write_db, 'PEAR_Error')) {
            return $this->_write_db;
        }
        $this->_initConn($this->_write_db);

        /* Check if we need to set up the read DB connection
         * seperately. */
        if (!empty($this->_params['splitread'])) {
            $params = array_merge($this->_params, $this->_params['read']);
            $this->_db = DB::connect($params,
                                     array('persistent' => !empty($params['persistent']),
                                           'ssl' => !empty($params['ssl'])));
            if (is_a($this->_db, 'PEAR_Error')) {
                return $this->_db;
            }
            $this->_initConn($this->_db);
        } else {
            /* Default to the same DB handle for the writer too. */
            $this->_db = $this->_write_db;
        }

        return true;
    }

    /**
     */
    private function _initConn(&$db)
    {
        // Set DB portability options.
        switch ($db->phptype) {
        case 'mssql':
            $db->setOption('portability', DB_PORTABILITY_LOWERCASE | DB_PORTABILITY_ERRORS | DB_PORTABILITY_RTRIM);
            break;
        default:
            $db->setOption('portability', DB_PORTABILITY_LOWERCASE | DB_PORTABILITY_ERRORS);
        }

        /* Handle any database specific initialization code to run. */
        switch ($db->dbsyntax) {
        case 'oci8':
            $query = "ALTER SESSION SET NLS_DATE_FORMAT = 'YYYY-MM-DD HH24:MI:SS'";

            /* Log the query at a DEBUG log level. */
            Horde::logMessage(sprintf('Kronolith_Driver_Sql::_initConn(): user = "%s"; query = "%s"',
                                      Auth::getAuth(), $query),
                              __FILE__, __LINE__, PEAR_LOG_DEBUG);

            $db->query($query);
            break;

        case 'pgsql':
            $query = "SET datestyle TO 'iso'";

            /* Log the query at a DEBUG log level. */
            Horde::logMessage(sprintf('Kronolith_Driver_Sql::_initConn(): user = "%s"; query = "%s"',
                                      Auth::getAuth(), $query),
                              __FILE__, __LINE__, PEAR_LOG_DEBUG);

            $db->query($query);
            break;
        }
    }

    /**
     * Converts a value from the driver's charset to the default
     * charset.
     *
     * @param mixed $value  A value to convert.
     *
     * @return mixed  The converted value.
     */
    public function convertFromDriver($value)
    {
        return String::convertCharset($value, $this->_params['charset']);
    }

    /**
     * Converts a value from the default charset to the driver's
     * charset.
     *
     * @param mixed $value  A value to convert.
     *
     * @return mixed  The converted value.
     */
    public function convertToDriver($value)
    {
        return String::convertCharset($value, NLS::getCharset(), $this->_params['charset']);
    }

    /**
     * Remove all events owned by the specified user in all calendars.
     *
     * @todo Refactor: move to Kronolith::
     *
     * @param string $user  The user name to delete events for.
     *
     * @param mixed  True | PEAR_Error
     */
    public function removeUserData($user)
    {
        return PEAR::raiseError('to be refactored');

        if (!Auth::isAdmin()) {
            return PEAR::raiseError(_("Permission Denied"));
        }

        $shares = $GLOBALS['kronolith_shares']->listShares($user, PERMS_EDIT);
        if (is_a($shares, 'PEAR_Error')) {
            return $shares;
        }

        foreach (array_keys($shares) as $calendar) {
            $ids = Kronolith::listEventIds(null, null, $calendar);
            if (is_a($ids, 'PEAR_Error')) {
                return $ids;
            }
            $uids = array();
            foreach ($ids as $cal) {
                $uids = array_merge($uids, array_keys($cal));
            }

            foreach ($uids as $uid) {
                $event = $this->getByUID($uid);
                if (is_a($event, 'PEAR_Error')) {
                    return $event;
                }

                $this->deleteEvent($event->getId());
            }
        }

        return true;
    }

}
