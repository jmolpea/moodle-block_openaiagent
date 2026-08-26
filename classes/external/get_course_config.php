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
 * External function: read the per-course assistant configuration.
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

/**
 * Returns the stored configuration for a course.
 */
class get_course_config extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'blockid' => new external_value(PARAM_INT, 'Owning block instance id (0 = course-wide default)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Read the course configuration.
     *
     * @param int $courseid Course id.
     * @param int $blockid Owning block instance id (0 = course-wide default).
     * @return array
     */
    public static function execute(int $courseid, int $blockid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'blockid' => $blockid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('block/openaiagent:managecourseconfig', $context);

        $raw = course_config::get_raw($params['courseid'], (int)$params['blockid']);
        $get = static function (string $field, $default = '') use ($raw) {
            if ($raw === null || !isset($raw->$field) || $raw->$field === null) {
                return $default;
            }
            return $raw->$field;
        };

        return [
            'enabled' => $raw === null ? 1 : (int)$raw->enabled,
            'tutorenabled' => (int)$get('tutorenabled', 1),
            'assistantenabled' => (int)$get('assistantenabled', 1),
            'courseprompt' => (string)$get('courseprompt'),
            'assistantprompt' => (string)$get('assistantprompt'),
            'assistantfaqs' => (string)$get('assistantfaqs'),
            'routerprompt' => (string)$get('routerprompt'),
            'modeloverride' => (string)$get('modeloverride'),
            'temperatureoverride' => ($raw && $raw->temperatureoverride !== null) ? (string)$raw->temperatureoverride : '',
            'maxoutputtokensoverride' => ($raw && $raw->maxoutputtokensoverride !== null)
                ? (int)$raw->maxoutputtokensoverride : 0,
            'languagepolicy' => (string)$get('languagepolicy', 'auto'),
            'evaluationpolicy' => (string)$get('evaluationpolicy'),
            'citabledocuments' => (string)$get('citabledocuments'),
            'internaldocuments' => (string)$get('internaldocuments'),
            'protectedactivities' => (string)$get('protectedactivities'),
            'fallbacknoinfo' => (string)$get('fallbacknoinfo'),
            'fallbackoutofscope' => (string)$get('fallbackoutofscope'),
            'fallbackevaluationblock' => (string)$get('fallbackevaluationblock'),
            'storeconversations' => $raw === null ? 1 : (int)$raw->storeconversations,
            'supportmode' => (string)$get('supportmode', 'inherit'),
            'supportemailto' => (string)$get('supportemailto', ''),
            'supportemailcc' => (string)$get('supportemailcc', ''),
            'supportcategorymap' => (string)$get('supportcategorymap', ''),
            'supportsubject' => (string)$get('supportsubject', ''),
            'supportbody' => (string)$get('supportbody', ''),
            'supportsignature' => (string)$get('supportsignature', ''),
            'supportslatext' => (string)$get('supportslatext', ''),
            'supportincludetranscript' => ($raw === null || $raw->supportincludetranscript === null)
                ? -1 : (int)$raw->supportincludetranscript,
            'supportcopytouser' => ($raw === null || $raw->supportcopytouser === null)
                ? -1 : (int)$raw->supportcopytouser,
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'enabled' => new external_value(PARAM_INT, 'Whether the assistant is enabled'),
            'tutorenabled' => new external_value(PARAM_INT, 'Whether the tutor agent is enabled'),
            'assistantenabled' => new external_value(PARAM_INT, 'Whether the platform assistant agent is enabled'),
            'courseprompt' => new external_value(PARAM_RAW, 'Course-specific prompt'),
            'assistantprompt' => new external_value(PARAM_RAW, 'Assistant system prompt'),
            'assistantfaqs' => new external_value(PARAM_RAW, 'Assistant FAQ content'),
            'routerprompt' => new external_value(PARAM_RAW, 'Router (intent classifier) system prompt'),
            'modeloverride' => new external_value(PARAM_RAW, 'Model override'),
            'temperatureoverride' => new external_value(PARAM_RAW, 'Temperature override (empty = unset)'),
            'maxoutputtokensoverride' => new external_value(PARAM_INT, 'Max output tokens override (0 = unset)'),
            'languagepolicy' => new external_value(PARAM_RAW, 'Language policy'),
            'evaluationpolicy' => new external_value(PARAM_RAW, 'Evaluation policy'),
            'citabledocuments' => new external_value(PARAM_RAW, 'Citable documents'),
            'internaldocuments' => new external_value(PARAM_RAW, 'Internal documents'),
            'protectedactivities' => new external_value(PARAM_RAW, 'Protected activities'),
            'fallbacknoinfo' => new external_value(PARAM_RAW, 'No-information fallback'),
            'fallbackoutofscope' => new external_value(PARAM_RAW, 'Out-of-scope fallback'),
            'fallbackevaluationblock' => new external_value(PARAM_RAW, 'Evaluation-block fallback'),
            'storeconversations' => new external_value(PARAM_INT, 'Whether to store conversations'),
            'supportmode' => new external_value(PARAM_RAW, 'Support escalation mode (inherit|on|off)'),
            'supportemailto' => new external_value(PARAM_RAW, 'Destination addresses (empty inherits the site value)'),
            'supportemailcc' => new external_value(PARAM_RAW, 'Copy addresses (empty inherits the site value)'),
            'supportcategorymap' => new external_value(PARAM_RAW, 'Category to recipient map'),
            'supportsubject' => new external_value(PARAM_RAW, 'Subject template (empty inherits the site value)'),
            'supportbody' => new external_value(PARAM_RAW, 'Body template (empty inherits the site value)'),
            'supportsignature' => new external_value(PARAM_RAW, 'Signature (empty inherits the site value)'),
            'supportslatext' => new external_value(PARAM_RAW, 'Expected response time (empty inherits the site value)'),
            'supportincludetranscript' => new external_value(PARAM_INT, 'Include the transcript: -1 inherit, 0 no, 1 yes'),
            'supportcopytouser' => new external_value(PARAM_INT, 'Copy the participant: -1 inherit, 0 no, 1 yes'),
        ]);
    }
}
