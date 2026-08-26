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
 * Library hooks for block_openaiagent.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Build a configuration navigation node for one assistant (block instance).
 *
 * @param stdClass $instance Block instance record.
 * @param bool $single Whether it is the only assistant in this scope.
 * @param int $courseidforlink Course id to carry in the link (SITEID for
 *                             category/site scopes; the config page re-derives
 *                             the real scope from the block instance id).
 * @return navigation_node
 */
function block_openaiagent_config_nav_node(stdClass $instance, bool $single, int $courseidforlink) {
    $label = get_string('courseconfig', 'block_openaiagent');
    if (!$single) {
        // Disambiguate by the assistant's configured display name (falling back
        // to its instance id) so each menu entry is identifiable.
        $name = '#' . $instance->id;
        if (!empty($instance->configdata)) {
            $bconfig = unserialize_object(base64_decode($instance->configdata));
            if (is_object($bconfig) && !empty($bconfig->botname)) {
                $name = format_string($bconfig->botname);
            }
        }
        $label = get_string('courseconfig_menu', 'block_openaiagent', $name);
    }

    $url = new moodle_url('/blocks/openaiagent/courseconfig.php', [
        'courseid' => $courseidforlink,
        'bid' => (int)$instance->id,
    ]);
    return navigation_node::create(
        $label,
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'block_openaiagent_courseconfig_' . (int)$instance->id,
        new pix_icon('i/settings', '')
    );
}

/**
 * Add a per-course assistant configuration link to the course navigation.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The course.
 * @param context_course $context The course context.
 * @return void
 */
function block_openaiagent_extend_navigation_course($navigation, $course, $context) {
    global $DB;

    if (!has_capability('block/openaiagent:managecourseconfig', $context)) {
        return;
    }

    // Each block instance of this plugin in the course is an independently
    // configured assistant, so expose one configuration link per instance. This
    // lets a course host, say, a technical-support bot and a subject-matter tutor
    // side by side, each with its own prompts, tools and knowledge base.
    $instances = $DB->get_records('block_instances', [
        'blockname' => 'openaiagent',
        'parentcontextid' => $context->id,
    ], 'id ASC');

    if (empty($instances)) {
        // No block instance lives directly in the course context (it may sit on a
        // module or dashboard page). Fall back to the single course-wide profile so
        // the configuration stays reachable from the course navigation.
        $url = new moodle_url('/blocks/openaiagent/courseconfig.php', ['courseid' => $course->id, 'bid' => 0]);
        $navigation->add_node(navigation_node::create(
            get_string('courseconfig', 'block_openaiagent'),
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'block_openaiagent_courseconfig',
            new pix_icon('i/settings', '')
        ));
        return;
    }

    $single = count($instances) === 1;
    foreach ($instances as $instance) {
        $navigation->add_node(block_openaiagent_config_nav_node($instance, $single, (int)$course->id));
    }
}

/**
 * Add the assistant configuration link to the site home (frontpage) navigation.
 *
 * This covers the site-course configuration used when the block is placed on
 * site-level pages (site home, category pages, dashboard).
 *
 * @param navigation_node $navigation The frontpage navigation node.
 * @param stdClass $course The site course.
 * @param context_course $context The site course context.
 * @return void
 */
function block_openaiagent_extend_navigation_frontpage($navigation, $course, $context) {
    block_openaiagent_extend_navigation_course($navigation, $course, $context);
}

/**
 * Add per-assistant configuration links to a category's settings navigation.
 *
 * A block instance placed in a category configures a category-wide assistant
 * (its own prompts, tools and knowledge base), so each such instance gets its
 * own configuration link in the category settings menu.
 *
 * @param navigation_node $navigation The category settings navigation node.
 * @param context_coursecat $context The category context.
 * @return void
 */
function block_openaiagent_extend_navigation_category_settings($navigation, $context) {
    global $DB;

    if (!has_capability('block/openaiagent:managecourseconfig', $context)) {
        return;
    }

    $instances = $DB->get_records('block_instances', [
        'blockname' => 'openaiagent',
        'parentcontextid' => $context->id,
    ], 'id ASC');
    if (empty($instances)) {
        return;
    }

    // Category assistants have no owning course, so the link carries the site
    // course id; courseconfig.php re-derives the real (category) scope from the
    // block instance id.
    $single = count($instances) === 1;
    foreach ($instances as $instance) {
        $navigation->add_node(block_openaiagent_config_nav_node($instance, $single, (int)SITEID));
    }
}

/**
 * Serve the tutor knowledge-base files uploaded in the course configuration.
 *
 * Only users who can manage the course assistant configuration may download
 * them: students interact with the documents through the tutor, never directly.
 *
 * @param stdClass $course The course object.
 * @param stdClass $birecord Block instance record (unused; files live in the course/category context).
 * @param context $context The context.
 * @param string $filearea The file area.
 * @param array $args Remaining file path arguments.
 * @param bool $forcedownload Whether the file should be downloaded.
 * @param array $options Additional options affecting file serving.
 * @return void False is never returned: send_file_not_found() throws.
 */
function block_openaiagent_pluginfile($course, $birecord, $context, $filearea, $args, $forcedownload, array $options = []) {
    // Course/site assistants store their knowledge base in a course context;
    // category assistants store it in the category context.
    if ($context->contextlevel !== CONTEXT_COURSE && $context->contextlevel !== CONTEXT_COURSECAT) {
        send_file_not_found();
    }
    $validareas = [
        \block_openaiagent\local\tutordocs::AREA_CITABLE,
        \block_openaiagent\local\tutordocs::AREA_INTERNAL,
    ];
    if (!in_array($filearea, $validareas, true)) {
        send_file_not_found();
    }

    require_login($course);
    require_capability('block/openaiagent:managecourseconfig', $context);

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = empty($args) ? '/' : ('/' . implode('/', $args) . '/');

    $fs = get_file_storage();
    $file = $fs->get_file(
        $context->id,
        \block_openaiagent\local\tutordocs::COMPONENT,
        $filearea,
        $itemid,
        $filepath,
        $filename
    );
    if (!$file || $file->is_directory()) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}
