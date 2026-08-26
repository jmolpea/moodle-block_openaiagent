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
 * Tests for the local knowledge-base retrieval helpers.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for chunking, retrieval and similarity scoring.
 *
 * @covers \block_openaiagent\local\rag
 */
final class rag_test extends \advanced_testcase {
    /**
     * Short texts come back as a single chunk.
     */
    public function test_chunk_text_short_text_single_chunk(): void {
        $chunks = rag::chunk_text('A short paragraph.');
        $this->assertSame(['A short paragraph.'], $chunks);
    }

    /**
     * Empty input produces no chunks.
     */
    public function test_chunk_text_empty(): void {
        $this->assertSame([], rag::chunk_text("  \n \n "));
    }

    /**
     * Long texts split into multiple chunks that jointly retain the content.
     */
    public function test_chunk_text_long_text_splits(): void {
        $paragraphs = [];
        for ($i = 0; $i < 20; $i++) {
            $paragraphs[] = str_repeat("Paragraph {$i} sentence. ", 30);
        }
        $text = implode("\n\n", $paragraphs);

        $chunks = rag::chunk_text($text);

        $this->assertGreaterThan(1, count($chunks));
        $joined = implode(' ', $chunks);
        $this->assertStringContainsString('Paragraph 0 sentence.', $joined);
        $this->assertStringContainsString('Paragraph 19 sentence.', $joined);
    }

    /**
     * Chunks created after a document heading carry a section breadcrumb so
     * the tutor can cite the real section instead of inventing one.
     */
    public function test_chunk_text_adds_section_breadcrumbs(): void {
        $heading = 'Unidad 3. Paso III: La curva de uso de recursos';
        $body = [];
        for ($i = 0; $i < 10; $i++) {
            $body[] = str_repeat("Contenido sobre la curva de recursos del proyecto {$i}. ", 20);
        }
        $text = "Introducción general del documento.\n\n" . $heading . "\n\n" . implode("\n\n", $body);

        $chunks = rag::chunk_text($text);

        $this->assertGreaterThan(1, count($chunks));
        $breadcrumbed = array_filter($chunks, static function (string $chunk) use ($heading): bool {
            return strpos($chunk, '[Section: ' . $heading . ']') === 0;
        });
        $this->assertNotEmpty($breadcrumbed);
    }

    /**
     * Page-structured chunking carries a hierarchical heading path and the
     * starting page number into the breadcrumb.
     */
    public function test_chunk_pages_carries_path_and_page(): void {
        $filler = str_repeat('Contenido detallado sobre la gestion del proyecto. ', 60);
        $pages = [
            "Introduccion general.\n\n" . $filler,
            "Unidad 3\n\nPaso III: La curva de uso de recursos\n\nIII.1 La curva S\n\n" . $filler,
        ];

        $chunks = rag::chunk_pages($pages);

        $matching = array_filter($chunks, static function (string $chunk): bool {
            return strpos($chunk, '[Section:') === 0
                && strpos($chunk, 'Unidad 3') !== false
                && strpos($chunk, 'Paso III') !== false
                && strpos($chunk, 'p. 2') !== false;
        });
        $this->assertNotEmpty($matching, 'A page-2 chunk should cite the Unidad 3 / Paso III path and page.');
    }

    /**
     * Ordinary body sentences never become breadcrumbs.
     */
    public function test_chunk_text_no_breadcrumb_without_headings(): void {
        $paragraphs = [];
        for ($i = 0; $i < 20; $i++) {
            $paragraphs[] = str_repeat("Plain body sentence number {$i}. ", 30);
        }

        foreach (rag::chunk_text(implode("\n\n", $paragraphs)) as $chunk) {
            $this->assertStringNotContainsString('[Section:', $chunk);
        }
    }

    /**
     * Cosine similarity behaves on identical, orthogonal and invalid vectors.
     */
    public function test_cosine(): void {
        $this->assertEqualsWithDelta(1.0, rag::cosine([1.0, 2.0], [1.0, 2.0]), 0.000001);
        $this->assertEqualsWithDelta(0.0, rag::cosine([1.0, 0.0], [0.0, 1.0]), 0.000001);
        $this->assertSame(0.0, rag::cosine([], []));
        $this->assertSame(0.0, rag::cosine([1.0], [1.0, 2.0]));
    }

    /**
     * Lexical retrieval ranks the chunk matching the query terms first and
     * carries the citable flag through.
     */
    public function test_retrieve_lexical_ranks_matching_chunk(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('embeddings_provider', 'none', 'block_openaiagent');

        $course = $this->getDataGenerator()->create_course();
        $now = time();
        $rows = [
            ['Photosynthesis converts light energy into chemical energy.', 1],
            ['The French Revolution began in 1789.', 0],
        ];
        foreach ($rows as $index => [$content, $citable]) {
            $DB->insert_record(rag::TABLE, (object) [
                'courseid' => $course->id,
                'contenthash' => sha1($content),
                'filename' => 'doc' . $index . '.pdf',
                'citable' => $citable,
                'chunkindex' => 0,
                'content' => $content,
                'embedding' => null,
                'embeddingmodel' => '',
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $results = rag::retrieve($course->id, 'How does photosynthesis work?', 5);

        $this->assertNotEmpty($results);
        $this->assertStringContainsString('Photosynthesis', $results[0]['content']);
        $this->assertTrue($results[0]['citable']);

        // A query matching nothing returns nothing (zero scores are dropped).
        $this->assertSame([], rag::retrieve($course->id, 'quantum entanglement', 5));

        // The diagnosed variant reports scoring mode, top score and term coverage.
        $diagnosed = rag::retrieve_diagnosed($course->id, 'How does photosynthesis work?', 5);
        $this->assertFalse($diagnosed['semantic']);
        $this->assertGreaterThan(0.0, $diagnosed['topscore']);
        $this->assertGreaterThan(0.0, $diagnosed['coverage']);

        $empty = rag::retrieve_diagnosed($course->id, 'quantum entanglement', 5);
        $this->assertSame([], $empty['chunks']);
        $this->assertSame(0.0, $empty['topscore']);
        $this->assertSame(0.0, $empty['coverage']);
    }

    /**
     * A chunk whose section breadcrumb literally contains the query term ranks
     * above a chunk that merely mentions the term in its body, so location
     * questions surface the section actually titled with the term.
     */
    public function test_retrieve_boosts_heading_match(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('embeddings_provider', 'none', 'block_openaiagent');

        $course = $this->getDataGenerator()->create_course();
        $now = time();
        $rows = [
            // Titled section: term appears in the breadcrumb + once in the body.
            "[Section: Unidad 2 › Paso II › II.1 El cronograma del proyecto | p. 30]\nDefinición del cronograma.",
            // Passing mentions only, no breadcrumb.
            'El cronograma se menciona aquí. Cronograma otra vez. Cronograma una tercera vez.',
        ];
        foreach ($rows as $index => $content) {
            $DB->insert_record(rag::TABLE, (object) [
                'courseid' => $course->id,
                'contenthash' => sha1($content),
                'filename' => 'guia.pdf',
                'citable' => 1,
                'chunkindex' => $index,
                'content' => $content,
                'embedding' => null,
                'embeddingmodel' => '',
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $results = rag::retrieve($course->id, '¿Dónde se explica el cronograma en la guía?', 5);

        $this->assertNotEmpty($results);
        $this->assertStringContainsString('II.1 El cronograma del proyecto', $results[0]['content']);
    }

    /**
     * Retrieval is scoped to a block instance: a chunk indexed for one assistant
     * is never returned for another assistant in the same course.
     */
    public function test_retrieve_is_isolated_per_block(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('embeddings_provider', 'none', 'block_openaiagent');

        $course = $this->getDataGenerator()->create_course();
        $now = time();
        $rows = [
            // Block 101's knowledge base.
            [101, 'Photosynthesis converts light energy into chemical energy.'],
            // Block 202's knowledge base (different content, same course).
            [202, 'The French Revolution began in 1789.'],
        ];
        foreach ($rows as $index => [$blockid, $content]) {
            $DB->insert_record(rag::TABLE, (object) [
                'courseid' => $course->id,
                'blockinstanceid' => $blockid,
                'contenthash' => sha1($content),
                'filename' => 'doc' . $index . '.pdf',
                'citable' => 1,
                'chunkindex' => 0,
                'content' => $content,
                'embedding' => null,
                'embeddingmodel' => '',
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        // Block 101 only sees its own chunk.
        $a = rag::retrieve($course->id, 'How does photosynthesis work?', 5, 101);
        $this->assertCount(1, $a);
        $this->assertStringContainsString('Photosynthesis', $a[0]['content']);

        // The same query returns nothing for block 202 (its KB is about history).
        $this->assertSame([], rag::retrieve($course->id, 'How does photosynthesis work?', 5, 202));

        // The legacy course-wide profile (0) sees neither block's chunks.
        $this->assertSame([], rag::retrieve($course->id, 'How does photosynthesis work?', 5, 0));
    }

    /**
     * The formatted context labels citable and internal excerpts differently.
     */
    public function test_format_context_labels(): void {
        $context = rag::format_context([
            ['filename' => 'a.pdf', 'citable' => true, 'content' => 'Citable text.'],
            ['filename' => 'b.pdf', 'citable' => false, 'content' => 'Internal text.'],
        ]);

        $this->assertStringContainsString('[citable] From "a.pdf"', $context);
        $this->assertStringContainsString('[internal] From "b.pdf"', $context);
        // The anti-hallucination rule for locations must be present.
        $this->assertStringContainsString('Never invent', $context);
        $this->assertSame('', rag::format_context([]));
    }

    /**
     * The context block must forbid reproducing the breadcrumb marker, not order
     * the model to quote it verbatim.
     *
     * The old wording ("quote the unit, section and page EXACTLY as they appear
     * in that marker") was the last instruction in the system message, after the
     * per-course prompt, so a model that follows instructions literally copied
     * "[Section: ... | p. N]" straight into the answer.
     */
    public function test_format_context_forbids_reproducing_the_marker(): void {
        $context = rag::format_context([
            ['filename' => 'a.pdf', 'citable' => true, 'content' => 'Citable text.'],
        ]);

        $this->assertStringContainsString('NEVER reproduce', $context);
        $this->assertStringNotContainsString('EXACTLY as they appear in', $context);
    }

    /**
     * Dash-joined names survive tokenization as one compound term.
     *
     * Every part of "Si-No-Si" is below the three-character floor, so before the
     * compound term existed the name of the tool contributed nothing at all to
     * the lexical score and only the embedding could find it.
     */
    public function test_tokenize_keeps_dash_joined_compounds(): void {
        $terms = rag::tokenize('¿Qué es la técnica Sí-No-Sí?');

        $this->assertContains('sí-no-sí', $terms);
        $this->assertContains('técnica', $terms);
    }

    /**
     * The parts of a compound are still emitted on their own when long enough,
     * so nothing that used to match stops matching.
     */
    public function test_tokenize_keeps_compound_parts_too(): void {
        $terms = rag::tokenize('la técnica Paro-Pienso-Procedo');

        $this->assertContains('paro-pienso-procedo', $terms);
        $this->assertContains('paro', $terms);
        $this->assertContains('pienso', $terms);
        $this->assertContains('procedo', $terms);
    }

    /**
     * A query with no compound produces exactly the terms it always did.
     */
    public function test_tokenize_without_compounds_is_unchanged(): void {
        $this->assertSame(
            ['cómo', 'aplica', 'técnica', 'del', 'feedback', 'sándwich'],
            rag::tokenize('¿Cómo se aplica la técnica del feedback sándwich?')
        );
    }

    /**
     * Separator normalization makes one compound term match every way a document
     * writes it: en dashes with spaces, plain hyphens, and a hyphen broken across
     * a line by the PDF extractor.
     */
    public function test_normalize_separators_matches_document_spellings(): void {
        $term = 'sí-no-sí';
        $this->assertStringContainsString($term, rag::normalize_separators('15. sí – no – sí'));
        $this->assertStringContainsString($term, rag::normalize_separators('la técnica sí-no-sí'));

        $ciclo = 'yo-contexto-equipo';
        $this->assertStringContainsString($ciclo, rag::normalize_separators('ciclo yo- contexto-equipo'));
    }

    /**
     * Only compound terms need the normalized haystack; a plain word is not one.
     */
    public function test_is_compound(): void {
        $this->assertTrue(rag::is_compound('sí-no-sí'));
        $this->assertFalse(rag::is_compound('sándwich'));
    }

    /**
     * When the question names a tool, the focused pass is that name alone.
     *
     * Keeping the padding alongside the name reproduced the failure it was
     * written to fix: the padding still dominated the query vector. Asked in
     * four words the tutor finds the tool's page at once, so the name on its own
     * IS the query that works.
     */
    public function test_focus_query_is_the_tool_name_alone(): void {
        $focus = rag::focus_query(
            'Necesito un resumen ejecutivo, no más de un párrafo, sobre qué es la '
                . 'técnica Sí-No-Sí. Es para una nota interna al coordinador.'
        );

        $this->assertSame('sí-no-sí', $focus);
    }

    /**
     * Acronyms count as tool names, whatever their length.
     *
     * The first version kept only terms of six characters or more, which threw
     * away every acronym the course uses: IAR, FODA, CAME, GROW, MAAN, SMART.
     */
    public function test_focus_query_keeps_short_acronyms(): void {
        $this->assertSame(
            'iar',
            rag::focus_query('¿Qué significan exactamente las siglas IAR según el glosario?')
        );
        $this->assertSame(
            'foda',
            rag::focus_query('En mi análisis FODA me salieron muchísimas debilidades, ¿qué hago?')
        );
    }

    /**
     * With no tool named, the focused pass falls back to the long words.
     */
    public function test_focus_query_without_a_tool_name(): void {
        $focus = rag::focus_query(
            'Existe alguna diferencia entre la técnica del sándwich y simplemente '
                . 'decir las cosas de forma amable? O es lo mismo?'
        );

        $this->assertStringContainsString('sándwich', $focus);
        $this->assertStringNotContainsString(' del ', ' ' . $focus . ' ');
    }

    /**
     * A capitalized ordinary word is not mistaken for an acronym.
     */
    public function test_name_terms_ignores_ordinary_capitalized_words(): void {
        $this->assertSame([], rag::name_terms('Necesito ayuda con esto por favor'));
        $this->assertSame(['grow'], rag::name_terms('Explícame el método GROW por favor'));
    }

    /**
     * A question that is already focused gets no second pass.
     */
    public function test_focus_query_adds_nothing_to_a_short_question(): void {
        $this->assertSame('', rag::focus_query('¿Qué es la rueda?'));
    }

    /**
     * Merging keeps the best of every pass and never repeats an excerpt.
     */
    public function test_merge_chunks_is_round_robin_and_deduplicated(): void {
        $first = [];
        foreach (['A1', 'A2', 'A3', 'A4'] as $body) {
            $first[] = ['filename' => 'g.pdf', 'citable' => true, 'content' => $body];
        }
        $second = [];
        foreach (['B1', 'A2', 'B3', 'B4'] as $body) {
            $second[] = ['filename' => 'g.pdf', 'citable' => true, 'content' => $body];
        }

        $merged = rag::merge_chunks([$first, $second], 6);

        $this->assertSame(['A1', 'B1', 'A2', 'A3', 'B3', 'A4'], array_column($merged, 'content'));
        $this->assertCount(6, $merged);
    }

    /**
     * The merge never returns more excerpts than the caller asked for, so the
     * prompt the tutor receives does not grow.
     */
    public function test_merge_chunks_respects_the_limit(): void {
        $set = [];
        foreach (range(1, 10) as $i) {
            $set[] = ['filename' => 'g.pdf', 'citable' => true, 'content' => 'chunk' . $i];
        }

        $this->assertCount(4, rag::merge_chunks([$set, $set], 4));
    }
}
