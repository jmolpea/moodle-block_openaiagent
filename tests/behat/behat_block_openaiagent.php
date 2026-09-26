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
 * Behat steps for block_openaiagent.
 *
 * @package    block_openaiagent
 * @category   test
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Moodle\BehatExtension\Exception\SkippedException;

/**
 * Behat steps for block_openaiagent.
 */
class behat_block_openaiagent extends behat_base {
    /**
     * Set Moodle's own "Allow AI tools for this course" switch.
     *
     * The switch exists from Moodle 5.1, so on 4.5 and 5.0 the scenario is
     * skipped rather than failed: there is nothing to honour there.
     *
     * @Given /^Moodle AI tools are (enabled|disabled) in the "(?P<shortname_string>(?:[^"]|\\")*)" course$/
     * @param string $state "enabled" or "disabled".
     * @param string $shortname Course short name.
     */
    public function moodle_ai_tools_are_in_the_course(string $state, string $shortname): void {
        global $DB;

        if (!method_exists(\core_ai\manager::class, 'is_ai_tools_enabled_in_course')) {
            throw new SkippedException('The per-course AI switch exists from Moodle 5.1.');
        }
        $courseid = $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $DB->set_field('course', 'enableaitools', $state === 'enabled' ? 1 : 0, ['id' => $courseid]);
    }

    /**
     * Open or close the assistant of a category to visitors who are not logged in.
     *
     * The same stored setting the block form writes for a manager.
     *
     * @Given /^the assistant of the "(?P<idnumber_string>(?:[^"]|\\")*)" category is (open|closed) to visitors$/
     * @param string $idnumber Category id number.
     * @param string $state "open" or "closed".
     */
    public function the_assistant_of_the_category_is_to_visitors(string $idnumber, string $state): void {
        global $DB;

        $categoryid = $DB->get_field('course_categories', 'id', ['idnumber' => $idnumber], MUST_EXIST);
        $context = \context_coursecat::instance($categoryid);
        $block = $DB->get_record(
            'block_instances',
            ['blockname' => 'openaiagent', 'parentcontextid' => $context->id],
            '*',
            MUST_EXIST
        );
        $config = $block->configdata ? unserialize_object(base64_decode($block->configdata)) : new \stdClass();
        $config->visitors = $state === 'open' ? 1 : 0;
        $DB->set_field('block_instances', 'configdata', base64_encode(serialize($config)), ['id' => $block->id]);
    }
}
