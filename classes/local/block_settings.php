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
 * Per-block settings of a category or site assistant.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Reads the platform switches stored in the block's own configuration.
 *
 * They live in block_instances.configdata, edited through the block's settings
 * form, so they need no schema change and travel with the block in Moodle's own
 * backup and restore. Every switch has a default that works untouched.
 */
final class block_settings {
    /**
     * The block's configuration object.
     *
     * @param int $blockinstanceid Block instance id.
     * @return \stdClass
     */
    private static function config(int $blockinstanceid): \stdClass {
        global $DB;

        $raw = $blockinstanceid > 0 ? $DB->get_field('block_instances', 'configdata', ['id' => $blockinstanceid]) : '';
        if (empty($raw)) {
            return new \stdClass();
        }
        $config = unserialize_object(base64_decode($raw));
        return $config instanceof \stdClass ? $config : new \stdClass();
    }

    /**
     * Whether a category assistant's catalogue is limited to its category tree (default yes).
     *
     * @param int $blockinstanceid Block instance id.
     * @return bool
     */
    public static function catalog_limited_to_category(int $blockinstanceid): bool {
        $config = self::config($blockinstanceid);
        return !isset($config->catalogscope) || $config->catalogscope !== 'site';
    }

    /**
     * Whether a category assistant shown inside a course also gets that course's tools (default yes).
     *
     * Only for participants actively enrolled in the course; the scope checks that.
     *
     * @param int $blockinstanceid Block instance id.
     * @return bool
     */
    public static function course_tools_in_course(int $blockinstanceid): bool {
        $config = self::config($blockinstanceid);
        return !isset($config->coursetoolsincourse) || (int)$config->coursetoolsincourse === 1;
    }

    /**
     * Whether an administrator opened this block to visitors (default no).
     *
     * @param int $blockinstanceid Block instance id.
     * @return bool
     */
    public static function visitors_switched_on(int $blockinstanceid): bool {
        $config = self::config($blockinstanceid);
        return isset($config->visitors) && (int)$config->visitors === 1;
    }

    /**
     * Whether a visitor may use this assistant right now.
     *
     * Every condition must hold: a category or site block, switched on for
     * visitors, a site that does not force login, and the visitor capability in
     * the block's context for the current (not logged in or guest) user.
     *
     * @param scope $scope Scope resolved for the visitor.
     * @return bool
     */
    public static function open_to_visitors(scope $scope): bool {
        global $CFG;

        return $scope->is_platform()
            && $scope->is_visitor()
            && empty($CFG->forcelogin)
            && self::visitors_switched_on($scope->blockinstanceid)
            && has_capability('block/openaiagent:usepublic', $scope->context);
    }
}
