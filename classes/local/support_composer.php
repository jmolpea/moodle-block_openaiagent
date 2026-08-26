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
 * Turns a stored support request into the message the support team receives.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Composes the subject, body and destination of a support escalation.
 *
 * This is where the participant's identity enters the message, and it enters it
 * from the database: name, address, course and groups are read here, at send
 * time, and none of them has ever been part of anything sent to the AI provider.
 * The model contributed one thing, the summary, and it is dropped in as plain
 * text like any other untrusted value.
 */
class support_composer {
    /**
     * The destination addresses for a request.
     *
     * A category rule replaces the general address rather than adding to it:
     * "technical incidents go to the help desk" means they go there, not there
     * as well as everywhere else.
     *
     * @param \stdClass $request Support request record.
     * @param array $config Effective course config.
     * @return array{to: string[], cc: string[]} Resolved addresses.
     */
    public static function recipients(\stdClass $request, array $config): array {
        $support = $config['support'] ?? [];

        $to = support_mailer::parse_addresses((string)($support['to'] ?? ''));
        $map = support_mailer::parse_category_map((string)($support['categorymap'] ?? ''));
        $category = (string)$request->category;
        if (isset($map[$category])) {
            $to = $map[$category];
        }

        $cc = support_mailer::parse_addresses((string)($support['cc'] ?? ''));

        return [
            'to' => self::expand($to, (int)$request->courseid),
            'cc' => self::expand($cc, (int)$request->courseid),
        ];
    }

    /**
     * Replace placeholders in an address list with real addresses.
     *
     * The allowed-domain list is applied here as well as on save. Saving is not
     * enough on its own: an administrator can tighten the list after a
     * destination was stored, and {course_teachers} resolves to whatever
     * addresses the course contacts happen to have, which nobody ever typed
     * into a form. This is the last point before the message goes out, so it is
     * the only place the guarantee can actually hold.
     *
     * @param string[] $entries Configured entries, tokens included.
     * @param int $courseid Course the request came from.
     * @return string[] Real addresses, deduplicated and within the allowed domains.
     */
    private static function expand(array $entries, int $courseid): array {
        $out = [];
        foreach ($entries as $entry) {
            $addresses = support_mailer::is_token($entry)
                ? support_mailer::course_contact_addresses($courseid)
                : [$entry];
            foreach ($addresses as $address) {
                if ($address === '' || in_array($address, $out, true)) {
                    continue;
                }
                if (!support_mailer::domain_allowed($address)) {
                    debugging(
                        "block_openaiagent: support address {$address} is outside the allowed domains, skipping.",
                        DEBUG_DEVELOPER
                    );
                    continue;
                }
                $out[] = $address;
            }
        }

        return $out;
    }

    /**
     * Every placeholder value for a request.
     *
     * @param \stdClass $request Support request record.
     * @param array $config Effective course config.
     * @return array Placeholder => value.
     */
    public static function placeholders(\stdClass $request, array $config): array {
        global $CFG, $DB;

        $user = \core_user::get_user((int)$request->userid, '*', IGNORE_MISSING);
        $course = $DB->get_record('course', ['id' => (int)$request->courseid], '*', IGNORE_MISSING);
        $context = self::course_context((int)$request->courseid);

        return [
            '{ticketref}' => (string)$request->ticketref,
            '{category}' => self::category_name((string)$request->category),
            '{summary}' => trim((string)$request->summary),
            '{transcript}' => self::transcript($request, $config),
            '{firstname}' => $user ? (string)$user->firstname : '',
            '{lastname}' => $user ? (string)$user->lastname : '',
            '{email}' => $user ? (string)$user->email : '',
            '{username}' => $user ? (string)$user->username : '',
            '{profileurl}' => $user
                ? (string)(new \moodle_url('/user/view.php', ['id' => $user->id, 'course' => $request->courseid]))
                : '',
            '{coursename}' => $course ? self::multilang((string)$course->fullname, $context) : '',
            '{courseid}' => (string)$request->courseid,
            '{courseurl}' => (string)(new \moodle_url('/course/view.php', ['id' => $request->courseid])),
            '{groups}' => self::group_names($request),
            '{roles}' => self::role_names($request, $context),
            '{datetime}' => userdate((int)$request->timecreated),
            '{siteurl}' => (string)$CFG->wwwroot,
        ];
    }

    /**
     * The subject line for a request.
     *
     * @param \stdClass $request Support request record.
     * @param array $config Effective course config.
     * @return string Single-line subject.
     */
    public static function subject(\stdClass $request, array $config): string {
        $template = (string)($config['support']['subject'] ?? '');
        if (trim($template) === '') {
            $template = defaults::SUPPORT_SUBJECT_DEFAULT;
        }

        // Flattened last, after substitution: the summary is written by a model
        // and a newline reaching a header is where header injection starts.
        return support_mailer::flatten_header(
            self::render($template, self::placeholders($request, $config), (int)$request->courseid)
        );
    }

    /**
     * The message body for a request.
     *
     * @param \stdClass $request Support request record.
     * @param array $config Effective course config.
     * @return string Plain-text body.
     */
    public static function body(\stdClass $request, array $config): string {
        $template = (string)($config['support']['body'] ?? '');
        if (trim($template) === '') {
            $template = defaults::SUPPORT_BODY_DEFAULT;
        }

        $body = self::render($template, self::placeholders($request, $config), (int)$request->courseid);

        $signature = trim((string)($config['support']['signature'] ?? ''));
        if ($signature !== '') {
            $body .= "\n\n" . self::render($signature, [], (int)$request->courseid);
        }

        return trim($body);
    }

    /**
     * Substitute placeholders and resolve multilang tags.
     *
     * @param string $template Raw template.
     * @param array $placeholders Placeholder => value.
     * @param int $courseid Course providing the filter context.
     * @return string
     */
    private static function render(string $template, array $placeholders, int $courseid): string {
        return self::multilang(strtr($template, $placeholders), self::course_context($courseid));
    }

    /**
     * Resolve multilang markup, and nothing else.
     *
     * The multilang filters are invoked directly, by name, rather than through
     * format_string() or format_text(). Each of the obvious routes is wrong here
     * for its own reason:
     *
     * - format_string() applies the *string* filters, and Moodle enables
     *   multilang for content only unless an administrator ticks a second box
     *   that is off by default. On a normally configured site it therefore
     *   strips the tags without choosing a language, and the support team
     *   receives "ConsultaQuery": both translations run together. That is the
     *   4.10.1 bug wearing a different hat.
     * - format_text() and filter_text() resolve the language correctly, but they
     *   run every content filter. In a plain-text email that means the glossary
     *   and activity-name filters injecting anchor tags into the message.
     *
     * Calling the filters we actually want gets the language right on a default
     * installation, leaves the hand-aligned layout untouched, and cannot pull
     * markup in from anywhere else. Both syntaxes are covered: the core filter's
     * <span lang="es" class="multilang">, which is the one to prefer, and the
     * {mlang} of the third-party filter_multilang2, when that plugin is present.
     *
     * @param string $text Text to resolve.
     * @param \context|null $context Context the filters run in.
     * @return string
     */
    public static function multilang(string $text, ?\context $context): string {
        if ($context === null || trim($text) === '') {
            return $text;
        }

        foreach (['multilang2', 'multilang'] as $name) {
            if (!filter_is_enabled($name)) {
                continue;
            }
            $class = self::filter_class($name);
            if ($class === null) {
                continue;
            }
            try {
                $filter = new $class($context, []);
                $text = $filter->filter($text);
            } catch (\Throwable $e) {
                // A filter that cannot run must not stop the escalation: the
                // message is still perfectly deliverable with its tags in place.
                debugging(
                    'block_openaiagent could not apply the ' . $name . ' filter: ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        return $text;
    }

    /**
     * The class implementing a filter, whichever naming it uses.
     *
     * Moodle 4.5 moved filters to filter_xxx \text_filter and left the old flat
     * class name working but deprecated. The new name is tried first so a
     * correctly updated filter never triggers a deprecation notice; the legacy
     * name remains for third-party filters that have not moved yet.
     *
     * @param string $name Filter name, e.g. 'multilang'.
     * @return string|null Class name, or null when the filter is not installed.
     */
    private static function filter_class(string $name): ?string {
        $namespaced = 'filter_' . $name . '\text_filter';
        if (class_exists($namespaced)) {
            return $namespaced;
        }

        $legacy = 'filter_' . $name;

        return class_exists($legacy) ? $legacy : null;
    }

    /**
     * The recent conversation, when the configuration allows sending it.
     *
     * @param \stdClass $request Support request record.
     * @param array $config Effective course config.
     * @return string Empty when it must not travel.
     */
    public static function transcript(\stdClass $request, array $config): string {
        $support = $config['support'] ?? [];
        if (empty($support['includetranscript']) || (int)$request->conversationid <= 0) {
            return '';
        }

        $turns = (int)($support['transcriptturns'] ?? 6);
        if ($turns <= 0) {
            return '';
        }

        $history = conversation_repository::recent_history((int)$request->conversationid, $turns);
        if (empty($history)) {
            // Nothing to show, either because the exchange is empty or because
            // this profile does not store message text at all.
            return '';
        }

        $lines = [get_string('support_mail_transcript', 'block_openaiagent')];
        foreach ($history as $turn) {
            $who = $turn['role'] === 'user'
                ? get_string('support_mail_participant', 'block_openaiagent')
                : get_string('support_mail_assistant', 'block_openaiagent');
            $lines[] = $who . ': ' . trim((string)$turn['content']);
        }

        return implode("\n\n", $lines);
    }

    /**
     * Readable name of a category.
     *
     * @param string $category Category key.
     * @return string
     */
    public static function category_name(string $category): string {
        $key = 'support_category_' . $category;
        if (get_string_manager()->string_exists($key, 'block_openaiagent')) {
            return get_string($key, 'block_openaiagent');
        }

        return $category;
    }

    /**
     * The participant's groups in the course.
     *
     * @param \stdClass $request Support request record.
     * @return string Comma-separated names, empty when there are none.
     */
    private static function group_names(\stdClass $request): string {
        $groups = groups_get_all_groups((int)$request->courseid, (int)$request->userid);
        $names = [];
        foreach ($groups as $group) {
            $names[] = self::multilang((string)$group->name, self::course_context((int)$request->courseid));
        }

        return implode(', ', $names);
    }

    /**
     * The participant's roles in the course.
     *
     * @param \stdClass $request Support request record.
     * @param \context|null $context Course context.
     * @return string Comma-separated names, empty when there are none.
     */
    private static function role_names(\stdClass $request, ?\context $context): string {
        if ($context === null) {
            return '';
        }

        $roles = get_user_roles($context, (int)$request->userid, true);
        $names = [];
        foreach ($roles as $role) {
            $name = role_get_name($role, $context, ROLENAME_ALIAS);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return implode(', ', $names);
    }

    /**
     * Course context, or null when the course has gone.
     *
     * @param int $courseid Course id.
     * @return \context|null
     */
    private static function course_context(int $courseid): ?\context {
        try {
            return \context_course::instance($courseid);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
