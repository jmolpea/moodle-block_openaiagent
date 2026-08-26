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
 * Support request sent event.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\event;

/**
 * Logged when an escalation is handed to the mail server.
 *
 * Required 'other' keys:
 *   - ticketref (string) reference shown to the participant
 *   - category  (string) category the request was filed under
 *
 * The destination addresses are deliberately not part of the event: they are
 * already stored on the request for auditing, and the site log is a far more
 * widely readable place than that table.
 */
class support_request_sent extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'block_openaiagent_supportreq';
    }

    /**
     * Returns the human-readable event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_support_request_sent', 'block_openaiagent');
    }

    /**
     * Returns the event description.
     *
     * @return string
     */
    public function get_description(): string {
        $ref = $this->other['ticketref'] ?? '?';
        $category = $this->other['category'] ?? '?';

        return "A support request '{$ref}' ({$category}) raised by user {$this->relateduserid} "
            . "in course {$this->courseid} was handed to the mail server.";
    }

    /**
     * Convenience factory.
     *
     * @param \stdClass $request Support request record.
     * @return self
     */
    public static function make(\stdClass $request): self {
        $courseid = (int)$request->courseid;
        $context = \context_course::instance($courseid, IGNORE_MISSING) ?: \context_system::instance();

        return self::create([
            'context' => $context,
            'objectid' => (int)$request->id,
            'courseid' => $courseid,
            'relateduserid' => (int)$request->userid,
            'other' => [
                'ticketref' => (string)$request->ticketref,
                'category' => (string)$request->category,
            ],
        ]);
    }
}
