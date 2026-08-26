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
 * Tests for the router intent interpreter.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent;

/**
 * Unit tests for orchestrator intent interpretation.
 *
 * @covers \block_openaiagent\orchestrator::interpret_intent
 */
final class interpret_intent_test extends \basic_testcase {
    /**
     * A confident tutor intent is honoured.
     */
    public function test_confident_tutor(): void {
        $json = '{"intent":"tutor","confidence":0.9,"reason":"concept","needs_clarification":false}';
        $this->assertSame('tutor', orchestrator::interpret_intent($json));
    }

    /**
     * A confident assistant intent is honoured.
     */
    public function test_confident_assistant(): void {
        $json = '{"intent":"assistant","confidence":0.88,"needs_clarification":false}';
        $this->assertSame('assistant', orchestrator::interpret_intent($json));
    }

    /**
     * Low confidence collapses any non-ambiguous intent to ambiguous.
     */
    public function test_low_confidence_becomes_ambiguous(): void {
        $json = '{"intent":"tutor","confidence":0.4,"needs_clarification":false}';
        $this->assertSame('ambiguous', orchestrator::interpret_intent($json));
    }

    /**
     * needs_clarification with sub-0.8 confidence collapses to ambiguous.
     */
    public function test_needs_clarification_becomes_ambiguous(): void {
        $json = '{"intent":"assistant","confidence":0.7,"needs_clarification":true}';
        $this->assertSame('ambiguous', orchestrator::interpret_intent($json));
    }

    /**
     * A minimal, intent-only payload (no confidence field, as produced by an
     * Agent Builder style prompt) is trusted and routes directly.
     */
    public function test_missing_confidence_is_trusted(): void {
        $this->assertSame('assistant', orchestrator::interpret_intent('{"intent":"assistant"}'));
        $this->assertSame('tutor', orchestrator::interpret_intent('{"intent":"tutor"}'));
        $this->assertSame('assistant', orchestrator::interpret_intent('{"intent":"asistente"}'));
    }

    /**
     * Invalid JSON is treated as ambiguous (fail safe).
     */
    public function test_invalid_json_is_ambiguous(): void {
        $this->assertSame('ambiguous', orchestrator::interpret_intent('not json at all'));
        $this->assertSame('ambiguous', orchestrator::interpret_intent(''));
        $this->assertSame('ambiguous', orchestrator::interpret_intent('{"foo":"bar"}'));
    }

    /**
     * An unknown intent label is treated as ambiguous.
     */
    public function test_unknown_intent_is_ambiguous(): void {
        $json = '{"intent":"banana","confidence":0.99}';
        $this->assertSame('ambiguous', orchestrator::interpret_intent($json));
    }

    /**
     * Legacy Spanish intent labels are mapped to the canonical English ones.
     */
    public function test_legacy_spanish_mapping(): void {
        $this->assertSame(
            'assistant',
            orchestrator::interpret_intent('{"intent":"asistente","confidence":0.9}')
        );
        $this->assertSame(
            'ambiguous',
            orchestrator::interpret_intent('{"intent":"ambigua","confidence":0.9}')
        );
        $this->assertSame(
            'ambiguous',
            orchestrator::interpret_intent('{"intent":"ambiguo","confidence":0.9}')
        );
    }

    /**
     * Whitespace and casing around the intent are tolerated.
     */
    public function test_whitespace_and_case(): void {
        $json = "  {\"intent\":\"  TUTOR \",\"confidence\":0.9}  ";
        $this->assertSame('tutor', orchestrator::interpret_intent($json));
    }
}
