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
 * Platform tool: how someone can get into a course.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\tools;

use block_openaiagent\local\scope;
use block_openaiagent\mcp\platform\base_tool;
use block_openaiagent\mcp\platform\catalog;

/**
 * Every enabled enrolment method of one course, and whether this user can use it.
 */
class get_enrolment_options extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public function name(): string {
        return 'moodle.get_enrolment_options';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public function description(): string {
        return 'Return how someone can enrol in ONE course: every enabled method with its type, how it works, '
            . 'restriction (none, enrolment_key, cohort, payment, guest_access, managed_by_institution, other), '
            . 'cost when it requires payment, enrolment opening and closing dates, and whether this user can use '
            . 'it now (available_to_this_user, not_available_reason). When the participant names a course, get '
            . 'its id from moodle.search_catalog first; omit target_course_id only for the course they are '
            . 'looking at. Enrolment keys and payment links are never available: the '
            . 'participant completes enrolment on enrol_url. A visitor must log in or create an account first.';
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
                'description' => 'Optional. Course id from moodle.search_catalog. Defaults to the course being viewed.',
            ],
        ]);
    }

    /**
     * Visitors may ask this too: it describes the course, not them.
     *
     * @return string[]
     */
    public function audiences(): array {
        return [scope::AUTHENTICATED, scope::VISITOR];
    }

    /**
     * Run the tool.
     *
     * @param array $input Arguments.
     * @param scope $scope Turn scope.
     * @return array
     */
    public function execute(array $input, scope $scope): array {
        $courseid = self::int_arg($input, 'target_course_id', 0, 0, PHP_INT_MAX) ?: $scope->pagecourseid;
        if ($courseid <= 0) {
            return ['error' => 'no_course', 'hint' => 'Find the course with moodle.search_catalog first.'];
        }
        $course = catalog::discoverable($courseid, $scope->userid);
        if (!$course) {
            return ['error' => 'course_not_found'];
        }

        $context = \context_course::instance($courseid);
        return [
            'course_id' => $courseid,
            'course' => format_string($course->fullname, true, ['context' => $context]),
            'enrol_url' => (new \moodle_url('/enrol/index.php', ['id' => $courseid]))->out(false),
            'already_enrolled' => !$scope->is_visitor() && is_enrolled($context, $scope->userid, '', true),
            'logged_in' => !$scope->is_visitor(),
            'methods' => catalog::enrolment_options($course, $scope),
        ];
    }
}
