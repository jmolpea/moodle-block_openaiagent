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
 * Turns a resolved profile into a platform (category or site) profile.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

use block_openaiagent\mcp\platform\registry;

/**
 * Adjusts the effective configuration of a category or site assistant.
 *
 * Only ever applied outside a course: a course assistant's configuration is
 * returned by {@see course_config::resolve()} and used untouched. Three things
 * change for a platform assistant:
 *
 * - Tools: the platform tools the block allows, plus, when the block is shown
 *   inside one of its courses to someone enrolled there, that course's tools.
 * - Default prompts: where the block keeps the built-in text, the platform
 *   version replaces the course one. A prompt an administrator wrote is kept.
 * - The scope travels with the configuration, so the tool loop and the
 *   instructions know where they are.
 */
final class platform_profile {
    /**
     * Apply the platform profile.
     *
     * @param array $config Configuration from course_config::resolve().
     * @param scope $scope Platform scope.
     * @return array
     */
    public static function apply(array $config, scope $scope): array {
        $tools = registry::enabled_names($scope);
        if (
            $scope->pagecourseid > 0
            && $scope->pagecourseenrolled
            && block_settings::course_tools_in_course($scope->blockinstanceid)
            && course_config::core_ai_allows($scope->pagecourseid)
        ) {
            // The block's own course-tool selection, which defaults to all of them.
            $tools = array_merge($tools, course_config::enabled_tools($scope->courseid, $scope->blockinstanceid));
        }
        $config['tools'] = array_values(array_unique($tools));

        $config['courseprompt'] = self::or_default(
            $config['courseprompt'],
            defaults::TUTOR_PROMPT,
            defaults::PLATFORM_TUTOR_PROMPT
        );
        $config['assistantprompt'] = self::or_default(
            $config['assistantprompt'],
            defaults::ASSISTANT_PROMPT,
            defaults::PLATFORM_ASSISTANT_PROMPT
        );
        $config['routerprompt'] = self::or_default(
            $config['routerprompt'],
            defaults::ROUTER_PROMPT,
            defaults::PLATFORM_ROUTER_PROMPT
        );
        $config['fallbacknoinfo'] = self::or_default(
            $config['fallbacknoinfo'],
            defaults::FALLBACK_NOINFO_DEFAULT,
            defaults::PLATFORM_FALLBACK_NOINFO
        );
        $config['fallbackoutofscope'] = self::or_default(
            $config['fallbackoutofscope'],
            defaults::FALLBACK_OUTOFSCOPE_DEFAULT,
            defaults::PLATFORM_FALLBACK_OUTOFSCOPE
        );

        $config['scope'] = $scope;
        return $config;
    }

    /**
     * The platform default when the stored text is empty or still the course default.
     *
     * The configuration form pre-fills the course defaults, so a category block
     * saved before this release holds the course text without anyone having
     * written it. Treating that as "not customised" is what moves those blocks
     * to the platform wording on upgrade, while any real edit is respected.
     *
     * @param string $stored Stored value.
     * @param string $coursedefault The course default it may still be.
     * @param string $platformdefault The platform default.
     * @return string
     */
    private static function or_default(string $stored, string $coursedefault, string $platformdefault): string {
        $normalise = static fn(string $text): string => trim(str_replace("\r\n", "\n", $text));
        $stored = $normalise($stored);
        if ($stored === '' || $stored === $normalise($coursedefault)) {
            return $platformdefault;
        }
        return $stored;
    }
}
