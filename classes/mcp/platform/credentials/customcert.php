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
 * Custom certificate (mod_customcert) as a credential source.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\credentials;

/**
 * Certificates issued by mod_customcert, read with its own API.
 *
 * The download link is the plugin's "My certificates" page, which only asks
 * the owner to be logged in: it works after the course is hidden or the
 * enrolment has expired, which is when people come looking for old certificates.
 */
class customcert implements source {
    /**
     * Source name.
     *
     * @return string
     */
    public function name(): string {
        return 'customcert';
    }

    /**
     * The activity module is installed and enabled.
     *
     * @return bool
     */
    public function is_available(): bool {
        global $DB;
        return class_exists('\\mod_customcert\\certificate')
            && method_exists('\\mod_customcert\\certificate', 'get_certificates_for_user')
            && $DB->get_field('modules', 'visible', ['name' => 'customcert']);
    }

    /**
     * The participant's certificates.
     *
     * @param int $userid User id.
     * @return array[]
     */
    public function credentials(int $userid): array {
        $out = [];
        foreach (\mod_customcert\certificate::get_certificates_for_user($userid, 0, 0) as $certificate) {
            $out[] = [
                'type' => 'certificate',
                'name' => format_string($certificate->name),
                'issuer' => format_string(get_site()->fullname),
                'course' => format_string((string)$certificate->coursename),
                'issued' => (int)$certificate->timecreated,
                'expires' => 0,
                'code' => (string)$certificate->code,
                'url' => (new \moodle_url('/mod/customcert/my_certificates.php', [
                    'userid' => $userid,
                    'certificateid' => $certificate->id,
                    'downloadcert' => 1,
                ]))->out(false),
                'verify_url' => '',
                'requires_course_access' => false,
                'source' => $this->name(),
            ];
        }
        return $out;
    }
}
