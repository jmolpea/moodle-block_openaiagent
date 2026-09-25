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
 * Course discovery and enrolment facts shared by the catalogue tools.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform;

use block_openaiagent\local\scope;

/**
 * What a user may learn about a course before being in it.
 *
 * Every check is Moodle's own: a course is discoverable when the user could
 * open its information or enrolment page, which is exactly what
 * {@see \core_course_category::can_view_course_info()} decides (hidden courses,
 * and "moodle/category:viewcourselist" on the category, which is how sites
 * restrict a category to some users), plus the category being visible to them.
 *
 * Enrolment methods are read from every enabled instance, whatever the plugin,
 * so custom methods work without code. The enrolment key and payment gateway
 * details are never returned.
 */
final class catalog {
    /** @var string Self-service, open to anyone who can see the course. */
    public const RESTRICTION_NONE = 'none';

    /** @var string Self-service, with a key the course staff hand out. */
    public const RESTRICTION_KEY = 'enrolment_key';

    /** @var string Self-service, limited to members of one cohort. */
    public const RESTRICTION_COHORT = 'cohort';

    /** @var string Enrolment after payment. */
    public const RESTRICTION_PAYMENT = 'payment';

    /** @var string Guest access, no enrolment. */
    public const RESTRICTION_GUEST = 'guest_access';

    /** @var string Done by the institution, not by the participant. */
    public const RESTRICTION_MANAGED = 'managed_by_institution';

    /** @var string A method this plugin does not know. */
    public const RESTRICTION_OTHER = 'other';

    /** @var string[] Methods that enrol people on the institution's side. */
    private const MANAGED = ['manual', 'cohort', 'meta', 'database', 'ldap', 'flatfile', 'imsenterprise',
        'category', 'lti', 'mnet'];

    /** @var string[] Payment methods. */
    private const PAYMENT = ['fee', 'paypal'];

    /**
     * The course record, when the user may see its information page.
     *
     * @param int $courseid Course id.
     * @param int $userid User id (0 = not logged in).
     * @return \stdClass|null
     */
    public static function discoverable(int $courseid, int $userid): ?\stdClass {
        global $DB;

        if ($courseid <= 0 || $courseid === (int)SITEID) {
            return null;
        }
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || !self::may_see($course, $userid)) {
            return null;
        }
        return $course;
    }

    /**
     * Whether the user may see a course's information page.
     *
     * @param \stdClass $course Course record (id, visible, category).
     * @param int $userid User id (0 = not logged in).
     * @return bool
     */
    public static function may_see(\stdClass $course, int $userid): bool {
        $user = $userid > 0 ? $userid : null;
        if (!\core_course_category::can_view_course_info($course, $user)) {
            return false;
        }
        return \core_course_category::get((int)$course->category, IGNORE_MISSING, false, $user) !== null;
    }

    /**
     * Whether a course sits inside a category or any of its subcategories.
     *
     * @param \stdClass $course Course record.
     * @param int $categoryid Category id.
     * @return bool
     */
    public static function in_category_tree(\stdClass $course, int $categoryid): bool {
        $category = \core_course_category::get((int)$course->category, IGNORE_MISSING, true);
        if (!$category) {
            return false;
        }
        return in_array($categoryid, array_map('intval', explode('/', trim((string)$category->path, '/'))), true);
    }

    /**
     * Every enabled enrolment method of a course, normalised.
     *
     * @param \stdClass $course Course record.
     * @param scope $scope Turn scope.
     * @return array[]
     */
    public static function enrolment_options(\stdClass $course, scope $scope): array {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $options = [];
        foreach (enrol_get_instances($course->id, true) as $instance) {
            $plugin = enrol_get_plugin($instance->enrol);
            if (!$plugin) {
                continue;
            }
            $options[] = self::describe_instance($instance, $plugin, $scope);
        }
        return $options;
    }

    /**
     * One enrolment method, as the model sees it.
     *
     * @param \stdClass $instance Enrolment instance.
     * @param \enrol_plugin $plugin Its plugin.
     * @param scope $scope Turn scope.
     * @return array
     */
    private static function describe_instance(\stdClass $instance, \enrol_plugin $plugin, scope $scope): array {
        $type = (string)$instance->enrol;
        $haskey = trim((string)($instance->password ?? '')) !== '';

        $cost = null;
        if (in_array($type, self::PAYMENT, true)) {
            // Same fallback as the payment plugins' own enrolment page.
            $amount = (float)($instance->cost ?? 0);
            if ($amount <= 0) {
                $amount = (float)$plugin->get_config('cost');
            }
            if ($amount >= 0.01 && !empty($instance->currency)) {
                $cost = \core_payment\helper::get_cost_as_string($amount, (string)$instance->currency);
            }
        }

        if ($type === 'self') {
            $restriction = !empty($instance->customint5) ? self::RESTRICTION_COHORT
                : ($haskey ? self::RESTRICTION_KEY : self::RESTRICTION_NONE);
        } else if (in_array($type, self::PAYMENT, true)) {
            $restriction = self::RESTRICTION_PAYMENT;
        } else if ($type === 'guest') {
            $restriction = self::RESTRICTION_GUEST;
        } else if (in_array($type, self::MANAGED, true)) {
            $restriction = self::RESTRICTION_MANAGED;
        } else {
            $restriction = self::RESTRICTION_OTHER;
        }

        [$available, $reason] = self::availability($instance, $plugin, $restriction, $scope);

        return [
            'type' => $type,
            'name' => format_string($plugin->get_instance_name($instance)),
            'how' => self::how($type, $restriction, $haskey),
            'restriction' => $restriction,
            'requires_payment' => $cost !== null,
            'cost' => $cost,
            'requires_key' => $haskey && in_array($type, ['self', 'guest'], true),
            'enrolment_opens' => self::date((int)($instance->enrolstartdate ?? 0)),
            'enrolment_closes' => self::date((int)($instance->enrolenddate ?? 0)),
            'available_to_this_user' => $available,
            'not_available_reason' => $reason,
        ];
    }

    /**
     * Whether this user could use a method now, and why not.
     *
     * The reason is worked out from the instance's own settings, never from
     * the plugin's message, which is HTML meant for the enrolment page and, for
     * a cohort, names the cohort.
     *
     * @param \stdClass $instance Enrolment instance.
     * @param \enrol_plugin $plugin Its plugin.
     * @param string $restriction Normalised restriction.
     * @param scope $scope Turn scope.
     * @return array [bool|null available, string|null reason]
     */
    private static function availability(\stdClass $instance, \enrol_plugin $plugin, string $restriction, scope $scope): array {
        global $DB, $USER;

        if ($restriction === self::RESTRICTION_MANAGED || $restriction === self::RESTRICTION_OTHER) {
            return [null, null];
        }
        if ($scope->is_visitor()) {
            return [null, 'requires_login'];
        }

        $userid = $scope->userid;
        $now = time();
        if ($DB->record_exists('user_enrolments', ['userid' => $userid, 'enrolid' => $instance->id])) {
            return [false, 'already_enrolled'];
        }
        if (!empty($instance->enrolstartdate) && (int)$instance->enrolstartdate > $now) {
            return [false, 'not_open_yet'];
        }
        if (!empty($instance->enrolenddate) && (int)$instance->enrolenddate < $now) {
            return [false, 'closed'];
        }
        if ($instance->enrol !== 'self') {
            return [$plugin->show_enrolme_link($instance) ? true : false, null];
        }

        if (empty($instance->customint6)) {
            return [false, 'new_enrolments_disabled'];
        }
        if (
            (int)$instance->customint3 > 0
                && $DB->count_records('user_enrolments', ['enrolid' => $instance->id]) >= (int)$instance->customint3
        ) {
            return [false, 'full'];
        }
        if (!empty($instance->customint5) && !cohort_is_member((int)$instance->customint5, $userid)) {
            return [false, 'cohort_only'];
        }
        // The plugin's own final word, which reads the session user.
        if ((int)$USER->id === $userid && $plugin->can_self_enrol($instance, false) !== true) {
            return [false, 'not_permitted'];
        }
        return [true, $restriction === self::RESTRICTION_KEY ? 'needs_key' : null];
    }

    /**
     * A plain description of how a method works, for the model to put in its own words.
     *
     * @param string $type Enrolment plugin name.
     * @param string $restriction Normalised restriction.
     * @param bool $haskey Whether a key is set.
     * @return string
     */
    private static function how(string $type, string $restriction, bool $haskey): string {
        switch ($restriction) {
            case self::RESTRICTION_NONE:
                return 'Self enrolment: the participant enrols themselves from the course page.';
            case self::RESTRICTION_KEY:
                return 'Self enrolment with an enrolment key that the course staff provide.';
            case self::RESTRICTION_COHORT:
                return 'Self enrolment, only for members of a specific group of users set by the institution.';
            case self::RESTRICTION_PAYMENT:
                return 'Enrolment after paying on the course page.';
            case self::RESTRICTION_GUEST:
                return 'Guest access: the course can be viewed without enrolling'
                    . ($haskey ? ', with a guest key from the course staff.' : '.');
            case self::RESTRICTION_MANAGED:
                return 'Enrolment done by the institution, not by the participant.';
            default:
                return 'Enrolment method "' . $type . '" configured by the institution.';
        }
    }

    /**
     * A readable date in the user's timezone, or null when not set.
     *
     * @param int $timestamp Unix time.
     * @return string|null
     */
    private static function date(int $timestamp): ?string {
        return $timestamp > 0 ? userdate($timestamp, '%Y-%m-%d %H:%M', 99, false, false) : null;
    }
}
