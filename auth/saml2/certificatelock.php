<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Lock and unlock the Private Key and Certificate files
 *
 * @package    auth_saml2
 * @author     Nicholas Hoobin <nicholashoobin@catalyst-au.net>
 * @copyright  Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());
admin_externalpage_setup('authsettingsaml2');

require('setup.php');

$form = new \auth_saml2\form\lockcertificate();

$baseurl = new moodle_url('/admin/auth_config.php?auth=saml2');

if ($data = $form->get_data()) {

    if ($form->is_submitted()) {

        $certfiles = array($saml2auth->certpem, $saml2auth->certcrt);
        foreach ($certfiles as $certfile) {
            chmod($certfile, 0440);
        }

        redirect($baseurl);
    }
} else if ($form->is_cancelled()) {
    redirect($baseurl);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('certificatelock', 'auth_saml2'));
echo $form->display();
echo $OUTPUT->footer();
