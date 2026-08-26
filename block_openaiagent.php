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
 * Block openaiagent main class.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Main block class for Smart Tutor & Support AI.
 */
class block_openaiagent extends block_base {
    /**
     * Initializes the block.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_openaiagent');
    }

    /**
     * Returns the block content.
     *
     * @return stdClass The block content.
     */
    public function get_content() {
        global $USER, $OUTPUT;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        // Each block instance is an independent assistant profile, keyed by its
        // id. The course id used to resolve that profile is derived from the
        // block's own context (course/category/site), NOT from the page it is
        // rendered on, so a category assistant shown across a category's courses
        // (via "display on subcontexts") always resolves the same profile and
        // history instead of splitting per viewing course.
        $blockinstanceid = (int)$this->instance->id;
        $courseid = \block_openaiagent\local\course_config::owning_courseid(
            $blockinstanceid,
            (int)$this->page->course->id
        );

        // Check if user has permission to use the chat. The capability is course-scoped.
        $context = \context_course::instance($courseid);
        if (!has_capability('block/openaiagent:use', $context)) {
            return $this->content;
        }

        // License gate. Without a valid key bound to this site the assistant is
        // blocked: a message is shown instead of the chat UI, and the
        // orchestrator refuses to run as a backstop. A site administrator can
        // still reach the plugin settings to paste a key.
        if (!\block_openaiagent\license\validator::is_valid()) {
            // Only surface the specific license reason to staff who can act on
            // it; students just see the generic unavailable notice.
            $banner = has_capability('block/openaiagent:managecourseconfig', $context)
                ? \block_openaiagent\license\validator::get_banner()
                : get_string('error_notconfigured', 'block_openaiagent');
            $this->content->text = $OUTPUT->notification($banner, 'warning');
            return $this->content;
        }

        // Check that the plugin is configured and enabled for this block instance.
        if (
            !$this->is_configured()
            || !\block_openaiagent\local\course_config::is_enabled($courseid, $blockinstanceid)
        ) {
            $this->content->text = $OUTPUT->notification(
                get_string('error_notconfigured', 'block_openaiagent'),
                'warning'
            );
            return $this->content;
        }

        // Get cosmetic configuration.
        $botname = !empty($this->config->botname)
            ? $this->config->botname
            : get_string('default_botname', 'block_openaiagent');
        $welcomemessage = !empty($this->config->welcomemessage)
            ? $this->config->welcomemessage
            : get_string('default_welcomemessage', 'block_openaiagent');
        $cardsubtitle = !empty($this->config->cardsubtitle)
            ? $this->config->cardsubtitle
            : get_string('default_cardsubtitle', 'block_openaiagent');
        $avatarurl = !empty($this->config->avatarurl)
            ? clean_param($this->config->avatarurl, PARAM_URL)
            : '';

        // Prepare data for template.
        $data = [
            'blockid' => $this->instance->id,
            'courseid' => $courseid,
            'botname' => format_string($botname),
            'welcomemessage' => format_string($welcomemessage),
            'cardsubtitle' => format_string($cardsubtitle),
            'avatarurl' => $avatarurl,
            'str_greeting' => get_string('greeting', 'block_openaiagent', format_string($USER->firstname)),
            // Pre-processed strings for template.
            'str_openchat' => get_string('openchat', 'block_openaiagent'),
            'str_closechat' => get_string('closechat', 'block_openaiagent'),
            'str_sendmessage' => get_string('sendmessage', 'block_openaiagent'),
            'str_typemessage' => get_string('typemessage', 'block_openaiagent'),
            'str_thinking' => get_string('thinking', 'block_openaiagent'),
            'str_newconversation' => get_string('newconversation', 'block_openaiagent'),
        ];

        // Configuration passed to the AMD module.
        $jsconfig = [
            'blockid' => $this->instance->id,
            'courseid' => $courseid,
            'avatarurl' => $avatarurl,
            'strings' => [
                'thinking' => get_string('thinking', 'block_openaiagent'),
                'error_openai_failed' => get_string('error_openai_failed', 'block_openaiagent'),
                'error_emptymessage' => get_string('error_emptymessage', 'block_openaiagent'),
            ],
        ];

        // Load JS module.
        $this->page->requires->js_call_amd('block_openaiagent/chat', 'init', [$jsconfig]);

        // Render the block content.
        $this->content->text = $OUTPUT->render_from_template('block_openaiagent/block', $data);

        return $this->content;
    }

    /**
     * Checks if the plugin is globally configured and enabled.
     *
     * @return bool True if configured, false otherwise.
     */
    private function is_configured(): bool {
        if ((int)get_config('block_openaiagent', 'enabled') !== 1) {
            return false;
        }
        $apikey = get_config('block_openaiagent', 'apikey');
        return !empty($apikey);
    }

    /**
     * Allow multiple instances of this block.
     *
     * @return bool True to allow multiple instances.
     */
    public function instance_allow_multiple() {
        return true;
    }

    /**
     * Clean up this assistant profile when the block instance is deleted.
     *
     * Since the multi-assistant release every configuration subsystem is keyed by
     * the block instance id, so deleting the block must remove its configuration,
     * enabled tools, knowledge-base chunks and files, and — importantly for
     * privacy — its stored conversations and messages, rather than orphaning them.
     *
     * @return bool
     */
    public function instance_delete() {
        global $DB;

        $blockid = (int)$this->instance->id;
        if ($blockid <= 0) {
            return true;
        }

        // Conversations and their messages (may contain personal data).
        $conversationids = $DB->get_fieldset_select(
            'block_openaiagent_conversations',
            'id',
            'blockinstanceid = ?',
            [$blockid]
        );
        if (!empty($conversationids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($conversationids);
            $DB->delete_records_select('block_openaiagent_messages', "conversationid $insql", $inparams);
        }
        $DB->delete_records('block_openaiagent_conversations', ['blockinstanceid' => $blockid]);

        // Configuration, enabled tools and the local knowledge-base chunk index.
        $DB->delete_records('block_openaiagent_courseconfig', ['blockinstanceid' => $blockid]);
        $DB->delete_records('block_openaiagent_coursetools', ['blockinstanceid' => $blockid]);
        $DB->delete_records('block_openaiagent_chunks', ['blockinstanceid' => $blockid]);

        // Uploaded knowledge-base files (stored in the profile's scope context
        // under itemid = block instance id). The block's own context is removed by
        // core, but these files live in the course/category context, so remove
        // them explicitly. This runs before the block_instances row is deleted, so
        // the scope context is still resolvable.
        $context = \block_openaiagent\local\tutordocs::file_context((int)SITEID, $blockid);
        $fs = get_file_storage();
        foreach (\block_openaiagent\local\tutordocs::areas() as $area) {
            $fs->delete_area_files($context->id, \block_openaiagent\local\tutordocs::COMPONENT, $area, $blockid);
        }

        return true;
    }

    /**
     * This block has global configuration.
     *
     * @return bool True.
     */
    public function has_config() {
        return true;
    }

    /**
     * Allow instance configuration.
     *
     * @return bool True to allow instance configuration.
     */
    public function instance_allow_config() {
        return true;
    }

    /**
     * Define where the block can be added.
     *
     * @return array Applicable formats.
     */
    public function applicable_formats() {
        // Outside a real course (site home, category pages, dashboard) the block
        // runs against the site course: the tutor/FAQ side works normally, while
        // MCP course tools should be disabled in that course config since there
        // is no course data to query.
        //
        // Note: applicable_formats keys are matched against the page *pagetype*.
        // The category listing page (/course/index.php?categoryid=N) has pagetype
        // "course-index-category", which the bare "category" key does not match, so
        // it is declared explicitly here to make the block addable on category pages.
        return [
            'all' => false,
            'course-view' => true,
            'site' => true,
            'category' => true,
            'course-index-category' => true,
            'mod' => true,
            'my' => true,
        ];
    }

    /**
     * Specialize the block title.
     */
    public function specialization() {
        if (!empty($this->config->botname)) {
            $this->title = format_string($this->config->botname);
        }
    }
}
