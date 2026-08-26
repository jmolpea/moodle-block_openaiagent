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
 * Install script for block_openaiagent.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Seed default agents and global defaults on fresh install.
 *
 * @return bool True on success.
 */
function xmldb_block_openaiagent_install() {
    \block_openaiagent\local\defaults::install();

    // Open the evaluation window. Until it closes the assistant runs with no
    // license key at all, so the plugin can be assessed before anyone has to
    // ask us for a key.
    \block_openaiagent\license\validator::start_trial();

    return true;
}
