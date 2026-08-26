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
 * Restore task for block_openaiagent.
 *
 * @package    block_openaiagent
 * @copyright  2026 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/openaiagent/backup/moodle2/restore_openaiagent_stepslib.php');

/**
 * Restores the assistant profile of a block instance.
 */
class restore_openaiagent_block_task extends restore_block_task {
    /**
     * No task-specific settings.
     */
    protected function define_my_settings() {
    }

    /**
     * Add the structure step that reads the profile.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_openaiagent_block_structure_step('openaiagent_structure', 'openaiagent.xml'));
    }

    /**
     * File areas owned by the block context (none: see the backup task).
     *
     * @return array
     */
    public function get_fileareas() {
        return [];
    }

    /**
     * Config attributes holding encoded content.
     *
     * @return array
     */
    public function get_configdata_encoded_attributes() {
        return [];
    }

    /**
     * Contents to be processed by the link decoder.
     *
     * The teacher-authored prompts are the only restored fields that can carry
     * an encoded link, and they are reached through the mapping the structure
     * step records for the configuration row it just wrote.
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        return [
            new restore_decode_content(
                'block_openaiagent_courseconfig',
                [
                    'courseprompt', 'assistantprompt', 'assistantfaqs', 'routerprompt',
                    'fallbacknoinfo', 'fallbackoutofscope', 'fallbackevaluationblock',
                ],
                restore_openaiagent_block_structure_step::MAPPING_CONFIG
            ),
        ];
    }

    /**
     * Link decoding rules.
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule(
                'OPENAIAGENTCOURSECONFIG',
                '/blocks/openaiagent/courseconfig.php?courseid=$1',
                'course'
            ),
        ];
    }
}
