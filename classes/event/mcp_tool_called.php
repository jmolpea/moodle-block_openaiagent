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
 * Event fired when an MCP tool is called.
 *
 * Visible in Site admin > Reports > Logs under "Smart Tutor & Support AI".
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\event;

/**
 * MCP tool called event.
 *
 * Required 'other' keys:
 *   - tool   (string) tool name, e.g. 'moodle.get_user_grades_summary'
 *   - ok     (bool)   whether the call succeeded
 *   - errtype (string) exception class on failure, empty on success
 */
class mcp_tool_called extends \core\event\base {
    /**
     * Initialise the event data.
     */
    protected function init(): void {
        $this->data['crud'] = 'r'; // Read-only tool calls.
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'course';
    }

    /**
     * Returns the human-readable event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_mcp_tool_called', 'block_openaiagent');
    }

    /**
     * Returns the event description.
     *
     * @return string
     */
    public function get_description(): string {
        $tool = $this->other['tool'] ?? '?';
        $ok   = empty($this->other['ok']) ? 'FAILED' : 'OK';
        return "User {$this->userid} called MCP tool '{$tool}' for course {$this->courseid}: {$ok}.";
    }

    /**
     * Returns objectid mapping for the event.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'course', 'restore' => 'course'];
    }

    /**
     * Convenience factory used by tool_registry.
     *
     * @param string $tool       Tool name.
     * @param int    $userid     Resolved user performing the call.
     * @param int    $courseid   Course context (0 = system).
     * @param bool   $ok         True if tool returned successfully.
     * @param string $errtype    Exception class name on failure, empty on success.
     */
    public static function make(string $tool, int $userid, int $courseid, bool $ok, string $errtype = ''): self {
        $context = $courseid > 0
            ? \context_course::instance($courseid, IGNORE_MISSING)
            : \context_system::instance();

        // Fall back to system context if course not found.
        if (!$context) {
            $context = \context_system::instance();
        }

        return self::create([
            'userid'    => $userid,
            'objectid'  => $courseid > 0 ? $courseid : null,
            'courseid'  => $courseid > 0 ? $courseid : 0,
            'context'   => $context,
            'other'     => [
                'tool'    => $tool,
                'ok'      => $ok,
                'errtype' => $errtype,
            ],
        ]);
    }
}
