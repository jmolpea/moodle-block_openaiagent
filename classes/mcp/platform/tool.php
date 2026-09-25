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
 * Contract for the platform assistant's tools.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform;

use block_openaiagent\local\scope;

/**
 * One read-only tool of the platform assistant (category and site home).
 *
 * The course assistant's tools live in {@see \block_openaiagent\mcp\tool_registry}
 * and are untouched by these. Every platform tool runs as the session user,
 * reads only what Moodle would show that user, and writes nothing.
 */
interface tool {
    /**
     * Tool name as the model sees it, in the moodle.verb_object form.
     *
     * @return string
     */
    public function name(): string;

    /**
     * What the tool returns, for the model.
     *
     * @return string
     */
    public function description(): string;

    /**
     * JSON schema of the arguments. Never includes the user or the course of
     * the session: those come from the scope.
     *
     * @return array
     */
    public function input_schema(): array;

    /**
     * Audiences allowed to reach the tool ({@see scope::AUTHENTICATED}, {@see scope::VISITOR}).
     *
     * A tool that returns anything about the user must not list the visitor
     * audience. The registry enforces this whatever the block configuration says.
     *
     * @return string[]
     */
    public function audiences(): array;

    /**
     * Whether the tool can work on this site (for example, a required plugin is installed).
     *
     * @return bool
     */
    public function is_available(): bool;

    /**
     * Run the tool.
     *
     * @param array $input Arguments from the model, not yet validated.
     * @param scope $scope Server-side scope of the turn.
     * @return array Small, predictable result.
     */
    public function execute(array $input, scope $scope): array;
}
