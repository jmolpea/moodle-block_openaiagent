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
 * Platform tool: the participant's course totals across their courses.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\tools;

use block_openaiagent\local\scope;
use block_openaiagent\mcp\platform\base_tool;

/**
 * The same course totals Moodle's "Grades overview" report shows the participant.
 *
 * Built on the overview report itself, as the core web service
 * gradereport_overview_get_course_grades is, so hidden grades, hidden totals and
 * the "show totals that contain hidden items" setting behave exactly as they do
 * on screen. Like that report, it lists courses where the enrolment is active.
 */
class get_my_grades extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public function name(): string {
        return 'moodle.get_my_grades';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public function description(): string {
        return 'Return the participant\'s course total grade in each course where their enrolment is active, '
            . 'as Moodle\'s grades overview shows it (hidden grades are never included). A course with no '
            . 'total yet has grade null. For grades of individual activities, point them to the course. '
            . 'Courses with an expired or suspended enrolment are not listed: use moodle.get_my_courses to '
            . 'explain why.';
    }

    /**
     * Input schema.
     *
     * @return array
     */
    public function input_schema(): array {
        return self::schema();
    }

    /**
     * Run the tool.
     *
     * @param array $input Arguments (none).
     * @param scope $scope Turn scope.
     * @return array
     */
    public function execute(array $input, scope $scope): array {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/lib.php');
        require_once($CFG->dirroot . '/grade/report/overview/lib.php');

        $userid = $scope->userid;
        $context = \context_course::instance(SITEID);
        $gpr = new \grade_plugin_return([
            'type' => 'report',
            'plugin' => 'overview',
            'courseid' => SITEID,
            'userid' => $userid,
        ]);
        $report = new \grade_report_overview($userid, $gpr, $context);
        $report->regrade_all_courses_if_needed();

        $out = [];
        foreach ($report->setup_courses_data(true) as $data) {
            $course = $data['course'];
            $coursecontext = \context_course::instance($course->id);
            $grade = $data['finalgrade'];
            $out[] = [
                'course_id' => (int)$course->id,
                'course' => format_string(get_course($course->id)->fullname, true, ['context' => $coursecontext]),
                'grade' => $grade === null ? null : grade_format_gradevalue($grade, $data['courseitem'], true),
                'url' => (new \moodle_url('/grade/report/user/index.php', ['id' => $course->id]))->out(false),
            ];
        }

        return ['courses' => $out];
    }
}
