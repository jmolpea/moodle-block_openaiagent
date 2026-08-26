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
 * Base class for activity configuration interpreters.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\config;

use cm_info;

/**
 * Turns a module's stored settings into behaviour rules a participant can act on.
 *
 * The availability API (core_availability) answers "is this activity gated?".
 * It says nothing about how the module behaves once you are inside it, which is
 * where a large share of "why can't I see this?" questions actually live: a
 * Q&A forum hides other people's posts until you have posted, a quiz can defer
 * its review options, an assignment can be past its cutoff. Those rules live in
 * each module's own tables and logic, so each module gets its own interpreter.
 *
 * Two hard rules for every subclass:
 *
 * 1. Never dump the instance record. Every emitted field is chosen explicitly.
 *    Module tables carry settings a participant must not see (quiz passwords and
 *    subnet locks, assignment blind marking, forum posting thresholds), so an
 *    allowlist is the only safe shape here.
 * 2. Only emit what applies. A rule that says "this activity has no cutoff date"
 *    is context paid for on every turn that teaches the model nothing.
 */
abstract class interpreter {
    /**
     * Cap on rules returned for one activity.
     *
     * The rules ride in the prompt of every remaining tool iteration, so the cap
     * is a cost control as much as a readability one. Module-specific rules are
     * merged before the generic ones, so the cap trims the least specific first.
     */
    public const MAX_RULES = 8;

    /**
     * Behaviour rules that apply to this activity for this user.
     *
     * @param \stdClass $instance Module instance record (may be empty).
     * @param cm_info $cm Course module info, built for the target user.
     * @param int $userid Target user id.
     * @return string[] Plain-language rules, most relevant first.
     */
    abstract public static function rules(\stdClass $instance, cm_info $cm, int $userid): array;

    /**
     * The user's own state against those rules.
     *
     * Configuration alone rarely answers the question: "this is a Q&A forum" is
     * useless without "and you have not posted yet". Subclasses call the module's
     * real API for this rather than inferring it from stored fields, so the
     * assistant reports what Moodle actually decided.
     *
     * @param \stdClass $instance Module instance record (may be empty).
     * @param cm_info $cm Course module info, built for the target user.
     * @param int $userid Target user id.
     * @return array Scalar map; empty when the module has nothing user-specific.
     */
    public static function user_state(\stdClass $instance, cm_info $cm, int $userid): array {
        unset($instance, $cm, $userid);
        return [];
    }

    /**
     * Resolve the interpreter class for a module, or null when there is none.
     *
     * A module without its own interpreter is not an error: the generic
     * interpreter still reports group mode, completion conditions and dates for
     * it, so every activity and resource in the course gets an answer.
     *
     * @param string $modname Module name, e.g. "forum".
     * @return class-string<self>|null
     */
    public static function for_modname(string $modname): ?string {
        $map = [
            'assign' => assign::class,
            'forum' => forum::class,
            'quiz' => quiz::class,
        ];

        return $map[$modname] ?? null;
    }
}
