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
 * Platform tool: the participant's own courses and the state of each.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\tools;

use block_openaiagent\local\scope;
use block_openaiagent\mcp\platform\base_tool;

/**
 * Every course the participant is enrolled in, with why they can or cannot get in.
 *
 * Courses whose enrolment is suspended, expired or not yet started are
 * included on purpose: "I cannot get into my course" is the question this tool
 * exists for, and the answer is different in each case.
 */
class get_my_courses extends base_tool {
    /** @var string Enrolment active and course reachable. */
    public const ACTIVE = 'active';

    /** @var string Enrolment suspended, or its enrolment method disabled. */
    public const SUSPENDED = 'suspended';

    /** @var string Enrolment end date has passed. */
    public const EXPIRED = 'expired';

    /** @var string Enrolment start date is still in the future. */
    public const NOT_STARTED = 'not_started';

    /** @var string Enrolment fine, but the course is hidden from the participant. */
    public const COURSE_HIDDEN = 'course_hidden';

    /** @var string Enrolment fine, course end date passed; still reachable. */
    public const COURSE_ENDED = 'course_ended';

    /**
     * Tool name.
     *
     * @return string
     */
    public function name(): string {
        return 'moodle.get_my_courses';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public function description(): string {
        return 'List the participant\'s own courses, most recently accessed first. For each: name, url, '
            . 'category, course start/end dates, access_status (active, suspended, expired, not_started, '
            . 'course_hidden, course_ended) with access_status_date when a date explains it, progress_percent '
            . '(null when the course does not track completion: say so, never estimate), completed and its '
            . 'date, roles, last access and in_this_category. Use it for "my courses", "which have I '
            . 'finished", "I cannot get into my course". Paginate with offset when has_more is true.';
    }

    /**
     * Input schema.
     *
     * @return array
     */
    public function input_schema(): array {
        return self::schema([
            'status' => [
                'type' => 'string',
                'enum' => ['all', 'in_progress', 'completed'],
                'description' => 'Optional filter. Default all.',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Optional. Courses per page, 1-' . self::MAX_ITEMS . '. Default ' . self::MAX_ITEMS . '.',
            ],
            'offset' => [
                'type' => 'integer',
                'description' => 'Optional. Courses to skip, for the next page.',
            ],
        ]);
    }

    /**
     * Run the tool.
     *
     * @param array $input Arguments.
     * @param scope $scope Turn scope.
     * @return array
     */
    public function execute(array $input, scope $scope): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $userid = $scope->userid;
        $limit = self::int_arg($input, 'limit', self::MAX_ITEMS, 1, self::MAX_ITEMS);
        $offset = self::int_arg($input, 'offset', 0, 0, PHP_INT_MAX);
        $filter = in_array($input['status'] ?? '', ['in_progress', 'completed'], true) ? $input['status'] : 'all';

        // All enrolments, whatever their state; the state is what we report.
        $courses = enrol_get_all_users_courses(
            $userid,
            false,
            'id, fullname, category, startdate, enddate, visible, enablecompletion'
        );
        unset($courses[SITEID]);
        if (!$courses) {
            return ['total' => 0, 'has_more' => false, 'courses' => []];
        }

        $lastaccess = $DB->get_records_menu('user_lastaccess', ['userid' => $userid], '', 'courseid, timeaccess');
        $completed = $DB->get_records_select_menu(
            'course_completions',
            'userid = :userid AND timecompleted IS NOT NULL',
            ['userid' => $userid],
            '',
            'course, timecompleted'
        );
        $enrolments = self::enrolments($userid);

        if ($filter !== 'all') {
            $courses = array_filter($courses, static function ($course) use ($completed, $filter): bool {
                return ($filter === 'completed') === isset($completed[$course->id]);
            });
        }

        // Most recently accessed first, then by name.
        uasort($courses, static function ($a, $b) use ($lastaccess): int {
            return [(int)($lastaccess[$b->id] ?? 0), $a->fullname] <=> [(int)($lastaccess[$a->id] ?? 0), $b->fullname];
        });

        $total = count($courses);
        $page = array_slice($courses, $offset, $limit, true);

        $out = [];
        foreach ($page as $course) {
            $out[] = self::describe($course, $userid, $scope, $enrolments[$course->id] ?? [], $lastaccess, $completed);
        }

        return [
            'total' => $total,
            'has_more' => $offset + count($out) < $total,
            'courses' => $out,
        ];
    }

    /**
     * Every enrolment of the user, grouped by course.
     *
     * @param int $userid User id.
     * @return array courseid => list of {status, timestart, timeend, instanceenabled}
     */
    private static function enrolments(int $userid): array {
        global $DB;

        $sql = "SELECT ue.id, e.courseid, ue.status, ue.timestart, ue.timeend, e.status AS instancestatus
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = :userid";
        $grouped = [];
        foreach ($DB->get_records_sql($sql, ['userid' => $userid]) as $row) {
            $grouped[(int)$row->courseid][] = $row;
        }
        return $grouped;
    }

    /**
     * The access status of one course, and the date that explains it.
     *
     * With several enrolments in the same course, one working enrolment is
     * enough. Otherwise the most helpful explanation wins: an expiry date first,
     * then a start date, then a plain suspension.
     *
     * @param \stdClass $course Course record.
     * @param int $userid User id.
     * @param array $enrolments The user's enrolments in this course.
     * @return array [status, ?date]
     */
    public static function access_status(\stdClass $course, int $userid, array $enrolments): array {
        $now = time();
        $expired = 0;
        $starts = 0;
        $active = false;

        foreach ($enrolments as $ue) {
            $usable = (int)$ue->status === ENROL_USER_ACTIVE && (int)$ue->instancestatus === ENROL_INSTANCE_ENABLED;
            if (!$usable) {
                continue;
            }
            if ((int)$ue->timeend > 0 && (int)$ue->timeend < $now) {
                $expired = max($expired, (int)$ue->timeend);
            } else if ((int)$ue->timestart > $now) {
                $starts = $starts ? min($starts, (int)$ue->timestart) : (int)$ue->timestart;
            } else {
                $active = true;
            }
        }

        if (!$active) {
            if ($expired) {
                return [self::EXPIRED, $expired];
            }
            if ($starts) {
                return [self::NOT_STARTED, $starts];
            }
            return [self::SUSPENDED, 0];
        }

        $context = \context_course::instance($course->id);
        if (!$course->visible && !has_capability('moodle/course:viewhiddencourses', $context, $userid)) {
            return [self::COURSE_HIDDEN, 0];
        }
        if ((int)$course->enddate > 0 && (int)$course->enddate < $now) {
            return [self::COURSE_ENDED, (int)$course->enddate];
        }
        return [self::ACTIVE, 0];
    }

    /**
     * One course, as the model sees it.
     *
     * @param \stdClass $course Course record.
     * @param int $userid User id.
     * @param scope $scope Turn scope.
     * @param array $enrolments The user's enrolments in this course.
     * @param array $lastaccess courseid => last access time.
     * @param array $completed courseid => completion time.
     * @return array
     */
    private static function describe(
        \stdClass $course,
        int $userid,
        scope $scope,
        array $enrolments,
        array $lastaccess,
        array $completed
    ): array {
        $context = \context_course::instance($course->id);
        [$status, $statusdate] = self::access_status($course, $userid, $enrolments);
        $hidden = $status === self::COURSE_HIDDEN;

        $category = \core_course_category::get((int)$course->category, IGNORE_MISSING, false, $userid);

        $progress = null;
        if (!$hidden && (int)$course->enablecompletion === 1) {
            $value = \core_completion\progress::get_course_progress_percentage($course, $userid);
            $progress = $value === null ? null : (int)round($value);
        }

        $roles = [];
        foreach (get_user_roles($context, $userid, false) as $role) {
            $roles[] = role_get_name($role, $context, ROLENAME_ALIAS);
        }

        return [
            'id' => (int)$course->id,
            'name' => format_string($course->fullname, true, ['context' => $context]),
            // A hidden course's page cannot be opened, so no link to it.
            'url' => $hidden ? null : (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
            'category' => $category ? $category->get_nested_name(false) : null,
            'in_this_category' => $scope->type === scope::CATEGORY && $category
                && in_array($scope->categoryid, array_map('intval', explode('/', trim((string)$category->path, '/'))), true),
            'start_date' => self::date((int)$course->startdate),
            'end_date' => self::date((int)$course->enddate),
            'access_status' => $status,
            'access_status_date' => self::date($statusdate),
            'tracks_completion' => (int)$course->enablecompletion === 1,
            'progress_percent' => $progress,
            'completed' => isset($completed[$course->id]),
            'completed_date' => isset($completed[$course->id]) ? self::date((int)$completed[$course->id]) : null,
            'roles' => array_values(array_unique($roles)),
            'last_access' => isset($lastaccess[$course->id]) ? self::date((int)$lastaccess[$course->id]) : null,
        ];
    }
}
