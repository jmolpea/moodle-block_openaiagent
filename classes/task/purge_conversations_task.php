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
 * Scheduled task for purging old conversations.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\task;

use block_openaiagent\local\conversation_repository;
use block_openaiagent\local\supportrequest;

/**
 * Deletes conversations (and their messages) older than the configured
 * retention period. Disabled by default (retention of 0 days = keep forever).
 */
class purge_conversations_task extends \core\task\scheduled_task {
    /**
     * Returns the human-readable task name shown in Site administration.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_purge_conversations', 'block_openaiagent');
    }

    /**
     * Purge conversations not modified within the retention window.
     *
     * @return void
     */
    public function execute(): void {
        $days = (int)get_config('block_openaiagent', 'conversation_retention_days');
        if ($days <= 0) {
            // Retention disabled: never auto-delete.
            return;
        }
        $cutoff = time() - ($days * DAYSECS);
        // Drafts nobody answered are retired here too. Leaving them pending
        // would keep a live token around indefinitely and would block the
        // conversation from ever offering the escalation again.
        $expired = supportrequest::expire_drafts();
        if ($expired > 0) {
            mtrace("block_openaiagent: expired {$expired} unanswered support draft(s).");
        }

        $deleted = conversation_repository::purge_older_than($cutoff);
        if ($deleted > 0) {
            mtrace("block_openaiagent: purged {$deleted} conversation(s) older than {$days} day(s).");
        }
    }
}
