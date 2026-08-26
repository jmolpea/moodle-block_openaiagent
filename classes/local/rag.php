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
 * Local retrieval over the course knowledge base.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

use block_openaiagent\ai\embeddings;

/**
 * Chunks course documents and retrieves the most relevant fragments.
 *
 * Retrieval is semantic (cosine similarity over stored embeddings) when an
 * embeddings provider is available, with a lexical keyword fallback so the
 * tutor keeps working with any chat provider and no embeddings key.
 */
class rag {
    /** @var string Chunks table. */
    public const TABLE = 'block_openaiagent_chunks';

    /**
     * @var string Chunker output version. BUMP THIS whenever chunk_pages() or
     * anything it calls changes what the stored chunks contain -- boundaries,
     * breadcrumbs, denoising or the extra chunks emitted. Indexed documents are
     * keyed by contenthash and never re-chunked otherwise, so without a bump the
     * change silently applies only to courses indexed from scratch afterwards.
     * See {@see tutordocs::sync_course}, which rebuilds a course on mismatch.
     */
    public const CHUNKER_VERSION = '2026080501-figurerefs';

    /** @var int Target chunk size in characters. */
    private const CHUNK_CHARS = 2500;

    /** @var int Overlap between consecutive chunks in characters. */
    private const CHUNK_OVERLAP = 250;

    /** @var int Safety cap on chunks loaded per course at query time. */
    private const MAX_COURSE_CHUNKS = 2000;

    /** @var float Weight of the normalized lexical component in hybrid scores. */
    private const LEXICAL_WEIGHT = 0.25;

    /**
     * Split extracted text into overlapping chunks on paragraph boundaries.
     *
     * Each chunk is prefixed with a "[Section: ...]" breadcrumb naming the full
     * heading path in effect where it starts (for example
     * "Unidad 3 › Paso III › III.1 La curva de uso de recursos"), so the tutor
     * can point users to the real unit/section instead of guessing one.
     *
     * @param string $text Normalized document text (no page structure).
     * @return string[] Chunks in document order.
     */
    public static function chunk_text(string $text): array {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        return self::build_chunks(self::denoise_pages([$text]), false);
    }

    /**
     * Split a page-structured document into breadcrumb-tagged chunks.
     *
     * Heading context is tracked across page boundaries, and every chunk records
     * the page it starts on so the tutor can cite "p. N" like the previous
     * vector-store integration did.
     *
     * @param string[] $pages Page texts in order (page number = index + 1).
     * @return string[] Chunks in document order.
     */
    public static function chunk_pages(array $pages): array {
        $pages = array_values(array_filter(array_map('strval', $pages), static fn($p) => trim($p) !== ''));
        if (empty($pages)) {
            return [];
        }
        $withpages = count($pages) > 1;
        return self::build_chunks(self::denoise_pages($pages), $withpages);
    }

    /**
     * Strip low-value noise from document pages before chunking.
     *
     * Removes table-of-contents entries (dot-leader lines), leading page-number
     * artifacts ("Página 12", "Page 3") and boilerplate lines that repeat on most
     * pages (copyright notices, running headers/footers). This keeps chunk text
     * and its "[Section: ...]" breadcrumb focused on real content, which improves
     * both retrieval precision and the citations the tutor can make. Blank lines
     * are preserved so paragraph boundaries survive.
     *
     * @param string[] $pages Page texts in order.
     * @return string[] Cleaned page texts, same count and order.
     */
    private static function denoise_pages(array $pages): array {
        // 1. Per-page cleanup: drop TOC lines and leading page-number tokens.
        $cleaned = [];
        foreach ($pages as $page) {
            $out = [];
            foreach (preg_split("/\n/u", (string)$page) ?: [] as $line) {
                $t = trim($line);
                if ($t !== '' && preg_match('/\.{4,}/u', $t)) {
                    // Table-of-contents entry ("Title ........ 42"): drop entirely.
                    continue;
                }
                $t = preg_replace('/^(?:p[aá]gina|page|p[aá]g\.?)\s*\d+\b\s*/iu', '', $t);
                $out[] = $t;
            }
            $cleaned[] = implode("\n", $out);
        }

        // 2. Cross-page boilerplate: remove substantial lines present on most
        // pages (only meaningful for multi-page documents).
        if (count($cleaned) >= 4) {
            $freq = [];
            foreach ($cleaned as $page) {
                $seen = [];
                foreach (preg_split("/\n/u", $page) ?: [] as $line) {
                    $norm = \core_text::strtolower(trim($line));
                    if (\core_text::strlen($norm) < 25 || isset($seen[$norm])) {
                        continue;
                    }
                    $seen[$norm] = true;
                    $freq[$norm] = ($freq[$norm] ?? 0) + 1;
                }
            }
            $threshold = (int)ceil(count($cleaned) * 0.6);
            $boilerplate = [];
            foreach ($freq as $norm => $count) {
                if ($count >= $threshold) {
                    $boilerplate[$norm] = true;
                }
            }
            if (!empty($boilerplate)) {
                foreach ($cleaned as $i => $page) {
                    $out = [];
                    foreach (preg_split("/\n/u", $page) ?: [] as $line) {
                        if (isset($boilerplate[\core_text::strtolower(trim($line))])) {
                            continue;
                        }
                        $out[] = $line;
                    }
                    $cleaned[$i] = implode("\n", $out);
                }
            }
        }

        return $cleaned;
    }

    /**
     * Core chunker: accumulates paragraphs into overlapping chunks while
     * maintaining a hierarchical heading path and the starting page number.
     *
     * @param string[] $pages Page texts in order.
     * @param bool $withpages Whether to tag chunks with a page number.
     * @return string[] Chunks in document order.
     */
    private static function build_chunks(array $pages, bool $withpages): array {
        $chunks = [];
        $current = '';
        $stack = [];            // Live heading path: level => heading text.
        $chunkpath = '';        // Heading path where the current chunk started.
        $chunkpage = 0;         // Page where the current chunk started.
        $captions = [];         // Numbered figure/table captions, for locator chunks.

        $flush = static function () use (&$chunks, &$current, &$chunkpath, &$chunkpage, $withpages): void {
            if (trim($current) === '') {
                $current = '';
                return;
            }
            $chunks[] = self::decorate(trim($current), $chunkpath, $withpages ? $chunkpage : 0);
            $current = '';
        };

        foreach ($pages as $pageindex => $pagetext) {
            $pageno = $pageindex + 1;
            $paragraphs = preg_split("/\n{2,}/u", trim($pagetext)) ?: [];

            foreach ($paragraphs as $paragraph) {
                $paragraph = trim($paragraph);
                if ($paragraph === '') {
                    continue;
                }

                // Update the heading path from every line of the paragraph so
                // multi-line headers (e.g. "Unidad 2" then "Paso II: ...") are
                // all captured, not just the first line.
                foreach (preg_split("/\n/u", $paragraph) ?: [] as $line) {
                    $line = trim($line);
                    if ($line !== '' && self::is_heading($line)) {
                        $level = self::heading_level($line);
                        $stack[$level] = $line;
                        foreach (array_keys($stack) as $lvl) {
                            if ($lvl > $level) {
                                unset($stack[$lvl]);
                            }
                        }
                    }
                }
                $path = self::heading_path($stack);

                // Numbered figure/table captions are recorded as their own locator
                // chunks (emitted after the body). A caption is the only trace an
                // image-only diagram leaves in extracted text: its body never
                // reaches the index, so a question about its content matches
                // nothing and the tutor reports the topic as absent from the
                // course. Measured on one client guide: 15 of its cuadros extract
                // as caption + "Fuente:" and nothing else.
                foreach (preg_split("/\n/u", $paragraph) ?: [] as $line) {
                    $caption = self::caption_of(trim($line));
                    if ($caption !== '') {
                        $captions[\core_text::strtolower($caption) . '|' . $pageno] = [
                            'label' => $caption,
                            'path' => $path,
                            'page' => $pageno,
                        ];
                    }
                }

                if ($current === '') {
                    $chunkpath = $path;
                    $chunkpage = $pageno;
                }

                // Hard-split paragraphs longer than a whole chunk.
                while (\core_text::strlen($paragraph) > self::CHUNK_CHARS) {
                    $head = \core_text::substr($paragraph, 0, self::CHUNK_CHARS);
                    $paragraph = \core_text::substr($paragraph, self::CHUNK_CHARS - self::CHUNK_OVERLAP);
                    if ($current !== '') {
                        $flush();
                    }
                    $chunks[] = self::decorate($head, $path, $withpages ? $pageno : 0);
                    $chunkpath = $path;
                    $chunkpage = $pageno;
                }

                $candidate = $current === '' ? $paragraph : $current . "\n\n" . $paragraph;
                if (\core_text::strlen($candidate) > self::CHUNK_CHARS) {
                    $flush();
                    // Start the next chunk with a trailing slice of the previous
                    // one so sentences cut at the boundary stay retrievable.
                    $tail = \core_text::substr(trim($candidate), -self::CHUNK_OVERLAP);
                    $current = trim($tail . "\n\n" . $paragraph);
                    $chunkpath = $path;
                    $chunkpage = $pageno;
                } else {
                    $current = $candidate;
                }
            }
        }

        $flush();

        // One small locator chunk per caption. Small on purpose: its embedding is
        // the caption and nothing else, so it ranks on the exact tool or concept
        // the caption names instead of being diluted inside a full page. The
        // caption also goes into the breadcrumb, which is where the heading-match
        // boost in {@see score_rows} looks.
        foreach ($captions as $caption) {
            $body = '[FIGURE REFERENCE] "' . $caption['label'] . '" is a numbered figure or table '
                . 'of this document, at the location given above. Figures are often images, so '
                . 'their content may not be present as text anywhere in these excerpts. If the '
                . 'participant asks about what this figure contains and no other excerpt carries '
                . 'it, give them this location and say it is a figure to consult in the document '
                . '-- do NOT tell them the course does not cover the topic.';
            $path = $caption['path'] !== ''
                ? $caption['path'] . ' › ' . $caption['label']
                : $caption['label'];
            $chunks[] = self::decorate($body, $path, $withpages ? $caption['page'] : 0);
        }

        return $chunks;
    }

    /**
     * The caption text of a numbered figure/table line, or '' if it is not one.
     *
     * Matches the "Cuadro 25: Tecnica del feedback sandwich" convention that
     * course guides use across languages, requiring the number so ordinary
     * sentences that merely start with the word ("Tabla de contenidos", "Figura
     * central del modelo") are not mistaken for captions.
     *
     * @param string $line Single trimmed line.
     * @return string Caption text ('' when the line is not a caption).
     */
    private static function caption_of(string $line): string {
        $len = \core_text::strlen($line);
        if ($len < 8 || $len > 200) {
            return '';
        }
        // Table-of-contents rows carry dot leaders; they point at the caption but
        // are not it, and indexing them would duplicate every locator.
        if (preg_match('/\.{4,}/u', $line)) {
            return '';
        }
        $pattern = '/^(?:cuadro|figura|tabla|gr[aá]fico|gr[aá]fica|ilustraci[oó]n|imagen|esquema'
            . '|box|figure|table|chart|exhibit|diagram|quadro|figura|imagem)'
            . '\s*(?:n[.\x{00BA}\x{00B0}]?\s*)?\d{1,3}\s*[:.\-\x{2013}\x{2014}]\s*\S/iu';
        if (!preg_match($pattern, $line)) {
            return '';
        }
        // Keep the whole caption, trailing period aside: the number identifies it
        // for the participant and the title carries the retrievable terms.
        return rtrim($line, " .;");
    }

    /**
     * Prefix a chunk with its section/page breadcrumb.
     *
     * @param string $chunk Chunk text.
     * @param string $path Heading path in effect where the chunk starts.
     * @param int $page Page number (0 = unknown / not page-structured).
     * @return string
     */
    private static function decorate(string $chunk, string $path, int $page): string {
        $bits = [];
        if ($path !== '' && strpos($chunk, $path) !== 0) {
            $bits[] = $path;
        }
        if ($page > 0) {
            $bits[] = 'p. ' . $page;
        }
        if (empty($bits)) {
            return $chunk;
        }
        return '[Section: ' . implode(' | ', $bits) . "]\n" . $chunk;
    }

    /**
     * Render the live heading stack as a "A › B › C" path.
     *
     * @param array $stack Level => heading text.
     * @return string
     */
    private static function heading_path(array $stack): string {
        if (empty($stack)) {
            return '';
        }
        ksort($stack);
        return implode(' › ', array_map('trim', $stack));
    }

    /**
     * Classify a heading into a nesting level so the path stays hierarchical.
     *
     * Level 1 = unit/module/topic containers, level 2 = step/chapter/lesson,
     * level 3 = numbered or generic subsections. A missed level only flattens
     * the path slightly.
     *
     * @param string $line Heading line.
     * @return int Level (1-3).
     */
    private static function heading_level(string $line): int {
        $l = \core_text::strtolower($line);
        if (preg_match('/^(unidad|unit|m[oó]dulo|module|tema|parte|part|bloque|unidade)\b/u', $l)) {
            return 1;
        }
        if (preg_match('/^(paso|step|cap[ií]tulo|chapter|lecci[oó]n|lesson|passo)\b/u', $l)) {
            return 2;
        }
        return 3;
    }

    /**
     * Heuristic: does a line look like a document heading?
     *
     * Recognizes keyword-led headings (Unidad/Capítulo/Chapter/...), numbered
     * headings ("3.2 Title") and short all-caps lines. Errs towards false
     * negatives: a missed heading only loses a breadcrumb.
     *
     * @param string $line Single trimmed line.
     * @return bool
     */
    private static function is_heading(string $line): bool {
        $len = \core_text::strlen($line);
        if ($len < 3 || $len > 120) {
            return false;
        }
        // Body sentences end with terminal punctuation; headings rarely do.
        if (preg_match('/[.,;]\s*$/u', $line)) {
            return false;
        }
        // Table-of-contents entries ("Unidad 3. La curva ......... 62") carry dot
        // leaders and a trailing page number; treating them as headings pollutes
        // the breadcrumb path with index text, so never accept them.
        if (preg_match('/\.{4,}/u', $line)) {
            return false;
        }
        $keywords = '/^(unidad|cap[ií]tulo|tema|m[oó]dulo|secci[oó]n|parte|anexo|ap[eé]ndice|paso|lecci[oó]n'
            . '|bloque|unit|chapter|section|module|part|appendix|step|lesson|topic'
            . '|unidade|se[cç][aã]o|passo)\b/iu';
        if (preg_match($keywords, $line)) {
            return true;
        }
        // Numbered headings ("3.", "3.2 Title") followed by a capitalized word.
        if (preg_match('/^\d{1,2}(\.\d{1,2}){0,3}[\.\)]?\s+\p{Lu}/u', $line)) {
            return true;
        }
        // Roman-numeral numbered headings ("II.1 Title", "III.1. Title",
        // "IV. Title") as used by many course guides for steps/subsections.
        $romansub = preg_match('/^[ivxlcdm]{1,6}\.\d{1,2}([\.\)]?)\s+\p{L}/iu', $line);
        $romantop = preg_match('/^[ivxlcdm]{1,6}[\.\)]\s+\p{Lu}/iu', $line);
        if ($romansub || $romantop) {
            return true;
        }
        // Short all-caps lines.
        if ($len <= 80 && preg_match('/\p{Lu}/u', $line) && !preg_match('/\p{Ll}/u', $line)) {
            return true;
        }
        return false;
    }

    /**
     * Retrieve the most relevant chunks for a query in a course.
     *
     * @param int $courseid Course id.
     * @param string $query User question.
     * @param int $limit Maximum chunks to return.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return array[] Each: ['filename', 'citable', 'content'].
     */
    public static function retrieve(int $courseid, string $query, int $limit, int $blockinstanceid = 0): array {
        return self::retrieve_diagnosed($courseid, $query, $limit, $blockinstanceid)['chunks'];
    }

    /**
     * Retrieve the most relevant chunks plus quality diagnostics.
     *
     * The diagnostics let the caller judge whether retrieval was weak (vague
     * query, low similarity, query terms absent from every result) and decide
     * to rewrite the query and retry.
     *
     * @param int $courseid Course id.
     * @param string $query User question.
     * @param int $limit Maximum chunks to return.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return array{chunks: array[], semantic: bool, topscore: float, coverage: float}
     *         'chunks' as in {@see retrieve}; 'semantic' whether embeddings scored;
     *         'topscore' best selected score (0.0 when nothing matched); 'coverage'
     *         fraction of main query terms present in at least one selected chunk.
     */
    public static function retrieve_diagnosed(int $courseid, string $query, int $limit, int $blockinstanceid = 0): array {
        global $DB;

        if ($limit <= 0) {
            $limit = 5;
        }

        $rows = $DB->get_records(
            self::TABLE,
            ['courseid' => $courseid, 'blockinstanceid' => $blockinstanceid],
            'contenthash ASC, chunkindex ASC',
            'id, filename, citable, content, embedding, embeddingmodel',
            0,
            self::MAX_COURSE_CHUNKS
        );
        if (!$rows) {
            return ['chunks' => [], 'semantic' => false, 'topscore' => 0.0, 'coverage' => 0.0];
        }

        [$scores, $semantic, $headinghits] = self::score_rows($rows, $query);

        arsort($scores);
        $ordered = array_keys($scores);

        // Reserve up to half the slots for the best heading matches (ranked by
        // hit count, then score), guaranteeing the titled section is retrieved
        // even when its body score alone would not reach the cut.
        $headingids = array_keys($headinghits);
        usort($headingids, static function ($a, $b) use ($headinghits, $scores): int {
            return [$headinghits[$b], $scores[$b]] <=> [$headinghits[$a], $scores[$a]];
        });

        $reserve = max(1, intdiv($limit, 2));
        $keep = [];
        foreach ($headingids as $id) {
            if (count($keep) >= $reserve) {
                break;
            }
            if ($scores[$id] > 0) {
                $keep[$id] = true;
            }
        }
        foreach ($ordered as $id) {
            if (count($keep) >= $limit) {
                break;
            }
            if ($scores[$id] <= 0) {
                break;
            }
            $keep[$id] = true;
        }

        // Emit the reserved heading matches first (best first) so the tutor sees
        // the titled section at the top of its context, then the rest by score.
        $selected = [];
        $done = [];
        $topscore = 0.0;
        $emit = static function (int $id) use (&$selected, &$done, &$topscore, $rows, $scores): void {
            $row = $rows[$id];
            $selected[] = [
                'filename' => (string)$row->filename,
                'citable' => (int)$row->citable === 1,
                'content' => (string)$row->content,
            ];
            $done[$id] = true;
            $topscore = max($topscore, (float)$scores[$id]);
        };
        foreach ($headingids as $id) {
            if (isset($keep[$id]) && !isset($done[$id])) {
                $emit($id);
            }
        }
        foreach ($ordered as $id) {
            if (isset($keep[$id]) && !isset($done[$id])) {
                $emit($id);
            }
        }

        return [
            'chunks' => $selected,
            'semantic' => (bool)$semantic,
            'topscore' => $topscore,
            'coverage' => self::term_coverage($selected, $query),
        ];
    }

    /**
     * Fraction of the query's main terms (4+ characters) literally present in
     * at least one selected chunk. 1.0 when there is nothing to check.
     *
     * @param array[] $selected Selected chunks (['content' => ...]).
     * @param string $query User question.
     * @return float Coverage in [0, 1].
     */
    private static function term_coverage(array $selected, string $query): float {
        $terms = array_values(array_filter(self::tokenize($query), static function (string $term): bool {
            return \core_text::strlen($term) >= 4;
        }));
        if (empty($terms)) {
            return 1.0;
        }
        if (empty($selected)) {
            return 0.0;
        }
        $haystack = self::fold_accents(\core_text::strtolower(implode("\n", array_column($selected, 'content'))));
        $normalized = null;
        $found = 0;
        foreach ($terms as $term) {
            if (self::is_compound($term)) {
                $normalized = $normalized ?? self::normalize_separators($haystack);
                $hay = $normalized;
            } else {
                $hay = $haystack;
            }
            if (mb_strpos($hay, self::fold_accents($term)) !== false) {
                $found++;
            }
        }
        return $found / count($terms);
    }

    /**
     * Score every chunk of a course against a query.
     *
     * Uses cosine similarity over stored embeddings when available, else lexical
     * keyword overlap, then adds a heading-match boost for chunks whose section
     * breadcrumb literally contains a query term.
     *
     * @param \stdClass[] $rows Chunk rows keyed by id.
     * @param string $query User question.
     * @return array{0: array, 1: bool, 2: array} [id=>score, semantic?, id=>headinghits]
     */
    private static function score_rows(array $rows, string $query): array {
        $semanticscores = self::semantic_scores($rows, $query);
        $semantic = $semanticscores !== null;
        $lexicalscores = self::lexical_scores($rows, $query);

        if (!$semantic) {
            $scores = $lexicalscores;
        } else {
            // Hybrid ranking: cosine similarity carries the ranking and a
            // normalized lexical component rewards chunks that literally
            // contain the query terms (acronyms, sigla, proper names), which
            // pure embeddings under-rank on short queries. PHP-only: no extra
            // generative tokens are spent.
            $maxlex = 0.0;
            foreach ($lexicalscores as $value) {
                $maxlex = max($maxlex, $value);
            }
            $scores = [];
            foreach ($rows as $id => $row) {
                $lex = $maxlex > 0.0 ? (($lexicalscores[$id] ?? 0.0) / $maxlex) : 0.0;
                if (isset($semanticscores[$id])) {
                    $scores[$id] = $semanticscores[$id] + self::LEXICAL_WEIGHT * $lex;
                } else {
                    // Chunk not embedded yet (e.g. freshly indexed): rank it on
                    // the lexical component alone so it stays retrievable until
                    // the embedding task catches up.
                    $scores[$id] = self::LEXICAL_WEIGHT * $lex;
                }
            }
        }

        $terms = self::tokenize($query);
        $headinghits = [];
        if (!empty($terms)) {
            foreach ($rows as $id => $row) {
                if (!isset($scores[$id])) {
                    continue;
                }
                $heading = self::breadcrumb_of((string)$row->content);
                if ($heading === '') {
                    continue;
                }
                $hl = self::fold_accents(\core_text::strtolower($heading));
                $hn = self::normalize_separators($hl);
                $hits = 0;
                foreach ($terms as $term) {
                    if (self::term_hits(self::is_compound($term) ? $hn : $hl, $term) > 0) {
                        $hits++;
                    }
                }
                if ($hits > 0) {
                    $headinghits[$id] = $hits;
                    $scores[$id] += $semantic ? min(0.4, 0.15 * $hits) : (15.0 * $hits);
                }
            }
        }

        return [$scores, $semantic, $headinghits];
    }

    /**
     * Inspect retrieval for a query: the top-scoring chunks with their
     * breadcrumb, score and whether they were selected. For the admin test tool
     * so misses can be diagnosed (is the section indexed? does its heading carry
     * the term? is it ranking high enough?).
     *
     * @param int $courseid Course id.
     * @param string $query User question.
     * @param int $limit Slots the tutor would receive.
     * @param int $show How many top rows to report.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return array{semantic: bool, total: int, rows: array[]}
     */
    public static function inspect(int $courseid, string $query, int $limit, int $show = 15, int $blockinstanceid = 0): array {
        global $DB;

        $rows = $DB->get_records(
            self::TABLE,
            ['courseid' => $courseid, 'blockinstanceid' => $blockinstanceid],
            'contenthash ASC, chunkindex ASC',
            'id, filename, citable, content, embedding, embeddingmodel',
            0,
            self::MAX_COURSE_CHUNKS
        );
        if (!$rows) {
            return ['semantic' => false, 'total' => 0, 'rows' => []];
        }

        [$scores, $semantic, $headinghits] = self::score_rows($rows, $query);

        // Reproduce the same selection the tutor would receive.
        $selectedids = [];
        foreach (self::retrieve($courseid, $query, $limit, $blockinstanceid) as $sel) {
            $selectedids[$sel['filename'] . '|' . mb_substr($sel['content'], 0, 200)] = true;
        }

        arsort($scores);
        $out = [];
        foreach (array_keys($scores) as $id) {
            if (count($out) >= $show) {
                break;
            }
            $row = $rows[$id];
            $breadcrumb = self::breadcrumb_of((string)$row->content);
            $key = (string)$row->filename . '|' . mb_substr((string)$row->content, 0, 200);
            $body = trim((string)preg_replace('/\s+/u', ' ', self::strip_breadcrumb((string)$row->content)));
            $out[] = [
                'filename' => (string)$row->filename,
                'breadcrumb' => $breadcrumb !== '' ? $breadcrumb : '(sin encabezado)',
                'score' => round((float)$scores[$id], 4),
                'headinghit' => !empty($headinghits[$id]),
                'selected' => isset($selectedids[$key]),
                'snippet' => \core_text::substr($body, 0, 160),
            ];
        }

        return ['semantic' => $semantic, 'total' => count($rows), 'rows' => $out];
    }

    /**
     * Remove the leading "[Section: ...]" breadcrumb from a chunk, if present.
     *
     * @param string $content Chunk content.
     * @return string Body without the breadcrumb prefix.
     */
    private static function strip_breadcrumb(string $content): string {
        if (strpos($content, '[Section: ') !== 0) {
            return $content;
        }
        $end = strpos($content, "]\n");
        return $end === false ? $content : \core_text::substr($content, $end + 2);
    }

    /**
     * Format retrieved chunks as an instructions block for the tutor.
     *
     * @param array[] $chunks Chunks from {@see retrieve}.
     * @return string Instruction text ('' when no chunks were retrieved).
     */
    public static function format_context(array $chunks): string {
        if (empty($chunks)) {
            return '';
        }

        $lines = [
            'Course document excerpts retrieved for this question. Every COURSE-SPECIFIC fact must come from '
                . 'these excerpts: what the course or its documents say, how the material is organised, its '
                . 'definitions, steps, examples, locations, and anything you attribute to the course material. '
                . 'You MAY use sound general knowledge of the subject to frame, contextualise and explain '
                . 'concepts pedagogically, but only when it does not contradict the excerpts, and NEVER '
                . 'present outside knowledge as if it came from the course documents. If the user asks what '
                . 'the course material says (or where), and the excerpts do not contain it, say so and apply '
                . 'the configured no-information fallback; never invent what a document "probably" says.',
            'Each excerpt begins with a "[Section: ...]" marker giving its heading path and, when available, '
                . 'its page ("p. N"). This marker is the ONLY authoritative source of location, and it is '
                . 'INTERNAL: it is scaffolding for you, not text for the participant. Read the unit, section '
                . 'and page VALUES from it and never take a location from anywhere else, but NEVER reproduce '
                . 'the marker itself or its syntax in your answer -- not the "[Section:" token, not the '
                . 'square brackets, not the " | " or " > " separators, and not a heading path that the marker '
                . 'cut off mid-sentence. Write the location as plain prose naming the document and the page, '
                . 'e.g. "Guia Practica PM4R Leadership, p. 56" or "Modulo 3, p. 56". Never invent, guess, '
                . 'renumber or paraphrase a unit, step, chapter, section title or page number that is not '
                . 'literally present in an excerpt marker. If none of the retrieved excerpts actually contain '
                . 'what the user asked about, do not name a location at all: say the retrieved material does '
                . 'not cover it and suggest they rephrase or point you to the right section.',
            'Excerpts marked [internal] must never be cited, named or quoted; use them only as background.',
            'An excerpt beginning "[FIGURE REFERENCE]" is a locator, not content: it records that a '
                . 'numbered figure or table exists at that location, because figures are usually '
                . 'images whose content never reaches this index. Treat it as evidence that the '
                . 'course DOES cover the topic. Name the figure and its page, say it is a figure to '
                . 'consult in the document, and explain what you can from the other excerpts and '
                . 'from general knowledge (clearly marked as such). Never answer that the material '
                . 'does not cover a topic when a figure reference for it was retrieved, and never '
                . 'reproduce the "[FIGURE REFERENCE]" tag or invent what the figure shows.',
            'NEVER expand an acronym or initialism unless its expansion appears literally in an excerpt. An '
                . 'excerpt that shows what a tool DOES, or lists its steps, does not tell you what its letters '
                . 'stand for: those are different facts. If you do not see the expansion written out, use the '
                . 'acronym exactly as the course writes it and say nothing about what the letters mean -- '
                . 'silently, without announcing the omission and without referring to this instruction.',
            'Do not treat the user\'s own claim about where something is (or what a unit is called) as fact: '
                . 'verify it against the excerpt markers before agreeing, and correct or decline if the '
                . 'excerpts do not support it.',
        ];
        $index = 1;
        foreach ($chunks as $chunk) {
            $label = $chunk['citable'] ? 'citable' : 'internal';
            $lines[] = '[' . $index . '] [' . $label . '] From "' . $chunk['filename'] . "\":\n" . $chunk['content'];
            $index++;
        }
        return implode("\n\n", $lines);
    }

    /**
     * Score chunks by cosine similarity to the query embedding.
     *
     * @param \stdClass[] $rows Chunk rows keyed by id.
     * @param string $query User question.
     * @return array|null Map id => score, or null when semantic scoring is unavailable.
     */
    private static function semantic_scores(array $rows, string $query): ?array {
        $model = embeddings::model();
        if ($model === '') {
            return null;
        }

        $embedded = [];
        foreach ($rows as $id => $row) {
            if ((string)$row->embeddingmodel !== $model || (string)$row->embedding === '') {
                continue;
            }
            $vector = json_decode((string)$row->embedding, true);
            if (is_array($vector) && !empty($vector)) {
                $embedded[$id] = $vector;
            }
        }
        if (empty($embedded)) {
            return null;
        }

        $result = embeddings::embed([$query]);
        if ($result === null || !isset($result[0])) {
            return null;
        }
        $queryvector = $result[0];

        $scores = [];
        foreach ($embedded as $id => $vector) {
            $scores[$id] = self::cosine($queryvector, $vector);
        }
        return $scores;
    }

    /**
     * Score chunks by keyword overlap with the query (fallback path).
     *
     * Scored over the WHOLE chunk. Best-passage scoring (score the best 600-char
     * window instead) was tried and measured worse: it rewards how densely the
     * query terms co-occur, so a long topical page wins and a terse isolated
     * definition -- the exact case it was meant to rescue -- loses. Measured on
     * this corpus, the glossary entry for the tool asked about fell from #13 to
     * #19, the IAR definition from #26 to #39, and the acronym expansion from
     * #56 to #87, at 2,3x the scoring cost. Do not reintroduce it without a
     * measurement; the dilution problem it targets lives in the cosine, not here.
     *
     * @param \stdClass[] $rows Chunk rows keyed by id.
     * @param string $query User question.
     * @return array Map id => score.
     */
    private static function lexical_scores(array $rows, string $query): array {
        $terms = self::tokenize($query);
        // Only pay for the normalized copy when a compound term needs it.
        $hascompound = false;
        foreach ($terms as $term) {
            $hascompound = $hascompound || self::is_compound($term);
        }
        $scores = [];
        foreach ($rows as $id => $row) {
            // Folded once per chunk, not once per term: term_hits() folds only the
            // short term, so both sides meet without refolding 2.500 characters
            // for every query word.
            $content = self::fold_accents(\core_text::strtolower((string)$row->content));
            $filename = self::fold_accents(\core_text::strtolower((string)$row->filename));
            $normalized = $hascompound ? self::normalize_separators($content) : $content;

            $score = 0.0;
            foreach ($terms as $term) {
                $haystack = self::is_compound($term) ? $normalized : $content;
                $count = self::term_hits($haystack, $term);
                if ($count > 0) {
                    // Diminishing returns per extra occurrence of the same term.
                    $score += (1 + log((float)$count)) * \core_text::strlen($term);
                }
                if (strpos($filename, self::fold_accents($term)) !== false) {
                    $score += 2.0;
                }
            }
            $scores[$id] = $score;
        }
        return $scores;
    }

    /**
     * Extract the "[Section: ...]" breadcrumb from a chunk, if present.
     *
     * @param string $content Chunk content (may start with a breadcrumb).
     * @return string The breadcrumb text (heading path and page), or ''.
     */
    private static function breadcrumb_of(string $content): string {
        if (strpos($content, '[Section: ') !== 0) {
            return '';
        }
        $end = strpos($content, "]\n");
        if ($end === false) {
            return '';
        }
        return \core_text::substr($content, 10, $end - 10);
    }

    /**
     * Extract lowercase search terms of 3+ characters from a query, plus any
     * dash-joined compound names it contains.
     *
     * The 3-character floor drops the short words a tool name can be made of:
     * "Si-No-Si" tokenizes to nothing but "tecnica", so the name of the tool
     * carries no lexical signal at all and only the embedding can find it --
     * which fails as soon as a long question dilutes the query vector. Emitting
     * the whole compound as one term restores that signal, and because the term
     * is long and rare it outweighs the generic words around it.
     *
     * Compound terms must be matched against {@see normalize_separators} output;
     * {@see is_compound} tells a caller which terms need it.
     *
     * @param string $query User question.
     * @return string[] Unique terms.
     */
    public static function tokenize(string $query): array {
        $lower = \core_text::strtolower($query);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $lower) ?: [];
        $terms = [];
        foreach ($parts as $part) {
            if (\core_text::strlen($part) >= 3 && !in_array($part, $terms, true)) {
                $terms[] = $part;
            }
        }
        foreach (self::compound_terms($lower) as $compound) {
            if (!in_array($compound, $terms, true)) {
                $terms[] = $compound;
            }
        }
        return $terms;
    }

    /**
     * Dash-joined compound names in a query, normalized to single hyphens.
     *
     * "Si-No-Si", "Paro-Pienso-Procedo", "Yo-Contexto-Equipo". Two or more word
     * parts joined by any hyphen or dash. Returned lowercase and separator-
     * normalized so a chunk written "Si - No - Si" still matches.
     *
     * @param string $lowerquery Already-lowercased query.
     * @return string[] Unique compound terms ('' when there are none).
     */
    public static function compound_terms(string $lowerquery): array {
        $out = [];
        if (!preg_match_all('/\p{L}+(?:[\s]*[\-\x{2010}-\x{2015}][\s]*\p{L}+)+/u', $lowerquery, $matches)) {
            return $out;
        }
        foreach ($matches[0] as $match) {
            $compound = self::normalize_separators($match);
            if ($compound !== '' && !in_array($compound, $out, true)) {
                $out[] = $compound;
            }
        }
        return $out;
    }

    /**
     * Is this term a dash-joined compound (so it needs a normalized haystack)?
     *
     * @param string $term Term from {@see tokenize}.
     * @return bool
     */
    public static function is_compound(string $term): bool {
        return strpos($term, '-') !== false;
    }

    /**
     * Collapse every run of whitespace, dash and slash into a single hyphen.
     *
     * Lets one compound term match every way a document writes it: "Si-No-Si",
     * "Si - No - Si", "Si No Si". Plain single-word terms are unaffected, since
     * they contain none of these characters.
     *
     * @param string $text Text to normalize.
     * @return string
     */
    public static function normalize_separators(string $text): string {
        return (string)preg_replace('/[\s\-\x{2010}-\x{2015}_\/]+/u', '-', $text);
    }

    /**
     * Strip diacritics so a term matches however the participant typed it.
     *
     * The lexical component compares lowercased text but not folded text, so
     * "sandwich" scored zero against a document that writes "sandwich" with an
     * accent -- and the whole point of that component is to catch exactly the
     * rare, high-precision words (tool names, acronyms, proper names) that
     * embeddings under-rank on short queries. Participants type without accents
     * constantly; the documents never do. Folding both sides is what makes the
     * two meet. Applied uniformly to term and haystack, so it cannot introduce
     * an asymmetric match.
     *
     * Kept ASCII-only on purpose: the Latin-script courses this serves need
     * nothing more, and a locale-dependent iconv//TRANSLIT would silently
     * produce different output on different servers.
     *
     * @param string $text Lowercased text.
     * @return string Text with Latin-1/Latin-A diacritics folded to ASCII.
     */
    public static function fold_accents(string $text): string {
        if (!preg_match('/[\x{00C0}-\x{024F}]/u', $text)) {
            // Overwhelmingly the common case for English text and plain terms.
            return $text;
        }
        static $map = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'ē' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ī' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ū' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y', 'š' => 's', 'ž' => 'z',
        ];
        return strtr($text, $map);
    }

    /**
     * The names of course tools mentioned in a query.
     *
     * Two shapes: dash-joined names ("Si-No-Si", "Paro-Pienso-Procedo") and
     * acronyms written in capitals (IAR, FODA, CAME, GROW, SMART, MAAN). These
     * are the highest-precision terms a question can carry -- they name exactly
     * one section of the material -- and they are precisely what a long, padded
     * question buries.
     *
     * @param string $query User question.
     * @return string[] Lowercased names, compounds first, at most four.
     */
    public static function name_terms(string $query): array {
        $names = self::compound_terms(\core_text::strtolower($query));
        // Runs of capitals and digits: an acronym, never an ordinary word, since
        // a capitalized word ("Necesito") carries lowercase letters and a
        // sentence-initial capital is only one character.
        if (preg_match_all('/(?<![\p{L}\p{N}])[\p{Lu}\p{N}]{2,8}(?![\p{L}\p{N}])/u', $query, $matches)) {
            foreach ($matches[0] as $candidate) {
                if (!preg_match('/\p{Lu}/u', $candidate)) {
                    continue;
                }
                $lower = \core_text::strtolower($candidate);
                if (!in_array($lower, $names, true)) {
                    $names[] = $lower;
                }
            }
        }
        return array_slice($names, 0, 4);
    }

    /**
     * Count a term in a haystack, anchoring short terms to word boundaries.
     *
     * A plain substring search finds a short acronym inside ordinary words --
     * "iar" matches "iniciar", "cambiar", "apreciar" -- so the one section that
     * actually names the tool ends up buried under dozens of false hits, and a
     * retrieval pass on the acronym returns noise. Long terms keep the cheap
     * substring search: "sandwich" inside a longer word is not a real risk, and
     * compounds must stay substring-matched because the haystack they are looked
     * up in has already been separator-normalized.
     *
     * Both sides are accent-folded: the term here (cheap, it is a few characters)
     * and the haystack once per chunk by the caller, so a participant who types
     * "sandwich" still matches a document that writes it with an accent.
     *
     * @param string $haystack Lowercased, accent-folded text to search.
     * @param string $term Lowercased search term.
     * @return int Occurrences.
     */
    private static function term_hits(string $haystack, string $term): int {
        $term = self::fold_accents($term);
        if (\core_text::strlen($term) >= 5 || self::is_compound($term)) {
            return substr_count($haystack, $term);
        }
        $count = preg_match_all(
            '/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u',
            $haystack
        );
        return $count === false ? 0 : (int)$count;
    }

    /**
     * Strip a long question down to the terms that actually carry the topic.
     *
     * Retrieval embeds the whole message, so a long question drowns its own
     * subject: "Necesito un resumen ejecutivo, no mas de un parrafo, sobre que
     * es la tecnica Si-No-Si. Es para una nota interna al coordinador." scores
     * against a vector dominated by "resumen ejecutivo", "nota interna" and
     * "coordinador", and the tool's own page falls out of the results -- while
     * the same question asked in four words finds it immediately. Keeping the
     * long words and the tool names gives that second, focused pass something to
     * match on. Both passes are merged, so this can only add.
     *
     * @param string $query User question.
     * @return string Focused query, or '' when it would add nothing.
     */
    public static function focus_query(string $query): string {
        $lower = \core_text::strtolower($query);

        // When the question names a tool, the focused pass is that name on its
        // own. Measured: "¿Que es la tecnica Si-No-Si?" retrieves the tool's page
        // immediately, while the same question wrapped in "necesito un resumen
        // ejecutivo... para una nota interna al coordinador" does not -- and
        // keeping the padding alongside the name reproduces the failure, because
        // the padding still dominates the query vector. The name alone IS the
        // short question that works.
        $names = self::name_terms($query);
        if (!empty($names)) {
            $focus = implode(' ', $names);
            return \core_text::strtolower($focus) === $lower ? '' : $focus;
        }

        // No tool named: fall back to the long words, which at least drops the
        // glue a polite request is padded with (que, para, sobre, como, esto).
        $terms = [];
        foreach (self::tokenize($query) as $term) {
            if (\core_text::strlen($term) >= 6) {
                $terms[] = $term;
            }
        }
        if (count($terms) < 2) {
            return '';
        }
        $focus = implode(' ', $terms);
        return \core_text::strtolower($focus) === $lower ? '' : $focus;
    }

    /**
     * Merge retrieval passes round-robin, best of each first, deduplicated.
     *
     * Round-robin rather than "first list then the rest": each pass is already
     * ordered best-first, so alternating guarantees the top hits of BOTH queries
     * survive the cut. Picking one whole pass over the other is what the caller
     * used to do, and it could discard a correct excerpt because a differently
     * worded query happened to score higher against its own vector -- scores
     * from two different queries are not comparable in the first place.
     *
     * @param array[] $sets Chunk lists, each as returned by {@see retrieve}.
     * @param int $limit Maximum chunks to return.
     * @return array[] Merged chunks, at most $limit of them.
     */
    public static function merge_chunks(array $sets, int $limit): array {
        $sets = array_values(array_filter($sets));
        if (count($sets) === 1) {
            return array_slice($sets[0], 0, $limit);
        }
        $merged = [];
        $seen = [];
        $depth = 0;
        $longest = 0;
        foreach ($sets as $set) {
            $longest = max($longest, count($set));
        }
        while ($depth < $longest && count($merged) < $limit) {
            foreach ($sets as $set) {
                if (!isset($set[$depth]) || count($merged) >= $limit) {
                    continue;
                }
                $chunk = $set[$depth];
                $key = ($chunk['filename'] ?? '') . '|' . \core_text::substr((string)($chunk['content'] ?? ''), 0, 200);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = $chunk;
            }
            $depth++;
        }
        return $merged;
    }

    /**
     * Cosine similarity between two vectors.
     *
     * @param float[] $a First vector.
     * @param float[] $b Second vector.
     * @return float Similarity in [-1, 1] (0 when lengths differ).
     */
    public static function cosine(array $a, array $b): float {
        $count = count($a);
        if ($count === 0 || $count !== count($b)) {
            return 0.0;
        }
        $a = array_values($a);
        $b = array_values($b);
        $dot = 0.0;
        $norma = 0.0;
        $normb = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
            $norma += $a[$i] * $a[$i];
            $normb += $b[$i] * $b[$i];
        }
        if ($norma <= 0.0 || $normb <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($norma) * sqrt($normb));
    }
}
