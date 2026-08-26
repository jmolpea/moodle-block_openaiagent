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
 * File-text index store for block_openaiagent.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Manages the persistent index of extracted text for course files.
 *
 * Records are keyed by contenthash so that duplicate files (same content,
 * different locations) are only extracted once.
 */
class filetext_store {
    /** @var int Extraction has not yet been attempted. */
    public const STATUS_PENDING = 0;

    /** @var int Extraction is currently in progress. */
    public const STATUS_PROCESSING = 1;

    /** @var int Text was successfully extracted and is stored. */
    public const STATUS_READY = 2;

    /** @var int Extraction failed with an error. */
    public const STATUS_FAILED = 3;

    /** @var int File contained no extractable text. */
    public const STATUS_EMPTY = 4;

    /**
     * Ensure a file is queued for indexing (idempotent by contenthash).
     *
     * @param \stored_file $file The Moodle stored file to queue.
     */
    public static function ensure_queued(\stored_file $file): void {
        global $DB;
        $contenthash = $file->get_contenthash();
        if (!$contenthash) {
            return;
        }
        $now = time();

        $existing = $DB->get_record('block_openaiagent_filetext', ['contenthash' => $contenthash], '*', IGNORE_MULTIPLE);
        if ($existing) {
            // Keep metadata fresh; do not reset status — same hash means same content.
            $upd = new \stdClass();
            $upd->id = $existing->id;
            $upd->fileid = (int)$file->get_id();
            $upd->mimetype = $file->get_mimetype();
            $upd->filesize = (int)$file->get_filesize();
            $upd->timemodified = (int)$file->get_timemodified();
            $DB->update_record('block_openaiagent_filetext', $upd);
            return;
        }

        $rec = new \stdClass();
        $rec->fileid = (int)$file->get_id();
        $rec->contenthash = $contenthash;
        $rec->mimetype = $file->get_mimetype();
        $rec->filesize = (int)$file->get_filesize();
        $rec->timemodified = (int)$file->get_timemodified();
        $rec->status = self::STATUS_PENDING;
        $rec->charcount = 0;
        $rec->pagecount = 0;
        $rec->errormsg = '';
        $rec->timecreated = $now;
        $rec->timeindexed = 0;
        $rec->extractedtext = null;
        $DB->insert_record('block_openaiagent_filetext', $rec);
    }

    /**
     * Get indexed text by contenthash.
     *
     * @param string $contenthash SHA-1 content hash of the file.
     * @return array{status:int, text:string, timeindexed:int, errormsg:string}
     */
    public static function get_by_contenthash(string $contenthash): array {
        global $DB;
        $rec = $DB->get_record('block_openaiagent_filetext', ['contenthash' => $contenthash], '*', IGNORE_MISSING);
        if (!$rec) {
            return ['status' => self::STATUS_PENDING, 'text' => '', 'timeindexed' => 0, 'errormsg' => ''];
        }
        $text = is_string($rec->extractedtext) ? $rec->extractedtext : '';
        return [
            'status' => (int)$rec->status,
            'text' => $text,
            'timeindexed' => (int)($rec->timeindexed ?? 0),
            'errormsg' => (string)($rec->errormsg ?? ''),
        ];
    }

    /**
     * Mark a job as PROCESSING and refresh the heartbeat timestamp.
     *
     * @param string $contenthash SHA-1 content hash of the file.
     */
    public static function mark_processing(string $contenthash): void {
        global $DB;

        $rec = $DB->get_record('block_openaiagent_filetext', ['contenthash' => $contenthash], '*', IGNORE_MISSING);
        if (!$rec) {
            return;
        }

        $upd = new \stdClass();
        $upd->id = $rec->id;
        $upd->status = self::STATUS_PROCESSING;
        $upd->timeindexed = time();
        $upd->errormsg = '';
        $DB->update_record('block_openaiagent_filetext', $upd);
    }

    /**
     * Reset stale PROCESSING jobs back to PENDING so they can be retried.
     *
     * @param int $timeoutseconds Seconds after which a PROCESSING job is considered stale. Default 600.
     * @return bool|int Number of records updated, or false on failure.
     */
    public static function reset_stale_processing(int $timeoutseconds = 600) {
        global $DB;

        $cutoff = time() - $timeoutseconds;
        $sql = "UPDATE {block_openaiagent_filetext}
                   SET status = ?
                 WHERE status = ?
                   AND timeindexed > 0
                   AND timeindexed < ?";

        return $DB->execute($sql, [self::STATUS_PENDING, self::STATUS_PROCESSING, $cutoff]);
    }

    /**
     * Persist the extraction result for a file.
     *
     * @param string $contenthash SHA-1 content hash of the file.
     * @param int    $status      One of the STATUS_* constants.
     * @param string $text        Extracted text (may be empty for FAILED/EMPTY).
     * @param string $errormsg    Error message when status is FAILED.
     * @param int    $pagecount   Number of pages detected (PDF only).
     */
    public static function set_result(
        string $contenthash,
        int $status,
        string $text = '',
        string $errormsg = '',
        int $pagecount = 0
    ): void {
        global $DB;
        $rec = $DB->get_record('block_openaiagent_filetext', ['contenthash' => $contenthash], '*', IGNORE_MISSING);
        if (!$rec) {
            return;
        }

        $upd = new \stdClass();
        $upd->id = $rec->id;
        $upd->status = $status;
        $upd->timeindexed = time();
        $upd->errormsg = $errormsg;
        $upd->pagecount = (int)$pagecount;
        $upd->charcount = (int)\core_text::strlen($text);

        if ($text !== '') {
            $upd->extractedtext = \core_text::substr($text, 0, 2000000);
        } else {
            $upd->extractedtext = null;
        }

        $DB->update_record('block_openaiagent_filetext', $upd);
    }

    /**
     * Extract text from a stored file right now and persist the result.
     *
     * Supports PDF and text/* files; anything else is recorded as EMPTY.
     * Used both by the cron indexer and by the knowledge-base synchronizer.
     *
     * @param \stored_file $file The Moodle stored file to extract.
     * @return int Resulting STATUS_* value.
     */
    public static function extract_file(\stored_file $file): int {
        $contenthash = $file->get_contenthash();
        self::mark_processing($contenthash);

        try {
            $mimetype = $file->get_mimetype();
            $text = '';

            if ($mimetype === 'application/pdf') {
                $tmpdir = make_temp_directory('block_openaiagent_index');
                $tmppath = $tmpdir . '/' . uniqid('pdf_', true) . '.pdf';
                $file->copy_content_to($tmppath);
                $text = pdf_text::extract($tmppath);
                @unlink($tmppath);
            } else if (strpos((string)$mimetype, 'text/') === 0) {
                $text = (string)$file->get_content();
            }

            $text = pdf_text::normalize_text($text);
            if (pdf_text::is_effectively_empty($text)) {
                self::set_result($contenthash, self::STATUS_EMPTY, '', 'no_text');
                return self::STATUS_EMPTY;
            }

            self::set_result($contenthash, self::STATUS_READY, $text, '');
            return self::STATUS_READY;
        } catch (\Throwable $e) {
            self::set_result($contenthash, self::STATUS_FAILED, '', 'exception: ' . $e->getMessage());
            return self::STATUS_FAILED;
        }
    }
}
