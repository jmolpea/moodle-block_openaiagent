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
 * Contract for a source of certificates or badges.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\credentials;

/**
 * One place a participant's credentials come from.
 *
 * Every source reads through the providing plugin's own API, never its tables,
 * and returns credentials in one shape so the model sees a single list.
 */
interface source {
    /**
     * Short internal name, for debugging only; never shown to the participant.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Whether the providing plugin is installed and enabled on this site.
     *
     * @return bool
     */
    public function is_available(): bool;

    /**
     * The participant's credentials from this source.
     *
     * Each entry: type ('badge'|'certificate'), name, issuer, course, issued and
     * expires (Unix time, 0 = none), code, url, verify_url, requires_course_access,
     * source.
     *
     * @param int $userid User id.
     * @return array[]
     */
    public function credentials(int $userid): array;
}
