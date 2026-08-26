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
 * Course knowledge-base document management for the tutor.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

use block_openaiagent\ai\embeddings;

/**
 * Manages the tutor knowledge-base file areas and their chunk index.
 *
 * Teachers upload documents in the course assistant configuration; this class
 * extracts their text, chunks it and embeds the chunks so {@see rag} can
 * retrieve them at question time.
 */
class tutordocs {
    /** @var string File component. */
    public const COMPONENT = 'block_openaiagent';

    /** @var string File area for documents the tutor may cite. */
    public const AREA_CITABLE = 'tutordocs_citable';

    /** @var string File area for internal documents (searched, never cited). */
    public const AREA_INTERNAL = 'tutordocs_internal';

    /** @var int Embedding update batch size. Kept small so one bad input or a
     * per-request size limit cannot take down a large course's whole batch. */
    private const EMBED_BATCH = 16;

    /**
     * Both knowledge-base file area names.
     *
     * @return string[]
     */
    public static function areas(): array {
        return [self::AREA_CITABLE, self::AREA_INTERNAL];
    }

    /**
     * Resolve the context under which a profile's knowledge-base files are stored.
     *
     * Course and activity-module blocks keep their files in the course context (so
     * behaviour is unchanged); category and site blocks, which have no owning
     * course, keep them in their own block-parent context (e.g. the category
     * context) so a category-wide assistant stores its documents where it lives.
     *
     * @param int $courseid Course id (used for the legacy course-wide profile).
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return \context
     */
    public static function file_context(int $courseid, int $blockinstanceid = 0): \context {
        global $DB;

        if ($blockinstanceid > 0) {
            $parentcontextid = $DB->get_field('block_instances', 'parentcontextid', ['id' => $blockinstanceid]);
            if ($parentcontextid) {
                $blockcontext = \context::instance_by_id((int)$parentcontextid, IGNORE_MISSING);
                if ($blockcontext) {
                    $coursecontext = $blockcontext->get_course_context(false);
                    return $coursecontext ?: $blockcontext;
                }
            }
        }
        return \context_course::instance($courseid);
    }

    /**
     * List the stored files of one knowledge-base area for a course.
     *
     * @param int $courseid Course id.
     * @param string $area One of the AREA_* constants.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return \stored_file[]
     */
    public static function files(int $courseid, string $area, int $blockinstanceid = 0): array {
        $context = self::file_context($courseid, $blockinstanceid);
        $fs = get_file_storage();
        $files = [];
        foreach ($fs->get_area_files($context->id, self::COMPONENT, $area, $blockinstanceid, 'filename', false) as $file) {
            $files[] = $file;
        }
        return $files;
    }

    /**
     * Whether the profile has any knowledge-base document uploaded.
     *
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return bool
     */
    public static function has_documents(int $courseid, int $blockinstanceid = 0): bool {
        foreach (self::areas() as $area) {
            if (!empty(self::files($courseid, $area, $blockinstanceid))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Synchronize the chunk index with the uploaded documents of a course.
     *
     * Extracts text for new files, (re)creates their chunks, removes chunks of
     * deleted files, and embeds chunks that lack a vector for the current
     * embeddings model. Safe to call repeatedly; work already done is skipped.
     *
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return void
     */
    public static function sync_course(int $courseid, int $blockinstanceid = 0): void {
        global $DB;

        // Internal documents are processed last so that a file uploaded to both
        // areas is treated as internal (the safer classification).
        $documents = [];
        foreach ([self::AREA_CITABLE => 1, self::AREA_INTERNAL => 0] as $area => $citable) {
            foreach (self::files($courseid, $area, $blockinstanceid) as $file) {
                $documents[$file->get_contenthash()] = [
                    'file' => $file,
                    'citable' => $citable,
                ];
            }
        }

        // A chunk row is keyed by the file's contenthash and treated as immutable,
        // so a change to the CHUNKER never reaches documents that are already
        // indexed: the file has not changed, only the way it is cut up. Without
        // this, every chunker improvement would only apply to courses created
        // afterwards, and existing ones would need a manual database delete.
        // Stamping the chunker version makes the rebuild automatic and one-off.
        $stamp = 'chunkerversion_' . $courseid . '_' . $blockinstanceid;
        if ((string)get_config('block_openaiagent', $stamp) !== rag::CHUNKER_VERSION) {
            $DB->delete_records(rag::TABLE, ['courseid' => $courseid, 'blockinstanceid' => $blockinstanceid]);
            set_config($stamp, rag::CHUNKER_VERSION, 'block_openaiagent');
        }

        foreach ($documents as $contenthash => $document) {
            self::index_document($courseid, $document['file'], $document['citable'], $blockinstanceid);
        }

        // Drop chunks belonging to files no longer uploaded (within this profile).
        if (empty($documents)) {
            $DB->delete_records(rag::TABLE, ['courseid' => $courseid, 'blockinstanceid' => $blockinstanceid]);
        } else {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($documents), SQL_PARAMS_NAMED, 'ch', false);
            $params['courseid'] = $courseid;
            $params['blockinstanceid'] = $blockinstanceid;
            $DB->delete_records_select(
                rag::TABLE,
                "courseid = :courseid AND blockinstanceid = :blockinstanceid AND contenthash $insql",
                $params
            );
        }

        self::embed_pending($courseid, $blockinstanceid);
    }

    /**
     * Ensure one document has extracted text and up-to-date chunk rows.
     *
     * @param int $courseid Course id.
     * @param \stored_file $file Uploaded document.
     * @param int $citable 1 when the tutor may cite this document.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return void
     */
    private static function index_document(int $courseid, \stored_file $file, int $citable, int $blockinstanceid = 0): void {
        global $DB;

        $contenthash = $file->get_contenthash();
        filetext_store::ensure_queued($file);

        $indexed = filetext_store::get_by_contenthash($contenthash);
        if ((int)$indexed['status'] === filetext_store::STATUS_PENDING) {
            filetext_store::extract_file($file);
            $indexed = filetext_store::get_by_contenthash($contenthash);
        }
        if ((int)$indexed['status'] !== filetext_store::STATUS_READY) {
            return;
        }

        $existing = $DB->get_records(
            rag::TABLE,
            ['courseid' => $courseid, 'blockinstanceid' => $blockinstanceid, 'contenthash' => $contenthash],
            '',
            'id, citable, filename'
        );
        if ($existing) {
            // Content is immutable per contenthash; only the metadata may change.
            $now = time();
            foreach ($existing as $row) {
                if ((int)$row->citable !== $citable || (string)$row->filename !== $file->get_filename()) {
                    $DB->update_record(rag::TABLE, (object) [
                        'id' => $row->id,
                        'citable' => $citable,
                        'filename' => $file->get_filename(),
                        'timemodified' => $now,
                    ]);
                }
            }
            return;
        }

        $now = time();
        $records = [];
        foreach (self::chunks_for_file($file, $indexed['text']) as $index => $chunk) {
            $records[] = (object) [
                'courseid' => $courseid,
                'blockinstanceid' => $blockinstanceid,
                'contenthash' => $contenthash,
                'filename' => $file->get_filename(),
                'citable' => $citable,
                'chunkindex' => $index,
                'content' => $chunk,
                'embedding' => null,
                'embeddingmodel' => '',
                'timecreated' => $now,
                'timemodified' => $now,
            ];
        }
        if (!empty($records)) {
            $DB->insert_records(rag::TABLE, $records);
        }
    }

    /**
     * Produce breadcrumb-tagged chunks for a document, page-aware for PDFs.
     *
     * For PDFs it re-extracts the text one page at a time so each chunk can
     * carry its page number ("p. N"); if per-page extraction is unavailable it
     * degrades to chunking the already-extracted flat text. Non-PDF files (text
     * uploads) have no pages, so they are chunked from the flat text directly.
     *
     * @param \stored_file $file The uploaded document.
     * @param string $flattext Flat extracted text from the file-text store.
     * @return string[] Chunks in document order.
     */
    private static function chunks_for_file(\stored_file $file, string $flattext): array {
        if ($file->get_mimetype() !== 'application/pdf') {
            return rag::chunk_text($flattext);
        }

        $pages = [];
        try {
            $tmpdir = make_temp_directory('block_openaiagent_index');
            $tmppath = $tmpdir . '/' . uniqid('pdfpages_', true) . '.pdf';
            $file->copy_content_to($tmppath);
            $pages = pdf_text::extract_pages($tmppath);
            @unlink($tmppath);
        } catch (\Throwable $e) {
            $pages = [];
        }

        // Only trust page-structured chunking when it plausibly matches the flat
        // extraction; otherwise fall back so a parser quirk never loses content.
        if (!empty($pages) && !pdf_text::is_effectively_empty(implode("\n", $pages))) {
            return rag::chunk_pages($pages);
        }
        return rag::chunk_text($flattext);
    }

    /**
     * Embed all course chunks lacking a vector for the current model.
     *
     * A provider failure leaves the chunks unembedded: retrieval degrades to
     * lexical scoring and the next sync retries.
     *
     * @param int $courseid Course id.
     * @param int $blockinstanceid Owning block instance id (0 = course-wide default).
     * @return void
     */
    private static function embed_pending(int $courseid, int $blockinstanceid = 0): void {
        global $DB;

        $model = embeddings::model();
        if ($model === '') {
            return;
        }

        $select = 'courseid = :courseid AND blockinstanceid = :blockinstanceid '
            . 'AND (embedding IS NULL OR embeddingmodel <> :model)';
        $pending = $DB->get_records_select(
            rag::TABLE,
            $select,
            ['courseid' => $courseid, 'blockinstanceid' => $blockinstanceid, 'model' => $model],
            'id ASC',
            'id, content'
        );
        if (!$pending) {
            return;
        }

        foreach (array_chunk($pending, self::EMBED_BATCH, true) as $batch) {
            $texts = array_map(static fn(\stdClass $row): string => (string)$row->content, array_values($batch));
            $vectors = embeddings::embed($texts);
            if ($vectors === null) {
                // Skip this batch rather than abort the course: a single failing
                // batch (e.g. a transient API error) must not leave every other
                // chunk unembedded. The scheduled sweep retries what is left.
                continue;
            }
            $now = time();
            $i = 0;
            foreach ($batch as $row) {
                $rounded = array_map(static fn(float $v): float => round($v, 6), $vectors[$i]);
                $DB->update_record(rag::TABLE, (object) [
                    'id' => $row->id,
                    'embedding' => json_encode($rounded),
                    'embeddingmodel' => $model,
                    'timemodified' => $now,
                ]);
                $i++;
            }
        }
    }

    /**
     * Embed chunks still lacking a vector across every course.
     *
     * Runs from the scheduled sweep so a course whose embedding failed during
     * its ad-hoc indexing (transient API error, one bad chunk) self-heals on the
     * next cron pass without the teacher having to re-save the configuration.
     *
     * @param int $maxcourses Safety cap on courses processed per run.
     * @return void
     */
    public static function embed_all_pending(int $maxcourses = 50): void {
        global $DB;

        $model = embeddings::model();
        if ($model === '') {
            return;
        }

        // A recordset avoids get_records_sql keying every row by its first column
        // (courseid), which is not unique once a course has several assistants.
        $rs = $DB->get_recordset_sql(
            "SELECT DISTINCT courseid, blockinstanceid
               FROM {" . rag::TABLE . "}
              WHERE embedding IS NULL OR embeddingmodel <> :model
           ORDER BY courseid ASC, blockinstanceid ASC",
            ['model' => $model]
        );

        $count = 0;
        foreach ($rs as $profile) {
            if ($count++ >= $maxcourses) {
                break;
            }
            self::embed_pending((int)$profile->courseid, (int)$profile->blockinstanceid);
        }
        $rs->close();
    }
}
