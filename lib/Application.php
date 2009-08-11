<?php
/**
 * Kronolith application API.
 *
 * @package Kronolith
 */
class Kronolith_Application extends Horde_Registry_Application
{
    public $version = 'H3 (3.0-git)';

    /**
     * Code to run when viewing prefs for this application.
     *
     * @param string $group  The prefGroup name.
     *
     * @return array  A list of variables to export to the prefs display page.
     */
    public function prefsInit($group)
    {
        $out = array();

        if (!$GLOBALS['prefs']->isLocked('day_hour_start') ||
            !$GLOBALS['prefs']->isLocked('day_hour_end')) {
            $out['day_hour_start_options'] = array();
            for ($i = 0; $i <= 48; ++$i) {
                $out['day_hour_start_options'][$i] = date(($GLOBALS['prefs']->getValue('twentyFour')) ? 'G:i' : 'g:ia', mktime(0, $i * 30, 0));
            }
            $out['day_hour_end_options'] = $out['day_hour_start_options'];
        }

        if (!empty($GLOBALS['conf']['holidays']['enable'])) {
            if (class_exists('Date_Holidays')) {
                foreach (Date_Holidays::getInstalledDrivers() as $driver) {
                    if ($driver['id'] == 'Composite') {
                        continue;
                    }
                    $_prefs['holiday_drivers']['enum'][$driver['id']] = $driver['title'];
                }
                asort($_prefs['holiday_drivers']['enum']);
            } else {
                $GLOBALS['notification']->push(_("Holidays support is not available on this server."), 'horde.error');
            }
        }

        return $out;
    }

    /**
     * Special preferences handling on update.
     *
     * @param string $item      The preference name.
     * @param boolean $updated  Set to true if preference was updated.
     *
     * @return boolean  True if preference was updated.
     */
    public function prefsHandle($item, $updated)
    {
        switch ($item) {
        case 'remote_cal_management':
            return $this->_prefsRemoteCalManagement($updated);

        case 'shareselect':
            return $this->_prefsShareSelect($updated);

        case 'holiday_drivers':
            $this->_prefsHolidayDrivers($updated);
            return true;

        case 'sourceselect':
            return $this->_prefsSourceSelect($updated);

        case 'fb_cals_select':
            $this->_prefsFbCalsSelect($updated);
            return true;

        case 'default_alarm_management':
            $GLOBALS['prefs']->setValue('default_alarm', (int)Horde_Util::getFormData('alarm_value') * (int)Horde_Util::getFormData('alarm_unit'));
            return true;
        }
    }

    /**
     * Do anything that we need to do as a result of certain preferences
     * changing.
     */
    public function prefsCallback()
    {
        if ($GLOBALS['prefs']->isDirty('event_alarms')) {
            $alarms = $GLOBALS['registry']->callByPackage('kronolith', 'listAlarms', array($_SERVER['REQUEST_TIME']));
            if (!is_a($alarms, 'PEAR_Error') && !empty($alarms)) {
                $horde_alarm = Horde_Alarm::factory();
                foreach ($alarms as $alarm) {
                    $alarm['start'] = new Horde_Date($alarm['start']);
                    $alarm['end'] = new Horde_Date($alarm['end']);
                    $horde_alarm->set($alarm);
                }
            }
        }
    }

    /**
     * Generate the menu to use on the prefs page.
     *
     * @return Horde_Menu  A Horde_Menu object.
     */
    public function prefsMenu()
    {
        return Kronolith::getMenu();
    }

    /**
     * TODO
     */
    protected function _prefsRemoteCalManagement($updated)
    {
        $calName = Horde_Util::getFormData('remote_name');
        $calUrl  = trim(Horde_Util::getFormData('remote_url'));
        $calUser = trim(Horde_Util::getFormData('remote_user'));
        $calPasswd = trim(Horde_Util::getFormData('remote_password'));

        $key = Horde_Auth::getCredential('password');
        if ($key) {
            $calUser = base64_encode(Secret::write($key, $calUser));
            $calPasswd = base64_encode(Secret::write($key, $calPasswd));
        }

        $calActionID = Horde_Util::getFormData('remote_action', 'add');

        if ($calActionID == 'add') {
            if (!empty($calName) && !empty($calUrl)) {
                $cals = unserialize($GLOBALS['prefs']->getValue('remote_cals'));
                $cals[] = array('name' => $calName,
                    'url'  => $calUrl,
                    'user' => $calUser,
                    'password' => $calPasswd);
                $GLOBALS['prefs']->setValue('remote_cals', serialize($cals));
                return $updated;
            }
        } elseif ($calActionID == 'delete') {
            $cals = unserialize($GLOBALS['prefs']->getValue('remote_cals'));
            foreach ($cals as $key => $cal) {
                if ($cal['url'] == $calUrl) {
                    unset($cals[$key]);
                    break;
                }
            }
            $GLOBALS['prefs']->setValue('remote_cals', serialize($cals));
            return $updated;
        } elseif ($calActionID == 'edit') {
            $cals = unserialize($GLOBALS['prefs']->getValue('remote_cals'));
            foreach ($cals as $key => $cal) {
                if ($cal['url'] == $calUrl) {
                    $cals[$key]['name'] = $calName;
                    $cals[$key]['url'] = $calUrl;
                    $cals[$key]['user'] = $calUser;
                    $cals[$key]['password'] = $calPasswd;
                    break;
                }
            }
            $GLOBALS['prefs']->setValue('remote_cals', serialize($cals));
            return $updated;
        }

        return false;
    }

    /**
     * TODO
     */
    protected function _prefsShareSelect($updated)
    {
        $default_share = Horde_Util::getFormData('default_share');
        if (!is_null($default_share)) {
            $sharelist = Kronolith::listCalendars();
            if ((is_array($sharelist)) > 0 &&
                isset($sharelist[$default_share])) {
                $GLOBALS['prefs']->setValue('default_share', $default_share);
                return true;
            }
        }

        return $updated;
    }

    /**
     * TODO
     */
    protected function _prefsHolidayDrivers()
    {
        $holiday_driversSelected = Horde_Util::getFormData('holiday_drivers');
        $holiday_driversFiltered = array();

        if (is_array($holiday_driversSelected)) {
            foreach ($holiday_driversSelected as $holiday_driver) {
                $holiday_driversFiltered[] = $holiday_driver;
            }
        }

        $GLOBALS['prefs']->setValue('holiday_drivers', serialize($holiday_driversFiltered));
    }

    /**
     * TODO
     */
    protected function _prefsSourceSelect($updated)
    {
        $search_sources = Horde_Util::getFormData('search_sources');
        if (!is_null($search_sources)) {
            $GLOBALS['prefs']->setValue('search_sources', $search_sources);
            $updated = true;
        }

        $search_fields_string = Horde_Util::getFormData('search_fields_string');
        if (!is_null($search_fields_string)) {
            $GLOBALS['prefs']->setValue('search_fields', $search_fields_string);
            $updated = true;
        }

        return $updated;
    }

    /**
     * TODO
     */
    protected function _prefsHandleFbCalsSelect()
    {
        $fb_calsSelected = Horde_Util::getFormData('fb_cals');
        $fb_cals = Kronolith::listCalendars();
        $fb_calsFiltered = array();

        if (isset($fb_calsSelected) && is_array($fb_calsSelected)) {
            foreach ($fb_calsSelected as $fb_cal) {
                $fb_calsFiltered[] = $fb_cal;
            }
        }

        $GLOBALS['prefs']->setValue('fb_cals', serialize($fb_calsFiltered));
    }

}
