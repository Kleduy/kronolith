<?php
/**
 * Copyright 2002-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/gpl.html.
 *
 * @author Chuck Hagenbuch <chuck@horde.org>
 */

require_once dirname(__FILE__) . '/../lib/Application.php';
Horde_Registry::appInit('kronolith');

require_once KRONOLITH_BASE . '/lib/Forms/EditCalendar.php';

// Exit if this isn't an authenticated user.
if (!Horde_Auth::getAuth()) {
    header('Location: ' . Horde::applicationUrl($prefs->getValue('defaultview') . '.php', true));
    exit;
}

$vars = Horde_Variables::getDefaultVariables();
try {
    $calendar = $kronolith_shares->getShare($vars->get('c'));
} catch (Exception $e) {
    $notification->push($e, 'horde.error');
    header('Location: ' . Horde::applicationUrl('calendars/', true));
    exit;
}
if ($calendar->get('owner') != Horde_Auth::getAuth() &&
    (!is_null($calendar->get('owner')) || !Horde_Auth::isAdmin())) {
    $notification->push(_("You are not allowed to change this calendar."), 'horde.error');
    header('Location: ' . Horde::applicationUrl('calendars/', true));
    exit;
}
$form = new Kronolith_EditCalendarForm($vars, $calendar);

// Execute if the form is valid.
if ($form->validate($vars)) {
    $original_name = $calendar->get('name');
    try {
        $form->execute();
        if ($calendar->get('name') != $original_name) {
            $notification->push(sprintf(_("The calendar \"%s\" has been renamed to \"%s\"."), $original_name, $calendar->get('name')), 'horde.success');
        } else {
            $notification->push(sprintf(_("The calendar \"%s\" has been saved."), $original_name), 'horde.success');
        }
    } catch (Exception $e) {
        $notification->push($e, 'horde.error');
    }
    header('Location: ' . Horde::applicationUrl('calendars/', true));
    exit;
}

$vars->set('name', $calendar->get('name'));
$vars->set('color', $calendar->get('color'));
$vars->set('description', $calendar->get('desc'));
$tagger = Kronolith::getTagger();
$vars->set('tags', implode(',', array_values($tagger->getTags($calendar->getName(), 'calendar'))));
$vars->set('system', is_null($calendar->get('owner')));
$title = $form->getTitle();
require KRONOLITH_TEMPLATES . '/common-header.inc';
require KRONOLITH_TEMPLATES . '/menu.inc';
echo $form->renderActive($form->getRenderer(), $vars, 'edit.php', 'post');
require $registry->get('templates', 'horde') . '/common-footer.inc';
$ac = Horde_Ajax_Imple::factory(array('kronolith', 'TagAutoCompleter'), array('triggerId' => 'tags', 'id'));
$ac->attach();
