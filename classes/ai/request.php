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
 * Provider-neutral chat completion request.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\ai;

/**
 * A provider-neutral request that each provider adapter translates to its wire format.
 *
 * Message items are maps with a 'role' key:
 * - ['role' => 'user'|'assistant', 'content' => string]
 * - ['role' => 'assistant', 'content' => string, 'toolcalls' => [['id','name','arguments'], ...]]
 * - ['role' => 'tool', 'toolcallid' => string, 'name' => string, 'content' => string]
 *
 * Tool definitions are maps: ['name' => string, 'description' => string, 'parameters' => array]
 * where 'parameters' is a JSON Schema object.
 */
class request {
    /** @var string Model identifier for the active provider. */
    public string $model = '';

    /** @var string System instructions. */
    public string $instructions = '';

    /** @var array[] Ordered conversation messages (see class docs for shapes). */
    public array $messages = [];

    /** @var array[] Neutral tool (function) definitions. */
    public array $tools = [];

    /** @var bool Whether the reply must be a single JSON object. */
    public bool $jsonmode = false;

    /** @var float Sampling temperature. */
    public float $temperature = 0.2;

    /**
     * @var string Requested reasoning effort ('' = provider default). Only
     * applied by adapters/models that support it (e.g. OpenAI gpt-5 family);
     * other adapters ignore it.
     */
    public string $reasoningeffort = '';

    /** @var int Maximum output tokens. */
    public int $maxtokens = 1000;

    /**
     * Append a plain user message.
     *
     * @param string $content Message text.
     * @return void
     */
    public function add_user_message(string $content): void {
        $this->messages[] = ['role' => 'user', 'content' => $content];
    }

    /**
     * Append an assistant message, optionally carrying tool calls.
     *
     * @param string $content Assistant text (may be empty when only calling tools).
     * @param array $toolcalls Tool calls as [['id','name','arguments'], ...].
     * @return void
     */
    public function add_assistant_message(string $content, array $toolcalls = []): void {
        $message = ['role' => 'assistant', 'content' => $content];
        if (!empty($toolcalls)) {
            $message['toolcalls'] = $toolcalls;
        }
        $this->messages[] = $message;
    }

    /**
     * Append a tool result message.
     *
     * @param string $toolcallid Id of the tool call being answered.
     * @param string $name Tool name.
     * @param string $content Serialized tool output.
     * @return void
     */
    public function add_tool_result(string $toolcallid, string $name, string $content): void {
        $this->messages[] = [
            'role' => 'tool',
            'toolcallid' => $toolcallid,
            'name' => $name,
            'content' => $content,
        ];
    }
}
