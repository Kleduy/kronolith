<?php
/**
 * Copyright 1999-2017 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (GPL). If you
 * did not receive this file, see http://www.horde.org/licenses/gpl.
 *
 * @author  Luc Saillard <luc.saillard@fr.alcove.com>
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @author  Jan Schneider <jan@horde.org>
 * @package Kronolith
 */
class Kronolith_Event_Sql extends Kronolith_Event
{
    /**
     * The type of the calender this event exists on.
     *
     * @var string
     */
    public $calendarType = 'internal';

    /**
     * Constructor.
     *
     * @param Kronolith_Driver $driver  The backend driver that this event
     *                                  is stored in.
     * @param mixed $eventObject        Backend specific event object
     *                                  that this will represent.
     */
    public function __construct(Kronolith_Driver $driver, $eventObject = null)
    {
        /* Set default alarm value. */
        if (isset($GLOBALS['prefs'])) {
            $this->alarm = $GLOBALS['prefs']->getValue('default_alarm');
        }

        parent::__construct($driver, $eventObject);

        if (!empty($this->calendar) &&
            $GLOBALS['calendar_manager']->getEntry(Kronolith::ALL_CALENDARS, $this->calendar) !== false) {
            $this->_backgroundColor = $GLOBALS['calendar_manager']->getEntry(Kronolith::ALL_CALENDARS, $this->calendar)->background();
            $this->_foregroundColor = $GLOBALS['calendar_manager']->getEntry(Kronolith::ALL_CALENDARS, $this->calendar)->foreground();
        }
    }

    /**
     * Imports a backend specific event object.
     *
     * @param array $event  Backend specific event object that this object
     *                      will represent.
     */
    public function fromDriver($SQLEvent)
    {
        $driver = $this->getDriver();

        if (isset($SQLEvent['event_timezone'])) {
            $this->timezone = $SQLEvent['event_timezone'];
        }

        $tz_local = date_default_timezone_get();
        $this->allday = (bool)$SQLEvent['event_allday'];
        if (!$this->allday && $driver->getParam('utc')) {
            $this->start = new Horde_Date($SQLEvent['event_start'], 'UTC');
            $this->start->setTimezone($tz_local);
            $this->end = new Horde_Date($SQLEvent['event_end'], 'UTC');
            $this->end->setTimezone($tz_local);
        } else {
            $this->start = new Horde_Date($SQLEvent['event_start']);
            $this->end = new Horde_Date($SQLEvent['event_end']);
            if ($this->end->hour == 23 && $this->end->min == 59) {
                $this->end->hour = $this->end->min = $this->end->sec = 0;
                $this->end->mday++;
            }
        }

        $this->durMin = ($this->end->timestamp() - $this->start->timestamp()) / 60;

        $this->title = $driver->convertFromDriver($SQLEvent['event_title']);
        $this->id = $SQLEvent['event_id'];
        $this->uid = $SQLEvent['event_uid'];
        $this->creator = $SQLEvent['event_creator_id'];
        $this->organizer = $SQLEvent['event_organizer'];

        if (!empty($SQLEvent['event_recurtype'])) {
            $this->recurrence = new Horde_Date_Recurrence($this->start);
            $this->recurrence->setRecurType((int)$SQLEvent['event_recurtype']);
            $this->recurrence->setRecurInterval((int)$SQLEvent['event_recurinterval']);
            if (isset($SQLEvent['event_recurenddate']) &&
                $SQLEvent['event_recurenddate'] != '9999-12-31 23:59:59') {
                if ($driver->getParam('utc')) {
                    $recur_end = new Horde_Date($SQLEvent['event_recurenddate'], 'UTC');
                    if ($recur_end->min == 0) {
                        /* Old recurrence end date format. */
                        $recur_end = new Horde_Date($SQLEvent['event_recurenddate']);
                        $recur_end->hour = 23;
                        $recur_end->min = 59;
                        $recur_end->sec = 59;
                    } else {
                        $recur_end->setTimezone(date_default_timezone_get());
                    }
                } else {
                    $recur_end = new Horde_Date($SQLEvent['event_recurenddate']);
                    $recur_end->hour = 23;
                    $recur_end->min = 59;
                    $recur_end->sec = 59;
                }
                $this->recurrence->setRecurEnd($recur_end);
            }
            if (isset($SQLEvent['event_recurcount'])) {
                $this->recurrence->setRecurCount((int)$SQLEvent['event_recurcount']);
            }
            if (isset($SQLEvent['event_recurdays'])) {
                $this->recurrence->recurData = (int)$SQLEvent['event_recurdays'];
            }
            if (!empty($SQLEvent['event_exceptions'])) {
                $this->recurrence->exceptions = explode(',', $SQLEvent['event_exceptions']);
            }
        }

        if (isset($SQLEvent['event_location'])) {
            $this->location = $driver->convertFromDriver($SQLEvent['event_location']);
        }
        if (isset($SQLEvent['event_url'])) {
            $this->url = $SQLEvent['event_url'];
        }
        if (isset($SQLEvent['event_private'])) {
            $this->private = (bool)($SQLEvent['event_private']);
        }
        if (isset($SQLEvent['event_status'])) {
            $this->status = (int)$SQLEvent['event_status'];
        }
        if (isset($SQLEvent['event_attendees'])) {
            $attendees = unserialize($SQLEvent['event_attendees']);
            if ($attendees) {
                if (!is_object($attendees)) {
                    $this->attendees = new Kronolith_Attendee_List();
                    foreach ($attendees as $email => $attendee) {
                        $this->attendees->add(Kronolith_Attendee::migrate(
                            $email, $driver->convertFromDriver($attendee)
                        ));
                    }
                } else {
                    $this->attendees = new Kronolith_Attendee_List(iterator_to_array($attendees));
                }
            }
        }
        if (isset($SQLEvent['event_resources'])) {
            $resources = unserialize($SQLEvent['event_resources']);
            if ($resources) {
                $this->_resources = array_change_key_case($driver->convertFromDriver($resources));
            }
        }
        if (isset($SQLEvent['event_description'])) {
            $this->description = $driver->convertFromDriver($SQLEvent['event_description']);
        }
        if (isset($SQLEvent['event_alarm'])) {
            $this->alarm = (int)$SQLEvent['event_alarm'];
        }
        if (isset($SQLEvent['event_alarm_methods'])) {
            $methods = unserialize($SQLEvent['event_alarm_methods']);
            if ($methods) {
                $this->methods = $driver->convertFromDriver($methods);
            }
        }
        if (isset($SQLEvent['event_baseid'])) {
            $this->baseid = $SQLEvent['event_baseid'];
        }
        if (isset($SQLEvent['event_exceptionoriginaldate'])) {
            if ($driver->getParam('utc')) {
               $this->exceptionoriginaldate = new Horde_Date($SQLEvent['event_exceptionoriginaldate'], 'UTC');
               $this->exceptionoriginaldate->setTimezone($tz_local);
            } else {
                $this->exceptionoriginaldate = new Horde_Date($SQLEvent['event_exceptionoriginaldate']);
            }

        }
        if (isset($SQLEvent['other_attributes'])) {
            // Maybe we need to guard this better against crap from the backend
            $this->otherAttributes = json_decode($SQLEvent['other_attributes'], true) ?? [];
        }

        $this->initialized = true;
        $this->stored = true;
    }

    /**
     * Prepares this event to be saved to the backend.
     *
     * @param boolean $full  Return full data, including uid and id.
     *
     * @return array  The event properties.
     */
    public function toProperties($full = false)
    {
        $driver = $this->getDriver();
        $properties = array();

        if ($full) {
            $properties['event_id'] = $this->id;
            $properties['event_uid'] = $this->uid;
        }

        /* Basic fields. */
        $properties['event_creator_id'] = $driver->convertToDriver($this->creator);
        $properties['event_title'] = $driver->convertToDriver($this->title);
        $properties['event_description'] = $driver->convertToDriver($this->description);
        $properties['event_location'] = $driver->convertToDriver($this->location);
        $properties['event_timezone'] = $this->timezone;
        $properties['event_url'] = (string)$this->url;
        $properties['event_private'] = (int)$this->private;
        $properties['event_status'] = $this->status;
        $properties['event_attendees'] = serialize($this->attendees);
        $properties['event_resources'] = serialize($driver->convertToDriver($this->getResources()));
        $properties['event_modified'] = $_SERVER['REQUEST_TIME'];
        $properties['event_organizer'] = $this->organizer;

        if ($this->isAllDay()) {
            $properties['event_start'] = $this->start->strftime('%Y-%m-%d %H:%M:%S');
            $properties['event_end'] = $this->end->strftime('%Y-%m-%d %H:%M:%S');
            $properties['event_allday'] = 1;
        } else {
            if ($driver->getParam('utc')) {
                $start = clone $this->start;
                $end = clone $this->end;
                $start->setTimezone('UTC');
                $end->setTimezone('UTC');
            } else {
                $start = $this->start;
                $end = $this->end;
            }
            $properties['event_start'] = $start->strftime('%Y-%m-%d %H:%M:%S');
            $properties['event_end'] = $end->strftime('%Y-%m-%d %H:%M:%S');
            $properties['event_allday'] = 0;
        }

        /* Alarm. */
        $properties['event_alarm'] = (int)$this->alarm;

        /* Alarm Notification Methods. */
        $properties['event_alarm_methods'] = serialize($driver->convertToDriver($this->methods));

        /* Recurrence. */
        if (!$this->recurs()) {
            $properties['event_recurtype'] = 0;
        } else {
            $recur = $this->recurrence->getRecurType();
            if ($this->recurrence->hasRecurEnd()) {
                if ($driver->getParam('utc')) {
                    $recur_end = clone $this->recurrence->recurEnd;
                    $recur_end->setTimezone('UTC');
                } else {
                    $recur_end = $this->recurrence->recurEnd;
                }
            } else {
                $recur_end = new Horde_Date(array('year' => 9999, 'month' => 12, 'mday' => 31, 'hour' => 23, 'min' => 59, 'sec' => 59));
            }

            $properties['event_recurtype'] = $recur;
            $properties['event_recurinterval'] = $this->recurrence->getRecurInterval();
            $properties['event_recurenddate'] = $recur_end->format('Y-m-d H:i:s');
            $properties['event_recurcount'] = $this->recurrence->getRecurCount();

            switch ($recur) {
            case Horde_Date_Recurrence::RECUR_WEEKLY:
                $properties['event_recurdays'] = $this->recurrence->getRecurOnDays();
                break;
            }
            $properties['event_exceptions'] = implode(',', $this->recurrence->getExceptions());
        }

        /* Exception information */
        if (!empty($this->baseid)) {
            $properties['event_baseid'] = $this->baseid;
            if ($driver->getParam('utc')) {
                $eod = clone $this->exceptionoriginaldate;
                $eod->setTimezone('UTC');
            } else {
                $eod = $this->exceptionoriginaldate;
            }
            $properties['event_exceptionoriginaldate'] = $eod->strftime('%Y-%m-%d %H:%M:%S');
        } else {
            /* This must be an empty string. */
            $properties['event_baseid'] = '';
            $properties['event_exceptionoriginaldate'] = null;
        }
        $properties['other_attributes'] = json_encode($this->otherAttributes);

        return $properties;
    }

}
