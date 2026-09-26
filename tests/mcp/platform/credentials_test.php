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
 * Tests for the certificates and badges tool.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform;

use block_openaiagent\local\scope;
use block_openaiagent\mcp\platform\tools\get_my_credentials;

/**
 * Unit tests for get_my_credentials and its sources.
 *
 * The mod_customcert and tool_certificate cases run only where those plugins
 * are installed; the Moodle badge and deduplication cases always run.
 *
 * @covers \block_openaiagent\mcp\platform\tools\get_my_credentials
 * @covers \block_openaiagent\mcp\platform\credentials\moodle_badges
 * @covers \block_openaiagent\mcp\platform\credentials\customcert
 * @covers \block_openaiagent\mcp\platform\credentials\tool_certificate
 * @covers \block_openaiagent\mcp\platform\credentials\obf
 */
final class credentials_test extends \advanced_testcase {
    /** @var int Category block id. */
    private int $blockid;

    /** @var \stdClass Participant. */
    private \stdClass $user;

    /**
     * A category assistant and a participant.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $this->blockid = (int)$this->getDataGenerator()->create_block('openaiagent', [
            'parentcontextid' => \context_coursecat::instance($category->id)->id,
            'pagetypepattern' => '*',
        ])->id;
        $this->user = $this->getDataGenerator()->create_user(['email' => 'ana@example.org']);
    }

    /**
     * Call the tool as the participant.
     *
     * @param array $input Arguments.
     * @return array
     */
    private function credentials(array $input = []): array {
        $this->setUser($this->user);
        return registry::call('moodle.get_my_credentials', $input, scope::for_block($this->blockid, (int)$this->user->id));
    }

    /**
     * Moodle badges are listed with their public page, and the email that comes with them is not.
     */
    public function test_moodle_badges(): void {
        global $CFG;
        $CFG->enablebadges = 1;
        $generator = $this->getDataGenerator()->get_plugin_generator('core_badges');
        $badge = $generator->create_badge(['name' => 'Liderazgo', 'status' => BADGE_STATUS_ACTIVE]);
        $badge->issue($this->user->id, true);
        $expiring = $generator->create_badge(['name' => 'Caducada', 'status' => BADGE_STATUS_ACTIVE]);
        $expiring->issue($this->user->id, true);
        global $DB;
        $DB->set_field('badge_issued', 'dateexpire', time() - DAYSECS, ['badgeid' => $expiring->id]);

        $other = $this->getDataGenerator()->create_user();
        $generator->create_badge(['name' => 'Ajena', 'status' => BADGE_STATUS_ACTIVE])->issue($other->id, true);

        $result = $this->credentials();
        $byname = array_column($result['credentials'], null, 'name');

        $this->assertArrayHasKey('Liderazgo', $byname);
        $this->assertArrayNotHasKey('Ajena', $byname);
        $this->assertStringContainsString('/badges/badge.php?hash=', $byname['Liderazgo']['url']);
        $this->assertFalse($byname['Liderazgo']['expired']);
        $this->assertTrue($byname['Caducada']['expired']);
        $this->assertStringNotContainsString('ana@example.org', json_encode($result));
    }

    /**
     * A custom certificate is listed with a download link that does not need the course.
     */
    public function test_customcert(): void {
        if (!(new credentials\customcert())->is_available()) {
            $this->markTestSkipped('mod_customcert is not installed.');
        }
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Excel', 'visible' => 0]);
        $cert = $this->getDataGenerator()->create_module('customcert', ['course' => $course->id, 'name' => 'Diploma Excel']);
        \mod_customcert\certificate::issue_certificate($cert->id, $this->user->id);

        $certificate = array_column($this->credentials(['type' => 'certificate'])['credentials'], null, 'name')['Diploma Excel'];
        $this->assertSame('Excel', $certificate['course']);
        $this->assertNotEmpty($certificate['code']);
        $this->assertStringContainsString('/mod/customcert/my_certificates.php', $certificate['url']);
        $this->assertStringContainsString('downloadcert=1', $certificate['url']);
        $this->assertFalse($certificate['requires_course_access']);
    }

    /**
     * A tool_certificate issue is listed with its view page and its expiry.
     */
    public function test_tool_certificate(): void {
        if (!(new credentials\tool_certificate())->is_available()) {
            $this->markTestSkipped('tool_certificate is not installed.');
        }
        $generator = $this->getDataGenerator()->get_plugin_generator('tool_certificate');
        $template = $generator->create_template((object)['name' => 'Certificado de Python']);
        $generator->issue($template, $this->user, time() - DAYSECS);

        $certificate = array_column($this->credentials()['credentials'], null, 'name')['Certificado de Python'];
        $this->assertStringContainsString('/admin/tool/certificate/view.php?code=', $certificate['url']);
        $this->assertTrue($certificate['expired']);
        $this->assertNotNull($certificate['expires']);
    }

    /**
     * Open Badge Factory installed but not connected to an account is not asked at all.
     */
    public function test_obf_without_connection(): void {
        global $CFG;
        if (!file_exists($CFG->dirroot . '/local/obf/lib.php')) {
            $this->markTestSkipped('local_obf is not installed.');
        }
        $this->assertFalse((new credentials\obf())->is_available());
        $this->assertNotContains('obf', array_map(fn($s) => $s->name(), get_my_credentials::sources()));
    }

    /**
     * The same badge from two sources is listed once, keeping Moodle's; different or distant badges stay.
     */
    public function test_duplicates(): void {
        $day = 1700000000;
        $badge = fn(string $name, string $source, int $issued) => [
            'type' => 'badge', 'name' => $name, 'issuer' => '', 'course' => '', 'issued' => $issued, 'expires' => 0,
            'code' => '', 'url' => 'https://example.org/' . $source, 'verify_url' => '', 'requires_course_access' => false,
            'source' => $source,
        ];
        $kept = get_my_credentials::without_duplicates([
            $badge('Liderazgo Ágil', 'obf', $day + HOURSECS),
            $badge('liderazgo agil', 'moodle', $day),
            $badge('Liderazgo Ágil', 'obf', $day + 10 * DAYSECS),
            $badge('Excel', 'obf', $day),
        ]);

        $this->assertCount(3, $kept);
        $sources = array_map(fn($c) => $c['source'] . '|' . $c['name'], $kept);
        $this->assertContains('moodle|liderazgo agil', $sources);
        $this->assertContains('obf|Excel', $sources);
    }

    /**
     * A visitor never reaches the tool.
     */
    public function test_visitor_has_no_credentials(): void {
        $this->setUser(0);
        $this->assertArrayNotHasKey('moodle.get_my_credentials', registry::permitted(scope::for_block($this->blockid, 0)));
    }
}
