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
 * Cache definitions for block_openaiagent.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Cached extracted text for files (keyed by file contenthash).
    'filetext' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simpledata' => true,
        'ttl' => 604800, // 7 days.
    ],
    // Per-user chat message rate limiting (keyed by 'm_{userid}_{minute}' and 'd_{userid}_{day}').
    'chatratelimit' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simpledata' => true,
        'ttl' => 86400, // 1 day — matches the daily window.
    ],
    // Visitor protections: per-address windows, the daily ceiling, the daily
    // notice flag and the conversation tokens. Keys never contain an address,
    // only a keyed hash of it.
    'visitor' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simpledata' => true,
        'ttl' => 86400,
    ],
    // Results of the catalogue tools for visitors: the same public answer for
    // everybody, so a burst of repeated questions does not repeat the queries.
    // Kept short on purpose: a course hidden by its teacher must disappear
    // from what visitors are told within minutes, not hours.
    'visitortools' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simpledata' => true,
        'ttl' => 600, // 10 minutes.
    ],
];
