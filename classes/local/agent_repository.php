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
 * Repository for agent definitions.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Loads and lists agent definitions.
 */
class agent_repository {
    /** @var string Table name. */
    private const TABLE = 'block_openaiagent_agents';

    /** @var string[] Valid agent types. */
    public const TYPES = ['router', 'tutor', 'assistant', 'ambiguity'];

    /**
     * Get an agent by id.
     *
     * @param int $id Agent id.
     * @return \stdClass|null Agent record or null.
     */
    public static function get(int $id): ?\stdClass {
        global $DB;
        if ($id <= 0) {
            return null;
        }
        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        return $record ?: null;
    }

    /**
     * Get the first enabled agent of a given type (used as a fallback).
     *
     * @param string $type Agent type.
     * @return \stdClass|null Agent record or null.
     */
    public static function get_default_for_type(string $type): ?\stdClass {
        global $DB;
        $records = $DB->get_records(self::TABLE, ['agenttype' => $type, 'enabled' => 1], 'id ASC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * Resolve an agent for a type, preferring a configured id then a default.
     *
     * @param int $preferredid Configured agent id (may be 0).
     * @param string $type Agent type.
     * @return \stdClass|null Agent record or null.
     */
    public static function resolve(int $preferredid, string $type): ?\stdClass {
        $agent = self::get($preferredid);
        if ($agent && $agent->agenttype === $type && (int)$agent->enabled === 1) {
            return $agent;
        }
        return self::get_default_for_type($type);
    }

    /**
     * List agents, optionally filtered by type.
     *
     * @param string|null $type Optional type filter.
     * @return \stdClass[] Agent records keyed by id.
     */
    public static function list(?string $type = null): array {
        global $DB;
        $conditions = [];
        if ($type !== null) {
            $conditions['agenttype'] = $type;
        }
        return $DB->get_records(self::TABLE, $conditions, 'agenttype ASC, name ASC');
    }

    /**
     * Build an id => name menu for a given type, for form selects.
     *
     * @param string $type Agent type.
     * @return array Menu of agent id => name (with a 0 => default option).
     */
    public static function menu(string $type): array {
        $menu = [0 => get_string('default')];
        foreach (self::list($type) as $agent) {
            $menu[(int)$agent->id] = format_string($agent->name);
        }
        return $menu;
    }
}
