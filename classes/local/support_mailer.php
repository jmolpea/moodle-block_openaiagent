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
 * Address handling and delivery for support escalation emails.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Parses, validates and delivers support escalation email.
 *
 * This class owns the mail primitives only. What goes inside the message (the
 * templates, the participant data, the transcript) is composed elsewhere: this
 * layer is what answers "can we reach that mailbox at all", which is why the
 * admin test button can already use it before the rest of the feature exists.
 */
class support_mailer {
    /**
     * @var int Id given to recipient objects that are not Moodle accounts.
     *
     * {@see email_to_user()} rejects a user with an empty id, so support
     * mailboxes -- which are ordinary external addresses, not site accounts --
     * need some non-zero value. A negative one can never collide with a real
     * user id, and {@see over_bounce_threshold()} simply finds no preference
     * rows for it and moves on.
     */
    private const EXTERNAL_RECIPIENT_ID = -99;

    /**
     * @var string Placeholder standing for the course contacts.
     *
     * It is resolved server-side at send time, so it is the one destination a
     * teacher without the managesupportrecipient capability may choose: by
     * construction it can never point outside the institution.
     */
    public const TOKEN_COURSE_TEACHERS = '{course_teachers}';

    /**
     * Split a configured address list into individual addresses.
     *
     * Accepts commas, semicolons, whitespace and line breaks as separators,
     * because administrators paste these lists from every imaginable source.
     *
     * @param string $raw Raw setting value.
     * @return string[] Unique, trimmed addresses in the order given.
     */
    public static function parse_addresses(string $raw): array {
        $parts = preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }

        $addresses = [];
        foreach ($parts as $part) {
            $address = trim($part);
            if ($address !== '' && !in_array($address, $addresses, true)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * Whether an entry is a placeholder rather than a literal address.
     *
     * @param string $entry Entry from a configured list.
     * @return bool
     */
    public static function is_token(string $entry): bool {
        return trim($entry) === self::TOKEN_COURSE_TEACHERS;
    }

    /**
     * Parse a "category: addresses" map, one rule per line.
     *
     * Lets a course send technical incidents to the help desk and academic ones
     * to the teaching team. Unknown categories are dropped rather than guessed:
     * a typo must not silently redirect anybody's personal data.
     *
     * @param string $raw Raw setting value.
     * @return array<string, string[]> Category => addresses.
     */
    public static function parse_category_map(string $raw): array {
        $map = [];
        foreach (preg_split('/[\r\n]+/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            [$category, $addresses] = explode(':', $line, 2);
            $category = \core_text::strtolower(trim($category));
            if (!in_array($category, defaults::SUPPORT_CATEGORIES, true)) {
                continue;
            }
            $parsed = self::parse_addresses($addresses);
            if (!empty($parsed)) {
                $map[$category] = $parsed;
            }
        }

        return $map;
    }

    /**
     * Every address mentioned anywhere in a category map.
     *
     * @param string $raw Raw setting value.
     * @return string[]
     */
    public static function category_map_addresses(string $raw): array {
        $all = [];
        foreach (self::parse_category_map($raw) as $addresses) {
            foreach ($addresses as $address) {
                if (!in_array($address, $all, true)) {
                    $all[] = $address;
                }
            }
        }

        return $all;
    }

    /**
     * Return the addresses that are not syntactically valid.
     *
     * @param string[] $addresses Addresses to check.
     * @return string[] The invalid ones.
     */
    public static function invalid_addresses(array $addresses): array {
        $invalid = [];
        foreach ($addresses as $address) {
            if (self::is_token($address)) {
                continue;
            }
            if (!validate_email($address)) {
                $invalid[] = $address;
            }
        }

        return $invalid;
    }

    /**
     * Domains the site allows as escalation destinations.
     *
     * An empty list means "no domain restriction". The mandatory protection for
     * the destination address is the managesupportrecipient capability, not this
     * list; this is an optional extra hardening step for institutions that want
     * it.
     *
     * @return string[] Lower-cased domains, empty when unrestricted.
     */
    public static function allowed_domains(): array {
        $raw = (string)get_config('block_openaiagent', 'support_allowed_domains');
        $domains = [];
        foreach (self::parse_addresses($raw) as $entry) {
            $domain = ltrim(\core_text::strtolower($entry), '@');
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /**
     * Whether an address is acceptable under the configured domain list.
     *
     * @param string $address Address to check.
     * @return bool True when allowed (always true if no list is configured).
     */
    public static function domain_allowed(string $address): bool {
        $allowed = self::allowed_domains();
        if (empty($allowed)) {
            return true;
        }

        $at = strrpos($address, '@');
        if ($at === false) {
            return false;
        }
        $domain = \core_text::strtolower(substr($address, $at + 1));

        foreach ($allowed as $candidate) {
            // A configured "example.org" also covers "mail.example.org", so an
            // institution does not have to enumerate every subdomain it uses.
            if ($domain === $candidate || substr($domain, -strlen('.' . $candidate)) === '.' . $candidate) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return the addresses rejected by the configured domain list.
     *
     * @param string[] $addresses Addresses to check.
     * @return string[] The rejected ones.
     */
    public static function disallowed_addresses(array $addresses): array {
        $rejected = [];
        foreach ($addresses as $address) {
            if (self::is_token($address)) {
                continue;
            }
            if (!self::domain_allowed($address)) {
                $rejected[] = $address;
            }
        }

        return $rejected;
    }

    /**
     * The addresses behind the {course_teachers} placeholder.
     *
     * Resolved from the site's own course-contact roles, which is the same
     * definition Moodle uses when it shows who teaches a course. That is what
     * makes the token safe to hand to a teacher who may not set a destination:
     * it can only ever land on somebody already listed as running this course.
     *
     * @param int $courseid Course id.
     * @return string[] Addresses, deduplicated.
     */
    public static function course_contact_addresses(int $courseid): array {
        global $CFG;

        if ($courseid <= 0) {
            return [];
        }

        try {
            $context = \context_course::instance($courseid);
        } catch (\Throwable $e) {
            return [];
        }

        $roleids = array_filter(array_map('intval', explode(',', (string)($CFG->coursecontact ?? ''))));
        if (empty($roleids)) {
            return [];
        }

        $addresses = [];
        foreach ($roleids as $roleid) {
            // The name fields are in the list because get_role_users() sorts by
            // them and complains, loudly, when they are missing from $fields.
            $fields = 'u.id, u.email, u.suspended, u.deleted, u.firstname, u.lastname';
            foreach (get_role_users($roleid, $context, false, $fields) as $user) {
                $email = trim((string)($user->email ?? ''));
                if ($email === '' || !empty($user->suspended) || !empty($user->deleted)) {
                    continue;
                }
                if (!in_array($email, $addresses, true)) {
                    $addresses[] = $email;
                }
            }
        }

        return $addresses;
    }

    /**
     * Flatten a value that is about to be used as a mail header.
     *
     * Anything reaching a header must be a single line. The subject template can
     * carry model-written text such as the incident summary, and a line break in
     * a header is how header injection starts.
     *
     * @param string $value Raw value.
     * @return string Single-line value.
     */
    public static function flatten_header(string $value): string {
        $flat = preg_replace('/[\r\n]+/', ' ', $value);

        return trim(preg_replace('/\s{2,}/', ' ', (string)$flat));
    }

    /**
     * Build a recipient object for an address that is not a Moodle account.
     *
     * @param string $address Destination address.
     * @return \stdClass User-shaped object accepted by {@see email_to_user()}.
     */
    public static function external_recipient(string $address): \stdClass {
        $recipient = new \stdClass();
        $recipient->id = self::EXTERNAL_RECIPIENT_ID;
        $recipient->email = $address;
        $recipient->username = $address;
        // Core calls fullname() when logging delivery problems, and it
        // reads every name field, so none of them may be missing.
        $recipient->firstname = get_string('support_recipient_name', 'block_openaiagent');
        $recipient->lastname = '';
        $recipient->firstnamephonetic = '';
        $recipient->lastnamephonetic = '';
        $recipient->middlename = '';
        $recipient->alternatename = '';
        $recipient->maildisplay = 1;
        $recipient->mailformat = 1;
        $recipient->deleted = 0;
        $recipient->suspended = 0;
        $recipient->emailstop = 0;
        $recipient->auth = 'manual';

        return $recipient;
    }

    /**
     * Deliver one message to one support address.
     *
     * The sender is always the site no-reply account: sending as the participant
     * would fail SPF and DMARC and land the message in a spam folder. The
     * participant's address travels as Reply-To instead, so answering the mail
     * reaches them directly.
     *
     * @param string $address Destination address.
     * @param string $subject Subject line (flattened here).
     * @param string $body Plain-text body.
     * @param \stdClass|null $replyto User to answer to, or null for none.
     * @return bool True when the mailer accepted the message. Not proof of delivery.
     */
    public static function send_to(string $address, string $subject, string $body, ?\stdClass $replyto = null): bool {
        $recipient = self::external_recipient($address);
        $from = \core_user::get_noreply_user();

        $replytoaddress = '';
        $replytoname = '';
        if ($replyto !== null && !empty($replyto->email)) {
            $replytoaddress = (string)$replyto->email;
            $replytoname = fullname($replyto);
        }

        return (bool)email_to_user(
            $recipient,
            $from,
            self::flatten_header($subject),
            $body,
            '',
            '',
            '',
            true,
            $replytoaddress,
            $replytoname
        );
    }

    /**
     * Send a probe message to the configured support addresses.
     *
     * Bounces from the destination mailbox go to the site no-reply address and
     * are invisible to Moodle, so this is the only way an administrator can find
     * out that the mailbox is wrong before participants start relying on it.
     *
     * @return array{ok: bool, message: string}
     */
    public static function send_test(): array {
        global $CFG;

        $to = self::parse_addresses((string)get_config('block_openaiagent', 'support_email_to'));
        $cc = self::parse_addresses((string)get_config('block_openaiagent', 'support_email_cc'));
        $addresses = array_values(array_unique(array_merge($to, $cc)));

        if (empty($addresses)) {
            return [
                'ok' => false,
                'message' => get_string('support_test_norecipients', 'block_openaiagent'),
            ];
        }

        $invalid = self::invalid_addresses($addresses);
        if (!empty($invalid)) {
            return [
                'ok' => false,
                'message' => get_string('support_test_invalid', 'block_openaiagent', implode(', ', $invalid)),
            ];
        }

        $subject = get_string('support_test_subject', 'block_openaiagent');
        $body = get_string('support_test_body', 'block_openaiagent', (object)[
            'sitename' => format_string(get_site()->fullname),
            'siteurl' => (string)$CFG->wwwroot,
        ]);

        $failed = [];
        foreach ($addresses as $address) {
            if (!self::send_to($address, $subject, $body)) {
                $failed[] = $address;
            }
        }

        if (!empty($failed)) {
            return [
                'ok' => false,
                'message' => get_string('support_test_failed', 'block_openaiagent', implode(', ', $failed)),
            ];
        }

        return [
            'ok' => true,
            'message' => get_string('support_test_ok', 'block_openaiagent', implode(', ', $addresses)),
        ];
    }
}
