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
 * Builds the interactive actions the chat shows alongside a reply.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Turns a pending support draft into a chat action card.
 *
 * The transport is deliberately generic — every action is
 * {type, id, label, payload} — so a later interaction (a satisfaction survey, a
 * "open this activity" button) is a new type and a new renderer, without
 * reopening the web service contract. The payload travels as a JSON string
 * because Moodle's external API is strongly typed and has no shape for free
 * form; that is acceptable here because the payload is written by the server,
 * never by a user.
 */
class support_action {
    /** @var string Action type understood by the chat renderer. */
    public const TYPE_CONFIRM = 'confirm_support';

    /**
     * The actions a conversation should be showing right now.
     *
     * Called both after a turn and when the chat is reopened, so a participant
     * who closed the window without answering still finds the card waiting.
     *
     * @param int $conversationid Conversation id.
     * @param array $config Effective course config.
     * @return array[] Zero or one action.
     */
    public static function pending(int $conversationid, array $config): array {
        $draft = supportrequest::pending_draft($conversationid);
        if ($draft === null) {
            return [];
        }

        return [self::confirm_action($draft, $config)];
    }

    /**
     * Build the confirmation card for a draft.
     *
     * @param \stdClass $draft Draft record.
     * @param array $config Effective course config.
     * @return array Action definition.
     */
    public static function confirm_action(\stdClass $draft, array $config): array {
        $sla = trim((string)($config['support']['slatext'] ?? ''));

        $payload = [
            'token' => (string)$draft->token,
            'title' => get_string('support_card_title', 'block_openaiagent'),
            'summary' => (string)$draft->summary,
            'reference' => (string)$draft->ticketref,
            'category' => (string)$draft->category,
            // Spelled out on the card itself. Telling somebody what is about to
            // be sent about them, at the moment they decide, is the whole point
            // of asking rather than sending.
            'privacynotice' => get_string('support_card_privacy', 'block_openaiagent'),
            // Kept apart from the notice so the card can emphasise it without
            // ever rendering the string as markup.
            'privacyemail' => get_string('support_card_privacy_email', 'block_openaiagent'),
            'confirmlabel' => get_string('support_card_confirm', 'block_openaiagent'),
            'cancellabel' => get_string('support_card_cancel', 'block_openaiagent'),
            'slatext' => $sla,
        ];

        return [
            'type' => self::TYPE_CONFIRM,
            'id' => (int)$draft->id,
            'label' => get_string('support_card_confirm', 'block_openaiagent'),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * The external-function description of one action.
     *
     * Kept here so send_message and get_conversation cannot drift apart.
     *
     * @return \core_external\external_single_structure
     */
    public static function external_structure(): \core_external\external_single_structure {
        return new \core_external\external_single_structure([
            'type' => new \core_external\external_value(PARAM_ALPHANUMEXT, 'Action type'),
            'id' => new \core_external\external_value(PARAM_INT, 'Identifier the action acts on'),
            'label' => new \core_external\external_value(PARAM_TEXT, 'Label of the primary button'),
            'payload' => new \core_external\external_value(PARAM_RAW, 'JSON payload, server-generated'),
        ]);
    }
}
