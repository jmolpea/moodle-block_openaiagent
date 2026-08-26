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
     * gpt-5.6 rejects function tools alongside a reasoning effort on
     * /v1/chat/completions: "To use function tools, use /v1/responses or set
     * reasoning_effort to 'none'". The assistant is the only route that sends
     * tools, so getting this wrong breaks every assistant turn with a 400 while
     * the tutor carries on working -- and getting it wrong the other way would
     * silently switch reasoning off on models that do support the combination.
     */
    public function test_only_gpt_5_6_rejects_tools_with_reasoning(): void {
        $this->assertTrue($this->rejects('gpt-5.6-luna'));
        $this->assertTrue($this->rejects('GPT-5.6-LUNA'));

        $this->assertFalse($this->rejects('gpt-5-mini'));
        $this->assertFalse($this->rejects('gpt-5'));
        $this->assertFalse($this->rejects('gpt-5-nano'));
        $this->assertFalse($this->rejects('gpt-4.1-mini'));
        $this->assertFalse($this->rejects('o4-mini'));
    }
}
