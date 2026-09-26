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
        $scope = \block_openaiagent\local\scope::for_block(
            $blockinstanceid,
            (int)$USER->id,
            (int)$this->page->course->id
        );
        $courseid = $scope->courseid;

        // Dashboard blocks are copied per user by Moodle with new ids, and every
        // assistant profile is keyed by block id, so each copy would start empty.
        // Only the people who can move it are told; everyone else sees nothing.
        // On the Dashboard that is its owner, through moodle/my:manageblocks,
        // which the page's own check knows about and moodle/block:edit does not.
        if ($scope->type === \block_openaiagent\local\scope::DASHBOARD) {
            if ($this->page->user_can_edit_blocks()) {
                $this->content->text = $OUTPUT->notification(
                    get_string('error_dashboardunsupported', 'block_openaiagent'),
                    'info'
                );
            }
            return $this->content;
        }

        // The enrolment page is allowed only for category assistants. A course
        // assistant never showed there, and its documents and tools are for the
        // course's participants, not for someone deciding whether to enrol.
        if ($scope->is_course() && strpos((string)$this->page->pagetype, 'enrol-') === 0) {
            return $this->content;
        }

        // A visitor who is not logged in, or a guest, on a category or site
        // assistant. Everything below is for logged-in users and is unchanged.
        if ($scope->is_platform() && $scope->is_visitor()) {
            return $this->visitor_content($scope);
        }

        // Check if user has permission to use the chat. A course assistant checks
        // the course context, as always; a category or site assistant checks its
        // own block context, so role overrides on the category apply to it.
        $context = $scope->context;
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

        // Moodle 5.1+ "Allow AI tools for this course". Both the course the page
        // belongs to and the block's owning course are checked: a category
        // assistant shown inside a course must respect that course's switch.
        $pagecourseid = (int)$this->page->course->id;
        if (
            !\block_openaiagent\local\course_config::core_ai_allows($pagecourseid)
            || !\block_openaiagent\local\course_config::core_ai_allows($courseid)
        ) {
            $notice = has_capability('block/openaiagent:managecourseconfig', $context)
                ? get_string('aitoolsdisabled_staff', 'block_openaiagent')
                : get_string('error_aitoolsdisabled', 'block_openaiagent');
            $this->content->text = $OUTPUT->notification($notice, 'info');
            return $this->content;
        }

        return $this->chat_content($courseid, $scope->pagecourseid, null);
    }

    /**
     * Render the chat card and load its script.
     *
     * @param int $courseid Owning course id.
     * @param int $pagecourseid Validated course of the page (0 = none).
     * @param array|null $visitor Visitor settings for the browser, or null for a logged-in user.
     * @return stdClass The block content.
     */
    private function chat_content(int $courseid, int $pagecourseid, ?array $visitor): stdClass {
        global $USER, $OUTPUT;

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
            'str_greeting' => $visitor === null
                ? get_string('greeting', 'block_openaiagent', format_string($USER->firstname))
                : get_string('greeting_visitor', 'block_openaiagent'),
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
            // Only a category block shown inside one of its courses sets this, and
            // the server validates it again on every request.
            'pagecourseid' => $pagecourseid,
            'avatarurl' => $avatarurl,
            'strings' => [
                'thinking' => get_string('thinking', 'block_openaiagent'),
                'error_openai_failed' => get_string('error_openai_failed', 'block_openaiagent'),
                'error_emptymessage' => get_string('error_emptymessage', 'block_openaiagent'),
            ],
        ];

        // A visitor's chat talks to the no-login endpoint with the page token,
        // captcha settings and message limit the server issued for this page.
        if ($visitor !== null) {
            $jsconfig['visitor'] = $visitor;
        }

        // Load JS module.
        $this->page->requires->js_call_amd('block_openaiagent/chat', 'init', [$jsconfig]);

        // Render the block content.
        $this->content->text = $OUTPUT->render_from_template('block_openaiagent/block', $data);

        return $this->content;
    }

    /**
     * The block for a visitor who is not logged in.
     *
     * Shown only on a category or site assistant that an administrator opened
     * to visitors, on a site that does not force login. Otherwise, as before,
     * a visitor sees nothing.
     *
     * @param \block_openaiagent\local\scope $scope Visitor scope.
     * @return stdClass The block content.
     */
    private function visitor_content(\block_openaiagent\local\scope $scope): stdClass {
        if (!\block_openaiagent\local\visitor_chat::available($scope)) {
            return $this->content;
        }
        return $this->chat_content($scope->courseid, $scope->pagecourseid, [
            'pagetoken' => \block_openaiagent\local\visitor_guard::issue_page_token((int)$this->instance->id),
            'captcha' => \block_openaiagent\local\visitor_guard::captcha(),
            'maxchars' => \block_openaiagent\local\visitor_guard::setting('visitor_max_chars', 500, 50),
        ]);
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
     * Save the block settings, keeping the visitor switch out of reach.
     *
     * Moodle replaces the whole configuration with what the form submitted.
     * The visitor switch is only shown to those who may open the assistant to
     * visitors, so for anyone else it would vanish from the submission and be
     * switched off, or, worse, could be forged on. For them the stored value is
     * kept, whatever arrives.
     *
     * @param stdClass $data Submitted configuration.
     * @param bool $nolongerused Unused, kept for the parent signature.
     * @return bool
     */
    public function instance_config_save($data, $nolongerused = false) {
        if (!has_capability('block/openaiagent:managepublicaccess', $this->context)) {
            $current = isset($this->config->visitors) ? (int)$this->config->visitors : 0;
            $data->visitors = $current;
        }
        return parent::instance_config_save($data, $nolongerused);
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
            // The course enrolment page, so a category assistant shown throughout
            // its category can answer "how do I enrol in this course?" right there.
            'enrol-index' => true,
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
