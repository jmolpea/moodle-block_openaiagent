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
 * Tests for input guardrails.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent;

/**
 * Unit tests for the input guardrails.
 *
 * @covers \block_openaiagent\guardrails
 */
final class guardrails_test extends \advanced_testcase {
    /**
     * An empty or whitespace-only message is rejected.
     */
    public function test_empty_message_rejected(): void {
        $this->resetAfterTest();
        set_config('enable_guardrails', 1, 'block_openaiagent');

        $result = guardrails::check('   ');
        $this->assertFalse($result->allowed);
        $this->assertSame('error_emptymessage', $result->errorcode);
    }

    /**
     * Messages over the maximum length are rejected.
     */
    public function test_too_long_rejected(): void {
        $this->resetAfterTest();
        set_config('enable_guardrails', 1, 'block_openaiagent');

        $result = guardrails::check(str_repeat('a', guardrails::MAX_LENGTH + 1));
        $this->assertFalse($result->allowed);
        $this->assertSame('error_messagetoolong', $result->errorcode);
    }

    /**
     * A normal message is allowed and trimmed.
     */
    public function test_normal_message_allowed_and_trimmed(): void {
        $this->resetAfterTest();
        set_config('enable_guardrails', 1, 'block_openaiagent');

        $result = guardrails::check('  Hello tutor  ');
        $this->assertTrue($result->allowed);
        $this->assertSame('Hello tutor', $result->message);
        $this->assertSame('', $result->errorcode);
    }

    /**
     * Control characters are stripped while newlines and tabs survive.
     */
    public function test_control_characters_stripped(): void {
        $this->resetAfterTest();
        set_config('enable_guardrails', 1, 'block_openaiagent');

        $result = guardrails::check("Hello\x00\x07world\nline\ttab");
        $this->assertTrue($result->allowed);
        $this->assertSame("Helloworld\nline\ttab", $result->message);
    }

    /**
     * With guardrails disabled, control characters are not stripped but
     * length and emptiness checks still apply.
     */
    public function test_guardrails_disabled_skips_sanitization(): void {
        $this->resetAfterTest();
        set_config('enable_guardrails', 0, 'block_openaiagent');

        $result = guardrails::check("Hello\x07world");
        $this->assertTrue($result->allowed);
        $this->assertSame("Hello\x07world", $result->message);
    }
}
