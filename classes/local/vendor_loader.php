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
 * Loads the Composer autoloader bundled with the plugin.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Loads Composer autoloader bundled with the plugin, if present.
 */
class vendor_loader {
    /** @var bool Whether the autoloader has already been loaded. */
    private static bool $loaded = false;

    /**
     * Load the Composer autoloader once.
     *
     * Subsequent calls are no-ops. Safe to call from multiple code paths.
     */
    public static function load(): void {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (is_readable($autoload)) {
            require_once($autoload);
        }
    }
}
