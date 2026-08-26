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
 * Per-user message rate limiting.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Counts messages per user within a minute and a day window.
 */
class rate_limiter {
    /**
     * Check whether a user may send another message right now.
     *
     * Reads limits from plugin config. A limit of 0 means unlimited.
     *
     * @param int $userid User id.
     * @return bool True when within limits.
     */
    public static function allow(int $userid): bool {
        $perminute = (int)get_config('block_openaiagent', 'rate_limit_per_user_minute');
        $perday = (int)get_config('block_openaiagent', 'rate_limit_per_user_day');

        $cache = \cache::make('block_openaiagent', 'chatratelimit');
        $now = time();

        if ($perminute > 0) {
            $minutekey = 'm_' . $userid . '_' . floor($now / 60);
            if ((int)($cache->get($minutekey) ?: 0) >= $perminute) {
                return false;
            }
        }
        if ($perday > 0) {
            $daykey = 'd_' . $userid . '_' . floor($now / 86400);
            if ((int)($cache->get($daykey) ?: 0) >= $perday) {
                return false;
            }
        }
        return true;
    }

    /**
     * Record one consumed message for the user.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function record(int $userid): void {
        $cache = \cache::make('block_openaiagent', 'chatratelimit');
        $now = time();

        $minutekey = 'm_' . $userid . '_' . floor($now / 60);
        $cache->set($minutekey, (int)($cache->get($minutekey) ?: 0) + 1);

        $daykey = 'd_' . $userid . '_' . floor($now / 86400);
        $cache->set($daykey, (int)($cache->get($daykey) ?: 0) + 1);
    }
}
