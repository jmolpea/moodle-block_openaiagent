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
 * Block edit form for Smart Tutor & Support AI.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Form for editing Smart Tutor & Support AI block instances.
 */
class block_openaiagent_edit_form extends block_edit_form {
    /**
     * Define the form elements specific to this block.
     *
     * @param MoodleQuickForm $mform The form being built.
     */
    protected function specific_definition($mform) {
        // Check if global API key is configured.
        $apikey = get_config('block_openaiagent', 'apikey');
        if (empty($apikey)) {
            $mform->addElement(
                'static',
                'apikey_warning',
                '',
                html_writer::div(
                    get_string('error_noapikey', 'block_openaiagent'),
                    'alert alert-warning'
                )
            );
        }

        // Section header.
        $mform->addElement('header', 'configheader', get_string('pluginname', 'block_openaiagent'));

        // The three cosmetic strings below are typed PARAM_CLEANHTML, not
        // PARAM_TEXT. PARAM_TEXT strips every tag, which silently destroyed the
        // core multilang syntax (<span lang="es" class="multilang">…</span>) the
        // moment the form was saved, so a multilingual name could never be
        // stored. CLEANHTML keeps that span (and the lang/class attributes the
        // filter needs) while still removing anything dangerous. The
        // {mlang}…{mlang} syntax of Multi-Language Content (v2) carries no tags
        // and survived either way; what it needs is the filter itself, enabled
        // and set to apply to "content and headings" so format_string() runs it.

        // Bot name field.
        $mform->addElement(
            'text',
            'config_botname',
            get_string('config_botname', 'block_openaiagent'),
            ['size' => 40]
        );
        $mform->setType('config_botname', PARAM_CLEANHTML);
        $mform->setDefault('config_botname', get_string('default_botname', 'block_openaiagent'));
        $mform->addHelpButton('config_botname', 'config_botname', 'block_openaiagent');

        // Welcome message field.
        $mform->addElement(
            'textarea',
            'config_welcomemessage',
            get_string('config_welcomemessage', 'block_openaiagent'),
            ['rows' => 3, 'cols' => 50]
        );
        $mform->setType('config_welcomemessage', PARAM_CLEANHTML);
        $mform->setDefault('config_welcomemessage', get_string('default_welcomemessage', 'block_openaiagent'));
        $mform->addHelpButton('config_welcomemessage', 'config_welcomemessage', 'block_openaiagent');

        // Card subtitle.
        $mform->addElement(
            'text',
            'config_cardsubtitle',
            get_string('config_cardsubtitle', 'block_openaiagent'),
            ['size' => 60]
        );
        $mform->setType('config_cardsubtitle', PARAM_CLEANHTML);
        $mform->setDefault('config_cardsubtitle', get_string('default_cardsubtitle', 'block_openaiagent'));
        $mform->addHelpButton('config_cardsubtitle', 'config_cardsubtitle', 'block_openaiagent');

        // Avatar URL (GIF or image).
        $mform->addElement(
            'text',
            'config_avatarurl',
            get_string('config_avatarurl', 'block_openaiagent'),
            ['size' => 80]
        );
        $mform->setType('config_avatarurl', PARAM_URL);
        $mform->addHelpButton('config_avatarurl', 'config_avatarurl', 'block_openaiagent');
    }
}
