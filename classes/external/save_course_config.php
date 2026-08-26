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
 * External function: save the per-course assistant configuration.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use block_openaiagent\local\course_config;
use block_openaiagent\local\support_mailer;

/**
 * Persists the per-course configuration.
 */
class save_course_config extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'blockid' => new external_value(PARAM_INT, 'Owning block instance id (0 = course-wide default)', VALUE_DEFAULT, 0),
            'enabled' => new external_value(PARAM_INT, 'Whether enabled', VALUE_DEFAULT, 1),
            'courseprompt' => new external_value(PARAM_RAW, 'Course-specific prompt', VALUE_DEFAULT, ''),
            'assistantprompt' => new external_value(PARAM_RAW, 'Assistant system prompt', VALUE_DEFAULT, ''),
            'assistantfaqs' => new external_value(PARAM_RAW, 'Assistant FAQ content', VALUE_DEFAULT, ''),
            'modeloverride' => new external_value(PARAM_RAW, 'Model override', VALUE_DEFAULT, ''),
            'temperatureoverride' => new external_value(PARAM_RAW, 'Temperature override (empty = unset)', VALUE_DEFAULT, ''),
            'maxoutputtokensoverride' => new external_value(PARAM_INT, 'Max tokens override (0 = unset)', VALUE_DEFAULT, 0),
            'languagepolicy' => new external_value(PARAM_RAW, 'Language policy', VALUE_DEFAULT, 'auto'),
            'evaluationpolicy' => new external_value(PARAM_RAW, 'Evaluation policy', VALUE_DEFAULT, ''),
            'citabledocuments' => new external_value(PARAM_RAW, 'Citable documents', VALUE_DEFAULT, ''),
            'internaldocuments' => new external_value(PARAM_RAW, 'Internal documents', VALUE_DEFAULT, ''),
            'protectedactivities' => new external_value(PARAM_RAW, 'Protected activities', VALUE_DEFAULT, ''),
            'fallbacknoinfo' => new external_value(PARAM_RAW, 'No-information fallback', VALUE_DEFAULT, ''),
            'fallbackoutofscope' => new external_value(PARAM_RAW, 'Out-of-scope fallback', VALUE_DEFAULT, ''),
            'fallbackevaluationblock' => new external_value(PARAM_RAW, 'Evaluation-block fallback', VALUE_DEFAULT, ''),
            'storeconversations' => new external_value(PARAM_INT, 'Whether to store conversations', VALUE_DEFAULT, 1),
            'routerprompt' => new external_value(PARAM_RAW, 'Router (intent classifier) system prompt', VALUE_DEFAULT, ''),
            'tutorenabled' => new external_value(PARAM_INT, 'Whether the tutor agent is enabled', VALUE_DEFAULT, 1),
            'assistantenabled' => new external_value(
                PARAM_INT,
                'Whether the platform assistant agent is enabled',
                VALUE_DEFAULT,
                1
            ),
            'supportmode' => new external_value(
                PARAM_ALPHA,
                'Support escalation mode (inherit|on|off)',
                VALUE_DEFAULT,
                'inherit'
            ),
            'supportemailto' => new external_value(
                PARAM_RAW,
                'Destination addresses (empty inherits the site value)',
                VALUE_DEFAULT,
                ''
            ),
            'supportemailcc' => new external_value(
                PARAM_RAW,
                'Copy addresses (empty inherits the site value)',
                VALUE_DEFAULT,
                ''
            ),
            'supportcategorymap' => new external_value(
                PARAM_RAW,
                'Category to recipient map',
                VALUE_DEFAULT,
                ''
            ),
            'supportsubject' => new external_value(
                PARAM_RAW,
                'Subject template (empty inherits the site value)',
                VALUE_DEFAULT,
                ''
            ),
            'supportbody' => new external_value(
                PARAM_RAW,
                'Body template (empty inherits the site value)',
                VALUE_DEFAULT,
                ''
            ),
            'supportsignature' => new external_value(
                PARAM_RAW,
                'Signature (empty inherits the site value)',
                VALUE_DEFAULT,
                ''
            ),
            'supportslatext' => new external_value(
                PARAM_TEXT,
                'Expected response time (empty inherits the site value)',
                VALUE_DEFAULT,
                ''
            ),
            'supportincludetranscript' => new external_value(
                PARAM_INT,
                'Include the transcript: -1 inherit, 0 no, 1 yes',
                VALUE_DEFAULT,
                -1
            ),
            'supportcopytouser' => new external_value(
                PARAM_INT,
                'Copy the participant: -1 inherit, 0 no, 1 yes',
                VALUE_DEFAULT,
                -1
            ),
        ]);
    }

    /**
     * Save configuration.
     *
     * @param int $courseid Course id.
     * @param int $blockid Owning block instance id (0 = course-wide default).
     * @param int $enabled Enabled flag.
     * @param string $courseprompt Course prompt.
     * @param string $assistantprompt Assistant system prompt.
     * @param string $assistantfaqs Assistant FAQ content.
     * @param string $modeloverride Model override.
     * @param string $temperatureoverride Temperature override (empty = unset).
     * @param int $maxoutputtokensoverride Max tokens override (0 = unset).
     * @param string $languagepolicy Language policy.
     * @param string $evaluationpolicy Evaluation policy.
     * @param string $citabledocuments Citable documents.
     * @param string $internaldocuments Internal documents.
     * @param string $protectedactivities Protected activities.
     * @param string $fallbacknoinfo No-information fallback.
     * @param string $fallbackoutofscope Out-of-scope fallback.
     * @param string $fallbackevaluationblock Evaluation-block fallback.
     * @param int $storeconversations Store conversations flag.
     * @param string $routerprompt Router (intent classifier) system prompt.
     * @param int $tutorenabled Whether the tutor agent is enabled.
     * @param int $assistantenabled Whether the platform assistant agent is enabled.
     * @param string $supportmode Support escalation mode (inherit|on|off).
     * @param string $supportemailto Destination addresses (empty inherits the site value).
     * @param string $supportemailcc Copy addresses (empty inherits the site value).
     * @param string $supportcategorymap Category to recipient map.
     * @param string $supportsubject Subject template (empty inherits the site value).
     * @param string $supportbody Body template (empty inherits the site value).
     * @param string $supportsignature Signature (empty inherits the site value).
     * @param string $supportslatext Expected response time (empty inherits the site value).
     * @param int $supportincludetranscript Include the transcript: -1 inherit, 0 no, 1 yes.
     * @param int $supportcopytouser Copy the participant: -1 inherit, 0 no, 1 yes.
     * @return array
     */
    public static function execute(
        int $courseid,
        int $blockid = 0,
        int $enabled = 1,
        string $courseprompt = '',
        string $assistantprompt = '',
        string $assistantfaqs = '',
        string $modeloverride = '',
        string $temperatureoverride = '',
        int $maxoutputtokensoverride = 0,
        string $languagepolicy = 'auto',
        string $evaluationpolicy = '',
        string $citabledocuments = '',
        string $internaldocuments = '',
        string $protectedactivities = '',
        string $fallbacknoinfo = '',
        string $fallbackoutofscope = '',
        string $fallbackevaluationblock = '',
        int $storeconversations = 1,
        string $routerprompt = '',
        int $tutorenabled = 1,
        int $assistantenabled = 1,
        string $supportmode = 'inherit',
        string $supportemailto = '',
        string $supportemailcc = '',
        string $supportcategorymap = '',
        string $supportsubject = '',
        string $supportbody = '',
        string $supportsignature = '',
        string $supportslatext = '',
        int $supportincludetranscript = -1,
        int $supportcopytouser = -1
    ): array {

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'blockid' => $blockid,
            'enabled' => $enabled,
            'courseprompt' => $courseprompt,
            'assistantprompt' => $assistantprompt,
            'assistantfaqs' => $assistantfaqs,
            'modeloverride' => $modeloverride,
            'temperatureoverride' => $temperatureoverride,
            'maxoutputtokensoverride' => $maxoutputtokensoverride,
            'languagepolicy' => $languagepolicy,
            'evaluationpolicy' => $evaluationpolicy,
            'citabledocuments' => $citabledocuments,
            'internaldocuments' => $internaldocuments,
            'protectedactivities' => $protectedactivities,
            'fallbacknoinfo' => $fallbacknoinfo,
            'fallbackoutofscope' => $fallbackoutofscope,
            'fallbackevaluationblock' => $fallbackevaluationblock,
            'storeconversations' => $storeconversations,
            'routerprompt' => $routerprompt,
            'tutorenabled' => $tutorenabled,
            'assistantenabled' => $assistantenabled,
            'supportmode' => $supportmode,
            'supportemailto' => $supportemailto,
            'supportemailcc' => $supportemailcc,
            'supportcategorymap' => $supportcategorymap,
            'supportsubject' => $supportsubject,
            'supportbody' => $supportbody,
            'supportsignature' => $supportsignature,
            'supportslatext' => $supportslatext,
            'supportincludetranscript' => $supportincludetranscript,
            'supportcopytouser' => $supportcopytouser,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/openaiagent:managecourseconfig', $context);

        // Never leave both content agents disabled: that would silence the
        // assistant entirely. Fall back to both enabled instead.
        $tutorenabledflag = $params['tutorenabled'] ? 1 : 0;
        $assistantenabledflag = $params['assistantenabled'] ? 1 : 0;
        if (!$tutorenabledflag && !$assistantenabledflag) {
            $tutorenabledflag = 1;
            $assistantenabledflag = 1;
        }

        // The destination is the only per-course field that decides where a
        // participant's name, address and transcript end up, so it carries its
        // own capability. Freezing it in the form is cosmetic: this external
        // function is reachable on its own, and this is the check that holds.
        // Only an actual change is refused, so saving the rest of the form
        // without the capability keeps working.
        $recipientfields = ['supportemailto', 'supportemailcc', 'supportcategorymap'];
        if (!has_capability('block/openaiagent:managesupportrecipient', $context)) {
            $existing = course_config::get_raw($params['courseid'], (int)$params['blockid']);
            foreach ($recipientfields as $field) {
                $current = trim((string)($existing->$field ?? ''));
                if (trim((string)$params[$field]) !== $current) {
                    require_capability('block/openaiagent:managesupportrecipient', $context);
                }
            }
        }

        // Reject malformed or out-of-domain addresses here too: the form checks
        // them, but the form is not the only way in.
        foreach (['supportemailto', 'supportemailcc'] as $field) {
            $raw = trim((string)$params[$field]);
            if ($raw === '') {
                continue;
            }
            $addresses = support_mailer::parse_addresses($raw);
            $invalid = support_mailer::invalid_addresses($addresses);
            if (!empty($invalid)) {
                throw new \moodle_exception(
                    'settings_support_email_invalid',
                    'block_openaiagent',
                    '',
                    implode(', ', $invalid)
                );
            }
            $rejected = support_mailer::disallowed_addresses($addresses);
            if (!empty($rejected)) {
                throw new \moodle_exception(
                    'settings_support_email_domain',
                    'block_openaiagent',
                    '',
                    implode(', ', $rejected)
                );
            }
        }

        $mapaddresses = support_mailer::category_map_addresses((string)$params['supportcategorymap']);
        $badmap = array_merge(
            support_mailer::invalid_addresses($mapaddresses),
            support_mailer::disallowed_addresses($mapaddresses)
        );
        if (!empty($badmap)) {
            throw new \moodle_exception(
                'settings_support_email_invalid',
                'block_openaiagent',
                '',
                implode(', ', array_unique($badmap))
            );
        }

        $supportmode = in_array($params['supportmode'], ['inherit', 'on', 'off'], true)
            ? $params['supportmode'] : 'inherit';

        $tristate = static function ($value): int {
            $value = (int)$value;
            return ($value === 0 || $value === 1) ? $value : -1;
        };

        $tempraw = trim((string)$params['temperatureoverride']);
        $data = [
            'enabled' => $params['enabled'] ? 1 : 0,
            'tutorenabled' => $tutorenabledflag,
            'assistantenabled' => $assistantenabledflag,
            'courseprompt' => $params['courseprompt'],
            'assistantprompt' => $params['assistantprompt'],
            'assistantfaqs' => $params['assistantfaqs'],
            'modeloverride' => trim((string)$params['modeloverride']),
            'temperatureoverride' => $tempraw === '' ? null : (float)$tempraw,
            'maxoutputtokensoverride' => $params['maxoutputtokensoverride'] > 0
                ? $params['maxoutputtokensoverride'] : null,
            'languagepolicy' => trim((string)$params['languagepolicy']) === '' ? 'auto' : $params['languagepolicy'],
            'evaluationpolicy' => $params['evaluationpolicy'],
            'citabledocuments' => $params['citabledocuments'],
            'internaldocuments' => $params['internaldocuments'],
            'protectedactivities' => $params['protectedactivities'],
            'fallbacknoinfo' => $params['fallbacknoinfo'],
            'fallbackoutofscope' => $params['fallbackoutofscope'],
            'fallbackevaluationblock' => $params['fallbackevaluationblock'],
            'storeconversations' => $params['storeconversations'] ? 1 : 0,
            'routerprompt' => $params['routerprompt'],
            'supportmode' => $supportmode,
            'supportemailto' => $params['supportemailto'],
            'supportemailcc' => $params['supportemailcc'],
            'supportcategorymap' => $params['supportcategorymap'],
            'supportsubject' => $params['supportsubject'],
            'supportbody' => $params['supportbody'],
            'supportsignature' => $params['supportsignature'],
            'supportslatext' => $params['supportslatext'],
            'supportincludetranscript' => $tristate($params['supportincludetranscript']),
            'supportcopytouser' => $tristate($params['supportcopytouser']),
        ];

        course_config::save($params['courseid'], $data, (int)$params['blockid']);

        return ['success' => true];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the save succeeded'),
        ]);
    }
}
