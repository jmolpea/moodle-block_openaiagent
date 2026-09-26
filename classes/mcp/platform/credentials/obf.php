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
 * Open Badge Factory (local_obf) as a credential source.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\credentials;

/**
 * Badges held on the Open Badge Factory service, read the way local_obf shows them on the profile.
 *
 * local_obf keeps the badges on the OBF service, not in Moodle, and exposes
 * local_obf_myprofile_get_assertions() for the profile page: it asks the
 * service by the user's addresses, applies the user's blacklist and caches the
 * result. This source calls exactly that function, so it sees what the profile
 * shows, and gets nothing (rather than an error) when the service is not
 * connected or does not answer.
 */
class obf implements source {
    /**
     * Source name.
     *
     * @return string
     */
    public function name(): string {
        return 'obf';
    }

    /**
     * The local plugin is installed and connected to an Open Badge Factory account.
     *
     * Without a connection local_obf has nothing to return and reports the
     * failure through debugging(), so it is not asked at all.
     *
     * @return bool
     */
    public function is_available(): bool {
        global $CFG;
        if (
            \core_component::get_component_directory('local_obf') === null
            || !file_exists($CFG->dirroot . '/local/obf/lib.php')
        ) {
            return false;
        }
        require_once($CFG->dirroot . '/local/obf/lib.php');
        return class_exists('\\classes\\obf_client') && \classes\obf_client::has_client_id();
    }

    /**
     * The participant's OBF badges.
     *
     * @param int $userid User id.
     * @return array[]
     */
    public function credentials(int $userid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/obf/lib.php');

        if (!function_exists('local_obf_myprofile_get_assertions')) {
            return [];
        }
        $collection = local_obf_myprofile_get_assertions($userid, $DB);
        if (!$collection || !method_exists($collection, 'get_assertions')) {
            return [];
        }

        $profile = (new \moodle_url('/user/profile.php', ['id' => $userid]))->out(false);
        $out = [];
        foreach ($collection->get_assertions() as $assertion) {
            $badge = $assertion->get_badge();
            if (!$badge) {
                continue;
            }
            $issuer = $badge->get_issuer();
            $out[] = [
                'type' => 'badge',
                'name' => format_string((string)$badge->get_name()),
                'issuer' => $issuer ? format_string((string)$issuer->get_name()) : '',
                'course' => '',
                'issued' => (int)$assertion->get_issuedon(),
                'expires' => (int)$assertion->get_expires(),
                'code' => '',
                'url' => $profile,
                'verify_url' => '',
                'requires_course_access' => false,
                'source' => $this->name(),
            ];
        }
        return $out;
    }
}
