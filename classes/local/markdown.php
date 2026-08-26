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
 * Safe Markdown rendering for assistant replies.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Converts model Markdown output to sanitized HTML for the chat UI.
 */
class markdown {
    /**
     * Render an assistant reply (Markdown) as safe HTML.
     *
     * Messages are stored raw in the database; conversion happens only at
     * display time, so this needs no schema change. format_text() runs the
     * Markdown converter and Moodle's HTML cleaner, so model output can never
     * inject scripts or unsafe markup into the chat.
     *
     * @param string $reply Raw assistant reply (Markdown or plain text).
     * @param \context $context Context the reply is rendered in.
     * @return string Sanitized HTML.
     */
    public static function to_html(string $reply, \context $context): string {
        return format_text(self::open_lists($reply), FORMAT_MARKDOWN, [
            'context' => $context,
            // Keep chat bubbles predictable: no auto-linking/content filters.
            'filter' => false,
            // Links from the assistant open in a new tab (with rel=noreferrer).
            'blanktarget' => true,
        ]);
    }

    /**
     * Insert the blank line classic Markdown needs before a list.
     *
     * Markdown only starts a list when the first item is preceded by a blank
     * line. Models routinely write the lead-in and the items as one block:
     *
     *     Cómo funciona (resumen según la guía):
     *     - Paso 1: ...
     *     - Paso 2: ...
     *
     * which renders as a single run-on paragraph with literal hyphens instead of
     * a list -- the same reply with a blank line renders perfectly. Chat UIs are
     * expected to be forgiving here, so the renderer is made forgiving rather
     * than asking the model to remember a blank line every time. Only the
     * transition from a text line into the first item is patched; items already
     * inside a list, and text that merely starts with a hyphen mid-sentence, are
     * left alone.
     *
     * @param string $text Raw Markdown from the model.
     * @return string Markdown with lists opened properly.
     */
    private static function open_lists(string $text): string {
        if ($text === '') {
            return $text;
        }
        // A list item: "- x", "* x", "+ x" or "1. x", optionally indented.
        $item = '[ \t]*(?:[-*+][ \t]+|\d{1,3}[.)][ \t]+)\S';
        // A line that is NOT blank and NOT itself a list item, then a newline,
        // then a list item. Insert one blank line between the two.
        $patched = preg_replace(
            '/^(?![ \t]*$)(?!' . $item . ')(.*\S.*)\R(?=' . $item . ')/mu',
            "$1\n\n",
            $text
        );
        // A preg failure (bad UTF-8, backtrack limit) returns null; never lose
        // the reply over formatting.
        return $patched === null ? $text : $patched;
    }
}
