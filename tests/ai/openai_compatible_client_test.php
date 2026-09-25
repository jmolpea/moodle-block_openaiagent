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
 * Tests for the OpenAI-compatible Chat Completions adapter.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\ai;

/**
 * Unit tests for model-capability handling.
 *
 * @covers \block_openaiagent\ai\openai_compatible_client
 */
final class openai_compatible_client_test extends \advanced_testcase {
    /**
     * Call the private capability check.
     *
     * @param string $model Model id.
     * @return bool
     */
    private function rejects(string $model): bool {
        $method = new \ReflectionMethod(openai_compatible_client::class, 'rejects_tools_with_reasoning');
        $method->setAccessible(true);
        return (bool)$method->invoke(null, $model);
    }

    /**
     * Only the models that actually refuse the combination are flagged.
     *
     * gpt-5.6 and gpt-6 reject function tools alongside a reasoning effort on
     * /v1/chat/completions: "To use function tools, use /v1/responses or set
     * reasoning_effort to 'none'". The assistant is the only route that sends
     * tools, so getting this wrong breaks every assistant turn with a 400 while
     * the tutor carries on working -- and getting it wrong the other way would
     * silently switch reasoning off on models that do support the combination.
     */
    public function test_only_gpt_5_6_and_gpt_6_reject_tools_with_reasoning(): void {
        $this->assertTrue($this->rejects('gpt-5.6-luna'));
        $this->assertTrue($this->rejects('GPT-5.6-LUNA'));
        $this->assertTrue($this->rejects('gpt-6-luna'));
        $this->assertTrue($this->rejects('gpt-6-sol'));

        $this->assertFalse($this->rejects('gpt-5-mini'));
        $this->assertFalse($this->rejects('gpt-5'));
        $this->assertFalse($this->rejects('gpt-5-nano'));
        $this->assertFalse($this->rejects('gpt-4.1-mini'));
        $this->assertFalse($this->rejects('o4-mini'));
    }

    /**
     * Call the private DeepSeek thinking-mode builder.
     *
     * @param request $request Neutral request.
     * @return array
     */
    private function deepseek_thinking(request $request): array {
        $method = new \ReflectionMethod(openai_compatible_client::class, 'deepseek_thinking');
        $method->setAccessible(true);
        return (array)$method->invoke(null, $request);
    }

    /**
     * A DeepSeek request that carries tools never has thinking on.
     *
     * With thinking on, DeepSeek answers 400 to a tools request that does not
     * resend every earlier reasoning_content, which the tool loop does not keep.
     * The site effort must not switch it back on.
     */
    public function test_deepseek_disables_thinking_with_tools(): void {
        $request = new request();
        $request->tools = [['name' => 'moodle__get_context', 'description' => 'x', 'parameters' => []]];
        $request->reasoningeffort = 'high';

        $this->assertSame(['thinking' => ['type' => 'disabled']], $this->deepseek_thinking($request));
    }

    /**
     * The router, the only JSON-mode caller, runs DeepSeek without thinking.
     */
    public function test_deepseek_disables_thinking_in_json_mode(): void {
        $request = new request();
        $request->jsonmode = true;
        $request->reasoningeffort = 'low';

        $this->assertSame(['thinking' => ['type' => 'disabled']], $this->deepseek_thinking($request));
    }

    /**
     * Without tools, the site effort passes through, and an empty setting sends nothing.
     */
    public function test_deepseek_passes_effort_through_without_tools(): void {
        $request = new request();
        $request->reasoningeffort = 'medium';
        $this->assertSame(['reasoning_effort' => 'medium'], $this->deepseek_thinking($request));

        $request->reasoningeffort = '';
        $this->assertSame([], $this->deepseek_thinking($request));
    }
}
