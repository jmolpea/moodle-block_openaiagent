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
 * Platform tool: what the participant has due, and what is scheduled, across their courses.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\tools;

use block_openaiagent\local\scope;
use block_openaiagent\mcp\platform\base_tool;

/**
 * Pending work (the Timeline block's list) plus other calendar events.
 *
 * Deadlines come from Moodle's action events, the same source as the Timeline
 * block, so work already submitted or completed drops out on its own. Other
 * events (sessions, site or course events) come from the calendar, keeping
 * only activities the participant can see.
 */
class get_my_deadlines extends base_tool {
    /** @var int Days looked back for overdue work. */
    private const OVERDUE_DAYS = 7;

    /**
     * Tool name.
     *
     * @return string
     */
    public function name(): string {
        return 'moodle.get_my_deadlines';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public function description(): string {
        return 'Return the participant\'s pending work across all their courses (assignments to submit, '
            . 'quizzes closing, other actions; overdue items from the last ' . self::OVERDUE_DAYS . ' days are '
            . 'flagged) and other calendar events (sessions, course and site events) in the next days. '
            . 'Each item has name, course, date and url. Submitted or completed work is not listed.';
    }

    /**
     * Input schema.
     *
     * @return array
     */
    public function input_schema(): array {
        return self::schema([
            'days' => [
                'type' => 'integer',
                'description' => 'Optional. How many days ahead to look, 1-60. Default 14.',
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
        global $CFG;
        require_once($CFG->dirroot . '/calendar/lib.php');

        $days = self::int_arg($input, 'days', 14, 1, 60);
        $now = time();
        $to = $now + $days * DAYSECS;
        $user = \core_user::get_user($scope->userid, '*', MUST_EXIST);

        $deadlines = [];
        $seen = [];
        $actions = \core_calendar\local\api::get_action_events_by_timesort(
            $now - self::OVERDUE_DAYS * DAYSECS,
            $to,
            null,
            self::MAX_ITEMS,
            true,
            $user
        );
        foreach ($actions as $event) {
            $seen[(int)$event->get_id()] = true;
            $time = $event->get_times()->get_sort_time()->getTimestamp();
            $action = $event->get_action();
            $course = $event->get_course();
            $deadlines[] = [
                'name' => format_string($event->get_name()),
                'course' => $course ? format_string($course->get('fullname')) : null,
                'date' => self::date($time),
                'overdue' => $time < $now,
                'action' => $action ? format_string($action->get_name()) : null,
                'url' => $action && $action->get_url() ? $action->get_url()->out(false) : null,
            ];
        }

        return [
            'days' => $days,
            'deadlines' => $deadlines,
            'events' => self::other_events($scope->userid, $now, $to, $seen),
        ];
    }

    /**
     * Calendar events that are not deadlines, limited to what the user can see.
     *
     * @param int $userid User id.
     * @param int $from Window start.
     * @param int $to Window end.
     * @param array $seen Event ids already listed as deadlines.
     * @return array
     */
    private static function other_events(int $userid, int $from, int $to, array $seen): array {
        global $DB;

        $courses = enrol_get_users_courses($userid, true, 'id, fullname, visible');
        $courseids = array_keys($courses);
        $courseids[] = (int)SITEID;
        $groupids = $DB->get_fieldset_select('groups_members', 'groupid', 'userid = ?', [$userid]);

        $events = calendar_get_events($from, $to, [$userid], $groupids ?: false, $courseids, true, true);
        usort($events, static function ($a, $b): int {
            return (int)$a->timestart <=> (int)$b->timestart;
        });

        $out = [];
        foreach ($events as $event) {
            if (count($out) >= self::MAX_ITEMS || isset($seen[(int)$event->id])) {
                continue;
            }
            // Teacher-only reminders.
            if (($event->eventtype ?? '') === 'gradingdue') {
                continue;
            }
            $url = null;
            if (!empty($event->modulename)) {
                $courseid = (int)$event->courseid;
                if (!isset($courses[$courseid])) {
                    continue;
                }
                $instances = get_fast_modinfo($courseid, $userid)->get_instances_of($event->modulename);
                $cm = $instances[(int)$event->instance] ?? null;
                if (!$cm || !$cm->uservisible) {
                    continue;
                }
                $url = $cm->url ? $cm->url->out(false) : null;
            }
            $courseid = (int)($event->courseid ?? 0);
            $out[] = [
                'name' => format_string($event->name),
                'course' => isset($courses[$courseid]) ? format_string($courses[$courseid]->fullname) : null,
                'date' => self::date((int)$event->timestart),
                'duration_minutes' => (int)round((int)($event->timeduration ?? 0) / 60),
                'location' => trim((string)($event->location ?? '')),
                'url' => $url,
            ];
        }
        return $out;
    }
}
