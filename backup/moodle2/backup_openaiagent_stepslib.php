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
 * Backup structure step for block_openaiagent.
 *
 * @package    block_openaiagent
 * @copyright  2026 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use block_openaiagent\local\course_config;
use block_openaiagent\local\tutordocs;

/**
 * Writes the assistant profile of one block instance into the backup.
 *
 * Scope is deliberately the teacher's configuration, not the runtime state:
 * conversations and messages are personal data that must not travel with a
 * course copy, and the knowledge-base chunks with their embeddings are a
 * derived index that the restore rebuilds from the documents themselves.
 */
class backup_openaiagent_block_structure_step extends backup_block_structure_step {
    /**
     * Define the profile structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $blockid = (int)$this->task->get_blockid();
        $courseid = (int)$this->task->get_courseid();
        $filescontextid = (int)$this->task->get_contextid();

        // The profile is keyed by the course the block instance belongs to,
        // which the plugin derives from the block's own context rather than
        // from the page it is displayed on, and the knowledge-base documents
        // live in that course (or category) context under itemid = block
        // instance id, so the context has to be recorded explicitly: it is not
        // the block context the restore would assume.
        //
        // Neither lookup may abort the backup. A course copy failing because an
        // assistant profile could not be read would block the whole site's
        // backups, so a broken context degrades to "no profile in this backup"
        // instead: the block context and the plan's course id hold no profile
        // rows or documents, and the restore simply finds nothing to rebuild.
        try {
            $courseid = course_config::owning_courseid($blockid, $courseid);
            $filescontextid = (int)tutordocs::file_context($courseid, $blockid)->id;
        } catch (\Throwable $e) {
            debugging('block_openaiagent: could not resolve the profile of block instance '
                . $blockid . ' for backup: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        $openaiagent = new backup_nested_element('openaiagent', ['id'], ['filescontextid']);

        $config = new backup_nested_element('config', ['id'], [
            'blockinstanceid', 'enabled', 'tutorenabled', 'assistantenabled',
            'routeragentid', 'tutoragentid', 'assistantagentid', 'ambiguityagentid',
            'courseprompt', 'assistantprompt', 'assistantfaqs', 'routerprompt',
            'modeloverride', 'temperatureoverride', 'maxoutputtokensoverride',
            'languagepolicy', 'evaluationpolicy', 'citabledocuments', 'internaldocuments',
            'protectedactivities', 'fallbacknoinfo', 'fallbackoutofscope',
            'fallbackevaluationblock', 'storeconversations',
            'supportmode', 'supportemailto', 'supportemailcc', 'supportcategorymap',
            'supportsubject', 'supportbody', 'supportsignature', 'supportslatext',
            'supportincludetranscript', 'supportcopytouser',
        ]);

        $tools = new backup_nested_element('tools');
        $tool = new backup_nested_element('tool', ['id'], [
            'toolname', 'enabled', 'requirescapability',
        ]);

        $openaiagent->add_child($config);
        $openaiagent->add_child($tools);
        $tools->add_child($tool);

        $openaiagent->set_source_array([
            (object)['id' => $blockid, 'filescontextid' => $filescontextid],
        ]);

        // Only this block instance's own profile. A legacy course-wide row
        // (blockinstanceid = 0) is never read by a real block instance, so
        // copying it here would give the restored assistant a configuration the
        // original one did not use.
        //
        // Literal values must be wrapped in backup_helper::is_sqlparam(): the
        // backup structure API reads any other non-negative value as the *path
        // of another element* to take the value from, so a plain id makes it
        // look for an element named e.g. "3873" and throw
        // baseelementincorrectfinalorattribute, aborting the whole course
        // backup. Only the negative backup::VAR_* constants are literals.
        $config->set_source_table('block_openaiagent_courseconfig', [
            'courseid' => \backup_helper::is_sqlparam($courseid),
            'blockinstanceid' => \backup_helper::is_sqlparam($blockid),
        ]);

        $tool->set_source_table('block_openaiagent_coursetools', [
            'courseid' => \backup_helper::is_sqlparam($courseid),
            'blockinstanceid' => \backup_helper::is_sqlparam($blockid),
        ]);

        // The uploaded knowledge base, annotated against its real storage
        // context and against the block instance id used as itemid.
        foreach (tutordocs::areas() as $area) {
            $config->annotate_files(tutordocs::COMPONENT, $area, 'blockinstanceid', $filescontextid);
        }

        return $this->prepare_block_structure($openaiagent);
    }
}
