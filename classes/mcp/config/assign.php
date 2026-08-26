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
 * Configuration interpreter for mod_assign.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\config;

use cm_info;

/**
 * Explains why an assignment will or will not accept a submission.
 *
 * The one that matters most here is the draft setting: with submission drafts
 * on, a participant can upload their file, see it listed, and still not have
 * submitted anything, because the "Submit for grading" button is a separate
 * step. They report it as "I handed it in and the teacher says I didn't", and
 * no availability check will ever explain it.
 *
 * Blind marking and marking workflow are deliberately not emitted: they concern
 * how staff grade, not what the participant can do.
 */
class assign extends interpreter {
    /**
     * Submission window, draft/team/statement mechanics and attempt limits.
     *
     * @param \stdClass $instance Assign instance record.
     * @param cm_info $cm Course module info, built for the target user.
     * @param int $userid Target user id.
     * @return string[] Plain-language rules.
     */
    public static function rules(\stdClass $instance, cm_info $cm, int $userid): array {
        unset($cm);

        $rules = [];
        $now = time();

        // The draft trap comes first: it is the one that silently costs marks.
        if (!empty($instance->submissiondrafts)) {
            $rules[] = get_string('actcfg_assign_drafts', 'block_openaiagent');
        }

        // Second, because it is specific to this participant and it changes how
        // every date below applies to them. Ordering matters here: rules are
        // capped at interpreter::MAX_RULES and trimmed from the end.
        $extension = self::extension_date($instance, $userid);
        if ($extension > 0) {
            $rules[] = get_string('actcfg_assign_extension', 'block_openaiagent', userdate($extension));
        }

        $from = (int)($instance->allowsubmissionsfromdate ?? 0);
        if ($from > $now) {
            $rules[] = get_string('actcfg_assign_from', 'block_openaiagent', userdate($from));
        }

        $due = (int)($instance->duedate ?? 0);
        if ($due > 0) {
            $rules[] = $due < $now
                ? get_string('actcfg_assign_due_past', 'block_openaiagent', userdate($due))
                : get_string('actcfg_assign_due', 'block_openaiagent', userdate($due));
        }

        // The cutoff, not the due date, is what actually blocks a submission.
        $cutoff = (int)($instance->cutoffdate ?? 0);
        if ($cutoff > 0) {
            $rules[] = $cutoff < $now
                ? get_string('actcfg_assign_cutoff_past', 'block_openaiagent', userdate($cutoff))
                : get_string('actcfg_assign_cutoff', 'block_openaiagent', userdate($cutoff));
        }

        if (!empty($instance->teamsubmission)) {
            $rules[] = get_string('actcfg_assign_team', 'block_openaiagent');
        }

        if (!empty($instance->requiresubmissionstatement)) {
            $rules[] = get_string('actcfg_assign_statement', 'block_openaiagent');
        }

        $maxattempts = (int)($instance->maxattempts ?? -1);
        if ($maxattempts > 0) {
            $rules[] = get_string('actcfg_assign_attempts', 'block_openaiagent', $maxattempts);
        }

        return $rules;
    }

    /**
     * The participant's personal extended due date, if they have one.
     *
     * @param \stdClass $instance Assign instance record.
     * @param int $userid Target user id.
     * @return int Timestamp, or 0 when there is no extension.
     */
    private static function extension_date(\stdClass $instance, int $userid): int {
        global $DB;

        $assignid = (int)($instance->id ?? 0);
        if ($assignid <= 0) {
            return 0;
        }

        $flags = $DB->get_record(
            'assign_user_flags',
            ['assignment' => $assignid, 'userid' => $userid],
            'extensionduedate',
            IGNORE_MISSING
        );

        return $flags ? (int)$flags->extensionduedate : 0;
    }

    /**
     * The state of this participant's current submission.
     *
     * "draft" here is the answer to the most common assignment complaint: the
     * work exists but was never submitted for grading.
     *
     * @param \stdClass $instance Assign instance record.
     * @param cm_info $cm Course module info.
     * @param int $userid Target user id.
     * @return array Scalar map.
     */
    public static function user_state(\stdClass $instance, cm_info $cm, int $userid): array {
        global $DB;

        unset($cm);

        $assignid = (int)($instance->id ?? 0);
        if ($assignid <= 0) {
            return [];
        }

        // Team submissions are stored against the group, not the user, so a
        // per-user lookup legitimately finds nothing for them. Reporting the
        // absence as "no submission" would be wrong, so it is left unset and the
        // team rule tells the model to check with the group.
        $submission = $DB->get_record(
            'assign_submission',
            ['assignment' => $assignid, 'userid' => $userid, 'latest' => 1],
            'status, timemodified',
            IGNORE_MULTIPLE
        );

        if (!$submission) {
            return ['submission_status' => empty($instance->teamsubmission) ? 'none' : null];
        }

        return [
            'submission_status' => (string)$submission->status,
            'submission_time' => (int)$submission->timemodified,
        ];
    }
}
