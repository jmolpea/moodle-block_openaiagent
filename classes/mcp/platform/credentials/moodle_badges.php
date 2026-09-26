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
 * Moodle's own badges as a credential source.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\credentials;

/**
 * Badges issued by Moodle core, read with badges_get_user_badges().
 *
 * That function also returns the user's email address; only the fields listed
 * here are ever passed on.
 */
class moodle_badges implements source {
    /**
     * Source name.
     *
     * @return string
     */
    public function name(): string {
        return 'moodle';
    }

    /**
     * Badges are enabled on the site.
     *
     * @return bool
     */
    public function is_available(): bool {
        global $CFG;
        return !empty($CFG->enablebadges);
    }

    /**
     * The participant's badges.
     *
     * @param int $userid User id.
     * @return array[]
     */
    public function credentials(int $userid): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/badgeslib.php');

        $courses = [];
        $out = [];
        foreach (badges_get_user_badges($userid) as $badge) {
            $course = '';
            if (!empty($badge->courseid)) {
                $courses[$badge->courseid] = $courses[$badge->courseid]
                    ?? (string)$DB->get_field('course', 'fullname', ['id' => $badge->courseid]);
                $course = format_string($courses[$badge->courseid]);
            }
            $out[] = [
                'type' => 'badge',
                'name' => format_string($badge->name),
                'issuer' => format_string((string)$badge->issuername),
                'course' => $course,
                'issued' => (int)$badge->dateissued,
                'expires' => (int)($badge->dateexpire ?? 0),
                'code' => '',
                'url' => (new \moodle_url('/badges/badge.php', ['hash' => $badge->uniquehash]))->out(false),
                'verify_url' => '',
                'requires_course_access' => false,
                'source' => $this->name(),
            ];
        }
        return $out;
    }
}
