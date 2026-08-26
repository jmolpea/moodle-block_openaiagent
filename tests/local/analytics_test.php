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
 * Tests for the analytics rollup builder and read model.
 *
 * @package    block_openaiagent
 * @copyright  2026 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for {@see analytics}.
 *
 * @covers \block_openaiagent\local\analytics
 */
final class analytics_test extends \advanced_testcase {
    /**
     * Enable the assistant for a course by inserting a config row.
     *
     * @param int $courseid Course id.
     * @return void
     */
    private function enable_course(int $courseid): void {
        global $DB;
        $DB->insert_record('block_openaiagent_courseconfig', (object)[
            'courseid' => $courseid,
            'blockinstanceid' => 0,
            'enabled' => 1,
            'languagepolicy' => 'auto',
            'storeconversations' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => 0,
        ]);
    }

    /**
     * The rollup build aggregates messages into the headline overview figures.
     */
    public function test_build_and_overview(): void {
        $this->resetAfterTest();
        set_config('log_messages', 1, 'block_openaiagent');

        $course = $this->getDataGenerator()->create_course();
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($u1->id, $course->id);
        $this->getDataGenerator()->enrol_user($u2->id, $course->id);
        $this->enable_course($course->id);

        // Two users, three questions, three tutor answers with tokens.
        foreach ([$u1, $u1, $u2] as $u) {
            $conv = conversation_repository::create($u->id, $course->id);
            conversation_repository::add_message($conv->id, 'user', 'q');
            conversation_repository::add_message($conv->id, 'assistant', 'a', [
                'route' => 'tutor',
                'prompttokens' => 70,
                'completiontokens' => 30,
                'totaltokens' => 100,
            ]);
        }

        analytics::build();

        $today = analytics::day_start(time());
        $o = analytics::get_overview($today, $today);

        $this->assertSame(3, $o->questions);
        $this->assertSame(3, $o->answers);
        $this->assertSame(0, $o->errors);
        $this->assertSame(300, $o->totaltokens);
        $this->assertSame(2, $o->distinctusers);
        $this->assertSame(1, $o->coursesconfigured);
        $this->assertSame(1, $o->courseswithuse);
        $this->assertSame(2, $o->exposedparticipants);
        $this->assertEqualsWithDelta(1.0, $o->adoptionrate, 0.001);

        // Route breakdown counts assistant answers per route.
        $routes = analytics::get_route_breakdown($today, $today);
        $this->assertSame(3, $routes['tutor']);

        // Cost uses the default price for gpt-4o when the agent model resolves to it.
        $recur = analytics::get_recurrence($today, $today);
        $this->assertSame(2, $recur['1']);
        $this->assertSame(0, $recur['2-3']);
    }

    /**
     * The per-course table reflects usage and adoption for the range.
     */
    public function test_course_rows(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Algebra 101']);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->enable_course($course->id);

        $conv = conversation_repository::create($user->id, $course->id);
        conversation_repository::add_message($conv->id, 'user', 'q');
        conversation_repository::add_message($conv->id, 'assistant', 'a', ['route' => 'assistant']);

        analytics::build();

        $today = analytics::day_start(time());
        $rows = analytics::get_course_rows($today, $today);
        $this->assertCount(1, $rows);
        // The generator hands back the id as a string; the rollup normalises it.
        $this->assertSame((int)$course->id, $rows[0]->courseid);
        $this->assertSame(1, $rows[0]->users);
        $this->assertSame(1, $rows[0]->questions);
        $this->assertSame(1, $rows[0]->enrolled);

        // The name filter narrows the listing.
        $this->assertCount(1, analytics::get_course_rows($today, $today, 'algebra'));
        $this->assertCount(0, analytics::get_course_rows($today, $today, 'nomatch'));
    }

    /**
     * Filtering by course must narrow the whole dashboard, not only the table
     * at the bottom. While the headline figures stayed site-wide they read as
     * if they belonged to the single course listed underneath them.
     */
    public function test_course_filter_narrows_every_figure(): void {
        $this->resetAfterTest();
        set_config('log_messages', 1, 'block_openaiagent');

        $algebra = $this->getDataGenerator()->create_course(['fullname' => 'Algebra 101']);
        $history = $this->getDataGenerator()->create_course(['fullname' => 'History 202']);
        $this->enable_course($algebra->id);
        $this->enable_course($history->id);

        // One question in Algebra, two in History, so a site-wide figure and a
        // course-scoped one cannot coincide by accident.
        $plan = [[$algebra, 1, 'tutor'], [$history, 2, 'assistant']];
        foreach ($plan as [$course, $times, $route]) {
            for ($i = 0; $i < $times; $i++) {
                $user = $this->getDataGenerator()->create_user();
                $this->getDataGenerator()->enrol_user($user->id, $course->id);
                $conv = conversation_repository::create($user->id, $course->id);
                conversation_repository::add_message($conv->id, 'user', 'q');
                conversation_repository::add_message($conv->id, 'assistant', 'a', [
                    'route' => $route,
                    'prompttokens' => 10,
                    'completiontokens' => 5,
                    'totaltokens' => 15,
                ]);
            }
        }

        analytics::build();
        $today = analytics::day_start(time());
        $scope = analytics::courseids_matching('algebra');

        // Unfiltered: everything.
        $all = analytics::get_overview($today, $today);
        $this->assertSame(3, $all->questions);
        $this->assertSame(45, $all->totaltokens);
        $this->assertSame(3, $all->conversations);
        $this->assertSame(2, $all->coursesconfigured);

        // Filtered: only Algebra, in every figure.
        $one = analytics::get_overview($today, $today, $scope);
        $this->assertSame(1, $one->questions);
        $this->assertSame(15, $one->totaltokens);
        $this->assertSame(1, $one->conversations);
        $this->assertSame(1, $one->distinctusers);
        $this->assertSame(1, $one->coursesconfigured);
        $this->assertSame(1, $one->exposedparticipants);

        // Routes, series and cost follow the same scope.
        $routes = analytics::get_route_breakdown($today, $today, $scope);
        $this->assertSame(1, $routes['tutor']);
        $this->assertArrayNotHasKey('assistant', $routes);

        $series = analytics::get_timeseries($today, $today, $scope);
        $this->assertSame(1, $series[0]->questions);

        $cost = analytics::get_cost_by_model($today, $today, $scope);
        $this->assertSame(15, array_sum(array_column($cost, 'total')));

        $recur = analytics::get_recurrence($today, $today, $scope);
        $this->assertSame(1, $recur['1']);

        $rows = analytics::get_course_rows($today, $today, 'algebra', 0, $scope);
        $this->assertCount(1, $rows);
        $this->assertSame((int)$algebra->id, $rows[0]->courseid);
    }

    /**
     * A filter that matches no course must show nothing, not everything. It
     * resolves to an impossible id rather than an empty list, because an empty
     * list means "no restriction" everywhere else.
     */
    public function test_course_filter_matching_nothing_shows_nothing(): void {
        $this->resetAfterTest();
        set_config('log_messages', 1, 'block_openaiagent');

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Algebra 101']);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->enable_course($course->id);

        $conv = conversation_repository::create($user->id, $course->id);
        conversation_repository::add_message($conv->id, 'user', 'q');
        conversation_repository::add_message($conv->id, 'assistant', 'a', ['route' => 'tutor']);

        analytics::build();
        $today = analytics::day_start(time());

        $scope = analytics::courseids_matching('no-such-course');
        $this->assertNotEmpty($scope);

        $overview = analytics::get_overview($today, $today, $scope);
        $this->assertSame(0, $overview->questions);
        $this->assertSame(0, $overview->conversations);
        $this->assertSame([], analytics::get_route_breakdown($today, $today, $scope));
    }

    /**
     * An empty filter is "the whole site", not "nothing".
     */
    public function test_empty_filter_resolves_to_no_restriction(): void {
        $this->resetAfterTest();

        $this->assertSame([], analytics::courseids_matching(''));
        $this->assertSame([], analytics::courseids_matching('   '));
    }

    /**
     * Cost is grouped by the model actually called, not by the agent's seeded
     * default, and cached input is billed at its own rate.
     */
    public function test_cost_by_model_uses_the_called_model_and_cached_price(): void {
        $this->resetAfterTest();
        set_config('analytics_prices', '', 'block_openaiagent');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->enable_course($course->id);

        // Two turns of the same agent on two different models: the switch is
        // exactly what the old rollup (grouped by the agent record) could not see.
        $conv = conversation_repository::create($user->id, $course->id);
        conversation_repository::add_message($conv->id, 'assistant', 'a', [
            'route' => 'tutor',
            'agentid' => 7,
            'model' => 'gpt-5-mini',
            'prompttokens' => 1000,
            'cachedtokens' => 800,
            'completiontokens' => 100,
            'totaltokens' => 1100,
        ]);
        conversation_repository::add_message($conv->id, 'assistant', 'a', [
            'route' => 'tutor',
            'agentid' => 7,
            'model' => 'gpt-4.1-mini',
            'prompttokens' => 500,
            'cachedtokens' => 0,
            'completiontokens' => 50,
            'totaltokens' => 550,
        ]);

        analytics::build();

        $today = analytics::day_start(time());
        $cost = analytics::get_cost_by_model($today, $today);
        $this->assertCount(2, $cost);

        $bymodel = [];
        foreach ($cost as $row) {
            $bymodel[$row->model] = $row;
        }
        $this->assertArrayHasKey('gpt-5-mini', $bymodel);
        $this->assertArrayHasKey('gpt-4.1-mini', $bymodel);

        // For gpt-5-mini: 200 uncached in @0.25, 800 cached in @0.025, 100 out @2.0.
        $this->assertSame(800, $bymodel['gpt-5-mini']->cached);
        $this->assertEqualsWithDelta(0.00027, $bymodel['gpt-5-mini']->cost, 0.0000001);
        // For gpt-4.1-mini, no cache hit: 500 in @0.4, 50 out @1.6.
        $this->assertEqualsWithDelta(0.00028, $bymodel['gpt-4.1-mini']->cost, 0.0000001);

        $this->assertEqualsWithDelta(800 / 1500, analytics::get_overview($today, $today)->cachehitrate, 0.0001);
    }

    /**
     * Turns recorded before the model column existed keep the only model
     * information they ever had: their agent's default.
     */
    public function test_cost_by_model_falls_back_to_the_agent_default(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->enable_course($course->id);

        $agentid = $DB->insert_record('block_openaiagent_agents', (object)[
            'name' => 'Tutor',
            'agenttype' => 'tutor',
            'defaultmodel' => 'gpt-4.1-nano',
            'temperature' => 0.2,
            'maxoutputtokens' => 1000,
            'enabled' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => 0,
        ]);

        $conv = conversation_repository::create($user->id, $course->id);
        conversation_repository::add_message($conv->id, 'assistant', 'a', [
            'route' => 'tutor',
            'agentid' => $agentid,
            'prompttokens' => 100,
            'completiontokens' => 10,
            'totaltokens' => 110,
        ]);

        analytics::build();

        $today = analytics::day_start(time());
        $cost = analytics::get_cost_by_model($today, $today);
        $this->assertCount(1, $cost);
        $this->assertSame('gpt-4.1-nano', $cost[0]->model);
    }

    /**
     * The price setting accepts an optional cached-input rate; without it the
     * cached tokens are billed at the full input price rather than a guess.
     */
    public function test_price_map_parses_the_optional_cached_rate(): void {
        $this->resetAfterTest();

        set_config('analytics_prices', "my-model|1|2|0.1\nother-model|3|4", 'block_openaiagent');
        $prices = analytics::get_price_map();

        $this->assertSame([1.0, 2.0, 0.1], $prices['my-model']);
        $this->assertSame([3.0, 4.0, 3.0], $prices['other-model']);
    }

    /**
     * Rebuilding a day is idempotent (no double counting across runs).
     */
    public function test_rebuild_is_idempotent(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->enable_course($course->id);

        $conv = conversation_repository::create($user->id, $course->id);
        conversation_repository::add_message($conv->id, 'user', 'q');

        analytics::build();
        analytics::build();

        $today = analytics::day_start(time());
        $this->assertSame(1, analytics::get_overview($today, $today)->questions);
    }
}
