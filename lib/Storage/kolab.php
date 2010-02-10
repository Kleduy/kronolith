<?php
/**
 * Horde Kronolith free/busy driver for the Kolab IMAP Server.
 * Copyright 2004-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * not receive such a file, see also http://www.fsf.org/copyleft/gpl.html.
 *
 * @author  Stuart Binge <omicron@mighty.co.za>
 * @package Kronolith
 */
class Kronolith_Storage_kolab extends Kronolith_Storage
{
    protected $_params = array();

    function __construct($user, $params = array())
    {
        $this->_user = $user;
        $this->_params = $params;
    }

    /**
     * @throws Kronolith_Exception
     */
    public function search($email, $private_only = false)
    {
        global $conf;

        if (class_exists('Horde_Kolab_Session')) {
            $session = Horde_Kolab_Session::singleton();
            $server = $session->freebusy_server;
        } else {
            $server = sprintf('%s://%s:%d/freebusy/',
                              $conf['storage']['freebusy']['protocol'],
                              Kolab::getServer('imap'),
                              $conf['storage']['freebusy']['port']);
        }

        $fb_url = sprintf('%s/%s.xfb', $server, $email);

        $options['method'] = 'GET';
        $options['timeout'] = 5;
        $options['allowRedirects'] = true;

        if (!empty($GLOBALS['conf']['http']['proxy']['proxy_host'])) {
            $options = array_merge($options, $GLOBALS['conf']['http']['proxy']);
        }

        $http = new HTTP_Request($fb_url, $options);
        $http->setBasicAuth(Horde_Auth::getAuth(), Horde_Auth::getCredential('password'));
        @$http->sendRequest();
        if ($http->getResponseCode() != 200) {
            throw new Kronolith_Exception(sprintf(_("Unable to retrieve free/busy information for %s"),
                                            $email), Kronolith::ERROR_FB_NOT_FOUND);
        }
        $vfb_text = $http->getResponseBody();

        $iCal = new Horde_iCalendar;
        $iCal->parsevCalendar($vfb_text);

        $vfb = &$iCal->findComponent('VFREEBUSY');
        if ($vfb === false) {
            throw new Kronolith_Exception(sprintf(_("No free/busy information is available for %s"),
                                    $email), Kronolith::ERROR_FB_NOT_FOUND);
        }

        return $vfb;
    }

    public function store($email, $vfb, $public = false)
    {
        // We don't care about storing FB info at the moment; we rather let
        // Kolab's freebusy.php script auto-generate it for us.
    }

}
