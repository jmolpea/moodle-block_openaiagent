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
 * Tests for the MCP tool registry.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp;

/**
 * Unit tests for moodle.search_course_content.
 *
 * @covers \block_openaiagent\mcp\tool_registry
 */
final class tool_registry_test extends \advanced_testcase {
    /** @var \stdClass Course under test. */
    private \stdClass $course;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    /** @var array<string,\stdClass> Created modules keyed by a short handle. */
    private array $modules = [];

    /**
     * A course whose activity names use the real-world numbering of the reported
     * failures: a leading "N.M", punctuation, and a descriptive tail.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');

        $names = [
            'forum26' => '2.6 Actividad individual: Decir que NO',
            'webinar23' => '2.3. Webinar: Semana 2',
            'webinar21' => '2.1. Synchronous activity: Week 2 webinar',
            'quiz27' => '2.7. Cuestionario de evaluación: semana 2',
        ];
        foreach ($names as $handle => $name) {
            $this->modules[$handle] = $this->getDataGenerator()->create_module(
                'page',
                ['course' => $this->course->id, 'name' => $name]
            );
        }
    }

    /**
     * Run the search tool as the enrolled student.
     *
     * @param string $query Search query.
     * @return array Tool output.
     */
    private function search(string $query): array {
        $this->setUser($this->student);
        return tool_registry::call(
            'moodle.search_course_content',
            ['query' => $query],
            (int)$this->student->id,
            (int)$this->course->id
        );
    }

    /**
     * The exact phrasings that returned nothing in production now resolve. The old
     * implementation matched the whole query as one contiguous substring of the
     * name, so "actividad 2.6" missed "2.6 Actividad individual: ..." (wrong word
     * order) and "2.3 Webinar Semana 2" missed "2.3. Webinar: Semana 2" (the dot
     * and the colon). Both left the assistant telling the user the activity did
     * not exist.
     *
     * @dataProvider loose_query_provider
     * @param string $query What the participant typed.
     * @param string $expectedhandle Module that must come back first.
     */
    public function test_loose_queries_find_the_activity(string $query, string $expectedhandle): void {
        $result = $this->search($query);

        $this->assertGreaterThan(0, $result['count'], "No result for: {$query}");
        $this->assertSame(
            $this->modules[$expectedhandle]->cmid,
            $result['results'][0]['cmid'],
            "Wrong top hit for: {$query}"
        );
    }

    /**
     * Query phrasings taken from the real conversations.
     *
     * @return array[] [query, expected module handle]
     */
    public static function loose_query_provider(): array {
        return [
            'word order reversed' => ['actividad 2.6', 'forum26'],
            'bare numbering' => ['2.6', 'forum26'],
            'connectors and a noun' => ['el foro de la actividad 2.6', 'forum26'],
            'missing dot and colon' => ['2.3 Webinar Semana 2', 'webinar23'],
            'accent and words only' => ['cuestionario de evaluación', 'quiz27'],
            'english phrasing' => ['week 2 webinar', 'webinar21'],
        ];
    }

    /**
     * A query matching nothing still returns cleanly rather than a false positive.
     */
    public function test_unrelated_query_returns_nothing(): void {
        $result = $this->search('astrofísica cuántica');

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['results']);
    }

    /**
     * Results carry how many query terms matched, so the caller can tell a solid
     * hit from a near miss instead of confirming the wrong activity.
     */
    public function test_results_report_match_strength(): void {
        $result = $this->search('actividad 2.6');

        $this->assertSame(2, $result['term_count']);
        $this->assertSame(2, $result['results'][0]['matched_terms']);
    }

    /**
     * An activity the student cannot open yet, but is told about, must come back
     * flagged as restricted. Reporting it as non-existent was the failure behind
     * "no tengo permiso para acceder a los detalles de la restricción": the tool
     * hid the very activity the assistant needed in order to explain the gate.
     */
    public function test_gated_activity_is_returned_as_restricted(): void {
        global $DB;

        $cmid = $this->modules['quiz27']->cmid;
        $DB->set_field('course_modules', 'availability', json_encode([
            'op' => '&',
            'c' => [['type' => 'date', 'd' => '>=', 't' => time() + DAYSECS]],
            // Here showc true means greyed out with the reason, not hidden outright.
            'showc' => [true],
        ]), ['id' => $cmid]);
        rebuild_course_cache($this->course->id, true);

        $result = $this->search('cuestionario de evaluación');

        $this->assertSame(1, $result['count']);
        $this->assertSame($cmid, $result['results'][0]['cmid']);
        $this->assertFalse($result['results'][0]['available_to_user']);
        $this->assertNotSame('', $result['results'][0]['availability_summary']);
    }

    /**
     * An activity inside a locked SECTION is still found, and still explains why.
     *
     * This is the regression that produced the worst answer seen in production: a
     * whole week was gated, so every activity inside carried uservisible=false with
     * an EMPTY availableinfo (the reason lives on the section). The search dropped
     * them all, returned nothing for "2.6", and the model bound the participant's
     * activity to an unrelated forum it could see, stating the equivalence as fact.
     */
    public function test_activity_in_a_locked_section_is_still_found(): void {
        global $DB;

        // Move the target activity into its own section and gate that section.
        $section = $this->getDataGenerator()->create_course_section([
            'course' => $this->course->id,
            'section' => 1,
        ]);
        $cmid = $this->modules['forum26']->cmid;
        moveto_module(get_coursemodule_from_id('', $cmid), $section);

        $DB->set_field('course_sections', 'availability', json_encode([
            'op' => '&',
            'c' => [['type' => 'date', 'd' => '>=', 't' => time() + DAYSECS]],
            'showc' => [true],
        ]), ['id' => $section->id]);
        rebuild_course_cache($this->course->id, true);

        $result = $this->search('actividad 2.6');

        $this->assertSame(1, $result['count'], 'A gated section must not hide its activities from search.');
        $this->assertSame($cmid, $result['results'][0]['cmid']);
        $this->assertFalse($result['results'][0]['available_to_user']);
        // The reason comes from the section, since the activity itself has none.
        $this->assertNotSame('', $result['results'][0]['availability_summary']);
    }

    /**
     * A search that finds nothing carries an explicit instruction not to invent an
     * activity from elsewhere in the context.
     */
    public function test_empty_result_warns_against_inferring(): void {
        $result = $this->search('astrofísica cuántica');

        $this->assertSame(0, $result['count']);
        $this->assertArrayHasKey('no_results', $result['notes']);
        $this->assertStringContainsString('Do NOT infer', $result['notes']['no_results']);
        $this->assertArrayHasKey('never_equate', $result['notes']);
    }

    /**
     * An enrolled student can reach the support link.
     *
     * The tool used to test moodle/course:view on its own. That capability means
     * "view courses WITHOUT participating" -- managers hold it, students do not --
     * so the tool threw for every student it exists to serve, and the assistant
     * reported that it could not retrieve the support link.
     */
    public function test_student_can_get_the_support_link(): void {
        set_config('support_url', 'https://example.org/support', 'block_openaiagent');
        $this->setUser($this->student);

        $result = tool_registry::call(
            'moodle.get_support_link',
            [],
            (int)$this->student->id,
            (int)$this->course->id
        );

        $this->assertTrue($result['available']);
        $this->assertSame('https://example.org/support', $result['url']);
    }

    /**
     * A user who is neither enrolled nor privileged still gets nothing.
     */
    public function test_outsider_cannot_get_the_support_link(): void {
        set_config('support_url', 'https://example.org/support', 'block_openaiagent');
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $this->expectException(\required_capability_exception::class);
        tool_registry::call('moodle.get_support_link', [], (int)$outsider->id, (int)$this->course->id);
    }

    /**
     * An activity the teacher hid stays hidden: the tolerant matching must not
     * become a way to enumerate content the participant is not meant to see.
     */
    public function test_teacher_hidden_activity_is_never_returned(): void {
        set_coursemodule_visible($this->modules['forum26']->cmid, 0);
        rebuild_course_cache($this->course->id, true);

        $result = $this->search('actividad 2.6');

        foreach ($result['results'] as $row) {
            $this->assertNotSame($this->modules['forum26']->cmid, $row['cmid']);
        }
    }
}
