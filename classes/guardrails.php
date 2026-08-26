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
 * Input guardrails applied before any OpenAI call.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent;

/**
 * Validates and normalizes user input.
 */
class guardrails {
    /** @var int Maximum accepted message length (characters). */
    public const MAX_LENGTH = 4000;

    /**
     * Result of a guardrail check.
     *
     * @param bool $allowed Whether the message passes.
     * @param string $errorcode Lang string key (without component) when blocked.
     * @param string $message Normalized message when allowed.
     */
    private function __construct(
        /** @var bool $allowed Whether the message passes. */
        public bool $allowed,
        /** @var string $errorcode Lang string key (without component) when blocked. */
        public string $errorcode = '',
        /** @var string $message Normalized message when allowed. */
        public string $message = ''
    ) {
    }

    /**
     * Run guardrails over a raw user message.
     *
     * @param string $raw Raw message from the client.
     * @return self
     */
    public static function check(string $raw): self {
        $message = trim($raw);

        if ($message === '') {
            return new self(false, 'error_emptymessage');
        }

        // Count by characters (multibyte-safe).
        if (\core_text::strlen($message) > self::MAX_LENGTH) {
            return new self(false, 'error_messagetoolong');
        }

        if ((int)get_config('block_openaiagent', 'enable_guardrails') !== 1) {
            return new self(true, '', $message);
        }

        // Strip control characters that could break the JSON payload, but keep
        // newlines and tabs.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $message);
        if ($clean === null) {
            // Preg failed (e.g. invalid UTF-8): reject rather than forward garbage.
            return new self(false, 'error_emptymessage');
        }
        $clean = trim($clean);
        if ($clean === '') {
            return new self(false, 'error_emptymessage');
        }

        return new self(true, '', $clean);
    }
}
