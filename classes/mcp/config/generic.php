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
 * Configuration interpreter that applies to every activity and resource.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\config;

use cm_info;

/**
 * Module-agnostic behaviour rules.
 *
 * Everything here comes from a core API that every module implements, so it
 * works for a page, a SCORM package or a module this plugin has never heard of:
 *
 * - group mode, which explains a whole family of "my classmate sees something
 *   I don't" questions in any activity;
 * - completion conditions, which core renders as human-readable descriptions
 *   per module, answering "why isn't this ticked off?";
 * - the timeopen/timeclose pair, used by most core modules with the same
 *   meaning.
 *
 * Module tables use the same column name for different things often enough that
 * the date probe stays deliberately narrow: fields whose meaning varies by
 * module (a forum "duedate" is a soft target, an assignment "duedate" is not)
 * belong to that module's own interpreter, not here.
 */
class generic extends interpreter {
    /**
     * Date fields shared across core modules with a consistent meaning.
     *
     * @var array<string,string> Field name => open|close.
     */
    private const DATE_FIELDS = [
        'timeopen' => 'open',
        'timeclose' => 'close',
    ];

    /**
     * Group mode, completion conditions and standard open/close dates.
     *
     * @param \stdClass $instance Module instance record (may be empty).
     * @param cm_info $cm Course module info, built for the target user.
     * @param int $userid Target user id.
     * @return string[] Plain-language rules.
     */
    public static function rules(\stdClass $instance, cm_info $cm, int $userid): array {
        $rules = [];

        // The groupmode of cm_info already resolves the course-level "force
        // group mode" setting, so this is the mode actually in effect, not the
        // one stored on the module.
        $groupmode = (int)$cm->groupmode;
        if ($groupmode === SEPARATEGROUPS) {
            $rules[] = get_string('actcfg_groups_separate', 'block_openaiagent');
        } else if ($groupmode === VISIBLEGROUPS) {
            $rules[] = get_string('actcfg_groups_visible', 'block_openaiagent');
        }

        foreach (self::DATE_FIELDS as $field => $kind) {
            $ts = isset($instance->$field) ? (int)$instance->$field : 0;
            if ($ts <= 0) {
                continue;
            }
            $when = userdate($ts);
            if ($kind === 'open' && $ts > time()) {
                $rules[] = get_string('actcfg_opens_on', 'block_openaiagent', $when);
            } else if ($kind === 'close') {
                $rules[] = $ts < time()
                    ? get_string('actcfg_closed_on', 'block_openaiagent', $when)
                    : get_string('actcfg_closes_on', 'block_openaiagent', $when);
            }
        }

        $completion = self::completion_rule($cm, $userid);
        if ($completion !== '') {
            $rules[] = $completion;
        }

        return $rules;
    }

    /**
     * Completion conditions, as core renders them for the module.
     *
     * core_completion asks each module to describe its own custom rules ("Post
     * 3 discussions", "View the activity"), which is the one place where core
     * exposes module-specific semantics through a module-agnostic API. Any
     * failure degrades to no rule rather than breaking the tool call, since
     * completion internals have moved between Moodle versions.
     *
     * @param cm_info $cm Course module info.
     * @param int $userid Target user id.
     * @return string Rule text, or '' when completion is off or unreadable.
     */
    private static function completion_rule(cm_info $cm, int $userid): string {
        global $CFG;

        // The COMPLETION_* constants live in completionlib.php, which is not
        // loaded by default.
        require_once($CFG->libdir . '/completionlib.php');

        if ((int)$cm->completion === COMPLETION_TRACKING_NONE) {
            return '';
        }

        try {
            $details = \core_completion\cm_completion_details::get_instance($cm, $userid);
            $descriptions = [];
            foreach ($details->get_details() as $detail) {
                $text = trim((string)($detail->description ?? ''));
                if ($text !== '') {
                    $descriptions[] = $text;
                }
            }
            if (!$descriptions) {
                return '';
            }
            return get_string('actcfg_completion', 'block_openaiagent', implode('; ', $descriptions));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The user's groups in this activity, when group mode is in effect.
     *
     * @param \stdClass $instance Module instance record (may be empty).
     * @param cm_info $cm Course module info.
     * @param int $userid Target user id.
     * @return array Scalar map.
     */
    public static function user_state(\stdClass $instance, cm_info $cm, int $userid): array {
        unset($instance);

        if ((int)$cm->groupmode === NOGROUPS) {
            return [];
        }

        try {
            $groups = groups_get_activity_allowed_groups($cm, $userid);
            $names = [];
            foreach ($groups as $group) {
                $names[] = format_string($group->name);
            }
            return ['groups' => $names];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
