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
 * Tests for per-course configuration resolution.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for the course configuration helper.
 *
 * @covers \block_openaiagent\local\course_config
 */
final class course_config_test extends \advanced_testcase {
    /**
     * A course with no config row is enabled by default.
     */
    public function test_enabled_by_default(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->assertTrue(course_config::is_enabled($course->id));
    }

    /**
     * An explicit enabled=0 row disables the assistant.
     */
    public function test_explicit_disable(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        course_config::save($course->id, ['enabled' => 0]);
        $this->assertFalse(course_config::is_enabled($course->id));
    }

    /**
     * save() then resolve() round-trips course values and never yields nulls
     * for string fields.
     */
    public function test_save_and_resolve(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        \block_openaiagent\local\defaults::install();

        $course = $this->getDataGenerator()->create_course();

        course_config::save($course->id, [
            'enabled' => 1,
            'courseprompt' => 'Be concise.',
            'temperatureoverride' => 0.5,
            'maxoutputtokensoverride' => 800,
            'languagepolicy' => 'es',
            'storeconversations' => 1,
        ]);

        $resolved = course_config::resolve($course->id);

        $this->assertSame('Be concise.', $resolved['courseprompt']);
        $this->assertSame(0.5, $resolved['temperatureoverride']);
        $this->assertSame(800, $resolved['maxoutputtokensoverride']);
        $this->assertSame('es', $resolved['languagepolicy']);
        $this->assertTrue($resolved['enabled']);

        // String fields are never null.
        foreach (
            ['courseprompt', 'modeloverride', 'evaluationpolicy', 'fallbacknoinfo'] as $field
        ) {
            $this->assertIsString($resolved[$field], "$field should be a string");
        }

        // Agents resolve to the seeded defaults.
        $this->assertNotNull($resolved['agents']['router']);
        $this->assertNotNull($resolved['agents']['tutor']);
    }

    /**
     * Both content agents default to enabled, and disabling one is round-tripped.
     */
    public function test_agent_toggles_resolve(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        \block_openaiagent\local\defaults::install();

        $course = $this->getDataGenerator()->create_course();

        // No row: both agents on by default so the block works out of the box.
        $resolved = course_config::resolve($course->id);
        $this->assertSame(1, $resolved['tutorenabled']);
        $this->assertSame(1, $resolved['assistantenabled']);

        // Disabling the tutor is persisted and resolved.
        course_config::save($course->id, ['enabled' => 1, 'tutorenabled' => 0, 'assistantenabled' => 1]);
        $resolved = course_config::resolve($course->id);
        $this->assertSame(0, $resolved['tutorenabled']);
        $this->assertSame(1, $resolved['assistantenabled']);
    }

    /**
     * Overrides left empty resolve to null sentinels, not zero/empty values.
     */
    public function test_empty_overrides_resolve_to_null(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        course_config::save($course->id, [
            'enabled' => 1,
            'temperatureoverride' => null,
            'maxoutputtokensoverride' => null,
        ]);

        $resolved = course_config::resolve($course->id);
        $this->assertNull($resolved['temperatureoverride']);
        $this->assertNull($resolved['maxoutputtokensoverride']);
    }

    /**
     * Without saved tools, the course inherits the default tool set.
     */
    public function test_enabled_tools_default(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $this->assertSame(defaults::default_tool_names(), course_config::enabled_tools($course->id));
    }

    /**
     * Two block instances in the same course keep fully independent profiles:
     * config saved for one is invisible to the other, which still resolves to
     * defaults.
     */
    public function test_profiles_are_isolated_per_block(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        \block_openaiagent\local\defaults::install();

        $course = $this->getDataGenerator()->create_course();
        $blocka = 101;
        $blockb = 202;

        course_config::save($course->id, [
            'enabled' => 1,
            'courseprompt' => 'Support bot guidance.',
        ], $blocka);

        // Block A resolves to its own values.
        $resolveda = course_config::resolve($course->id, $blocka);
        $this->assertSame('Support bot guidance.', $resolveda['courseprompt']);
        $this->assertSame($blocka, $resolveda['blockinstanceid']);

        // Block B has no row of its own: it falls back to global defaults, not to
        // block A's configuration.
        $this->assertNull(course_config::get_raw($course->id, $blockb));
        $resolvedb = course_config::resolve($course->id, $blockb);
        $this->assertSame('', $resolvedb['courseprompt']);

        // Disabling block B does not disable block A.
        course_config::save($course->id, ['enabled' => 0], $blockb);
        $this->assertFalse(course_config::is_enabled($course->id, $blockb));
        $this->assertTrue(course_config::is_enabled($course->id, $blocka));
    }

    /**
     * Enabled tools are stored per block instance, not shared across the course.
     */
    public function test_tools_are_isolated_per_block(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $blocka = 101;
        $blockb = 202;

        course_config::save_tools($course->id, ['moodle.get_context'], $blocka);

        // Block A has just the saved subset.
        $this->assertSame(['moodle.get_context'], course_config::enabled_tools($course->id, $blocka));
        // Block B has no rows of its own, so it inherits the default tool set.
        $this->assertSame(defaults::default_tool_names(), course_config::enabled_tools($course->id, $blockb));
    }

    /**
     * Saving a tool subset is reflected by enabled_tools().
     */
    public function test_save_tools_subset(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $subset = ['moodle.get_context', 'moodle.get_course_progress'];
        course_config::save_tools($course->id, $subset);

        $enabled = course_config::enabled_tools($course->id);
        sort($enabled);
        sort($subset);
        $this->assertSame($subset, $enabled);
    }
}
