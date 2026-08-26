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
 * Per-course assistant configuration page.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use block_openaiagent\form\course_config_form;
use block_openaiagent\local\course_config;
use block_openaiagent\local\defaults;
use block_openaiagent\local\tutordocs;
use block_openaiagent\task\index_tutordocs_task;

$courseid = required_param('courseid', PARAM_INT);
// Which assistant (block instance) is being configured. 0 = the course-wide
// default profile (legacy link); a positive id targets one specific block
// instance so a course (or category, or the site) can host several independently
// configured assistants.
$blockinstanceid = optional_param('bid', 0, PARAM_INT);

// Resolve the scope the assistant lives in from its block instance. A block
// placed in a course configures a course assistant; one placed in a category
// configures a category-wide assistant. Everything is still keyed by the block
// instance id, so profiles never collide, but the surrounding context (used for
// capability checks and knowledge-base file storage) follows the block's home.
if ($blockinstanceid > 0) {
    // A positive block id must be a real block instance of this plugin, so a
    // forged id cannot spawn orphan configuration rows or reach another context.
    $blockrecord = $DB->get_record(
        'block_instances',
        ['id' => $blockinstanceid, 'blockname' => 'openaiagent'],
        '*',
        MUST_EXIST
    );
    $blockcontext = context::instance_by_id($blockrecord->parentcontextid);
    $coursecontext = $blockcontext->get_course_context(false);
    // Course/module blocks key and store their data under the course; category
    // and site blocks have no owning course, so they key under the site course
    // but keep their own context for capability checks and file storage.
    $scopecontext = $coursecontext ?: $blockcontext;
    $courseid = $coursecontext ? (int)$coursecontext->instanceid : (int)SITEID;
} else {
    // Legacy course-wide profile (no specific block instance).
    $scopecontext = context_course::instance($courseid);
}

if ($scopecontext->contextlevel == CONTEXT_COURSE) {
    $course = get_course($scopecontext->instanceid);
    require_login($course);
} else {
    $course = get_course(SITEID);
    require_login();
}
$PAGE->set_context($scopecontext);
require_capability('block/openaiagent:managecourseconfig', $scopecontext);

$url = new moodle_url('/blocks/openaiagent/courseconfig.php', ['courseid' => $courseid, 'bid' => $blockinstanceid]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('courseconfig', 'block_openaiagent'));
$PAGE->set_heading($scopecontext->get_context_name(false));

$form = new course_config_form($url->out(false), ['courseid' => $courseid, 'blockinstanceid' => $blockinstanceid]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

// Prefill the form from stored data.
$raw = course_config::get_raw($courseid, $blockinstanceid);
$data = $raw !== null ? (array) $raw : ['courseid' => $courseid];

// Load the knowledge-base file areas into draft areas for the file managers.
// Files are stored under itemid = block instance id so each assistant keeps its
// own knowledge base.
$filemanageroptions = course_config_form::filemanager_options();
$docareas = ['tutordocs_citable' => tutordocs::AREA_CITABLE, 'tutordocs_internal' => tutordocs::AREA_INTERNAL];
foreach ($docareas as $field => $area) {
    $draftitemid = file_get_submitted_draft_itemid($field);
    file_prepare_draft_area($draftitemid, $scopecontext->id, tutordocs::COMPONENT, $area, $blockinstanceid, $filemanageroptions);
    $data[$field] = $draftitemid;
}

if ($raw !== null) {
    if ($raw->temperatureoverride === null) {
        $data['temperatureoverride'] = '';
    }
    if ($raw->maxoutputtokensoverride === null) {
        $data['maxoutputtokensoverride'] = 0;
    }
    // Show the built-in assistant prompt as an editable starting point when the
    // course has never customised it.
    if (trim((string)($data['assistantprompt'] ?? '')) === '') {
        $data['assistantprompt'] = defaults::ASSISTANT_PROMPT;
    }
    // Same for the router prompt: show the built-in default as the editable
    // starting point when the course has never customised it.
    if (trim((string)($data['routerprompt'] ?? '')) === '') {
        $data['routerprompt'] = defaults::ROUTER_PROMPT;
    }
    $enabledtools = array_fill_keys(course_config::enabled_tools($courseid, $blockinstanceid), true);
    foreach (defaults::default_tool_names() as $toolname) {
        $data[course_config_form::tool_element_name($toolname)] = isset($enabledtools[$toolname]) ? 1 : 0;
    }
}
$form->set_data($data);

if ($submitted = $form->get_data()) {
    $tools = [];
    foreach (defaults::default_tool_names() as $toolname) {
        $field = course_config_form::tool_element_name($toolname);
        if (!empty($submitted->$field)) {
            $tools[] = $toolname;
        }
    }

    // Minus one keeps meaning "inherit"; anything that is not a deliberate 0 or 1
    // collapses back to it.
    $supporttristate = static function ($value): int {
        $value = (int)$value;
        return ($value === 0 || $value === 1) ? $value : -1;
    };

    // The destination fields are frozen in the form for anyone without the
    // capability, and a frozen element submits nothing. Including them anyway
    // would blank a perfectly good address on every save by a teacher, so they
    // are only written by someone allowed to change them.
    $supportrecipients = [];
    if (has_capability('block/openaiagent:managesupportrecipient', $scopecontext)) {
        $supportrecipients = [
            'supportemailto' => (string)$submitted->supportemailto,
            'supportemailcc' => (string)$submitted->supportemailcc,
            'supportcategorymap' => (string)$submitted->supportcategorymap,
        ];
    }

    $tempraw = trim((string)$submitted->temperatureoverride);
    course_config::save($courseid, [
        'enabled' => (int)$submitted->enabled,
        'tutorenabled' => (int)$submitted->tutorenabled,
        'assistantenabled' => (int)$submitted->assistantenabled,
        'courseprompt' => $submitted->courseprompt,
        'assistantprompt' => $submitted->assistantprompt,
        'assistantfaqs' => $submitted->assistantfaqs,
        'routerprompt' => $submitted->routerprompt,
        'modeloverride' => trim((string)$submitted->modeloverride),
        'temperatureoverride' => $tempraw === '' ? null : (float)$tempraw,
        'maxoutputtokensoverride' => (int)$submitted->maxoutputtokensoverride > 0
            ? (int)$submitted->maxoutputtokensoverride : null,
        'languagepolicy' => $submitted->languagepolicy,
        'evaluationpolicy' => $submitted->evaluationpolicy,
        'citabledocuments' => $submitted->citabledocuments,
        'internaldocuments' => $submitted->internaldocuments,
        'protectedactivities' => $submitted->protectedactivities,
        'fallbacknoinfo' => $submitted->fallbacknoinfo,
        'fallbackoutofscope' => $submitted->fallbackoutofscope,
        'fallbackevaluationblock' => $submitted->fallbackevaluationblock,
        'storeconversations' => (int)$submitted->storeconversations,
        'supportmode' => in_array($submitted->supportmode, ['inherit', 'on', 'off'], true)
            ? $submitted->supportmode : 'inherit',
        'supportsubject' => $submitted->supportsubject,
        'supportbody' => $submitted->supportbody,
        'supportsignature' => $submitted->supportsignature,
        'supportslatext' => $submitted->supportslatext,
        'supportincludetranscript' => $supporttristate($submitted->supportincludetranscript),
        'supportcopytouser' => $supporttristate($submitted->supportcopytouser),
    ] + $supportrecipients, $blockinstanceid);
    course_config::save_tools($courseid, $tools, $blockinstanceid);

    // Persist the knowledge-base uploads and queue their (re)indexing.
    file_save_draft_area_files(
        $submitted->tutordocs_citable,
        $scopecontext->id,
        tutordocs::COMPONENT,
        tutordocs::AREA_CITABLE,
        $blockinstanceid,
        $filemanageroptions
    );
    file_save_draft_area_files(
        $submitted->tutordocs_internal,
        $scopecontext->id,
        tutordocs::COMPONENT,
        tutordocs::AREA_INTERNAL,
        $blockinstanceid,
        $filemanageroptions
    );
    index_tutordocs_task::queue($courseid, $blockinstanceid);

    redirect(
        $url,
        get_string('courseconfig_saved', 'block_openaiagent'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('courseconfig', 'block_openaiagent'));

// When a specific block instance is targeted, name it so a teacher configuring
// several assistants in one course always knows which one they are editing.
if ($blockinstanceid > 0) {
    $bi = $DB->get_record('block_instances', ['id' => $blockinstanceid]);
    $assistantlabel = '#' . $blockinstanceid;
    if ($bi && !empty($bi->configdata)) {
        $bconfig = unserialize_object(base64_decode($bi->configdata));
        if (is_object($bconfig) && !empty($bconfig->botname)) {
            $assistantlabel = format_string($bconfig->botname) . ' (#' . $blockinstanceid . ')';
        }
    }
    echo $OUTPUT->notification(
        get_string('courseconfig_forassistant', 'block_openaiagent', $assistantlabel),
        \core\output\notification::NOTIFY_INFO
    );
}

$form->display();
echo $OUTPUT->footer();
