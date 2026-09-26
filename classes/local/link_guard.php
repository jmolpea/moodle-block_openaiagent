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
 * Keeps invented links out of answers to visitors.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Removes links a visitor could not trust.
 *
 * Measured on fifty visitor questions: when the site had no support page, the
 * model wrote one anyway ("northfield.example.edu/support", or a link whose
 * target was literally "support_url"). A visitor with little technical
 * knowledge clicks it and lands nowhere. A Markdown link survives only when it
 * points to this site or appears word for word in the assistant's own
 * documents; any other link keeps its text and loses its target.
 */
final class link_guard {
    /**
     * Unlink every Markdown link that is not to this site or in the block's documents.
     *
     * @param string $markdown Reply as written by the model.
     * @param int $blockinstanceid Assistant block, whose documents may carry external links.
     * @return string
     */
    public static function clean(string $markdown, int $blockinstanceid): string {
        return preg_replace_callback(
            '/\[([^\]]*)\]\(([^)\s]*)\)/u',
            static function (array $match) use ($blockinstanceid): string {
                return self::trusted($match[2], $blockinstanceid) ? $match[0] : $match[1];
            },
            $markdown
        );
    }

    /**
     * Whether a link target may be shown.
     *
     * @param string $url Link target.
     * @param int $blockinstanceid Assistant block.
     * @return bool
     */
    private static function trusted(string $url, int $blockinstanceid): bool {
        global $CFG, $DB;

        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }
        $site = parse_url($CFG->wwwroot);
        $link = parse_url($url);
        if (!empty($link['host']) && !empty($site['host']) && strcasecmp($link['host'], $site['host']) === 0) {
            return true;
        }
        // A link the administrator put in the assistant's documents.
        return $DB->record_exists_select(
            'block_openaiagent_chunks',
            'blockinstanceid = :blockid AND ' . $DB->sql_like('content', ':url', false, false),
            ['blockid' => $blockinstanceid, 'url' => '%' . $DB->sql_like_escape($url) . '%']
        );
    }
}
