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
 * Platform tool: search the course catalogue.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\tools;

use block_openaiagent\local\block_settings;
use block_openaiagent\local\scope;
use block_openaiagent\mcp\platform\base_tool;
use block_openaiagent\mcp\platform\catalog;

/**
 * Courses the user may discover, with what the model needs to recommend them.
 *
 * Courses come from Moodle's own listing and search, which already apply
 * course and category visibility for the user; each one is checked again with
 * {@see catalog::may_see()}. In a category block the catalogue is limited to
 * that category and its subcategories. Courses that have ended are left out,
 * except the participant's own, which are marked.
 */
class search_catalog extends base_tool {
    /** @var int Courses read per page before filtering. */
    private const BATCH = 100;

    /**
     * Tool name.
     *
     * @return string
     */
    public function name(): string {
        return 'moodle.search_catalog';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public function description(): string {
        return 'Search the course catalogue the participant can see (in a category assistant, only that '
            . 'category and its subcategories). With no query, lists the catalogue. Each course has name, url, '
            . 'enrol_url, category, summary, dates, tags, visible custom fields, teachers and a summary of its '
            . 'enrolment methods (cost when it requires payment). Courses that have ended are not listed, '
            . 'except the participant\'s own (is_mine). Use one or two keywords; if nothing matches, try '
            . 'synonyms or a broader word. Justify every recommendation with the course\'s own summary, tags '
            . 'or fields, never with invented details.';
    }

    /**
     * Input schema.
     *
     * @return array
     */
    public function input_schema(): array {
        return self::schema([
            'query' => [
                'type' => 'string',
                'description' => 'Optional. Keywords to search in course names and summaries.',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Optional. Courses to return, 1-' . self::MAX_ITEMS . '. Default 10.',
            ],
            'offset' => [
                'type' => 'integer',
                'description' => 'Optional. Courses to skip, for the next page.',
            ],
        ]);
    }

    /**
     * Visitors may browse the catalogue.
     *
     * @return string[]
     */
    public function audiences(): array {
        return [scope::AUTHENTICATED, scope::VISITOR];
    }

    /**
     * Run the tool.
     *
     * @param array $input Arguments.
     * @param scope $scope Turn scope.
     * @return array
     */
    public function execute(array $input, scope $scope): array {
        $query = trim(\core_text::substr(clean_param((string)($input['query'] ?? ''), PARAM_TEXT), 0, 100));
        $limit = self::int_arg($input, 'limit', 10, 1, self::MAX_ITEMS);
        $offset = self::int_arg($input, 'offset', 0, 0, 1000);

        $mine = [];
        if (!$scope->is_visitor()) {
            $mine = array_fill_keys(array_keys(enrol_get_all_users_courses($scope->userid, false, 'id')), true);
        }

        // Read in batches until the page is full: filtering happens after
        // Moodle's own query, so a page of raw results can shrink.
        $matches = [];
        $skipped = 0;
        $more = false;
        for ($from = 0; $from < 1000; $from += self::BATCH) {
            $batch = $this->fetch($query, $scope, $from);
            foreach ($batch as $course) {
                if (!$this->keep($course, $scope, isset($mine[$course->id]))) {
                    continue;
                }
                if ($skipped < $offset) {
                    $skipped++;
                    continue;
                }
                if (count($matches) >= $limit) {
                    $more = true;
                    break 2;
                }
                $matches[] = $course;
            }
            if (count($batch) < self::BATCH) {
                break;
            }
        }

        $out = [];
        foreach ($matches as $course) {
            $out[] = $this->describe($course, $scope, isset($mine[$course->id]));
        }
        return [
            'query' => $query,
            'limited_to_category' => self::limited($scope)
                ? \core_course_category::get($scope->categoryid, IGNORE_MISSING, true)?->get_formatted_name()
                : null,
            'has_more' => $more,
            'courses' => $out,
        ];
    }

    /**
     * Whether the catalogue is limited to the block's category tree.
     *
     * A category assistant is limited by default; its settings can open it to
     * the whole site catalogue.
     *
     * @param scope $scope Turn scope.
     * @return bool
     */
    private static function limited(scope $scope): bool {
        return $scope->type === scope::CATEGORY && block_settings::catalog_limited_to_category($scope->blockinstanceid);
    }

    /**
     * One batch of candidate courses from Moodle's listing or search.
     *
     * @param string $query Keywords, or '' to list.
     * @param scope $scope Turn scope.
     * @param int $from Offset.
     * @return \core_course_list_element[]
     */
    private function fetch(string $query, scope $scope, int $from): array {
        $options = ['offset' => $from, 'limit' => self::BATCH, 'summary' => true, 'coursecontacts' => true];
        if ($query !== '') {
            return \core_course_category::search_courses(['search' => $query], $options);
        }
        $root = self::limited($scope)
            ? \core_course_category::get($scope->categoryid, IGNORE_MISSING)
            : \core_course_category::top();
        if (!$root) {
            return [];
        }
        return $root->get_courses($options + ['recursive' => true]);
    }

    /**
     * Whether a course belongs in the answer.
     *
     * @param \core_course_list_element $course Candidate.
     * @param scope $scope Turn scope.
     * @param bool $ismine Whether the user is or was enrolled.
     * @return bool
     */
    private function keep(\core_course_list_element $course, scope $scope, bool $ismine): bool {
        $record = (object)['id' => $course->id, 'visible' => $course->visible, 'category' => $course->category];
        if ((int)$course->id === (int)SITEID || !catalog::may_see($record, $scope->userid)) {
            return false;
        }
        if (self::limited($scope) && !catalog::in_category_tree($record, $scope->categoryid)) {
            return false;
        }
        $ended = (int)$course->enddate > 0 && (int)$course->enddate < time();
        return !$ended || $ismine;
    }

    /**
     * One course, as the model sees it.
     *
     * @param \core_course_list_element $course Course.
     * @param scope $scope Turn scope.
     * @param bool $ismine Whether the user is or was enrolled.
     * @return array
     */
    private function describe(\core_course_list_element $course, scope $scope, bool $ismine): array {
        $context = \context_course::instance($course->id);
        $category = \core_course_category::get((int)$course->category, IGNORE_MISSING, true);

        $teachers = [];
        foreach ($course->get_course_contacts() as $contact) {
            $teachers[] = $contact['rolename'] . ': ' . $contact['username'];
        }

        $methods = [];
        foreach (catalog::enrolment_options(get_course($course->id), $scope) as $method) {
            $methods[] = array_intersect_key($method, array_flip(['type', 'restriction', 'cost']));
        }

        return [
            'id' => (int)$course->id,
            'name' => format_string($course->fullname, true, ['context' => $context]),
            'shortname' => format_string($course->shortname, true, ['context' => $context]),
            'url' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
            'enrol_url' => (new \moodle_url('/enrol/index.php', ['id' => $course->id]))->out(false),
            'category' => $category ? $category->get_nested_name(false) : null,
            'summary' => self::plain(format_text($course->summary, $course->summaryformat, [
                'context' => $context,
                'noclean' => false,
            ])),
            'start_date' => self::date((int)$course->startdate),
            'end_date' => self::date((int)$course->enddate),
            'tags' => array_values(\core_tag_tag::get_item_tags_array('core', 'course', $course->id)),
            'fields' => self::custom_fields((int)$course->id),
            'teachers' => $teachers,
            'is_mine' => $ismine,
            'enrolment_methods' => $methods,
        ];
    }

    /**
     * Course custom fields with a value that this user may see.
     *
     * @param int $courseid Course id.
     * @return array Field name => plain value.
     */
    private static function custom_fields(int $courseid): array {
        $handler = \core_course\customfield\course_handler::create();
        $fields = [];
        foreach ($handler->get_instance_data($courseid, true) as $data) {
            $field = $data->get_field();
            if (!$handler->can_view($field, $courseid)) {
                continue;
            }
            $value = self::plain((string)$data->export_value(), 300);
            if ($value !== '') {
                $fields[$field->get_formatted_name(false)] = $value;
            }
        }
        return $fields;
    }
}
