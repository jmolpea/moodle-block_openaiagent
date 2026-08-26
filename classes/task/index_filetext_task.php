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
 * Scheduled task for indexing file text content.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\task;

use block_openaiagent\local\filetext_store;

/**
 * Indexes pending file-text extraction jobs (PDFs and plain-text files).
 *
 * Processes a small batch per cron run to avoid blocking long-running tasks.
 */
class index_filetext_task extends \core\task\scheduled_task {
    /**
     * Returns the human-readable task name shown in Site administration.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_index_filetext', 'block_openaiagent');
    }

    /**
     * Executes the scheduled task.
     *
     * Resets stale processing jobs, then processes a small batch of pending
     * file-text extraction records (PDFs and plain-text files).
     */
    public function execute(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('block_openaiagent_filetext');
        if (!$dbman->table_exists($table)) {
            mtrace('block_openaiagent: filetext table missing; run plugin upgrade (Site administration -> Notifications).');
            return;
        }

        $fs = get_file_storage();

        // Reset stale PROCESSING records back to PENDING so they can be retried.
        // A record is stale if it has been in PROCESSING for more than 10 minutes.
        $now = time();
        $staleafter = 10 * 60;
        $DB->execute(
            "UPDATE {block_openaiagent_filetext}
                SET status = ?, errormsg = ?
              WHERE status = ? AND timeindexed > 0 AND timeindexed < ?",
            [filetext_store::STATUS_PENDING, 'stale_processing_reset', filetext_store::STATUS_PROCESSING, $now - $staleafter]
        );

        // Process a small batch per run to avoid long cron locks.
        $limit = 5;
        $records = $DB->get_records(
            'block_openaiagent_filetext',
            ['status' => filetext_store::STATUS_PENDING],
            'timecreated ASC',
            '*',
            0,
            $limit
        );

        foreach ($records as $rec) {
            $file = $fs->get_file_by_id((int)$rec->fileid);
            if (!$file) {
                filetext_store::set_result($rec->contenthash, filetext_store::STATUS_FAILED, '', 'file_not_found');
                continue;
            }
            filetext_store::extract_file($file);
        }
    }
}
