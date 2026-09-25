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
 * Tests for the per-course configuration form.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\form;

use block_openaiagent\local\defaults;

/**
 * Unit tests for the course configuration form helpers.
 *
 * @covers \block_openaiagent\form\course_config_form
 */
final class course_config_form_test extends \advanced_testcase {
    /**
     * Tool checkbox names must survive a form POST unchanged.
     *
     * PHP rewrites dots (and spaces) in top-level request variable names to
     * underscores, so an element named after the raw tool name never receives
     * its submitted value and the selection cannot be changed. parse_str()
     * applies the same mangling, which makes this an exact regression test.
     */
    public function test_tool_element_names_survive_a_post(): void {
        foreach (defaults::default_tool_names() as $toolname) {
            $element = course_config_form::tool_element_name($toolname);

            $parsed = [];
            parse_str($element . '=1', $parsed);

            $this->assertArrayHasKey(
                $element,
                $parsed,
                'Element name "' . $element . '" is rewritten by PHP when submitted.'
            );
        }
    }

    /**
     * Every tool maps to a distinct element name, so no two checkboxes collide.
     */
    public function test_tool_element_names_are_unique(): void {
        $names = array_map(
            [course_config_form::class, 'tool_element_name'],
            defaults::default_tool_names()
        );

        $this->assertSame(count($names), count(array_unique($names)));
    }

    /**
     * Platform tool names survive a POST too, and never collide with course tools.
     */
    public function test_platform_tool_element_names(): void {
        $names = array_merge(\block_openaiagent\mcp\platform\registry::default_names(), defaults::default_tool_names());
        $elements = array_map([course_config_form::class, 'tool_element_name'], $names);
        $this->assertSame(count($elements), count(array_unique($elements)));
        foreach ($elements as $element) {
            $parsed = [];
            parse_str($element . '=1', $parsed);
            $this->assertArrayHasKey($element, $parsed);
        }
    }

    /**
     * Each scope gets its own defaults and tool list; the course form is unchanged.
     */
    public function test_form_follows_the_scope(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $build = function (string $scopetype): \MoodleQuickForm {
            $form = new course_config_form(null, [
                'courseid' => SITEID,
                'blockinstanceid' => 0,
                'scopetype' => $scopetype,
            ]);
            $property = new \ReflectionProperty(\moodleform::class, '_form');
            $property->setAccessible(true);
            return $property->getValue($form);
        };
        $default = static fn(\MoodleQuickForm $mform, string $name) => $mform->getElement($name)->getValue();

        $course = $build('course');
        $this->assertSame(defaults::ASSISTANT_PROMPT, $default($course, 'assistantprompt'));
        $this->assertTrue($course->elementExists('tool_moodle__get_course_outline'));
        $this->assertFalse($course->elementExists('tool_moodle__get_my_courses'));

        $category = $build('category');
        $this->assertSame(defaults::PLATFORM_ASSISTANT_PROMPT, $default($category, 'assistantprompt'));
        $this->assertSame(defaults::PLATFORM_ROUTER_PROMPT, $default($category, 'routerprompt'));
        $this->assertTrue($category->elementExists('tool_moodle__get_my_courses'));
        $this->assertTrue($category->elementExists('tool_moodle__get_course_outline'));

        $site = $build('site');
        $this->assertTrue($site->elementExists('tool_moodle__search_catalog'));
        $this->assertFalse($site->elementExists('tool_moodle__get_course_outline'));
    }
}
