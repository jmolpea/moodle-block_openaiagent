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
 * Guards the key routing anchors in the default router prompt.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent;

use block_openaiagent\local\defaults;

/**
 * Unit tests for the default router prompt content.
 *
 * @covers \block_openaiagent\local\defaults
 */
final class router_prompt_test extends \basic_testcase {
    /**
     * The exact regression case ("what grade do I have?") is pinned as an
     * "assistant" example so live-data questions stop landing on the tutor.
     */
    public function test_grade_question_anchored_to_assistant(): void {
        $prompt = defaults::ROUTER_PROMPT;

        $this->assertStringContainsString('¿Qué nota tengo en el curso?', $prompt);
        $this->assertStringContainsString('"intent":"assistant"', $prompt);
        $this->assertStringContainsString('Decisive tie-breaker', $prompt);
    }

    /**
     * The "assistant" route is defined before "tutor" so a small model no longer
     * anchors on the tutor option first, and both routes are present.
     */
    public function test_assistant_route_is_not_subordinate_to_tutor(): void {
        $prompt = defaults::ROUTER_PROMPT;

        $assistantpos = strpos($prompt, '- "assistant":');
        $tutorpos = strpos($prompt, '- "tutor":');

        $this->assertNotFalse($assistantpos);
        $this->assertNotFalse($tutorpos);
        $this->assertLessThan($tutorpos, $assistantpos);
    }

    /**
     * Exam/quiz/assessment content is still kept on the tutor (which carries the
     * evaluation-block safeguard), never routed to the assistant.
     */
    public function test_assessment_content_stays_on_tutor(): void {
        $prompt = defaults::ROUTER_PROMPT;

        $this->assertStringContainsString('exam/quiz/assessment content', $prompt);
        $this->assertStringContainsString('never to "assistant"', $prompt);
    }

    /**
     * Questions about where a topic lives inside the course documents/guide are
     * routed to the tutor, with a worked example, so they are not mistaken for
     * platform navigation.
     */
    public function test_document_location_questions_go_to_tutor(): void {
        $prompt = defaults::ROUTER_PROMPT;

        $this->assertStringContainsString('COURSE STUDY MATERIAL', $prompt);
        $this->assertStringContainsString('¿Dónde se explica el cronograma en la guía?', $prompt);
        // The cronograma location example must resolve to the tutor.
        $line = strstr($prompt, '¿Dónde se explica el cronograma en la guía?');
        $line = $line !== false ? strtok($line, "\n") : '';
        $this->assertStringContainsString('"intent":"tutor"', $line);
    }

    /**
     * The unconditional '"ok" -> ambiguous' example is gone. It contradicted the
     * runtime instruction to reuse the previous route for a contentless follow-up,
     * and a small model follows the worked example: plain acceptances ("sí, por
     * favor", "yes please") were classified ambiguous and answered with another
     * question instead of the offer the user had just accepted.
     */
    public function test_contentless_followup_inherits_previous_route(): void {
        $prompt = defaults::ROUTER_PROMPT;

        $this->assertStringNotContainsString(
            '"ok" -> {"intent":"ambiguous"',
            $prompt,
            'The unconditional "ok" -> ambiguous example must not come back.'
        );
        // Both branches must be shown, conditioned on whether a route is supplied.
        $this->assertStringContainsString('"ok" (no previous route) -> {"intent":"ambiguous"', $prompt);
        $this->assertStringContainsString('"yes please" (previous route "assistant") -> {"intent":"assistant"', $prompt);
        $this->assertStringContainsString('reuse the previous route', $prompt);
    }

    /**
     * A bare activity reference is content, not ambiguity: "actividad 2.5 y 2.6"
     * spent a user turn on a clarifying question in production.
     */
    public function test_activity_fragment_is_not_ambiguous(): void {
        $prompt = defaults::ROUTER_PROMPT;

        $line = strstr($prompt, '"actividad 2.5 y 2.6"');
        $line = $line !== false ? strtok($line, "\n") : '';
        $this->assertStringContainsString('"intent":"assistant"', $line);
        $this->assertStringContainsString('naming a course object IS content', $prompt);
    }

    /**
     * The ambiguity agent has no documents, no tools and no user data, so its
     * prompt must forbid saying anything about the course. Without that it started
     * citing invented sections and chapters of the participant guide, and emitting
     * the tutor's out-of-scope fallback at users who had asked a valid question.
     */
    public function test_ambiguity_prompt_forbids_answering(): void {
        $prompt = defaults::AMBIGUITY_PROMPT;

        $this->assertStringContainsString('never answer', strtolower($prompt));
        $this->assertStringContainsString('no course documents, no tools', $prompt);
        $this->assertStringContainsString('Never name or cite a document', $prompt);
        // Matched on the current wording of the rule, not on a paraphrase of it:
        // the assertion drifted away from the prompt and stopped meaning anything.
        $this->assertStringContainsString(
            "never deliver the course's configured fallback",
            strtolower($prompt)
        );
    }
}
