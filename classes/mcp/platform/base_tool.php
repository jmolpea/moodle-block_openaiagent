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
 * Shared helpers for the platform assistant's tools.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform;

use block_openaiagent\local\scope;

/**
 * Defaults and formatting shared by the platform tools.
 */
abstract class base_tool implements tool {
    /** @var int Longest list a tool returns unless the model asks for less. */
    public const MAX_ITEMS = 20;

    /** @var int Longest course summary sent to the model, in characters. */
    public const MAX_SUMMARY_CHARS = 600;

    /**
     * Logged-in users only, unless a tool says otherwise.
     *
     * @return string[]
     */
    public function audiences(): array {
        return [scope::AUTHENTICATED];
    }

    /**
     * Available everywhere, unless a tool says otherwise.
     *
     * @return bool
     */
    public function is_available(): bool {
        return true;
    }

    /**
     * Build an object schema.
     *
     * @param array $properties Property definitions.
     * @param string[] $required Required property names.
     * @return array
     */
    protected static function schema(array $properties = [], array $required = []): array {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * A date the model can read without converting, in the user's timezone.
     *
     * Epoch seconds are what the course tools return, and inside one course that
     * is manageable. Across a dozen courses, models misread them often enough to
     * matter, so the platform tools say the date outright.
     *
     * @param int $timestamp Unix time; 0 or less means "not set".
     * @return string|null "YYYY-MM-DD HH:MM" in the user's timezone, or null.
     */
    protected static function date(int $timestamp): ?string {
        if ($timestamp <= 0) {
            return null;
        }
        return userdate($timestamp, '%Y-%m-%d %H:%M', 99, false, false);
    }

    /**
     * Plain text from formatted HTML, shortened to a limit.
     *
     * Not html_to_text(): it writes bold and headings in capitals, and a course
     * summary that bolds a name or a keyword reached the model shouting it.
     *
     * @param string $html Formatted text.
     * @param int $max Maximum characters.
     * @return string
     */
    protected static function plain(string $html, int $max = self::MAX_SUMMARY_CHARS): string {
        $text = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', ' ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (\core_text::strlen($text) > $max) {
            $text = rtrim(\core_text::substr($text, 0, $max - 1)) . '…';
        }
        return $text;
    }

    /**
     * A bounded integer argument.
     *
     * @param array $input Tool arguments.
     * @param string $key Argument name.
     * @param int $default Value when absent.
     * @param int $min Lowest accepted value.
     * @param int $max Highest accepted value.
     * @return int
     */
    protected static function int_arg(array $input, string $key, int $default, int $min, int $max): int {
        $value = isset($input[$key]) && is_numeric($input[$key]) ? (int)$input[$key] : $default;
        return max($min, min($max, $value));
    }
}
