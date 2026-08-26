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
 * Tests for conversation and message persistence.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for the conversation repository.
 *
 * @covers \block_openaiagent\local\conversation_repository
 */
final class conversation_repository_test extends \advanced_testcase {
    /**
     * A conversation cannot be loaded by a different user (cross-user guard).
     */
    public function test_get_owned_blocks_cross_user(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_user();
        $attacker = $this->getDataGenerator()->create_user();

        $conversation = conversation_repository::create($owner->id, $course->id);

        $this->assertNotNull(
            conversation_repository::get_owned($conversation->id, $owner->id, $course->id)
        );
        $this->assertNull(
            conversation_repository::get_owned($conversation->id, $attacker->id, $course->id)
        );
    }

    /**
     * A conversation cannot be loaded from a different course.
     */
    public function test_get_owned_blocks_cross_course(): void {
        $this->resetAfterTest();

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $conversation = conversation_repository::create($user->id, $course1->id);

        $this->assertNull(
            conversation_repository::get_owned($conversation->id, $user->id, $course2->id)
        );
    }

    /**
     * A conversation created for one block instance is not visible to another
     * block instance in the same course (per-assistant isolation).
     */
    public function test_get_owned_blocks_cross_block_instance(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $conversation = conversation_repository::create($user->id, $course->id, 101);

        $this->assertNotNull(
            conversation_repository::get_owned($conversation->id, $user->id, $course->id, 101)
        );
        // Same course and user, different block instance: not owned.
        $this->assertNull(
            conversation_repository::get_owned($conversation->id, $user->id, $course->id, 202)
        );
        // The legacy course-wide profile (0) is also isolated from block profiles.
        $this->assertNull(
            conversation_repository::get_owned($conversation->id, $user->id, $course->id, 0)
        );
    }

    /**
     * reset only clears the target block instance's conversations, leaving the
     * other assistants in the same course untouched.
     */
    public function test_reset_is_scoped_to_block_instance(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $conva = conversation_repository::create($user->id, $course->id, 101);
        $convb = conversation_repository::create($user->id, $course->id, 202);

        conversation_repository::reset($user->id, $course->id, 101);

        $this->assertFalse($DB->record_exists('block_openaiagent_conversations', ['id' => $conva->id]));
        $this->assertTrue($DB->record_exists('block_openaiagent_conversations', ['id' => $convb->id]));
    }

    /**
     * Message content is stored when message logging is enabled.
     */
    public function test_add_message_stores_content_when_logging_enabled(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('log_messages', 1, 'block_openaiagent');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $conversation = conversation_repository::create($user->id, $course->id);

        $id = conversation_repository::add_message($conversation->id, 'user', 'secret content');
        $record = $DB->get_record('block_openaiagent_messages', ['id' => $id]);

        $this->assertSame('secret content', $record->content);
    }

    /**
     * Message content is suppressed when message logging is disabled, but
     * metadata is still retained.
     */
    public function test_add_message_suppresses_content_when_logging_disabled(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('log_messages', 0, 'block_openaiagent');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $conversation = conversation_repository::create($user->id, $course->id);

        $id = conversation_repository::add_message($conversation->id, 'assistant', 'private reply', [
            'route' => 'tutor',
            'totaltokens' => 42,
        ]);
        $record = $DB->get_record('block_openaiagent_messages', ['id' => $id]);

        $this->assertSame('', $record->content);
        $this->assertSame('tutor', $record->route);
        $this->assertSame(42, (int)$record->totaltokens);
    }

    /**
     * get_messages enforces ownership before returning rows.
     */
    public function test_get_messages_ownership(): void {
        $this->resetAfterTest();
        set_config('log_messages', 1, 'block_openaiagent');

        $course = $this->getDataGenerator()->create_course();
        $owner = $this->getDataGenerator()->create_user();
        $attacker = $this->getDataGenerator()->create_user();
        $conversation = conversation_repository::create($owner->id, $course->id);
        conversation_repository::add_message($conversation->id, 'user', 'hi');

        $this->assertCount(
            1,
            conversation_repository::get_messages($conversation->id, $owner->id, $course->id)
        );
        $this->assertCount(
            0,
            conversation_repository::get_messages($conversation->id, $attacker->id, $course->id)
        );
    }

    /**
     * reset removes the user's conversations and their messages only.
     */
    public function test_reset_deletes_user_data(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('log_messages', 1, 'block_openaiagent');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $conversation = conversation_repository::create($user->id, $course->id);
        conversation_repository::add_message($conversation->id, 'user', 'hi');
        $otherconv = conversation_repository::create($other->id, $course->id);
        conversation_repository::add_message($otherconv->id, 'user', 'hey');

        conversation_repository::reset($user->id, $course->id);

        $this->assertFalse($DB->record_exists('block_openaiagent_conversations', ['id' => $conversation->id]));
        $this->assertSame(0, $DB->count_records(
            'block_openaiagent_messages',
            ['conversationid' => $conversation->id]
        ));
        // The other user's data is untouched.
        $this->assertTrue($DB->record_exists('block_openaiagent_conversations', ['id' => $otherconv->id]));
    }

    /**
     * purge_older_than deletes stale conversations and their messages while
     * keeping recently modified ones, and leaves no orphan messages.
     */
    public function test_purge_older_than_removes_stale_only(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('log_messages', 1, 'block_openaiagent');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        // Old conversation (modified 100 days ago).
        $old = conversation_repository::create($user->id, $course->id);
        conversation_repository::add_message($old->id, 'user', 'old');
        $DB->set_field(
            'block_openaiagent_conversations',
            'timemodified',
            time() - (100 * DAYSECS),
            ['id' => $old->id]
        );

        // Recent conversation (modified now).
        $recent = conversation_repository::create($user->id, $course->id);
        conversation_repository::add_message($recent->id, 'user', 'recent');

        $cutoff = time() - (30 * DAYSECS);
        $deleted = conversation_repository::purge_older_than($cutoff);

        $this->assertSame(1, $deleted);
        $this->assertFalse($DB->record_exists('block_openaiagent_conversations', ['id' => $old->id]));
        $this->assertSame(0, $DB->count_records(
            'block_openaiagent_messages',
            ['conversationid' => $old->id]
        ));
        // Recent conversation and its message survive.
        $this->assertTrue($DB->record_exists('block_openaiagent_conversations', ['id' => $recent->id]));
        $this->assertSame(1, $DB->count_records(
            'block_openaiagent_messages',
            ['conversationid' => $recent->id]
        ));
    }

    /**
     * A non-positive cutoff is a no-op (retention disabled).
     */
    public function test_purge_older_than_noop_when_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $conversation = conversation_repository::create($user->id, $course->id);

        $this->assertSame(0, conversation_repository::purge_older_than(0));
        $this->assertTrue($DB->record_exists('block_openaiagent_conversations', ['id' => $conversation->id]));
    }

    /**
     * A course that turns off conversation storage keeps the metadata a turn
     * produces — route, model, tokens, so the cost dashboard stays correct —
     * but retains nothing the participant wrote. The setting existed as a
     * column, a web-service parameter and a backup field for several releases
     * without ever being consulted, so this pins that it now gates the content.
     */
    public function test_storeconversations_off_keeps_metadata_but_not_text(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('log_messages', 1, 'block_openaiagent');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        course_config::save((int)$course->id, ['storeconversations' => 0]);

        $conversation = conversation_repository::create($user->id, $course->id);
        $messageid = conversation_repository::add_message(
            $conversation->id,
            'user',
            'texto que no debe quedar guardado',
            ['route' => 'assistant', 'model' => 'gpt-4.1-mini', 'totaltokens' => 1234]
        );

        $stored = $DB->get_record('block_openaiagent_messages', ['id' => $messageid], '*', MUST_EXIST);

        $this->assertSame('', $stored->content);
        $this->assertSame('assistant', $stored->route);
        $this->assertSame('gpt-4.1-mini', $stored->model);
        $this->assertSame(1234, (int)$stored->totaltokens);
    }

    /**
     * The default, and the behaviour every existing course keeps: content is
     * stored. A profile that has never been configured must not lose its
     * history because a new column defaulted the wrong way.
     */
    public function test_storeconversations_on_by_default_keeps_text(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('log_messages', 1, 'block_openaiagent');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();

        $conversation = conversation_repository::create($user->id, $course->id);
        $messageid = conversation_repository::add_message($conversation->id, 'user', 'hola');

        $stored = $DB->get_record('block_openaiagent_messages', ['id' => $messageid], '*', MUST_EXIST);

        $this->assertSame('hola', $stored->content);
    }
}
