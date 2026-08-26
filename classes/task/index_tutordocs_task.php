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
 * Ad hoc task synchronizing a course knowledge base.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\task;

use block_openaiagent\local\tutordocs;

/**
 * Extracts, chunks and embeds the tutor documents of one course.
 *
 * Queued when the course assistant configuration is saved so uploads become
 * searchable without waiting for cron, and retried automatically by the task
 * system if extraction or the embeddings provider fails.
 */
class index_tutordocs_task extends \core\task\adhoc_task {
    /**
     * Queue a synchronization for a course (deduplicated).
     *
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return void
     */
    public static function queue(int $courseid, int $blockinstanceid = 0): void {
        $task = new self();
        $task->set_custom_data(['courseid' => $courseid, 'blockinstanceid' => $blockinstanceid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Executes the task.
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $courseid = isset($data->courseid) ? (int)$data->courseid : 0;
        $blockinstanceid = isset($data->blockinstanceid) ? (int)$data->blockinstanceid : 0;
        if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
            return;
        }

        tutordocs::sync_course($courseid, $blockinstanceid);
    }
}
