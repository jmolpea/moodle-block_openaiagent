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
 * Tests for the tutor knowledge-base helpers.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for tutordocs scope resolution.
 *
 * @covers \block_openaiagent\local\tutordocs
 */
final class tutordocs_test extends \advanced_testcase {
    /**
     * Insert a bare block instance under a context and return its id.
     *
     * @param int $parentcontextid Owning context id.
     * @return int Block instance id.
     */
    private function make_block(int $parentcontextid): int {
        global $DB;
        return (int)$DB->insert_record('block_instances', (object) [
            'blockname' => 'openaiagent',
            'parentcontextid' => $parentcontextid,
            'showinsubcontexts' => 0,
            'requiredbytheme' => 0,
            'pagetypepattern' => '*',
            'defaultregion' => 'side-pre',
            'defaultweight' => 0,
            'configdata' => '',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * A block placed in a course stores its knowledge base in the course context.
     */
    public function test_file_context_course_block(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);
        $blockid = $this->make_block($coursecontext->id);

        $this->assertSame($coursecontext->id, tutordocs::file_context($course->id, $blockid)->id);
    }

    /**
     * A block placed in a category stores its knowledge base in the category
     * context (there is no owning course), which is the crux of category-wide
     * assistants.
     */
    public function test_file_context_category_block(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $catcontext = \context_coursecat::instance($category->id);
        $blockid = $this->make_block($catcontext->id);

        $ctx = tutordocs::file_context((int)SITEID, $blockid);
        $this->assertSame($catcontext->id, $ctx->id);
        $this->assertSame(CONTEXT_COURSECAT, $ctx->contextlevel);
    }

    /**
     * The legacy course-wide profile (no block instance) resolves to the course
     * context, preserving pre-multi-assistant behaviour.
     */
    public function test_file_context_legacy_falls_back_to_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $this->assertSame(
            \context_course::instance($course->id)->id,
            tutordocs::file_context($course->id, 0)->id
        );
    }
}
