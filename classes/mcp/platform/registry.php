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
 * Registry of the platform assistant's tools.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform;

use block_openaiagent\local\scope;

/**
 * Lists, filters and runs the platform tools.
 *
 * Two filters decide what reaches the model, in this order:
 *
 * 1. Security, in code and not configurable: the tool must allow the user's
 *    audience and be available on this site. A visitor never reaches a tool
 *    that returns personal data, whatever the block configuration says.
 * 2. Configuration: an administrator may switch individual tools off for a
 *    block. A tool with no stored choice is on.
 */
final class registry {
    /** @var string[] Tool classes, in the order they are offered. */
    private const TOOLS = [
        tools\get_my_profile::class,
        tools\get_my_courses::class,
        tools\get_my_course_progress::class,
        tools\get_my_deadlines::class,
        tools\get_my_grades::class,
        tools\get_my_notifications::class,
        tools\search_catalog::class,
        tools\get_enrolment_options::class,
        tools\get_site_access_info::class,
    ];

    /**
     * Every platform tool, whatever the scope.
     *
     * @return tool[] Keyed by tool name.
     */
    public static function all(): array {
        $tools = [];
        foreach (self::TOOLS as $class) {
            $tool = new $class();
            $tools[$tool->name()] = $tool;
        }
        return $tools;
    }

    /**
     * Names of every platform tool: the platform default selection.
     *
     * @return string[]
     */
    public static function default_names(): array {
        return array_keys(self::all());
    }

    /**
     * The tools that pass the security filter for a scope.
     *
     * @param scope $scope Turn scope.
     * @return tool[] Keyed by tool name.
     */
    public static function permitted(scope $scope): array {
        if (!$scope->is_platform()) {
            return [];
        }
        $permitted = [];
        foreach (self::all() as $name => $tool) {
            if (in_array($scope->audience, $tool->audiences(), true) && $tool->is_available()) {
                $permitted[$name] = $tool;
            }
        }
        return $permitted;
    }

    /**
     * The tools a block offers in a scope: permitted, and not switched off.
     *
     * Existing category and site blocks have stored choices only for course
     * tools, so every platform tool starts switched on for them without any
     * data migration.
     *
     * @param scope $scope Turn scope.
     * @return string[] Tool names.
     */
    public static function enabled_names(scope $scope): array {
        $disabled = self::switched_off($scope->courseid, $scope->blockinstanceid);
        return array_values(array_diff(array_keys(self::permitted($scope)), $disabled));
    }

    /**
     * Tools a block has explicitly switched off.
     *
     * @param int $courseid Owning course id.
     * @param int $blockinstanceid Block instance id.
     * @return string[] Tool names.
     */
    public static function switched_off(int $courseid, int $blockinstanceid): array {
        global $DB;

        return $DB->get_fieldset_select(
            'block_openaiagent_coursetools',
            'toolname',
            'courseid = :courseid AND blockinstanceid = :blockid AND enabled = 0',
            ['courseid' => $courseid, 'blockid' => $blockinstanceid]
        );
    }

    /**
     * Whether a name belongs to a platform tool.
     *
     * @param string $name Tool name.
     * @return bool
     */
    public static function is_platform_tool(string $name): bool {
        return in_array($name, self::default_names(), true);
    }

    /**
     * Neutral function definitions for the model.
     *
     * Dots are encoded as double underscores because most providers restrict
     * function names to [a-zA-Z0-9_-], exactly as for the course tools.
     *
     * @param scope $scope Turn scope.
     * @param string[] $names Tool names to include.
     * @return array[]
     */
    public static function function_tools(scope $scope, array $names): array {
        $permitted = self::permitted($scope);
        $definitions = [];
        foreach ($names as $name) {
            if (!isset($permitted[$name])) {
                continue;
            }
            $tool = $permitted[$name];
            $schema = $tool->input_schema();
            if (empty($schema['properties'])) {
                $schema['properties'] = new \stdClass();
            }
            $definitions[] = [
                'name' => str_replace('.', '__', $name),
                'description' => $tool->description(),
                'parameters' => $schema,
            ];
        }
        return $definitions;
    }

    /**
     * A public tool's result for visitors, from a shared cache.
     *
     * Only the tool results are cached, never the model's answers: a visitor
     * can steer an answer with their own words, and a cached answer would be
     * served to the next visitor. A tool result is plain Moodle data, and for
     * visitors it is the same for everyone who asks the same thing.
     *
     * @param tool $tool The tool.
     * @param array $input Arguments.
     * @param scope $scope Visitor scope.
     * @return array
     */
    private static function cached_public_call(tool $tool, array $input, scope $scope): array {
        ksort($input);
        $key = sha1(implode('|', [
            $tool->name(),
            json_encode($input),
            $scope->blockinstanceid,
            $scope->pagecourseid,
            current_language(),
        ]));
        $cache = \cache::make('block_openaiagent', 'visitortools');
        $cached = $cache->get($key);
        if (is_string($cached)) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $result = $tool->execute($input, $scope);
        $cache->set($key, json_encode($result));
        return $result;
    }

    /**
     * Run a platform tool, auditing the call.
     *
     * @param string $name Tool name.
     * @param array $input Arguments from the model.
     * @param scope $scope Turn scope; the user always comes from here.
     * @return array Tool output.
     * @throws \moodle_exception When the tool is unknown or not permitted in this scope.
     */
    public static function call(string $name, array $input, scope $scope): array {
        $permitted = self::permitted($scope);
        if (!isset($permitted[$name])) {
            throw new \moodle_exception('mcp_unknown_tool', 'block_openaiagent', '', $name);
        }

        // Identity never comes from the model.
        unset($input['user_id'], $input['course_id'], $input['mcp_session_token']);

        $ok = false;
        $errtype = '';
        try {
            // Site access facts are cheap and must be exact; only the catalogue is cached.
            $result = $scope->is_visitor() && $name !== 'moodle.get_site_access_info'
                ? self::cached_public_call($permitted[$name], $input, $scope)
                : $permitted[$name]->execute($input, $scope);
            $ok = true;
            return $result;
        } catch (\Throwable $e) {
            $errtype = get_class($e);
            throw $e;
        } finally {
            try {
                \block_openaiagent\event\mcp_tool_called::make($name, $scope->userid, $scope->courseid, $ok, $errtype)
                    ->trigger();
            } catch (\Throwable $ignored) {
                // Audit failures must never break tool calls.
                unset($ignored);
            }
        }
    }
}
