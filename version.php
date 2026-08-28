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
 * Version information for block_openaiagent.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_openaiagent';
$plugin->version = 2026082800;
$plugin->requires = 2024100700; // Moodle 4.5.
// Only 4.5 is declared because only 4.5 has been tested end to end. Moodle
// 5.x moved the web root into public/ and requires PHP 8.3; the plugin shows
// no removed APIs against it, but promising a version nobody has run is how
// refunds happen. Raise the ceiling once 5.x is actually exercised.
$plugin->supported = [405, 405];
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '4.15.1';
