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
 * Configuration interpreter for mod_forum.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\config;

use cm_info;

/**
 * Explains why a forum behaves the way it does for this participant.
 *
 * The case this was written for: a participant asked why they could not see
 * their classmates' replies. The forum had no access restrictions at all, so
 * the availability API reported it as fully available and the assistant
 * answered "this forum has no restrictions" — correct, and useless. The forum
 * was a Q&A forum, where Moodle hides other people's posts until you have
 * posted your own and the editing window has passed. That rule lives in
 * forum_user_can_see_post(), not in core_availability, and nothing outside
 * mod_forum could see it.
 */
class forum extends interpreter {
    /**
     * Forum-type rules plus posting windows.
     *
     * @param \stdClass $instance Forum instance record.
     * @param cm_info $cm Course module info, built for the target user.
     * @param int $userid Target user id.
     * @return string[] Plain-language rules.
     */
    public static function rules(\stdClass $instance, cm_info $cm, int $userid): array {
        global $CFG;

        unset($cm, $userid);

        $rules = [];
        $type = (string)($instance->type ?? '');

        switch ($type) {
            case 'qanda':
                // Two separate facts, and participants hit them in this order.
                // The visibility requirement is evaluated per discussion, not
                // once for the whole forum, which is why someone who has posted
                // in one thread still sees nothing in another.
                $rules[] = get_string('actcfg_forum_qanda', 'block_openaiagent');
                $rules[] = get_string(
                    'actcfg_forum_qanda_delay',
                    'block_openaiagent',
                    format_time((int)($CFG->maxeditingtime ?? 1800))
                );
                break;
            case 'single':
                $rules[] = get_string('actcfg_forum_single', 'block_openaiagent');
                break;
            case 'eachuser':
                $rules[] = get_string('actcfg_forum_eachuser', 'block_openaiagent');
                break;
            case 'news':
                $rules[] = get_string('actcfg_forum_news', 'block_openaiagent');
                break;
            case 'blog':
                $rules[] = get_string('actcfg_forum_blog', 'block_openaiagent');
                break;
        }

        $cutoff = (int)($instance->cutoffdate ?? 0);
        if ($cutoff > 0) {
            $rules[] = $cutoff < time()
                ? get_string('actcfg_forum_cutoff_past', 'block_openaiagent', userdate($cutoff))
                : get_string('actcfg_forum_cutoff_future', 'block_openaiagent', userdate($cutoff));
        }

        $due = (int)($instance->duedate ?? 0);
        if ($due > 0) {
            $rules[] = get_string('actcfg_forum_due', 'block_openaiagent', userdate($due));
        }

        $lockafter = (int)($instance->lockdiscussionafter ?? 0);
        if ($lockafter > 0) {
            $rules[] = get_string('actcfg_forum_lock', 'block_openaiagent', format_time($lockafter));
        }

        return $rules;
    }

    /**
     * Whether the participant has posted, and whether the editing window passed.
     *
     * Reported at forum level because the tool is addressed by cmid, while
     * Moodle evaluates Q&A visibility per discussion. The rule text says so, so
     * the assistant does not promise more precision than this carries.
     *
     * @param \stdClass $instance Forum instance record.
     * @param cm_info $cm Course module info.
     * @param int $userid Target user id.
     * @return array Scalar map.
     */
    public static function user_state(\stdClass $instance, cm_info $cm, int $userid): array {
        global $CFG, $DB;

        unset($cm);

        $forumid = (int)($instance->id ?? 0);
        if ($forumid <= 0) {
            return [];
        }

        $lastpost = $DB->get_field_sql(
            "SELECT MAX(p.created)
               FROM {forum_posts} p
               JOIN {forum_discussions} d ON d.id = p.discussion
              WHERE d.forum = :forumid AND p.userid = :userid",
            ['forumid' => $forumid, 'userid' => $userid]
        );

        $state = [
            'has_posted_in_forum' => !empty($lastpost),
        ];

        if ((string)($instance->type ?? '') !== 'qanda') {
            return $state;
        }

        $editingtime = (int)($CFG->maxeditingtime ?? 1800);
        $state['editing_period_seconds'] = $editingtime;
        $state['editing_period_elapsed'] = !empty($lastpost) && (time() - (int)$lastpost) >= $editingtime;

        return $state;
    }
}
