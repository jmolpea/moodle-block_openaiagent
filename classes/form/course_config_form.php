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
 * Per-course assistant configuration form.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

use block_openaiagent\local\defaults;
use block_openaiagent\local\support_mailer;

/**
 * Course configuration form.
 */
class course_config_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $courseid = (int)$this->_customdata['courseid'];
        $blockinstanceid = (int)($this->_customdata['blockinstanceid'] ?? 0);
        $context = \context_course::instance($courseid);

        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('hidden', 'bid', $blockinstanceid);
        $mform->setType('bid', PARAM_INT);

        // General.
        $mform->addElement('header', 'generalhdr', get_string('courseconfig', 'block_openaiagent'));

        $mform->addElement('advcheckbox', 'enabled', get_string('cc_enabled', 'block_openaiagent'));
        $mform->setDefault('enabled', 1);

        // Per-agent toggles. Disabling one content agent turns the block into a
        // single-purpose bot: the router is skipped and every message goes
        // straight to the remaining agent.
        $mform->addElement(
            'advcheckbox',
            'tutorenabled',
            get_string('cc_tutorenabled', 'block_openaiagent')
        );
        $mform->setDefault('tutorenabled', 1);

        $mform->addElement(
            'advcheckbox',
            'assistantenabled',
            get_string('cc_assistantenabled', 'block_openaiagent')
        );
        $mform->setDefault('assistantenabled', 1);

        $mform->addElement(
            'advcheckbox',
            'storeconversations',
            get_string('cc_storeconversations', 'block_openaiagent')
        );
        $mform->setDefault('storeconversations', 1);
        $mform->addElement(
            'static',
            'storeconversations_help',
            '',
            get_string('cc_storeconversations_help', 'block_openaiagent')
        );

        $mform->addElement(
            'static',
            'agenttoggles_help',
            '',
            get_string('cc_agenttoggles_help', 'block_openaiagent')
        );

        $langoptions = [
            'auto' => get_string('languageauto', 'block_openaiagent'),
            'en' => 'English',
            'es' => 'Español',
        ];
        $mform->addElement(
            'select',
            'languagepolicy',
            get_string('cc_languagepolicy', 'block_openaiagent'),
            $langoptions
        );
        $mform->setDefault('languagepolicy', 'auto');

        // Model overrides.
        $mform->addElement('header', 'overrideshdr', get_string('cc_overrides', 'block_openaiagent'));

        $mform->addElement(
            'text',
            'modeloverride',
            get_string('cc_modeloverride', 'block_openaiagent'),
            ['size' => 40]
        );
        $mform->setType('modeloverride', PARAM_TEXT);

        $mform->addElement(
            'text',
            'temperatureoverride',
            get_string('cc_temperatureoverride', 'block_openaiagent'),
            ['size' => 10]
        );
        $mform->setType('temperatureoverride', PARAM_RAW_TRIMMED);

        $mform->addElement(
            'text',
            'maxoutputtokensoverride',
            get_string('cc_maxoutputtokensoverride', 'block_openaiagent'),
            ['size' => 10]
        );
        $mform->setType('maxoutputtokensoverride', PARAM_INT);

        // Tutor / knowledge base.
        $mform->addElement('header', 'tutorhdr', get_string('cc_tutorsection', 'block_openaiagent'));

        $filemanageroptions = self::filemanager_options();
        $mform->addElement(
            'filemanager',
            'tutordocs_citable',
            get_string('cc_tutordocs_citable', 'block_openaiagent'),
            null,
            $filemanageroptions
        );
        $mform->addElement(
            'static',
            'tutordocs_citable_help',
            '',
            get_string('cc_tutordocs_citable_help', 'block_openaiagent')
        );

        $mform->addElement(
            'filemanager',
            'tutordocs_internal',
            get_string('cc_tutordocs_internal', 'block_openaiagent'),
            null,
            $filemanageroptions
        );
        $mform->addElement(
            'static',
            'tutordocs_internal_help',
            '',
            get_string('cc_tutordocs_internal_help', 'block_openaiagent')
        );

        $mform->addElement(
            'textarea',
            'courseprompt',
            get_string('cc_courseprompt', 'block_openaiagent'),
            ['rows' => 4, 'cols' => 60]
        );
        $mform->setType('courseprompt', PARAM_TEXT);
        // Seeded like the assistant's: this field REPLACES the default tutor prompt,
        // so shipping it pre-filled means a course works well out of the box and the
        // author edits a working prompt instead of writing one from nothing against
        // a default they cannot see.
        $mform->setDefault('courseprompt', defaults::TUTOR_PROMPT);

        $mform->addElement(
            'textarea',
            'citabledocuments',
            get_string('cc_citabledocuments', 'block_openaiagent'),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('citabledocuments', PARAM_TEXT);

        $mform->addElement(
            'textarea',
            'internaldocuments',
            get_string('cc_internaldocuments', 'block_openaiagent'),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('internaldocuments', PARAM_TEXT);

        $mform->addElement(
            'textarea',
            'protectedactivities',
            get_string('cc_protectedactivities', 'block_openaiagent'),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('protectedactivities', PARAM_TEXT);

        $mform->addElement(
            'textarea',
            'evaluationpolicy',
            get_string('cc_evaluationpolicy', 'block_openaiagent'),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('evaluationpolicy', PARAM_TEXT);
        $mform->setDefault('evaluationpolicy', defaults::EVALUATION_RULES_DEFAULT);

        $mform->addElement(
            'textarea',
            'fallbacknoinfo',
            get_string('cc_fallbacknoinfo', 'block_openaiagent'),
            ['rows' => 2, 'cols' => 60]
        );
        $mform->setType('fallbacknoinfo', PARAM_TEXT);
        $mform->setDefault('fallbacknoinfo', defaults::FALLBACK_NOINFO_DEFAULT);

        $mform->addElement(
            'textarea',
            'fallbackoutofscope',
            get_string('cc_fallbackoutofscope', 'block_openaiagent'),
            ['rows' => 2, 'cols' => 60]
        );
        $mform->setType('fallbackoutofscope', PARAM_TEXT);
        $mform->setDefault('fallbackoutofscope', defaults::FALLBACK_OUTOFSCOPE_DEFAULT);

        $mform->addElement(
            'textarea',
            'fallbackevaluationblock',
            get_string('cc_fallbackevaluationblock', 'block_openaiagent'),
            ['rows' => 2, 'cols' => 60]
        );
        $mform->setType('fallbackevaluationblock', PARAM_TEXT);
        $mform->setDefault('fallbackevaluationblock', defaults::FALLBACK_EVALUATIONBLOCK_DEFAULT);

        // Assistant.
        $mform->addElement('header', 'assistanthdr', get_string('cc_assistantsection', 'block_openaiagent'));

        $mform->addElement(
            'textarea',
            'assistantprompt',
            get_string('cc_assistantprompt', 'block_openaiagent'),
            ['rows' => 10, 'cols' => 60]
        );
        $mform->setType('assistantprompt', PARAM_TEXT);
        $mform->setDefault('assistantprompt', defaults::ASSISTANT_PROMPT);

        $mform->addElement(
            'textarea',
            'assistantfaqs',
            get_string('cc_assistantfaqs', 'block_openaiagent'),
            ['rows' => 8, 'cols' => 60]
        );
        $mform->setType('assistantfaqs', PARAM_TEXT);

        // Router.
        $mform->addElement('header', 'routerhdr', get_string('cc_routersection', 'block_openaiagent'));

        $mform->addElement(
            'textarea',
            'routerprompt',
            get_string('cc_routerprompt', 'block_openaiagent'),
            ['rows' => 10, 'cols' => 60]
        );
        $mform->setType('routerprompt', PARAM_TEXT);
        $mform->setDefault('routerprompt', defaults::ROUTER_PROMPT);
        $mform->addElement(
            'static',
            'routerprompt_help',
            '',
            get_string('cc_routerprompt_help', 'block_openaiagent')
        );

        // Support escalation. Belongs to the platform assistant only: the tutor
        // route never sees this feature.
        $mform->addElement('header', 'supporthdr', get_string('cc_supportsection', 'block_openaiagent'));

        $mform->addElement(
            'static',
            'supportintro',
            '',
            get_string('cc_supportintro', 'block_openaiagent')
        );

        $mform->addElement('select', 'supportmode', get_string('cc_supportmode', 'block_openaiagent'), [
            'inherit' => get_string('cc_support_inherit', 'block_openaiagent'),
            'on' => get_string('cc_support_on', 'block_openaiagent'),
            'off' => get_string('cc_support_off', 'block_openaiagent'),
        ]);
        $mform->setType('supportmode', PARAM_ALPHA);
        $mform->setDefault('supportmode', 'inherit');

        // The destination is the one field a course cannot freely set. Everything
        // else here is wording; this one decides where a participant's name,
        // address and transcript are sent, so it is gated by its own capability.
        // Freezing is only the visible half: save_course_config repeats the check,
        // because the external function can be called without this form.
        $mform->addElement('text', 'supportemailto', get_string('cc_supportemailto', 'block_openaiagent'), ['size' => 60]);
        $mform->setType('supportemailto', PARAM_RAW_TRIMMED);

        $mform->addElement('text', 'supportemailcc', get_string('cc_supportemailcc', 'block_openaiagent'), ['size' => 60]);
        $mform->setType('supportemailcc', PARAM_RAW_TRIMMED);

        $mform->addElement(
            'textarea',
            'supportcategorymap',
            get_string('cc_supportcategorymap', 'block_openaiagent'),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('supportcategorymap', PARAM_RAW);

        if (!has_capability('block/openaiagent:managesupportrecipient', $context)) {
            foreach (['supportemailto', 'supportemailcc', 'supportcategorymap'] as $element) {
                $mform->hardFreeze($element);
            }
            $mform->addElement(
                'static',
                'supportrecipientnote',
                '',
                get_string('cc_supportrecipient_locked', 'block_openaiagent')
            );
        }

        $mform->addElement('text', 'supportsubject', get_string('cc_supportsubject', 'block_openaiagent'), ['size' => 60]);
        $mform->setType('supportsubject', PARAM_RAW_TRIMMED);

        $mform->addElement(
            'textarea',
            'supportbody',
            get_string('cc_supportbody', 'block_openaiagent'),
            ['rows' => 10, 'cols' => 60]
        );
        $mform->setType('supportbody', PARAM_RAW);

        $mform->addElement(
            'textarea',
            'supportsignature',
            get_string('cc_supportsignature', 'block_openaiagent'),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('supportsignature', PARAM_RAW);

        $mform->addElement('text', 'supportslatext', get_string('cc_supportslatext', 'block_openaiagent'), ['size' => 60]);
        $mform->setType('supportslatext', PARAM_TEXT);

        $tristate = [
            '-1' => get_string('cc_support_inherit', 'block_openaiagent'),
            '1' => get_string('yes'),
            '0' => get_string('no'),
        ];

        $mform->addElement(
            'select',
            'supportincludetranscript',
            get_string('cc_supportincludetranscript', 'block_openaiagent'),
            $tristate
        );
        $mform->setType('supportincludetranscript', PARAM_INT);
        $mform->setDefault('supportincludetranscript', -1);

        $mform->addElement(
            'select',
            'supportcopytouser',
            get_string('cc_supportcopytouser', 'block_openaiagent'),
            $tristate
        );
        $mform->setType('supportcopytouser', PARAM_INT);
        $mform->setDefault('supportcopytouser', -1);

        // Assistant tools.
        $mform->addElement('header', 'toolshdr', get_string('cc_tools', 'block_openaiagent'));
        foreach (defaults::default_tool_names() as $toolname) {
            $element = self::tool_element_name($toolname);
            $mform->addElement('advcheckbox', $element, $toolname);
            $mform->setDefault($element, 1);
        }

        $this->add_action_buttons();
    }

    /**
     * Form element name for a tool checkbox.
     *
     * Tool names contain dots ("moodle.get_context") and PHP rewrites dots to
     * underscores when it builds $_POST, so an element named after the raw tool
     * name never finds its own submitted value: every checkbox read back as
     * unchecked and the selection could never be changed. Dots are encoded as
     * double underscores, the same encoding used for the model-facing function
     * names in \block_openaiagent\orchestrator::function_tools().
     *
     * @param string $toolname Tool name, e.g. "moodle.get_context".
     * @return string POST-safe element name.
     */
    public static function tool_element_name(string $toolname): string {
        return 'tool_' . str_replace('.', '__', $toolname);
    }

    /**
     * File manager options shared by both knowledge-base areas.
     *
     * Only formats the text extractor understands are accepted, so a teacher
     * cannot upload a document the tutor would silently ignore.
     *
     * @return array
     */
    public static function filemanager_options(): array {
        return [
            'subdirs' => 0,
            'maxfiles' => 20,
            'accepted_types' => ['.pdf', '.txt', '.md'],
        ];
    }

    /**
     * Validate the submitted data.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (empty($data['tutorenabled']) && empty($data['assistantenabled'])) {
            $errors['tutorenabled'] = get_string('cc_error_noagents', 'block_openaiagent');
        }

        if (trim((string)$data['temperatureoverride']) !== '') {
            if (!is_numeric($data['temperatureoverride'])) {
                $errors['temperatureoverride'] = get_string('cc_error_temperature', 'block_openaiagent');
            } else {
                $temp = (float)$data['temperatureoverride'];
                if ($temp < 0 || $temp > 2) {
                    $errors['temperatureoverride'] = get_string('cc_error_temperature', 'block_openaiagent');
                }
            }
        }

        // Support addresses are checked here so a typo is caught before the page
        // reloads, and again in save_course_config, which is the check that
        // actually protects anything.
        foreach (['supportemailto', 'supportemailcc'] as $field) {
            $raw = trim((string)($data[$field] ?? ''));
            if ($raw === '') {
                continue;
            }
            $addresses = support_mailer::parse_addresses($raw);
            $invalid = support_mailer::invalid_addresses($addresses);
            if (!empty($invalid)) {
                $errors[$field] = get_string(
                    'settings_support_email_invalid',
                    'block_openaiagent',
                    implode(', ', $invalid)
                );
                continue;
            }
            $rejected = support_mailer::disallowed_addresses($addresses);
            if (!empty($rejected)) {
                $errors[$field] = get_string(
                    'settings_support_email_domain',
                    'block_openaiagent',
                    implode(', ', $rejected)
                );
            }
        }

        $map = trim((string)($data['supportcategorymap'] ?? ''));
        if ($map !== '') {
            $addresses = support_mailer::category_map_addresses($map);
            if (empty($addresses)) {
                // Every line was unparseable or named a category that does not
                // exist; silently ignoring them would leave the course believing
                // it had routed something it has not.
                $errors['supportcategorymap'] = get_string('cc_supportcategorymap_invalid', 'block_openaiagent');
            } else {
                $bad = array_merge(
                    support_mailer::invalid_addresses($addresses),
                    support_mailer::disallowed_addresses($addresses)
                );
                if (!empty($bad)) {
                    $errors['supportcategorymap'] = get_string(
                        'settings_support_email_invalid',
                        'block_openaiagent',
                        implode(', ', array_unique($bad))
                    );
                }
            }
        }

        return $errors;
    }
}
