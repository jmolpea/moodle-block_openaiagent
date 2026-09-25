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
 * Platform tool: the participant's progress in one of their courses.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\tools;

use block_openaiagent\local\scope;
use block_openaiagent\mcp\platform\base_tool;

/**
 * Tracked activities done and pending, and the course completion conditions still open.
 */
class get_my_course_progress extends base_tool {
    /** @var int Most activities listed per group (done / pending). */
    private const MAX_ACTIVITIES = 40;

    /**
     * Tool name.
     *
     * @return string
     */
    public function name(): string {
        return 'moodle.get_my_course_progress';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public function description(): string {
        return 'Return the participant\'s completion progress in ONE of their courses (target_course_id: '
            . 'an id from moodle.get_my_courses): percentage, tracked activities done and pending, and the course '
            . 'completion conditions not yet met. When tracks_completion is false, say the course does not '
            . 'track progress; never estimate it. Only courses the participant can currently open are answered; '
            . 'otherwise the result says why.';
    }

    /**
     * Input schema.
     *
     * @return array
     */
    public function input_schema(): array {
        return self::schema([
            'target_course_id' => [
                'type' => 'integer',
                'description' => 'Id of one of the participant\'s courses, from moodle.get_my_courses.',
            ],
        ], ['target_course_id']);
    }

    /**
     * Run the tool.
     *
     * The session scope's course id is never used here: the model names one of
     * the participant's courses, and the server checks they can open it.
     *
     * @param array $input Arguments.
     * @param scope $scope Turn scope.
     * @return array
     */
    public function execute(array $input, scope $scope): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $courseid = self::int_arg($input, 'target_course_id', 0, 0, PHP_INT_MAX);
        $userid = $scope->userid;

        $course = $courseid > 0 && $courseid !== (int)SITEID ? $DB->get_record('course', ['id' => $courseid]) : false;
        if (!$course) {
            return ['error' => 'not_your_course'];
        }
        $context = \context_course::instance($courseid);
        if (!is_enrolled($context, $userid, '', true)) {
            return ['error' => 'not_your_course', 'hint' => 'Check access_status with moodle.get_my_courses.'];
        }
        if (!$course->visible && !has_capability('moodle/course:viewhiddencourses', $context, $userid)) {
            return ['error' => 'course_hidden'];
        }

        $info = new \completion_info($course);
        $name = format_string($course->fullname, true, ['context' => $context]);
        if (!$info->is_enabled()) {
            return ['course_id' => $courseid, 'course' => $name, 'tracks_completion' => false];
        }

        $modinfo = get_fast_modinfo($course, $userid);
        $done = [];
        $pending = [];
        foreach ($info->get_activities() as $cm) {
            $cminfo = $modinfo->get_cm($cm->id);
            if (!$cminfo->uservisible) {
                continue;
            }
            $data = $info->get_data($cm, false, $userid);
            $item = [
                'name' => format_string($cminfo->name, true, ['context' => $context]),
                'type' => $cminfo->modname,
                'url' => $cminfo->url ? $cminfo->url->out(false) : null,
            ];
            if (in_array((int)$data->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                $item['completed_date'] = self::date((int)$data->timemodified);
                $done[] = $item;
            } else {
                $pending[] = $item;
            }
        }

        $conditions = [];
        foreach ($info->get_completions($userid) as $completion) {
            if (!$completion->is_complete()) {
                $conditions[] = self::plain($completion->get_criteria()->get_title_detailed(), 200);
            }
        }

        $progress = \core_completion\progress::get_course_progress_percentage($course, $userid);
        $completedat = $DB->get_field('course_completions', 'timecompleted', ['userid' => $userid, 'course' => $courseid]);

        return [
            'course_id' => $courseid,
            'course' => $name,
            'tracks_completion' => true,
            'progress_percent' => $progress === null ? null : (int)round($progress),
            'completed' => !empty($completedat),
            'completed_date' => self::date((int)$completedat),
            'activities_done_count' => count($done),
            'activities_pending_count' => count($pending),
            'activities_done' => array_slice($done, 0, self::MAX_ACTIVITIES),
            'activities_pending' => array_slice($pending, 0, self::MAX_ACTIVITIES),
            'completion_conditions_pending' => $conditions,
        ];
    }
}
