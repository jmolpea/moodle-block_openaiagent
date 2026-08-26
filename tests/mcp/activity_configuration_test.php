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
 * Tests for moodle.get_activity_configuration.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp;

use block_openaiagent\local\defaults;

/**
 * Unit tests for the activity configuration tool and its interpreters.
 *
 * @covers \block_openaiagent\mcp\tool_registry
 * @covers \block_openaiagent\mcp\config\forum
 * @covers \block_openaiagent\mcp\config\generic
 */
final class activity_configuration_test extends \advanced_testcase {
    /** @var \stdClass Course under test. */
    private \stdClass $course;

    /** @var \stdClass Enrolled student. */
    private \stdClass $student;

    /**
     * A course with one enrolled student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * Run the tool as the enrolled student.
     *
     * @param int $cmid Course module id.
     * @param int|null $boundcourseid Session course (defaults to the test course).
     * @return array Tool output.
     */
    private function config(int $cmid, ?int $boundcourseid = null): array {
        $this->setUser($this->student);
        return tool_registry::call(
            'moodle.get_activity_configuration',
            ['cmid' => $cmid],
            (int)$this->student->id,
            $boundcourseid ?? (int)$this->course->id
        );
    }

    /**
     * Flatten the rules so assertions read against one string.
     *
     * @param array $result Tool output.
     * @return string
     */
    private function rules(array $result): string {
        return implode(' | ', $result['behaviour_rules']);
    }

    /**
     * The reported case. A Q&A forum carries no access restriction whatsoever,
     * so get_activity_access_requirements reports it as available and the
     * assistant used to answer "this forum has no restrictions". The reason the
     * participant could not see their classmates' replies lives in the forum's
     * own type, and this tool is the one that surfaces it.
     */
    public function test_qanda_forum_explains_why_posts_are_hidden(): void {
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $this->course->id,
            'type' => 'qanda',
        ]);

        $result = $this->config((int)$forum->cmid);

        // The availability API still says "nothing wrong here" -- which is
        // precisely why the behaviour rules have to carry the answer.
        $this->assertTrue($result['is_available_now']);
        $this->assertSame('', $result['availability_summary']);

        // Asserted through get_string so the test does not depend on the
        // language PHPUnit happens to run in.
        global $CFG;
        $this->assertStringContainsString(
            get_string('actcfg_forum_qanda', 'block_openaiagent'),
            $this->rules($result)
        );
        $this->assertStringContainsString(
            get_string('actcfg_forum_qanda_delay', 'block_openaiagent', format_time((int)$CFG->maxeditingtime)),
            $this->rules($result)
        );
        $this->assertFalse($result['user_state']['has_posted_in_forum']);
        $this->assertFalse($result['user_state']['editing_period_elapsed']);
    }

    /**
     * Once the participant has posted and the editing window has passed, the
     * state flips: the assistant must stop telling them to post and look
     * elsewhere for the cause.
     */
    public function test_qanda_state_flips_once_the_editing_window_passed(): void {
        global $CFG, $DB;

        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $this->course->id,
            'type' => 'qanda',
        ]);

        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $this->course->id,
            'forum' => $forum->id,
            'userid' => $this->student->id,
        ]);

        // Backdate the post beyond the editing window.
        $old = time() - ((int)$CFG->maxeditingtime + 600);
        $DB->set_field('forum_posts', 'created', $old, ['discussion' => $discussion->id]);

        $result = $this->config((int)$forum->cmid);

        $this->assertTrue($result['user_state']['has_posted_in_forum']);
        $this->assertTrue($result['user_state']['editing_period_elapsed']);
    }

    /**
     * A standard forum must not be described as Q&A. Inventing a restriction is
     * the same failure as missing one, pointed the other way.
     */
    public function test_standard_forum_gets_no_qanda_rule(): void {
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $this->course->id,
            'type' => 'general',
        ]);

        $result = $this->config((int)$forum->cmid);

        $this->assertStringNotContainsString(
            get_string('actcfg_forum_qanda', 'block_openaiagent'),
            $this->rules($result)
        );
        $this->assertArrayNotHasKey('editing_period_elapsed', $result['user_state']);
    }

    /**
     * Separate groups explain a whole family of "my classmate sees something I
     * don't" questions, in any module, through a core API.
     */
    public function test_separate_groups_are_reported_for_any_module(): void {
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $this->course->id,
            'groupmode' => SEPARATEGROUPS,
        ]);

        $result = $this->config((int)$page->cmid);

        $this->assertStringContainsString(
            get_string('actcfg_groups_separate', 'block_openaiagent'),
            $this->rules($result)
        );
    }

    /**
     * A module with no interpreter of its own must still answer, and when there
     * is genuinely nothing to report it must carry the note that stops the model
     * concluding "no restrictions" from an empty list.
     */
    public function test_module_without_interpreter_still_answers(): void {
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $this->course->id,
        ]);

        $result = $this->config((int)$page->cmid);

        $this->assertSame('page', $result['modname']);
        $this->assertIsArray($result['behaviour_rules']);
        if (!$result['behaviour_rules']) {
            $this->assertArrayHasKey('note', $result);
        }
    }

    /**
     * The interpreters emit an explicit allowlist, never the instance record, so
     * settings a participant must not see cannot reach the model. The quiz
     * password and subnet lock are the sharpest examples, and mod_quiz is a
     * module that does have an interpreter, so this pins the allowlist where it
     * would be easiest to get wrong.
     */
    public function test_sensitive_settings_never_reach_the_model(): void {
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $this->course->id,
            'password' => 'clave-secreta-del-cuestionario',
            'subnet' => '10.0.0.0/8',
        ]);

        $result = $this->config((int)$quiz->cmid);
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('clave-secreta-del-cuestionario', $encoded);
        $this->assertStringNotContainsString('10.0.0.0/8', $encoded);
    }

    /**
     * The quiz counterpart of the forum case: nothing is restricted, the quiz
     * simply withholds the mark until it closes. Without this the assistant can
     * only offer guesses about why the participant sees no grade.
     */
    public function test_quiz_deferred_review_is_explained(): void {
        global $DB;

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);

        // The quiz generator ignores the review fields and always stores the
        // full "show everything" bitmask (69904), so they are written directly.
        $afterclose = \mod_quiz\question\display_options::AFTER_CLOSE;
        $DB->set_field('quiz', 'reviewmarks', $afterclose, ['id' => $quiz->id]);
        $DB->set_field('quiz', 'reviewcorrectness', $afterclose, ['id' => $quiz->id]);

        $result = $this->config((int)$quiz->cmid);

        $this->assertStringContainsString(
            get_string('actcfg_quiz_review_marks_close', 'block_openaiagent'),
            $this->rules($result)
        );
        $this->assertStringContainsString(
            get_string('actcfg_quiz_review_correct_close', 'block_openaiagent'),
            $this->rules($result)
        );
        $this->assertSame(0, $result['user_state']['attempts_finished']);
        $this->assertFalse($result['user_state']['attempt_in_progress']);
    }

    /**
     * A quiz that shows everything straight away must produce no review rule:
     * silence is correct when there is nothing withheld.
     */
    public function test_quiz_with_immediate_review_gets_no_review_rule(): void {
        global $DB;

        $immediate = \mod_quiz\question\display_options::DURING
            | \mod_quiz\question\display_options::IMMEDIATELY_AFTER
            | \mod_quiz\question\display_options::LATER_WHILE_OPEN
            | \mod_quiz\question\display_options::AFTER_CLOSE;

        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $DB->set_field('quiz', 'reviewmarks', $immediate, ['id' => $quiz->id]);
        $DB->set_field('quiz', 'reviewcorrectness', $immediate, ['id' => $quiz->id]);

        $result = $this->config((int)$quiz->cmid);

        $this->assertStringNotContainsString(
            get_string('actcfg_quiz_review_marks_close', 'block_openaiagent'),
            $this->rules($result)
        );
        $this->assertStringNotContainsString(
            get_string('actcfg_quiz_review_marks_never', 'block_openaiagent'),
            $this->rules($result)
        );
    }

    /**
     * The most expensive assignment misunderstanding: the file is uploaded, the
     * participant believes they submitted, and the work sits as a draft.
     */
    public function test_assignment_draft_trap_is_explained(): void {
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $this->course->id,
            'submissiondrafts' => 1,
        ]);

        $result = $this->config((int)$assign->cmid);

        $this->assertStringContainsString(
            get_string('actcfg_assign_drafts', 'block_openaiagent'),
            $this->rules($result)
        );
        $this->assertSame('none', $result['user_state']['submission_status']);
    }

    /**
     * A passed cutoff is the reason submissions are refused, and it is a
     * different date from the due date. Both are reported, distinctly.
     */
    public function test_assignment_past_cutoff_is_reported(): void {
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $this->course->id,
            'duedate' => time() - (14 * DAYSECS),
            'cutoffdate' => time() - (7 * DAYSECS),
        ]);

        $result = $this->config((int)$assign->cmid);
        $rules = $this->rules($result);

        $this->assertStringContainsString(
            get_string('actcfg_assign_cutoff_past', 'block_openaiagent', userdate(time() - (7 * DAYSECS))),
            $rules
        );
        $this->assertStringNotContainsString(
            get_string('actcfg_assign_cutoff', 'block_openaiagent', userdate(time() - (7 * DAYSECS))),
            $rules
        );
    }

    /**
     * A personal extension explains "my classmate could still submit and I
     * could not", and the reverse.
     */
    public function test_assignment_personal_extension_is_reported(): void {
        global $DB;

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $this->course->id,
            'duedate' => time() - DAYSECS,
        ]);

        $extension = time() + (7 * DAYSECS);
        $DB->insert_record('assign_user_flags', (object) [
            'assignment' => $assign->id,
            'userid' => $this->student->id,
            'locked' => 0,
            'mailed' => 0,
            'extensionduedate' => $extension,
            'workflowstate' => '',
            'allocatedmarker' => 0,
        ]);

        $result = $this->config((int)$assign->cmid);

        $this->assertStringContainsString(
            get_string('actcfg_assign_extension', 'block_openaiagent', userdate($extension)),
            $this->rules($result)
        );
    }

    /**
     * The chat is scoped to one course, so a cmid from another course must be
     * refused even when the caller could otherwise reach it.
     */
    public function test_cmid_outside_the_bound_course_is_refused(): void {
        $othercourse = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $othercourse->id,
            'type' => 'qanda',
        ]);

        $this->expectException(\moodle_exception::class);
        $this->config((int)$forum->cmid, (int)$this->course->id);
    }

    /**
     * A teacher-hidden activity must not be described to a participant, even
     * when the model supplies its cmid directly. The listing tools already skip
     * these; the cmid-addressed tools did not, so a guessed or stale cmid could
     * still return a hidden activity's name and settings.
     *
     * @dataProvider hidden_activity_tool_provider
     * @param string $tool Tool name addressed by cmid.
     */
    public function test_hidden_activity_is_refused_by_cmid_tools(string $tool): void {
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $this->course->id,
            'type' => 'qanda',
        ]);
        set_coursemodule_visible((int)$forum->cmid, 0);
        rebuild_course_cache((int)$this->course->id, true);

        $this->setUser($this->student);
        $this->expectException(\moodle_exception::class);
        tool_registry::call(
            $tool,
            ['cmid' => (int)$forum->cmid],
            (int)$this->student->id,
            (int)$this->course->id
        );
    }

    /**
     * Every tool that takes a raw cmid.
     *
     * @return array[]
     */
    public static function hidden_activity_tool_provider(): array {
        return [
            'configuration' => ['moodle.get_activity_configuration'],
            'details' => ['moodle.get_activity_details'],
            'access requirements' => ['moodle.get_activity_access_requirements'],
            'contents listing' => ['moodle.list_activity_contents'],
        ];
    }

    /**
     * The other side of the guard, and the one that must not regress: an
     * activity restricted by an access condition stays visible on the course
     * page (visible = 1, uservisible = false) and explaining WHY it is locked is
     * the assistant's whole purpose. Only a teacher-hidden activity has both
     * flags false, so this one must still be answered.
     */
    public function test_restricted_but_shown_activity_is_still_answered(): void {
        $future = time() + (30 * DAYSECS);
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $this->course->id,
            'type' => 'qanda',
            'availability' => json_encode([
                'op' => '&',
                'c' => [['type' => 'date', 'd' => '>=', 't' => $future]],
                'showc' => [true],
            ]),
        ]);
        rebuild_course_cache((int)$this->course->id, true);

        $result = $this->config((int)$forum->cmid);

        $this->assertFalse($result['is_available_now']);
        $this->assertNotSame('', $result['availability_summary']);
        $this->assertStringContainsString(
            get_string('actcfg_forum_qanda', 'block_openaiagent'),
            $this->rules($result)
        );
    }

    /**
     * Guard against the desync that makes a tool invisible: a name listed as a
     * default but absent from the registry is never exposed to the model, and a
     * name in the registry with no dispatch case throws only at call time. Both
     * failure modes are silent in production.
     */
    public function test_every_default_tool_is_registered_and_dispatchable(): void {
        $registered = array_column(tool_registry::list_tools(), 'name');

        foreach (defaults::default_tool_names() as $name) {
            $this->assertContains($name, $registered, "Default tool {$name} is missing from the registry");
        }

        $this->setUser($this->student);
        foreach ($registered as $name) {
            try {
                tool_registry::call($name, [], (int)$this->student->id, (int)$this->course->id);
            } catch (\moodle_exception $e) {
                $this->assertNotSame(
                    'mcp_unknown_tool',
                    $e->errorcode,
                    "Registered tool {$name} has no dispatch case"
                );
            } catch (\Throwable $e) {
                // Missing arguments are expected here; anything that is not
                // "unknown tool" proves the name reached an implementation.
                unset($e);
            }
        }
    }
}
