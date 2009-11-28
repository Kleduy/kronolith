<?php
/**
 * Horde_Form for deleting calendars.
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @package Kronolith
 */

/** Horde_Form */
require_once 'Horde/Form.php';

/** Horde_Form_Renderer */
require_once 'Horde/Form/Renderer.php';

/**
 * The Kronolith_DeleteCalendarForm class provides the form for
 * deleting a calendar.
 *
 * @author  Chuck Hagenbuch <chuck@horde.org>
 * @package Kronolith
 */
class Kronolith_DeleteCalendarForm extends Horde_Form {

    /**
     * Calendar being deleted
     */
    var $_calendar;

    function Kronolith_DeleteCalendarForm(&$vars, &$calendar)
    {
        $this->_calendar = &$calendar;
        parent::Horde_Form($vars, sprintf(_("Delete %s"), $calendar->get('name')));

        $this->addHidden('', 'c', 'text', true);
        $this->addVariable(sprintf(_("Really delete the calendar \"%s\"? This cannot be undone and all data on this calendar will be permanently removed."), $this->_calendar->get('name')), 'desc', 'description', false);

        $this->setButtons(array(_("Delete"), _("Cancel")));
    }

    function execute()
    {
        // If cancel was clicked, return false.
        if ($this->_vars->get('submitbutton') == _("Cancel")) {
            return false;
        }

        return Kronolith::deleteShare($this->_calendar);
    }

}
