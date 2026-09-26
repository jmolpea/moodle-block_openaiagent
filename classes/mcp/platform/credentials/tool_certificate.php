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
 * Workplace certificates (tool_certificate) as a credential source.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\credentials;

/**
 * Certificates issued by tool_certificate, read with its own API.
 *
 * The owner of an issue can always open it by its code, whatever happened to
 * the course, and the plugin's public verification page is linked when the
 * site lets visitors verify certificates.
 */
class tool_certificate implements source {
    /**
     * Source name.
     *
     * @return string
     */
    public function name(): string {
        return 'tool_certificate';
    }

    /**
     * The admin tool is installed.
     *
     * @return bool
     */
    public function is_available(): bool {
        return class_exists('\\tool_certificate\\certificate')
            && method_exists('\\tool_certificate\\certificate', 'get_issues_for_user')
            && class_exists('\\tool_certificate\\template');
    }

    /**
     * The participant's certificates.
     *
     * @param int $userid User id.
     * @return array[]
     */
    public function credentials(int $userid): array {
        global $CFG, $DB;

        $publicverify = has_capability('tool/certificate:verify', \context_system::instance(), $CFG->siteguest);
        $courses = [];
        $out = [];
        foreach (\tool_certificate\certificate::get_issues_for_user($userid, 0, 0) as $issue) {
            $course = '';
            if (!empty($issue->courseid)) {
                $courses[$issue->courseid] = $courses[$issue->courseid]
                    ?? (string)$DB->get_field('course', 'fullname', ['id' => $issue->courseid]);
                $course = format_string($courses[$issue->courseid]);
            }
            $out[] = [
                'type' => 'certificate',
                'name' => format_string($issue->name),
                'issuer' => format_string(get_site()->fullname),
                'course' => $course,
                'issued' => (int)$issue->timecreated,
                'expires' => (int)($issue->expires ?? 0),
                'code' => (string)$issue->code,
                'url' => \tool_certificate\template::view_url($issue->code)->out(false),
                'verify_url' => $publicverify
                    ? (new \moodle_url('/admin/tool/certificate/index.php', ['code' => $issue->code]))->out(false)
                    : '',
                'requires_course_access' => false,
                'source' => $this->name(),
            ];
        }
        return $out;
    }
}
