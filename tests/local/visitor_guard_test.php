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
 * Tests for the visitor protections.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for {@see visitor_guard}.
 *
 * @covers \block_openaiagent\local\visitor_guard
 */
final class visitor_guard_test extends \advanced_testcase {
    /**
     * A page token is bound to its block, cannot be altered and expires.
     */
    public function test_page_token(): void {
        $this->resetAfterTest();

        $token = visitor_guard::issue_page_token(42);
        $this->assertTrue(visitor_guard::valid_page_token($token, 42));
        $this->assertFalse(visitor_guard::valid_page_token($token, 43));

        [$blockid, $expiry, $signature] = explode('.', $token);
        $this->assertFalse(visitor_guard::valid_page_token("43.$expiry.$signature", 43));
        $this->assertFalse(visitor_guard::valid_page_token("$blockid." . ($expiry + 999999) . ".$signature", 42));
        $this->assertFalse(visitor_guard::valid_page_token('', 42));
        $this->assertFalse(visitor_guard::valid_page_token('garbage', 42));

        $old = visitor_guard::issue_page_token(42, time() - visitor_guard::PAGE_TOKEN_LIFETIME - 10);
        $this->assertFalse(visitor_guard::valid_page_token($old, 42));

        // A different site secret makes every token invalid.
        set_config('visitor_secret', random_string(64), 'block_openaiagent');
        $this->assertFalse(visitor_guard::valid_page_token($token, 42));
    }

    /**
     * The address window and the daily ceiling close after their limits.
     */
    public function test_limits(): void {
        $this->resetAfterTest();
        set_config('visitor_rate_limit_count', 3, 'block_openaiagent');
        set_config('visitor_daily_cap', 5, 'block_openaiagent');

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue(visitor_guard::address_allowed());
            visitor_guard::record();
        }
        $this->assertFalse(visitor_guard::address_allowed());
        $this->assertFalse(visitor_guard::daily_cap_reached());

        visitor_guard::record();
        visitor_guard::record();
        $this->assertTrue(visitor_guard::daily_cap_reached());
        $this->assertSame(5, visitor_guard::today_count());

        // Zero means no limit.
        set_config('visitor_rate_limit_count', 0, 'block_openaiagent');
        set_config('visitor_daily_cap', 0, 'block_openaiagent');
        $this->assertTrue(visitor_guard::address_allowed());
        $this->assertFalse(visitor_guard::daily_cap_reached());
    }

    /**
     * The address is never stored, only a keyed hash of it.
     */
    public function test_address_hash(): void {
        $this->resetAfterTest();
        $hash = visitor_guard::address_hash();
        $this->assertSame(32, strlen($hash));
        $this->assertStringNotContainsString((string)getremoteaddr(), $hash);
    }

    /**
     * Administrators are told once a day that the ceiling was reached.
     */
    public function test_cap_notice_once_a_day(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $sink = $this->redirectMessages();

        $this->assertTrue(visitor_guard::notify_cap_reached());
        $this->assertFalse(visitor_guard::notify_cap_reached());
        $messages = $sink->get_messages();
        $this->assertCount(count(get_admins()), $messages);
        $this->assertSame('visitorcap', $messages[0]->eventtype);
        $sink->close();
    }

    /**
     * A conversation token only continues the conversation it was issued for.
     */
    public function test_conversation_token(): void {
        $this->resetAfterTest();

        $token = visitor_guard::conversation_token(7);
        $this->assertSame(7, visitor_guard::conversation_id($token));
        $this->assertSame($token, visitor_guard::conversation_token(7, $token));

        // A token held for another conversation is not reused for this one.
        $this->assertNotSame($token, visitor_guard::conversation_token(8, $token));

        $this->assertNull(visitor_guard::conversation_id(random_string(40)));
        $this->assertNull(visitor_guard::conversation_id('short'));
        $this->assertNull(visitor_guard::conversation_id(str_repeat('!', 40)));
    }

    /**
     * Without a complete captcha configuration nothing is asked; with one, an empty token fails.
     */
    public function test_captcha_configuration(): void {
        $this->resetAfterTest();

        $this->assertNull(visitor_guard::captcha());
        $this->assertTrue(visitor_guard::captcha_passes(''));

        set_config('visitor_captcha', 'turnstile', 'block_openaiagent');
        set_config('visitor_captcha_sitekey', 'site-key', 'block_openaiagent');
        $this->assertNull(visitor_guard::captcha(), 'A captcha without its secret is not usable.');

        set_config('visitor_captcha_secret', 'secret-key', 'block_openaiagent');
        $captcha = visitor_guard::captcha();
        $this->assertSame('turnstile', $captcha['provider']);
        $this->assertSame('site-key', $captcha['sitekey']);
        $this->assertArrayNotHasKey('secret', $captcha);
        $this->assertStringNotContainsString('secret-key', json_encode($captcha));

        // Refused before any request leaves the site.
        $this->assertFalse(visitor_guard::captcha_passes(''));
    }
}
