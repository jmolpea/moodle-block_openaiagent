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
 * Tests for support escalation address handling and delivery.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for the support mailer.
 *
 * @covers \block_openaiagent\local\support_mailer
 */
final class support_mailer_test extends \advanced_testcase {
    /**
     * Administrators paste address lists in every possible shape.
     */
    public function test_parse_addresses_accepts_every_separator(): void {
        $this->assertSame(
            ['a@example.org', 'b@example.org', 'c@example.org'],
            support_mailer::parse_addresses("a@example.org, b@example.org\nc@example.org")
        );
        $this->assertSame(
            ['a@example.org', 'b@example.org'],
            support_mailer::parse_addresses('a@example.org;   b@example.org')
        );
        $this->assertSame([], support_mailer::parse_addresses('   '));
    }

    /**
     * A repeated address must not produce a duplicate email.
     */
    public function test_parse_addresses_deduplicates(): void {
        $this->assertSame(
            ['a@example.org', 'b@example.org'],
            support_mailer::parse_addresses('a@example.org, b@example.org, a@example.org')
        );
    }

    /**
     * Malformed addresses are reported so they can be rejected on save.
     */
    public function test_invalid_addresses(): void {
        $addresses = ['good@example.org', 'not-an-address', 'also bad@example.org'];
        $this->assertSame(
            ['not-an-address', 'also bad@example.org'],
            support_mailer::invalid_addresses($addresses)
        );
    }

    /**
     * With no configured list, every domain is acceptable.
     */
    public function test_domain_unrestricted_when_no_list(): void {
        $this->resetAfterTest();
        set_config('support_allowed_domains', '', 'block_openaiagent');

        $this->assertTrue(support_mailer::domain_allowed('anyone@wherever.example'));
    }

    /**
     * A configured list restricts destinations, subdomains included.
     */
    public function test_domain_list_allows_subdomains(): void {
        $this->resetAfterTest();
        set_config('support_allowed_domains', 'example.org, uni.example.net', 'block_openaiagent');

        $this->assertTrue(support_mailer::domain_allowed('cau@example.org'));
        $this->assertTrue(support_mailer::domain_allowed('cau@mail.example.org'));
        $this->assertTrue(support_mailer::domain_allowed('cau@uni.example.net'));
        $this->assertFalse(support_mailer::domain_allowed('teacher@gmail.com'));
        // A domain merely ending in "example.org" must not slip through as a suffix.
        $this->assertFalse(support_mailer::domain_allowed('cau@notexample.org'));
        $this->assertFalse(support_mailer::domain_allowed('no-at-sign'));
    }

    /**
     * The rejected list is what the settings page reports back to the admin.
     */
    public function test_disallowed_addresses(): void {
        $this->resetAfterTest();
        set_config('support_allowed_domains', 'example.org', 'block_openaiagent');

        $this->assertSame(
            ['teacher@gmail.com'],
            support_mailer::disallowed_addresses(['cau@example.org', 'teacher@gmail.com'])
        );
    }

    /**
     * A line break in a subject is how mail header injection starts.
     */
    public function test_flatten_header_removes_line_breaks(): void {
        $injected = "Support request\r\nBcc: attacker@example.com";
        $flat = support_mailer::flatten_header($injected);

        $this->assertStringNotContainsString("\r", $flat);
        $this->assertStringNotContainsString("\n", $flat);
        $this->assertSame('Support request Bcc: attacker@example.com', $flat);
    }

    /**
     * Runs of whitespace left by flattening are collapsed, not left ragged.
     */
    public function test_flatten_header_collapses_whitespace(): void {
        $this->assertSame('a b', support_mailer::flatten_header("  a \n\n   b  "));
    }

    /**
     * The recipient object must carry everything email_to_user() looks at.
     */
    public function test_external_recipient_is_mailable(): void {
        $this->resetAfterTest();
        $recipient = support_mailer::external_recipient('cau@example.org');

        $this->assertNotEmpty($recipient->id);
        $this->assertSame('cau@example.org', $recipient->email);
        $this->assertSame(0, $recipient->deleted);
        $this->assertSame(0, $recipient->suspended);
        $this->assertSame(0, $recipient->emailstop);
        $this->assertNotSame('nologin', $recipient->auth);
        // Core calls fullname() when logging delivery trouble, and it
        // reads every name field.
        foreach (['firstname', 'lastname', 'firstnamephonetic', 'lastnamephonetic', 'middlename', 'alternatename'] as $f) {
            $this->assertObjectHasProperty($f, $recipient);
        }
        $this->assertNotEmpty(fullname($recipient));
    }

    /**
     * The message goes out from the no-reply account, answering the participant.
     */
    public function test_send_to_uses_noreply_sender_and_participant_replyto(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEmails();

        $participant = $this->getDataGenerator()->create_user([
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'email' => 'ada@example.org',
        ]);

        $this->assertTrue(support_mailer::send_to('cau@example.org', 'Subject', 'Body', $participant));

        $messages = $sink->get_messages();
        $sink->close();

        $this->assertCount(1, $messages);
        $this->assertSame('cau@example.org', $messages[0]->to);
        $this->assertStringContainsString('ada@example.org', $messages[0]->header);
    }

    /**
     * Without a configured destination the probe fails loudly instead of
     * pretending it sent something.
     */
    public function test_send_test_without_recipients(): void {
        $this->resetAfterTest();
        set_config('support_email_to', '', 'block_openaiagent');
        set_config('support_email_cc', '', 'block_openaiagent');

        $result = support_mailer::send_test();

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['message']);
    }

    /**
     * The probe reaches every configured address exactly once.
     */
    public function test_send_test_reaches_all_addresses_once(): void {
        $this->resetAfterTest();
        set_config('support_email_to', 'cau@example.org, cau@example.org', 'block_openaiagent');
        set_config('support_email_cc', 'boss@example.org', 'block_openaiagent');

        $sink = $this->redirectEmails();
        $result = support_mailer::send_test();
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertTrue($result['ok']);
        $this->assertCount(2, $messages);
    }
}
