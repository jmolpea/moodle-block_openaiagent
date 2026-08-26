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
 * Restore structure step for block_openaiagent.
 *
 * @package    block_openaiagent
 * @copyright  2026 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_openaiagent\local\course_config;
use block_openaiagent\local\tutordocs;
use block_openaiagent\task\index_tutordocs_task;

/**
 * Rebuilds the assistant profile for the restored block instance.
 *
 * Everything is re-keyed to the new (course, block instance) pair: that pair is
 * the profile identity throughout the plugin, and both halves of it change on a
 * course copy, which is precisely why the configuration used to come back empty.
 */
class restore_openaiagent_block_structure_step extends restore_structure_step {
    /** @var string Mapping name linking the old profile to the restored one. */
    const MAPPING = 'openaiagent_profile';

    /** @var string Mapping name of the configuration row itself, used by the link decoder. */
    const MAPPING_CONFIG = 'openaiagent_config';

    /** @var int Context id the knowledge-base files were stored in at backup time. */
    protected $oldfilescontextid = 0;

    /** @var bool Whether a configuration row was actually restored. */
    protected $profilerestored = false;

    /**
     * Only run when the backup actually carries a profile file.
     *
     * Backups taken before this feature existed have no openaiagent.xml, and a
     * structure step whose file is missing aborts the whole restore. Restoring
     * an older course copy must still work — it simply arrives without a
     * profile, exactly as it does today.
     *
     * @return bool
     */
    protected function execute_condition() {
        return file_exists($this->task->get_taskbasepath() . '/' . $this->filename);
    }

    /**
     * Paths to process.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        return [
            new restore_path_element('openaiagent', '/block/openaiagent'),
            new restore_path_element('openaiagent_config', '/block/openaiagent/config'),
            new restore_path_element('openaiagent_tool', '/block/openaiagent/tools/tool'),
        ];
    }

    /**
     * Read the profile header (where its files used to live).
     *
     * @param array|object $data Parsed element.
     * @return void
     */
    public function process_openaiagent($data) {
        $data = (object)$data;
        $this->oldfilescontextid = (int)($data->filescontextid ?? 0);
    }

    /**
     * Restore the per-course configuration row.
     *
     * @param array|object $data Parsed element.
     * @return void
     */
    public function process_openaiagent_config($data) {
        global $DB;

        $data = (object)$data;
        $courseid = (int)$this->get_courseid();
        $blockid = (int)$this->task->get_blockid();
        if ($blockid <= 0 || $courseid <= 0) {
            return;
        }

        // Same whitelist the configuration form writes through, so a restored
        // profile can never carry a column the UI cannot edit back.
        $fields = [
            'enabled', 'tutorenabled', 'assistantenabled',
            'routeragentid', 'tutoragentid', 'assistantagentid', 'ambiguityagentid',
            'courseprompt', 'assistantprompt', 'assistantfaqs', 'routerprompt',
            'modeloverride', 'temperatureoverride', 'maxoutputtokensoverride',
            'languagepolicy', 'evaluationpolicy', 'citabledocuments', 'internaldocuments',
            'protectedactivities', 'fallbacknoinfo', 'fallbackoutofscope',
            'fallbackevaluationblock', 'storeconversations',
            'supportmode', 'supportemailto', 'supportemailcc', 'supportcategorymap',
            'supportsubject', 'supportbody', 'supportsignature', 'supportslatext',
            'supportincludetranscript', 'supportcopytouser',
        ];
        $values = [];
        foreach ($fields as $field) {
            if (isset($data->$field)) {
                $values[$field] = $data->$field;
            }
        }

        // Agent records are site-level, so their ids mean nothing on another
        // site (and a matching id could even point at an agent of a different
        // type). An id that does not resolve locally is reset to 0, which the
        // repository reads as "use the default agent for this route" rather than
        // leaving a dangling reference behind.
        $agentroutes = [
            'routeragentid' => 'router',
            'tutoragentid' => 'tutor',
            'assistantagentid' => 'assistant',
            'ambiguityagentid' => 'ambiguity',
        ];
        foreach ($agentroutes as $field => $agenttype) {
            $agentid = (int)($values[$field] ?? 0);
            $exists = $agentid > 0 && $DB->record_exists('block_openaiagent_agents', [
                'id' => $agentid,
                'agenttype' => $agenttype,
            ]);
            if (!$exists) {
                $values[$field] = 0;
            }
        }

        // Support destinations are institution-specific. Carrying them into
        // another site would quietly point a restored course at the previous
        // institution's help desk, which is both confusing and a way for one
        // organisation's participant data to reach another's mailbox. The
        // wording (templates, signature, response time) is portable and stays.
        //
        // When the origin cannot be established, treat the restore as
        // cross-site: dropping an address on a same-site duplicate is a visible
        // annoyance that falls back to the site default, while keeping one on a
        // cross-site restore is a silent data leak.
        if (!$this->restoring_into_origin_site()) {
            foreach (['supportemailto', 'supportemailcc', 'supportcategorymap'] as $field) {
                unset($values[$field]);
            }
        }

        course_config::save($courseid, $values, $blockid);
        $this->profilerestored = true;

        // The knowledge-base files were stored under itemid = old block instance
        // id; this mapping is what re-points them at the new one.
        $this->set_mapping(self::MAPPING, (int)($data->blockinstanceid ?? 0), $blockid, true);

        // The prompts may contain links to the plugin's own configuration page,
        // which the backup replaced with a site-independent placeholder. The
        // decoder only reaches rows it can find through a mapping, so the saved
        // row is mapped by its own id; without this the restored prompts keep
        // the raw placeholder text instead of a working link.
        $configid = (int)$DB->get_field('block_openaiagent_courseconfig', 'id', [
            'courseid' => $courseid,
            'blockinstanceid' => $blockid,
        ]);
        if ($configid > 0) {
            $this->set_mapping(self::MAPPING_CONFIG, (int)($data->id ?? 0), $configid);
        }
    }

    /**
     * Whether this restore lands on the same site the backup came from.
     *
     * @return bool True only when the origin is known and matches this site.
     */
    private function restoring_into_origin_site(): bool {
        global $CFG;

        $info = $this->task->get_info();
        $origin = isset($info->original_wwwroot) ? (string)$info->original_wwwroot : '';
        if ($origin === '') {
            return false;
        }

        return rtrim($origin, '/') === rtrim((string)$CFG->wwwroot, '/');
    }

    /**
     * Restore one enabled/disabled MCP tool row.
     *
     * @param array|object $data Parsed element.
     * @return void
     */
    public function process_openaiagent_tool($data) {
        global $DB;

        $data = (object)$data;
        $courseid = (int)$this->get_courseid();
        $blockid = (int)$this->task->get_blockid();
        $toolname = (string)($data->toolname ?? '');
        if ($blockid <= 0 || $courseid <= 0 || $toolname === '') {
            return;
        }

        $key = [
            'courseid' => $courseid,
            'blockinstanceid' => $blockid,
            'toolname' => $toolname,
        ];
        $now = time();
        $existing = $DB->get_record('block_openaiagent_coursetools', $key);
        if ($existing) {
            // Restoring into a course that already has this profile row (an
            // import rather than a copy): the incoming selection wins.
            $DB->update_record('block_openaiagent_coursetools', (object)[
                'id' => $existing->id,
                'enabled' => (int)($data->enabled ?? 1),
                'timemodified' => $now,
            ]);
            return;
        }
        $DB->insert_record('block_openaiagent_coursetools', (object)($key + [
            'enabled' => (int)($data->enabled ?? 1),
            'requirescapability' => (string)($data->requirescapability ?? ''),
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]));
    }

    /**
     * Move the knowledge-base documents across and queue their re-indexing.
     *
     * @return void
     */
    protected function after_execute() {
        if (!$this->profilerestored) {
            return;
        }

        $courseid = (int)$this->get_courseid();
        $blockid = (int)$this->task->get_blockid();

        // These files are not where the block-restore machinery expects a
        // block's files to be (block context, itemid 0): they live in the
        // course/category context under itemid = block instance id. Both the
        // source and destination contexts are therefore passed explicitly.
        if ($this->oldfilescontextid > 0) {
            $newfilescontextid = (int)tutordocs::file_context($courseid, $blockid)->id;
            foreach (tutordocs::areas() as $area) {
                try {
                    restore_dbops::send_files_to_pool(
                        $this->get_basepath(),
                        $this->get_restoreid(),
                        tutordocs::COMPONENT,
                        $area,
                        $this->oldfilescontextid,
                        $this->task->get_userid(),
                        self::MAPPING,
                        null,
                        $newfilescontextid,
                        true
                    );
                } catch (\Throwable $e) {
                    // A knowledge base that fails to travel is recoverable (the
                    // teacher re-uploads it); aborting the whole course restore
                    // over it is not. The text configuration is already saved.
                    debugging('block_openaiagent: could not restore knowledge-base files for area '
                        . $area . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }

        // Chunks and embeddings are a derived index keyed by the old profile, so
        // they are never copied: the restored profile builds its own from the
        // documents that just landed.
        index_tutordocs_task::queue($courseid, $blockid);
    }
}
