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
 * The legacy certificate activity (mod_certificate) as a credential source.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\credentials;

/**
 * Certificates issued by mod_certificate, read from its tables.
 *
 * The one source that is not read through an API, with the site owner's
 * agreement: mod_certificate has no function that lists a user's certificates
 * across courses, and the one that returns a single issue,
 * certificate_get_issue(), issues the certificate when it does not exist yet,
 * so calling it to look would hand out certificates. The query below only
 * reads the two tables its own install.xml defines, and only the owner's rows.
 *
 * Its download page (view.php?action=get) requires access to the course, so a
 * certificate whose course the participant can no longer open is reported
 * without a link and with requires_course_access set, for the assistant to
 * offer support instead of a link that would not work.
 */
class certificate implements source {
    /**
     * Source name.
     *
     * @return string
     */
    public function name(): string {
        return 'certificate';
    }

    /**
     * The activity module is installed and enabled.
     *
     * @return bool
     */
    public function is_available(): bool {
        global $DB;
        return \core_component::get_component_directory('mod_certificate') !== null
            && (bool)$DB->get_field('modules', 'visible', ['name' => 'certificate'])
            && $DB->get_manager()->table_exists('certificate_issues');
    }

    /**
     * The participant's certificates.
     *
     * @param int $userid User id.
     * @return array[]
     */
    public function credentials(int $userid): array {
        global $DB;

        $sql = "SELECT ci.id, ci.code, ci.timecreated, c.id AS certificateid, c.name, c.course,
                       co.fullname AS coursename, cm.id AS cmid
                  FROM {certificate_issues} ci
                  JOIN {certificate} c ON c.id = ci.certificateid
                  JOIN {course} co ON co.id = c.course
             LEFT JOIN {modules} m ON m.name = :modname
             LEFT JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = c.id AND cm.deletioninprogress = 0
                 WHERE ci.userid = :userid
              ORDER BY ci.timecreated DESC";
        $out = [];
        foreach ($DB->get_records_sql($sql, ['modname' => 'certificate', 'userid' => $userid]) as $issue) {
            $url = self::reachable_url($issue, $userid);
            $out[] = [
                'type' => 'certificate',
                'name' => format_string($issue->name),
                'issuer' => format_string(get_site()->fullname),
                'course' => format_string($issue->coursename),
                'issued' => (int)$issue->timecreated,
                'expires' => 0,
                'code' => (string)$issue->code,
                'url' => $url,
                'verify_url' => '',
                'requires_course_access' => $url === '',
                'source' => $this->name(),
            ];
        }
        return $out;
    }

    /**
     * The certificate page, when the participant can open it now; '' otherwise.
     *
     * The same conditions view.php enforces: an active enrolment (or course
     * view rights), a course they may see, and the activity visible to them.
     *
     * @param \stdClass $issue Issue row with course and cmid.
     * @param int $userid User id.
     * @return string
     */
    private static function reachable_url(\stdClass $issue, int $userid): string {
        if (empty($issue->cmid)) {
            return '';
        }
        $context = \context_course::instance((int)$issue->course, IGNORE_MISSING);
        if (
            !$context
            || (!is_enrolled($context, $userid, '', true) && !has_capability('moodle/course:view', $context, $userid))
        ) {
            return '';
        }
        try {
            $cm = get_fast_modinfo((int)$issue->course, $userid)->get_cm((int)$issue->cmid);
        } catch (\Throwable $e) {
            return '';
        }
        if (!$cm->uservisible) {
            return '';
        }
        return (new \moodle_url('/mod/certificate/view.php', ['id' => $cm->id]))->out(false);
    }
}
