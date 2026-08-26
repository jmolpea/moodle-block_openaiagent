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
 * Backup task for block_openaiagent.
 *
 * @package    block_openaiagent
 * @copyright  2026 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/openaiagent/backup/moodle2/backup_openaiagent_stepslib.php');

/**
 * Adds the assistant profile (configuration, tool selection and knowledge base)
 * to the block's backup.
 *
 * Core backs up a block instance's own settings (the visible name, the welcome
 * message), but everything a teacher configures for the assistant lives in the
 * plugin's own tables keyed by (course, block instance). Without this task a
 * restored course got a block instance with no profile at all, which is why a
 * course copy came back with an empty configuration.
 */
class backup_openaiagent_block_task extends backup_block_task {
    /**
     * No task-specific settings.
     */
    protected function define_my_settings() {
    }

    /**
     * Add the structure step that writes the profile.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_openaiagent_block_structure_step('openaiagent_structure', 'openaiagent.xml'));
    }

    /**
     * File areas owned by the block context.
     *
     * The knowledge-base documents are deliberately not listed here: they are
     * stored in the course (or category) context under itemid = block instance
     * id, not in the block context, so they are annotated explicitly by the
     * structure step instead.
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
     * Encode links to the plugin's pages so they survive a restore elsewhere.
     *
     * @param string $content Content to encode.
     * @return string Encoded content.
     */
    public static function encode_content_links($content) {
        global $CFG;

        // Moodle registers this encoder site-wide as soon as the plugin is
        // installed and runs it over every text field of every backup, so the
        // overwhelmingly common case — content with no link to this plugin —
        // must not pay for a regular expression.
        if (strpos($content, '/blocks/openaiagent/courseconfig.php') === false) {
            return $content;
        }

        $base = preg_quote($CFG->wwwroot, '/');

        // Per-course assistant configuration page.
        return preg_replace(
            '/(' . $base . '\/blocks\/openaiagent\/courseconfig\.php\?courseid=)([0-9]+)/',
            '$@OPENAIAGENTCOURSECONFIG*$2@$',
            $content
        );
    }
}
