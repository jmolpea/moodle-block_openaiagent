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
 * Tests for the conditional retrieval-query rewriter.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for the rewrite triggers and the rewrite call plumbing.
 *
 * @covers \block_openaiagent\local\query_rewriter
 */
final class query_rewriter_test extends \advanced_testcase {
    /**
     * Load the fake client fixture.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        global $CFG;
        require_once($CFG->dirroot . '/blocks/openaiagent/tests/fixtures/fake_client.php');
    }

    /**
     * Vague messages (very short, pronoun-based or without identifiable terms)
     * trigger rewriting; substantive questions do not.
     */
    public function test_is_vague(): void {
        $this->assertTrue(query_rewriter::is_vague('¿y eso?'));
        $this->assertTrue(query_rewriter::is_vague('¿Para qué sirve?'));
        $this->assertTrue(query_rewriter::is_vague('¿Para qué sirve eso en un proyecto?'));
        $this->assertTrue(query_rewriter::is_vague('a b c d')); // No 3+ char terms.

        $this->assertFalse(query_rewriter::is_vague(
            '¿Qué diferencia hay entre la matriz de riesgos y la matriz de interesados del proyecto?'
        ));
        $this->assertFalse(query_rewriter::is_vague(''));
    }

    /**
     * Weak retrieval (nothing found, low top score, or query terms absent from
     * every result) triggers rewriting; a solid result set does not.
     */
    public function test_is_weak_retrieval(): void {
        $query = 'metodología PM4R del BID';

        // Nothing retrieved.
        $this->assertTrue(query_rewriter::is_weak_retrieval(
            ['chunks' => [], 'semantic' => true, 'topscore' => 0.0, 'coverage' => 0.0],
            $query
        ));

        // Semantic top score below the threshold.
        $this->assertTrue(query_rewriter::is_weak_retrieval(
            ['chunks' => [['content' => 'x']], 'semantic' => true, 'topscore' => 0.20, 'coverage' => 1.0],
            $query
        ));

        // Good score but no main query term in any chunk: results are off-topic.
        $this->assertTrue(query_rewriter::is_weak_retrieval(
            ['chunks' => [['content' => 'x']], 'semantic' => true, 'topscore' => 0.60, 'coverage' => 0.0],
            $query
        ));

        // Solid semantic retrieval.
        $this->assertFalse(query_rewriter::is_weak_retrieval(
            ['chunks' => [['content' => 'x']], 'semantic' => true, 'topscore' => 0.55, 'coverage' => 0.5],
            $query
        ));

        // Lexical mode uses its own scale.
        $this->assertTrue(query_rewriter::is_weak_retrieval(
            ['chunks' => [['content' => 'x']], 'semantic' => false, 'topscore' => 3.0, 'coverage' => 1.0],
            $query
        ));
        $this->assertFalse(query_rewriter::is_weak_retrieval(
            ['chunks' => [['content' => 'x']], 'semantic' => false, 'topscore' => 20.0, 'coverage' => 1.0],
            $query
        ));
    }

    /**
     * The rewriter keeps only the first line of the model output, strips
     * wrapping quotes and returns '' on failure.
     */
    public function test_rewrite_uses_first_line(): void {
        $this->resetAfterTest();

        $fake = new \block_openaiagent\fake_client();
        $fake->agenttext = "\"PM4R Project Management for Results BID metodología\"\nSegunda línea ignorada.";
        $conversation = (object)['id' => 0];

        $rewritten = query_rewriter::rewrite($fake, '¿qué es eso?', $conversation);

        $this->assertSame('PM4R Project Management for Results BID metodología', $rewritten);
        $this->assertCount(1, $fake->requests);
        $this->assertSame('minimal', $fake->requests[0]->reasoningeffort);

        $fake->failagent = true;
        $this->assertSame('', query_rewriter::rewrite($fake, '¿qué es eso?', $conversation));
    }

    /**
     * The rewriter model is the configured override when the provider owns it,
     * else a cheap per-provider default.
     */
    public function test_model_resolution(): void {
        $this->resetAfterTest();
        $fake = new \block_openaiagent\fake_client();

        // Default provider is openai: cheap default is gpt-5-nano.
        $this->assertSame('gpt-5-nano', query_rewriter::model($fake));

        // A configured override wins (the fake owns every model id).
        set_config('query_rewrite_model', 'my-custom-model', 'block_openaiagent');
        $this->assertSame('my-custom-model', query_rewriter::model($fake));
    }
}
