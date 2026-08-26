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
 * Scheduled task that sends grouped support digests.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\task;

use block_openaiagent\local\course_config;
use block_openaiagent\local\support_delivery;
use block_openaiagent\local\supportrequest;

/**
 * Groups a course's pending escalations into a single message.
 *
 * Only courses running in digest mode are touched here; everywhere else each
 * request is delivered on its own by an ad-hoc task the moment it is confirmed.
 */
class send_support_digest_task extends \core\task\scheduled_task {
    /**
     * Task name shown in the scheduled tasks report.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_send_support_digest', 'block_openaiagent');
    }

    /**
     * Send one digest per course that is due.
     *
     * @return void
     */
    public function execute(): void {
        $now = time();

        foreach (supportrequest::courses_with_queued() as $courseid) {
            $config = course_config::resolve($courseid);
            if (empty($config['support']['digestmode'])) {
                // Not a digest course: those requests belong to their own ad-hoc
                // tasks, and picking them up here would send them twice.
                continue;
            }

            // The batch waits until its oldest member has sat for the configured
            // interval. That is what makes it a digest rather than a slower way
            // of sending one email at a time.
            $minutes = max(1, (int)($config['support']['digestminutes'] ?? 30));
            $cutoff = $now - ($minutes * MINSECS);

            $due = supportrequest::queued($courseid, 0, 0);
            if (empty($due)) {
                continue;
            }
            $oldest = min(array_map(static fn($r) => (int)$r->timemodified, $due));
            if ($oldest > $cutoff) {
                continue;
            }

            mtrace("block_openaiagent: sending a digest of " . count($due) . " request(s) for course {$courseid}.");
            support_delivery::send_digest($courseid, $due);
        }
    }
}
