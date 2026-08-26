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
 * Tests for the per-user rate limiter.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for the rate limiter.
 *
 * @covers \block_openaiagent\local\rate_limiter
 */
final class rate_limiter_test extends \advanced_testcase {
    /**
     * A limit of zero means unlimited.
     */
    public function test_zero_limit_is_unlimited(): void {
        $this->resetAfterTest();
        set_config('rate_limit_per_user_minute', 0, 'block_openaiagent');
        set_config('rate_limit_per_user_day', 0, 'block_openaiagent');

        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue(rate_limiter::allow(123));
            rate_limiter::record(123);
        }
    }

    /**
     * The per-minute limit blocks once exhausted.
     */
    public function test_per_minute_limit(): void {
        $this->resetAfterTest();
        set_config('rate_limit_per_user_minute', 3, 'block_openaiagent');
        set_config('rate_limit_per_user_day', 0, 'block_openaiagent');

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue(rate_limiter::allow(7));
            rate_limiter::record(7);
        }
        $this->assertFalse(rate_limiter::allow(7));
    }

    /**
     * Limits are tracked independently per user.
     */
    public function test_limits_are_per_user(): void {
        $this->resetAfterTest();
        set_config('rate_limit_per_user_minute', 1, 'block_openaiagent');
        set_config('rate_limit_per_user_day', 0, 'block_openaiagent');

        rate_limiter::record(1);
        $this->assertFalse(rate_limiter::allow(1));
        $this->assertTrue(rate_limiter::allow(2));
    }
}
