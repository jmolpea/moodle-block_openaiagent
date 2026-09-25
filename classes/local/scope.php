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
 * Where an assistant lives and who is talking to it.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * The scope of one assistant turn, decided on the server.
 *
 * A block placed in a course is a course assistant and behaves exactly as it
 * always has. A block placed in a category or on the site home is a platform
 * assistant: it works with the participant's own data across their courses
 * and with the course catalogue, not with one course's content.
 *
 * The scope is always derived from the block instance's own context, never
 * from anything the browser says. The browser may name the course of the page
 * it is on, and that is only kept after checking it belongs to the block's
 * category and the user can see it.
 */
final class scope {
    /** @var string Block placed in a course or one of its activities. */
    public const COURSE = 'course';

    /** @var string Block placed in a course category. */
    public const CATEGORY = 'category';

    /** @var string Block placed on the site home or at system level. */
    public const SITE = 'site';

    /** @var string Block placed on a Dashboard, which this plugin does not support. */
    public const DASHBOARD = 'dashboard';

    /** @var string A logged-in, non-guest user. */
    public const AUTHENTICATED = 'authenticated';

    /** @var string Not logged in, or logged in as guest. */
    public const VISITOR = 'visitor';

    /**
     * Constructor. Use {@see self::for_block()} or {@see self::for_request()}.
     *
     * @param string $type One of the scope type constants.
     * @param string $audience One of the audience constants.
     * @param int $courseid Owning course id: the course, or SITEID outside a course.
     * @param int $categoryid Category of a category block, 0 otherwise.
     * @param int $blockinstanceid Block instance id (0 = legacy course-wide profile).
     * @param int $userid User id (0 for a visitor who is not logged in).
     * @param \context $context Context for capability checks.
     * @param int $pagecourseid Validated course of the page a category block is shown on, 0 if none.
     * @param bool $pagecourseenrolled Whether the user is actively enrolled in that course.
     */
    private function __construct(
        /** @var string One of the scope type constants. */
        public readonly string $type,
        /** @var string One of the audience constants. */
        public readonly string $audience,
        /** @var int Owning course id: the course, or SITEID outside a course. */
        public readonly int $courseid,
        /** @var int Category of a category block, 0 otherwise. */
        public readonly int $categoryid,
        /** @var int Block instance id (0 = legacy course-wide profile). */
        public readonly int $blockinstanceid,
        /** @var int User id (0 for a visitor who is not logged in). */
        public readonly int $userid,
        /** @var \context Context for capability checks. */
        public readonly \context $context,
        /** @var int Validated course of the page a category block is shown on, 0 if none. */
        public readonly int $pagecourseid = 0,
        /** @var bool Whether the user is actively enrolled in that course. */
        public readonly bool $pagecourseenrolled = false
    ) {
    }

    /**
     * Whether this is a course assistant.
     *
     * @return bool
     */
    public function is_course(): bool {
        return $this->type === self::COURSE;
    }

    /**
     * Whether this is a platform assistant (category or site home).
     *
     * @return bool
     */
    public function is_platform(): bool {
        return $this->type === self::CATEGORY || $this->type === self::SITE;
    }

    /**
     * Whether the user is a visitor.
     *
     * @return bool
     */
    public function is_visitor(): bool {
        return $this->audience === self::VISITOR;
    }

    /**
     * The audience a user id belongs to.
     *
     * @param int $userid User id.
     * @return string
     */
    public static function audience_of(int $userid): string {
        return ($userid <= 0 || isguestuser($userid)) ? self::VISITOR : self::AUTHENTICATED;
    }

    /**
     * Resolve the scope of a block instance.
     *
     * @param int $blockinstanceid Block instance id; must be a block of this plugin.
     * @param int $userid User id.
     * @param int $pagecourseid Course of the page the block is shown on (0 = none). Only
     *     used by category blocks, and only kept when it passes the checks.
     * @return self
     * @throws \dml_missing_record_exception When the id is not a block of this plugin.
     */
    public static function for_block(int $blockinstanceid, int $userid, int $pagecourseid = 0): self {
        global $DB;

        $block = $DB->get_record(
            'block_instances',
            ['id' => $blockinstanceid, 'blockname' => 'openaiagent'],
            'id, parentcontextid',
            MUST_EXIST
        );
        $parent = \context::instance_by_id((int)$block->parentcontextid);
        $blockcontext = \context_block::instance($blockinstanceid);
        $audience = self::audience_of($userid);

        $coursecontext = $parent->get_course_context(false);
        if ($coursecontext && (int)$coursecontext->instanceid !== (int)SITEID) {
            // Placed in a course or one of its activities: the course assistant,
            // checked against the course context exactly as before.
            return new self(
                self::COURSE,
                $audience,
                (int)$coursecontext->instanceid,
                0,
                $blockinstanceid,
                $userid,
                $coursecontext
            );
        }

        if ($parent->contextlevel == CONTEXT_COURSECAT) {
            $categoryid = (int)$parent->instanceid;
            [$pageid, $enrolled] = self::validate_page_course($pagecourseid, $categoryid, $userid);
            return new self(
                self::CATEGORY,
                $audience,
                (int)SITEID,
                $categoryid,
                $blockinstanceid,
                $userid,
                $blockcontext,
                $pageid,
                $enrolled
            );
        }

        if ($parent->contextlevel == CONTEXT_USER) {
            return new self(self::DASHBOARD, $audience, (int)SITEID, 0, $blockinstanceid, $userid, $blockcontext);
        }

        // Site home (the site course) or system level.
        return new self(self::SITE, $audience, (int)SITEID, 0, $blockinstanceid, $userid, $blockcontext);
    }

    /**
     * Resolve and validate the scope named by a web-service request.
     *
     * The browser sends the owning course id and the block id it was rendered
     * with. Both come from the server in the first place, so a pair that does
     * not match is a forged or stale request and is refused. With no block id
     * (the legacy course-wide profile) the course id is the scope.
     *
     * @param int $courseid Course id sent by the client.
     * @param int $blockinstanceid Block id sent by the client (0 = legacy profile).
     * @param int $userid Session user id.
     * @param int $pagecourseid Course of the page, for a category block (0 = none).
     * @return self
     * @throws \invalid_parameter_exception When the pair does not match.
     */
    public static function for_request(int $courseid, int $blockinstanceid, int $userid, int $pagecourseid = 0): self {
        if ($blockinstanceid <= 0) {
            $context = \context_course::instance($courseid);
            return new self(self::COURSE, self::audience_of($userid), $courseid, 0, 0, $userid, $context);
        }

        try {
            $scope = self::for_block($blockinstanceid, $userid, $pagecourseid);
        } catch (\dml_missing_record_exception $e) {
            throw new \invalid_parameter_exception('Unknown assistant block');
        }
        if ($scope->courseid !== $courseid) {
            throw new \invalid_parameter_exception('Block does not belong to this course');
        }
        return $scope;
    }

    /**
     * Keep the page's course only when it sits under the block's category.
     *
     * A category block shown throughout its category knows which course the
     * participant is looking at. That id comes from the browser, so it is only
     * kept when the course is inside the block's category tree and the user can
     * see it; anything else is dropped rather than reported, since a platform
     * assistant works perfectly well without it.
     *
     * @param int $pagecourseid Course id from the client.
     * @param int $categoryid The block's category.
     * @param int $userid User id.
     * @return array [validated course id or 0, whether the user is actively enrolled]
     */
    private static function validate_page_course(int $pagecourseid, int $categoryid, int $userid): array {
        global $DB;

        if ($pagecourseid <= 0 || $pagecourseid === (int)SITEID) {
            return [0, false];
        }
        $course = $DB->get_record('course', ['id' => $pagecourseid], 'id, category, visible');
        if (!$course) {
            return [0, false];
        }
        // Returns null when the category is hidden from this user.
        $category = \core_course_category::get((int)$course->category, IGNORE_MISSING, false, $userid ?: null);
        if (!$category) {
            return [0, false];
        }
        $path = array_map('intval', explode('/', trim((string)$category->path, '/')));
        if (!in_array($categoryid, $path, true)) {
            return [0, false];
        }

        $context = \context_course::instance($pagecourseid);
        if (!$course->visible && !has_capability('moodle/course:viewhiddencourses', $context, $userid)) {
            return [0, false];
        }
        $enrolled = $userid > 0 && is_enrolled($context, $userid, '', true);
        return [$pagecourseid, $enrolled];
    }
}
