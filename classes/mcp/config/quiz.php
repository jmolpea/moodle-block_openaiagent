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
 * Configuration interpreter for mod_quiz.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\config;

use cm_info;

/**
 * Explains why a quiz shows — or withholds — what it does.
 *
 * "Why can't I see my answers / my mark?" is as common as the forum case and
 * has the same shape: nothing is restricted, the quiz simply defers its review
 * options until it closes. That lives in the review bitmasks, which no other
 * tool in this plugin reads.
 *
 * Open and close dates are deliberately NOT emitted here: the generic
 * interpreter already reports timeopen/timeclose, and the review rules below
 * refer to "when the quiz closes" rather than restating the date, so the two
 * interpreters compose instead of repeating each other.
 *
 * The password and the subnet restriction are never emitted, by design.
 */
class quiz extends interpreter {
    /**
     * Attempt limits, timing, navigation and review visibility.
     *
     * @param \stdClass $instance Quiz instance record.
     * @param cm_info $cm Course module info, built for the target user.
     * @param int $userid Target user id.
     * @return string[] Plain-language rules.
     */
    public static function rules(\stdClass $instance, cm_info $cm, int $userid): array {
        unset($cm);

        $rules = [];

        // Review options first: they are what participants actually ask about.
        $rules = array_merge($rules, self::review_rules($instance));

        $attempts = (int)($instance->attempts ?? 0);
        if ($attempts > 0) {
            $rules[] = get_string('actcfg_quiz_attempts', 'block_openaiagent', $attempts);
        }

        $timelimit = (int)($instance->timelimit ?? 0);
        if ($timelimit > 0) {
            $rules[] = get_string('actcfg_quiz_timelimit', 'block_openaiagent', format_time($timelimit));
        }

        if ((string)($instance->navmethod ?? '') === 'sequential') {
            $rules[] = get_string('actcfg_quiz_sequential', 'block_openaiagent');
        }

        if (self::has_user_override($instance, $userid)) {
            $rules[] = get_string('actcfg_quiz_override', 'block_openaiagent');
        }

        return $rules;
    }

    /**
     * Describe when marks and correctness become visible.
     *
     * Each review setting is a bitmask of the four phases a quiz has. The only
     * distinctions worth a rule are the ones a participant experiences as
     * something missing: never shown at all, or withheld until the quiz closes.
     * Anything shown immediately needs no explanation.
     *
     * @param \stdClass $instance Quiz instance record.
     * @return string[] Rules.
     */
    private static function review_rules(\stdClass $instance): array {
        $rules = [];

        $checks = [
            'reviewmarks' => ['actcfg_quiz_review_marks_never', 'actcfg_quiz_review_marks_close'],
            'reviewcorrectness' => ['actcfg_quiz_review_correct_never', 'actcfg_quiz_review_correct_close'],
        ];

        foreach ($checks as $field => [$neverkey, $closekey]) {
            if (!isset($instance->$field)) {
                continue;
            }
            $phase = self::visibility_phase((int)$instance->$field);
            if ($phase === 'never') {
                $rules[] = get_string($neverkey, 'block_openaiagent');
            } else if ($phase === 'after_close') {
                $rules[] = get_string($closekey, 'block_openaiagent');
            }
        }

        return $rules;
    }

    /**
     * Reduce a review bitmask to the distinction that matters to a participant.
     *
     * @param int $mask Review setting bitmask.
     * @return string never|after_close|earlier
     */
    private static function visibility_phase(int $mask): string {
        if ($mask === 0) {
            return 'never';
        }

        $immediately = \mod_quiz\question\display_options::IMMEDIATELY_AFTER;
        $lateropen = \mod_quiz\question\display_options::LATER_WHILE_OPEN;
        $afterclose = \mod_quiz\question\display_options::AFTER_CLOSE;

        if (!($mask & $immediately) && !($mask & $lateropen) && ($mask & $afterclose)) {
            return 'after_close';
        }

        return 'earlier';
    }

    /**
     * Whether this user has a personal override on the quiz.
     *
     * Answers "why did it close for me and not for my classmate?" without
     * disclosing what the override actually changed, which is between the
     * participant and their teacher.
     *
     * @param \stdClass $instance Quiz instance record.
     * @param int $userid Target user id.
     * @return bool
     */
    private static function has_user_override(\stdClass $instance, int $userid): bool {
        global $DB;

        $quizid = (int)($instance->id ?? 0);
        if ($quizid <= 0) {
            return false;
        }

        return $DB->record_exists('quiz_overrides', ['quiz' => $quizid, 'userid' => $userid]);
    }

    /**
     * Attempts used against attempts allowed, and whether one is still open.
     *
     * Read straight from the attempts table rather than through mod_quiz's
     * locallib, which keeps this free of any load-order dependency on the quiz
     * module's function library.
     *
     * @param \stdClass $instance Quiz instance record.
     * @param cm_info $cm Course module info.
     * @param int $userid Target user id.
     * @return array Scalar map.
     */
    public static function user_state(\stdClass $instance, cm_info $cm, int $userid): array {
        global $DB;

        unset($cm);

        $quizid = (int)($instance->id ?? 0);
        if ($quizid <= 0) {
            return [];
        }

        $attempts = $DB->get_records(
            'quiz_attempts',
            ['quiz' => $quizid, 'userid' => $userid, 'preview' => 0],
            'attempt ASC',
            'id, state'
        );

        $finished = 0;
        $inprogress = false;
        foreach ($attempts as $attempt) {
            if ($attempt->state === 'finished') {
                $finished++;
            } else if ($attempt->state === 'inprogress' || $attempt->state === 'overdue') {
                $inprogress = true;
            }
        }

        $allowed = (int)($instance->attempts ?? 0);

        return [
            'attempts_finished' => $finished,
            'attempts_allowed' => $allowed > 0 ? $allowed : null,
            'attempt_in_progress' => $inprogress,
        ];
    }
}
