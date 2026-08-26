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
 * Resolves the effective configuration for a course.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Loads per-course configuration and merges it with global defaults.
 */
class course_config {
    /** @var string Course config table. */
    private const TABLE = 'block_openaiagent_courseconfig';

    /** @var string Course tools table. */
    private const TOOLS_TABLE = 'block_openaiagent_coursetools';

    /**
     * Resolve the stable "owning" course id for a block instance profile.
     *
     * Config, conversations and knowledge base are keyed by (course, block
     * instance). The course id must be derived from the block instance's own
     * context, NOT from the page it is viewed on: a block placed in a category
     * (or shown across a category's courses via "display on subcontexts") must
     * always resolve to the same profile. Course/module blocks return their
     * course; category and site blocks return the site course.
     *
     * @param int $blockinstanceid Block instance id (0 = no instance).
     * @param int $fallbackcourseid Course id to use when no instance is given.
     * @return int
     */
    public static function owning_courseid(int $blockinstanceid, int $fallbackcourseid): int {
        global $DB;

        if ($blockinstanceid > 0) {
            $parentcontextid = $DB->get_field('block_instances', 'parentcontextid', ['id' => $blockinstanceid]);
            if ($parentcontextid) {
                $blockcontext = \context::instance_by_id((int)$parentcontextid, IGNORE_MISSING);
                if ($blockcontext) {
                    $coursecontext = $blockcontext->get_course_context(false);
                    return $coursecontext ? (int)$coursecontext->instanceid : (int)SITEID;
                }
            }
        }
        return $fallbackcourseid;
    }

    /**
     * Load the raw config row for a block instance profile, or null if none.
     *
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return \stdClass|null
     */
    public static function get_raw(int $courseid, int $blockinstanceid = 0): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, [
            'courseid' => $courseid,
            'blockinstanceid' => $blockinstanceid,
        ]);
        return $record ?: null;
    }

    /**
     * Whether the assistant is enabled for this profile.
     *
     * A profile with no configuration row is treated as enabled so the block
     * works out of the box; an explicit row with enabled=0 disables it.
     *
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return bool
     */
    public static function is_enabled(int $courseid, int $blockinstanceid = 0): bool {
        $raw = self::get_raw($courseid, $blockinstanceid);
        if ($raw === null) {
            return true;
        }
        return (int)$raw->enabled === 1;
    }

    /**
     * Whether this profile stores the text of its conversations.
     *
     * Reads the single column rather than going through resolve(), which also
     * loads four agent records and the tool list — far too much work for a check
     * that runs on every message.
     *
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return bool True when message content may be stored.
     */
    public static function stores_conversations(int $courseid, int $blockinstanceid = 0): bool {
        $raw = self::get_raw($courseid, $blockinstanceid);
        if ($raw === null) {
            return true;
        }
        return (int)$raw->storeconversations === 1;
    }

    /**
     * Save (insert or update) a configuration row for a block instance profile.
     *
     * @param int $courseid Course id.
     * @param array $data Field => value pairs to persist.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return void
     */
    public static function save(int $courseid, array $data, int $blockinstanceid = 0): void {
        global $DB, $USER;

        $now = time();
        $existing = self::get_raw($courseid, $blockinstanceid);

        // Whitelist of writable columns.
        $fields = [
            'enabled', 'tutorenabled', 'assistantenabled',
            'routeragentid', 'tutoragentid',
            'assistantagentid', 'ambiguityagentid', 'courseprompt',
            'assistantprompt', 'assistantfaqs', 'routerprompt',
            'modeloverride', 'temperatureoverride', 'maxoutputtokensoverride', 'languagepolicy',
            'evaluationpolicy', 'citabledocuments', 'internaldocuments', 'protectedactivities',
            'fallbacknoinfo', 'fallbackoutofscope', 'fallbackevaluationblock', 'storeconversations',
            'supportmode', 'supportemailto', 'supportemailcc', 'supportcategorymap',
            'supportsubject', 'supportbody', 'supportsignature', 'supportslatext',
            'supportincludetranscript', 'supportcopytouser',
        ];

        $record = new \stdClass();
        $record->courseid = $courseid;
        $record->blockinstanceid = $blockinstanceid;
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $record->$field = $data[$field];
            }
        }
        $record->timemodified = $now;
        $record->usermodified = (int)$USER->id;

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record(self::TABLE, $record);
        } else {
            $record->timecreated = $now;
            $DB->insert_record(self::TABLE, $record);
        }
    }

    /**
     * Resolve the effective configuration, merging per-course values with
     * global setting defaults and resolving the agents for each route.
     *
     * Never returns null string fields: callers can use the values directly
     * when composing OpenAI instructions.
     *
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return array Effective configuration map.
     */
    public static function resolve(int $courseid, int $blockinstanceid = 0): array {
        $raw = self::get_raw($courseid, $blockinstanceid);

        $get = static function (string $field, $default = '') use ($raw) {
            if ($raw === null || !isset($raw->$field) || $raw->$field === null) {
                return $default;
            }
            return $raw->$field;
        };

        $languagepolicy = (string)$get('languagepolicy', (string)get_config('block_openaiagent', 'default_language_policy'));
        if ($languagepolicy === '') {
            $languagepolicy = 'auto';
        }

        return [
            'courseid' => $courseid,
            'blockinstanceid' => $blockinstanceid,
            'enabled' => self::is_enabled($courseid, $blockinstanceid),
            'tutorenabled' => (int)$get('tutorenabled', 1),
            'assistantenabled' => (int)$get('assistantenabled', 1),
            'courseprompt' => (string)$get('courseprompt'),
            'assistantprompt' => (string)$get('assistantprompt'),
            'assistantfaqs' => (string)$get('assistantfaqs'),
            'routerprompt' => (string)$get('routerprompt'),
            'modeloverride' => (string)$get('modeloverride'),
            'temperatureoverride' => ($raw && $raw->temperatureoverride !== null) ? (float)$raw->temperatureoverride : null,
            'maxoutputtokensoverride' => ($raw && $raw->maxoutputtokensoverride !== null)
                ? (int)$raw->maxoutputtokensoverride : null,
            'languagepolicy' => $languagepolicy,
            'evaluationpolicy' => (string)$get('evaluationpolicy'),
            'citabledocuments' => (string)$get('citabledocuments'),
            'internaldocuments' => (string)$get('internaldocuments'),
            'protectedactivities' => (string)$get('protectedactivities'),
            'fallbacknoinfo' => (string)$get('fallbacknoinfo'),
            'fallbackoutofscope' => (string)$get('fallbackoutofscope'),
            'fallbackevaluationblock' => (string)$get('fallbackevaluationblock'),
            'storeconversations' => $raw === null ? 1 : (int)$raw->storeconversations,
            'agents' => [
                'router' => agent_repository::resolve((int)$get('routeragentid', 0), 'router'),
                'tutor' => agent_repository::resolve((int)$get('tutoragentid', 0), 'tutor'),
                'assistant' => agent_repository::resolve((int)$get('assistantagentid', 0), 'assistant'),
                'ambiguity' => agent_repository::resolve((int)$get('ambiguityagentid', 0), 'ambiguity'),
            ],
            'tools' => self::enabled_tools($courseid, $blockinstanceid),
            'support' => self::support_from_raw($raw),
        ];
    }

    /**
     * Resolve the effective support escalation configuration for a profile.
     *
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return array Effective support configuration.
     */
    public static function support(int $courseid, int $blockinstanceid = 0): array {
        return self::support_from_raw(self::get_raw($courseid, $blockinstanceid));
    }

    /**
     * Merge the per-course support settings over the site defaults.
     *
     * Inheritance rules, which are what the course form promises:
     *
     * - Text fields inherit the site value when the course leaves them empty.
     * - The tri-state integers use -1 for "inherit", so a deliberate "no" stays
     *   distinguishable from "never configured".
     * - The site switch is a real master: a course may switch escalation off,
     *   but it cannot switch it on where the site has it off.
     * - The limits are site-wide only. They exist to protect the support mailbox
     *   and the site as a whole, so letting a single course raise its own ceiling
     *   would defeat the point.
     *
     * Addresses are returned as the raw configured strings, tokens included:
     * {course_teachers} can only be resolved at send time, when the course is
     * known.
     *
     * @param \stdClass|null $raw Course config row, or null when there is none.
     * @return array Effective support configuration.
     */
    private static function support_from_raw(?\stdClass $raw): array {
        $site = static function (string $name, $default = '') {
            $value = get_config('block_openaiagent', $name);
            return $value === false ? $default : $value;
        };

        $course = static function (string $field) use ($raw) {
            if ($raw === null || !isset($raw->$field) || $raw->$field === null) {
                return '';
            }
            return trim((string)$raw->$field);
        };

        // A course value wins only when it is not empty; empty means inherit.
        $text = static function (string $field, string $sitename) use ($course, $site) {
            $value = $course($field);
            return $value !== '' ? $value : (string)$site($sitename);
        };

        // Minus one means inherit; 0 and 1 are deliberate choices.
        $tristate = static function (string $field, string $sitename) use ($raw, $site) {
            $value = ($raw === null || !isset($raw->$field)) ? -1 : (int)$raw->$field;
            if ($value === 0 || $value === 1) {
                return $value === 1;
            }
            return (int)$site($sitename, 0) === 1;
        };

        $mode = $course('supportmode');
        if ($mode === '') {
            $mode = 'inherit';
        }
        $siteenabled = (int)$site('support_email_enabled', 0) === 1;
        $enabled = $siteenabled && $mode !== 'off';

        return [
            'enabled' => $enabled,
            'mode' => $mode,
            'siteenabled' => $siteenabled,
            'to' => $text('supportemailto', 'support_email_to'),
            'cc' => $text('supportemailcc', 'support_email_cc'),
            'categorymap' => $course('supportcategorymap'),
            'subject' => $text('supportsubject', 'support_subject_template'),
            'body' => $text('supportbody', 'support_body_template'),
            'signature' => $text('supportsignature', 'support_signature'),
            'slatext' => $text('supportslatext', 'support_sla_text'),
            'includetranscript' => $tristate('supportincludetranscript', 'support_include_transcript'),
            'copytouser' => $tristate('supportcopytouser', 'support_copy_to_user'),
            'transcriptturns' => (int)$site('support_transcript_turns', 6),
            'maxperuserday' => (int)$site('support_max_per_user_day', 3),
            'cooldownminutes' => (int)$site('support_cooldown_minutes', 10),
            'offercooldownturns' => (int)$site('support_offer_cooldown_turns', 5),
            'dedupehours' => (int)$site('support_dedupe_hours', 24),
            'maxpercourseday' => (int)$site('support_max_per_course_day', 200),
            'digestmode' => (int)$site('support_digest_mode', 0) === 1,
            'digestminutes' => (int)$site('support_digest_minutes', 30),
            'supporturl' => (string)$site('support_url'),
        ];
    }

    /**
     * Persist the enabled-tool selection for a course.
     *
     * Upserts a row per known tool name (from the plugin defaults) recording
     * whether it is enabled for this course.
     *
     * @param int $courseid Course id.
     * @param string[] $enablednames Tool names that should be enabled.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return void
     */
    public static function save_tools(int $courseid, array $enablednames, int $blockinstanceid = 0): void {
        global $DB;

        $now = time();
        $enabled = array_fill_keys($enablednames, true);
        $existing = $DB->get_records(
            self::TOOLS_TABLE,
            ['courseid' => $courseid, 'blockinstanceid' => $blockinstanceid],
            '',
            'toolname, id'
        );

        foreach (defaults::default_tool_names() as $toolname) {
            $isenabled = isset($enabled[$toolname]) ? 1 : 0;
            if (isset($existing[$toolname])) {
                $DB->update_record(self::TOOLS_TABLE, (object) [
                    'id' => $existing[$toolname]->id,
                    'enabled' => $isenabled,
                    'timemodified' => $now,
                ]);
            } else {
                $DB->insert_record(self::TOOLS_TABLE, (object) [
                    'courseid' => $courseid,
                    'blockinstanceid' => $blockinstanceid,
                    'toolname' => $toolname,
                    'enabled' => $isenabled,
                    'requirescapability' => '',
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
        }
    }

    /**
     * Return the list of enabled MCP tool names for a course.
     *
     * If no per-course tool rows exist, falls back to the plugin defaults.
     *
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return string[] Tool names.
     */
    public static function enabled_tools(int $courseid, int $blockinstanceid = 0): array {
        global $DB;

        $rows = $DB->get_records(self::TOOLS_TABLE, [
            'courseid' => $courseid,
            'blockinstanceid' => $blockinstanceid,
        ]);
        if (!$rows) {
            return defaults::default_tool_names();
        }

        $names = [];
        foreach ($rows as $row) {
            if ((int)$row->enabled === 1) {
                $names[] = (string)$row->toolname;
            }
        }
        return $names;
    }
}
