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
 * Tests for the site to course inheritance of the support settings.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for course_config::support().
 *
 * @covers \block_openaiagent\local\course_config
 */
final class support_courseconfig_test extends \advanced_testcase {
    /**
     * Apply a workable set of site defaults.
     *
     * @return void
     */
    private function set_site_defaults(): void {
        set_config('support_email_enabled', 1, 'block_openaiagent');
        set_config('support_email_to', 'cau@example.org', 'block_openaiagent');
        set_config('support_email_cc', '', 'block_openaiagent');
        set_config('support_subject_template', 'SITE SUBJECT', 'block_openaiagent');
        set_config('support_body_template', 'SITE BODY', 'block_openaiagent');
        set_config('support_include_transcript', 1, 'block_openaiagent');
        set_config('support_copy_to_user', 1, 'block_openaiagent');
        set_config('support_max_per_user_day', 3, 'block_openaiagent');
        set_config('support_allowed_domains', '', 'block_openaiagent');
    }

    /**
     * A course with no configuration row runs entirely on the site values.
     */
    public function test_course_without_row_inherits_everything(): void {
        $this->resetAfterTest();
        $this->set_site_defaults();
        $course = $this->getDataGenerator()->create_course();

        $support = course_config::support($course->id);

        $this->assertTrue($support['enabled']);
        $this->assertSame('cau@example.org', $support['to']);
        $this->assertSame('SITE SUBJECT', $support['subject']);
        $this->assertTrue($support['includetranscript']);
        $this->assertSame(3, $support['maxperuserday']);
    }

    /**
     * An empty course field means "inherit", not "blank".
     */
    public function test_empty_course_field_inherits(): void {
        $this->resetAfterTest();
        $this->set_site_defaults();
        $course = $this->getDataGenerator()->create_course();
        course_config::save($course->id, ['supportsubject' => '', 'supportemailto' => '']);

        $support = course_config::support($course->id);

        $this->assertSame('SITE SUBJECT', $support['subject']);
        $this->assertSame('cau@example.org', $support['to']);
    }

    /**
     * A course value replaces the site one.
     */
    public function test_course_values_override(): void {
        $this->resetAfterTest();
        $this->set_site_defaults();
        $course = $this->getDataGenerator()->create_course();
        course_config::save($course->id, [
            'supportsubject' => 'COURSE SUBJECT',
            'supportemailto' => 'tutor@example.org',
        ]);

        $support = course_config::support($course->id);

        $this->assertSame('COURSE SUBJECT', $support['subject']);
        $this->assertSame('tutor@example.org', $support['to']);
    }

    /**
     * A course may switch escalation off for itself.
     */
    public function test_course_can_disable(): void {
        $this->resetAfterTest();
        $this->set_site_defaults();
        $course = $this->getDataGenerator()->create_course();
        course_config::save($course->id, ['supportmode' => 'off']);

        $this->assertFalse(course_config::support($course->id)['enabled']);
    }

    /**
     * A course may not switch escalation on where the site has it off: the site
     * setting is a real master switch, not a default.
     */
    public function test_course_cannot_enable_against_the_site(): void {
        $this->resetAfterTest();
        $this->set_site_defaults();
        set_config('support_email_enabled', 0, 'block_openaiagent');
        $course = $this->getDataGenerator()->create_course();
        course_config::save($course->id, ['supportmode' => 'on']);

        $support = course_config::support($course->id);

        $this->assertFalse($support['enabled']);
        $this->assertFalse($support['siteenabled']);
    }

    /**
     * A deliberate "no" must stay distinguishable from "not configured".
     */
    public function test_tristate_distinguishes_no_from_inherit(): void {
        $this->resetAfterTest();
        $this->set_site_defaults();
        $course = $this->getDataGenerator()->create_course();

        course_config::save($course->id, ['supportincludetranscript' => -1]);
        $this->assertTrue(course_config::support($course->id)['includetranscript'], 'inherit follows the site');

        course_config::save($course->id, ['supportincludetranscript' => 0]);
        $this->assertFalse(course_config::support($course->id)['includetranscript'], 'explicit no wins');

        set_config('support_include_transcript', 0, 'block_openaiagent');
        course_config::save($course->id, ['supportincludetranscript' => 1]);
        $this->assertTrue(course_config::support($course->id)['includetranscript'], 'explicit yes wins');
    }

    /**
     * Two block instances in the same course keep separate support settings.
     */
    public function test_profiles_are_independent(): void {
        $this->resetAfterTest();
        $this->set_site_defaults();
        $course = $this->getDataGenerator()->create_course();

        course_config::save($course->id, ['supportsubject' => 'PROFILE ONE'], 11);
        course_config::save($course->id, ['supportsubject' => 'PROFILE TWO'], 22);

        $this->assertSame('PROFILE ONE', course_config::support($course->id, 11)['subject']);
        $this->assertSame('PROFILE TWO', course_config::support($course->id, 22)['subject']);
        $this->assertSame('SITE SUBJECT', course_config::support($course->id)['subject']);
    }

    /**
     * The limits are site-wide: a course cannot raise its own ceiling.
     */
    public function test_limits_come_from_the_site_only(): void {
        $this->resetAfterTest();
        $this->set_site_defaults();
        set_config('support_max_per_course_day', 50, 'block_openaiagent');
        $course = $this->getDataGenerator()->create_course();
        course_config::save($course->id, ['supportmode' => 'on']);

        $this->assertSame(50, course_config::support($course->id)['maxpercourseday']);
    }

    /**
     * The course-contacts token is not an address and must survive validation.
     */
    public function test_course_teachers_token_is_not_rejected(): void {
        $this->resetAfterTest();
        set_config('support_allowed_domains', 'example.org', 'block_openaiagent');

        $addresses = support_mailer::parse_addresses(support_mailer::TOKEN_COURSE_TEACHERS);

        $this->assertSame([], support_mailer::invalid_addresses($addresses));
        $this->assertSame([], support_mailer::disallowed_addresses($addresses));
    }

    /**
     * Category routing only accepts the closed category list.
     */
    public function test_category_map_parsing(): void {
        $raw = "tecnico: cau@example.org, cau2@example.org\n"
            . "academico: teachers@example.org\n"
            . "inventada: nobody@example.org\n"
            . "linea sin dos puntos";

        $map = support_mailer::parse_category_map($raw);

        $this->assertSame(['tecnico', 'academico'], array_keys($map));
        $this->assertSame(['cau@example.org', 'cau2@example.org'], $map['tecnico']);
        $this->assertArrayNotHasKey('inventada', $map);
        $this->assertCount(3, support_mailer::category_map_addresses($raw));
    }
}
