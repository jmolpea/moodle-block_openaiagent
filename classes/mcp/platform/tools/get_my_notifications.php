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
 * Platform tool: how many messages and notifications the participant has not read.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\tools;

use block_openaiagent\local\scope;
use block_openaiagent\mcp\platform\base_tool;

/**
 * Unread counts, plus the subject and date of the latest unread notifications.
 *
 * Private messages are never read: only how many conversations have something
 * unread. Notifications are system-generated, so their subject is sent, but
 * never their body, nor who triggered them.
 */
class get_my_notifications extends base_tool {
    /** @var int Unread notifications described. */
    private const LATEST = 5;

    /**
     * Tool name.
     *
     * @return string
     */
    public function name(): string {
        return 'moodle.get_my_notifications';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public function description(): string {
        return 'Return how many conversations have unread messages and how many notifications are unread, '
            . 'with the subject and date of the latest ' . self::LATEST . ' unread notifications. The content '
            . 'of messages is never available: point the participant to the messages or notifications page.';
    }

    /**
     * Input schema.
     *
     * @return array
     */
    public function input_schema(): array {
        return self::schema();
    }

    /**
     * Run the tool.
     *
     * @param array $input Arguments (none).
     * @param scope $scope Turn scope.
     * @return array
     */
    public function execute(array $input, scope $scope): array {
        global $CFG;

        $user = \core_user::get_user($scope->userid, '*', MUST_EXIST);
        $unreadconversations = empty($CFG->messaging) ? null : (int)\core_message\api::count_unread_conversations($user);

        $latest = [];
        foreach (\message_popup\api::get_popup_notifications($scope->userid, 'DESC', 50) as $notification) {
            if (!empty($notification->timeread)) {
                continue;
            }
            $latest[] = [
                'subject' => self::plain((string)$notification->subject, 200),
                'from_component' => self::component_name((string)($notification->component ?? '')),
                'date' => self::date((int)$notification->timecreated),
            ];
            if (count($latest) >= self::LATEST) {
                break;
            }
        }

        return [
            'unread_conversations' => $unreadconversations,
            'unread_notifications' => (int)\message_popup\api::count_unread_popup_notifications($scope->userid),
            'latest_unread_notifications' => $latest,
            'messages_url' => (new \moodle_url('/message/index.php'))->out(false),
            'notifications_url' => (new \moodle_url('/message/output/popup/notifications.php'))->out(false),
        ];
    }

    /**
     * Human name of the component that raised a notification.
     *
     * @param string $component Frankenstyle name.
     * @return string
     */
    private static function component_name(string $component): string {
        if ($component === '' || $component === 'moodle') {
            return get_string('site');
        }
        $sm = get_string_manager();
        return $sm->string_exists('pluginname', $component) ? get_string('pluginname', $component) : $component;
    }
}
