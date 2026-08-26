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
 * Ad-hoc task that delivers one confirmed support request.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\task;

use block_openaiagent\local\support_delivery;
use block_openaiagent\local\supportrequest;

/**
 * Sends one escalation, out of the participant's request cycle.
 *
 * Delivery happens here and not in the confirmation itself so that an SMTP
 * server that is slow, or down, never leaves somebody staring at a spinner in
 * the chat, and so that a refusal can be retried instead of being lost.
 */
class send_support_request_task extends \core\task\adhoc_task {
    /**
     * Queue delivery of a request.
     *
     * @param int $requestid Support request id.
     * @return void
     */
    public static function queue(int $requestid): void {
        $task = new self();
        $task->set_custom_data((object)['requestid' => $requestid]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Deliver the request.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $requestid = (int)($data->requestid ?? 0);
        if ($requestid <= 0) {
            return;
        }

        $request = supportrequest::get($requestid);
        if ($request === null) {
            return;
        }

        // Anything other than queued means somebody else has already dealt with
        // it: the digest run picked it up, or it was cancelled. Sending here as
        // well would be a duplicate.
        if ((string)$request->status !== supportrequest::STATUS_QUEUED) {
            return;
        }

        if (support_delivery::send($request)) {
            mtrace("block_openaiagent: support request {$request->ticketref} handed to the mail server.");

            return;
        }

        $after = supportrequest::get($requestid);
        if ($after !== null && (string)$after->status === supportrequest::STATUS_QUEUED) {
            // Still queued means attempts are left. Throwing is how an ad-hoc
            // task asks Moodle to try again later, with its own growing delay;
            // returning quietly would leave the request queued for ever.
            throw new \moodle_exception(
                'support_error_delivery',
                'block_openaiagent',
                '',
                (string)$request->ticketref
            );
        }

        mtrace("block_openaiagent: support request {$request->ticketref} failed permanently.");
    }
}
