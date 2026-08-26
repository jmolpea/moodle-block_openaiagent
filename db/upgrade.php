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
 * Upgrade script for block_openaiagent.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the block_openaiagent plugin.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool True on success.
 */
function xmldb_block_openaiagent_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // Upgrade steps will be added here as needed.

    if ($oldversion < 2026021503) {
        $table = new xmldb_table('block_openaiagent_filetext');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('fileid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('contenthash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
            $table->add_field('mimetype', XMLDB_TYPE_CHAR, '120', null, XMLDB_NOTNULL, null, null);
            $table->add_field('filesize', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('charcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('pagecount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('errormsg', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('extractedtext', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timeindexed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('contenthash_uq', XMLDB_KEY_UNIQUE, ['contenthash']);

            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('fileid_ix', XMLDB_INDEX_NOTUNIQUE, ['fileid']);

            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026021503, 'openaiagent');
    }

    if ($oldversion < 2026060600) {
        // Agents table.
        $table = new xmldb_table('block_openaiagent_agents');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('agenttype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'tutor');
            $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('baseprompt', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('defaultmodel', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('temperature', XMLDB_TYPE_NUMBER, '4, 2', null, XMLDB_NOTNULL, null, '0.20');
            $table->add_field('maxoutputtokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1000');
            $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('agenttype_ix', XMLDB_INDEX_NOTUNIQUE, ['agenttype']);
            $table->add_index('enabled_ix', XMLDB_INDEX_NOTUNIQUE, ['enabled']);
            $dbman->create_table($table);
        }

        // Course config table.
        $table = new xmldb_table('block_openaiagent_courseconfig');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('assistantname', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('welcomemessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('routeragentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('tutoragentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('assistantagentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('ambiguityagentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('vectorstoreid', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('courseprompt', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('modeloverride', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('temperatureoverride', XMLDB_TYPE_NUMBER, '4, 2', null, null, null, null);
            $table->add_field('maxoutputtokensoverride', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('languagepolicy', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, 'auto');
            $table->add_field('evaluationpolicy', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('citabledocuments', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('internaldocuments', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('protectedactivities', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('fallbacknoinfo', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('fallbackoutofscope', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('fallbackevaluationblock', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('storeconversations', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN_UNIQUE, ['courseid'], 'course', ['id']);
            $dbman->create_table($table);
        }

        // Course tools table.
        $table = new xmldb_table('block_openaiagent_coursetools');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('toolname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('requirescapability', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_index('course_tool_uq', XMLDB_INDEX_UNIQUE, ['courseid', 'toolname']);
            $dbman->create_table($table);
        }

        // Conversations table.
        $table = new xmldb_table('block_openaiagent_conversations');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('currentintent', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('activeagentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('lastresponseid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('conversationsummary', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('lastuserrequest', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_index('course_user_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid']);
            $dbman->create_table($table);
        }

        // Messages table.
        $table = new xmldb_table('block_openaiagent_messages');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('conversationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('role', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'user');
            $table->add_field('content', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('route', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('agentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('openairesponseid', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('prompttokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('completiontokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('totaltokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key(
                'conversationid',
                XMLDB_KEY_FOREIGN,
                ['conversationid'],
                'block_openaiagent_conversations',
                ['id']
            );
            $dbman->create_table($table);
        }

        // Seed default agents and sensible default global settings.
        \block_openaiagent\local\defaults::install();

        upgrade_block_savepoint(true, 2026060600, 'openaiagent');
    }

    if ($oldversion < 2026060700) {
        $table = new xmldb_table('block_openaiagent_courseconfig');

        $field = new xmldb_field('assistantprompt', XMLDB_TYPE_TEXT, null, null, null, null, null, 'courseprompt');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('assistantfaqs', XMLDB_TYPE_TEXT, null, null, null, null, null, 'assistantprompt');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_block_savepoint(true, 2026060700, 'openaiagent');
    }

    if ($oldversion < 2026060703) {
        // The router was seeded on gpt-4.1-nano with a tutor-biased prompt, which
        // chronically misrouted live-data questions ("what grade do I have?") to the
        // tutor instead of the course assistant. Refresh the seeded default router to
        // the new prompt and the more reliable gpt-4.1-mini model -- but only when the
        // row is still the untouched default, so customised routers are left alone.
        $oldrouterprompt = <<<'EOT'
Classify the intent of the latest user message.
Return valid JSON only. No Markdown. No extra text.

Allowed intents:
- "tutor": course content, theory, concepts, exercises, explanations, definitions,
  learning, quizzes, exams, academic activities, or any query requiring conceptual
  reasoning and not real LMS data.
- "assistant": progress, grades, scores, available activities, submissions,
  restrictions, access, dates, webinars, quiz attempts, navigation issues, or any
  query requiring real Moodle LMS data.
- "ambiguous": short, incomplete, or context-dependent messages such as "yes", "ok",
  "help", "I do not understand", "explain", "I cannot", when the target route cannot
  be determined safely.

Rules:
- Do not answer the user.
- Do not solve the query.
- Only classify.
- If confidence is below 0.65, return "ambiguous".
- If the message contains exam options, an evaluative calculation, or a conceptual
  question, classify as "tutor".
- If the message requires real Moodle data, classify as "assistant".

Return exactly this JSON shape:
{"intent":"tutor|assistant|ambiguous","confidence":0.0,"reason":"string","needs_clarification":true}
EOT;

        $now = time();
        $routers = $DB->get_records('block_openaiagent_agents', ['agenttype' => 'router']);
        foreach ($routers as $router) {
            $untouchedprompt = trim((string)$router->baseprompt) === trim($oldrouterprompt);
            $nanomodel = trim((string)$router->defaultmodel) === 'gpt-4.1-nano';

            // Only refresh rows that still look like the seeded default. A router whose
            // prompt was edited keeps its prompt; a router whose model was deliberately
            // changed away from nano keeps its model.
            if (!$untouchedprompt && !$nanomodel) {
                continue;
            }

            $update = (object) ['id' => $router->id, 'timemodified' => $now];
            if ($untouchedprompt) {
                $update->baseprompt = \block_openaiagent\local\defaults::ROUTER_PROMPT;
            }
            if ($nanomodel) {
                $update->defaultmodel = 'gpt-4.1-mini';
            }
            $DB->update_record('block_openaiagent_agents', $update);
        }

        // Move the global default off nano too, unless an admin changed it.
        if ((string)get_config('block_openaiagent', 'default_router_model') === 'gpt-4.1-nano') {
            set_config('default_router_model', 'gpt-4.1-mini', 'block_openaiagent');
        }

        upgrade_block_savepoint(true, 2026060703, 'openaiagent');
    }

    if ($oldversion < 2026060704) {
        // The router prompt and model are read from the agents table at runtime, not
        // from defaults.php, and there is no UI to edit agents. The previous step
        // refreshed the seeded router only when its stored prompt matched the old
        // default byte-for-byte, which line-ending/encoding differences can defeat --
        // leaving installs on the original tutor-biased prompt. Since the router is an
        // internal agent with nothing for a user to customise, force it back to the
        // current default prompt and a reliable model unconditionally.
        $now = time();
        foreach ($DB->get_records('block_openaiagent_agents', ['agenttype' => 'router']) as $router) {
            $DB->update_record('block_openaiagent_agents', (object) [
                'id' => $router->id,
                'baseprompt' => \block_openaiagent\local\defaults::ROUTER_PROMPT,
                'defaultmodel' => 'gpt-4.1-mini',
                'timemodified' => $now,
            ]);
        }

        if ((string)get_config('block_openaiagent', 'default_router_model') === 'gpt-4.1-nano') {
            set_config('default_router_model', 'gpt-4.1-mini', 'block_openaiagent');
        }

        upgrade_block_savepoint(true, 2026060704, 'openaiagent');
    }

    if ($oldversion < 2026061100) {
        // Per-course router prompt override (editable intent classifier).
        $table = new xmldb_table('block_openaiagent_courseconfig');
        $field = new xmldb_field('routerprompt', XMLDB_TYPE_TEXT, null, null, null, null, null, 'assistantfaqs');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Dedicated random signing secret for MCP session tokens. Without it the
        // HMAC key was derived only from passwordsaltmain (often unset) and the
        // MCP key hash, which can both be empty -- making tokens forgeable.
        if ((string)get_config('block_openaiagent', 'mcp_signing_secret') === '') {
            set_config('mcp_signing_secret', bin2hex(random_bytes(32)), 'block_openaiagent');
        }

        upgrade_block_savepoint(true, 2026061100, 'openaiagent');
    }

    if ($oldversion < 2026061102) {
        // The OpenAI Agent Builder integration has been retired: the MCP endpoint
        // now accepts only server-issued session tokens, so the static shared key
        // (and its hash) no longer authenticate anything. Drop the stored values
        // so no obsolete secret lingers in configuration.
        unset_config('mcp_key', 'block_openaiagent');
        unset_config('mcp_key_hash', 'block_openaiagent');

        upgrade_block_savepoint(true, 2026061102, 'openaiagent');
    }

    if ($oldversion < 2026070700) {
        // Multi-provider release: the plugin now talks to OpenAI, Anthropic,
        // Gemini or DeepSeek through a provider-neutral client, grounds the
        // tutor in a local knowledge base (documents uploaded in the course
        // configuration) instead of OpenAI vector stores, and executes the
        // Moodle tools locally instead of through the hosted MCP tool.

        // Knowledge-base chunk index.
        $table = new xmldb_table('block_openaiagent_chunks');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contenthash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
        $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('citable', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('chunkindex', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('content', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('embedding', XMLDB_TYPE_TEXT, null, null, null, null, null);
        // No DEFAULT, for the same reason as the 'model' columns below: XMLDB
        // refuses '' on a CHAR NOT NULL column and strips it with a warning.
        $table->add_field('embeddingmodel', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_index('course_hash_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'contenthash']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Provider defaults for existing installs (the active provider stays
        // OpenAI, so behaviour does not change on upgrade).
        $providerdefaults = [
            'provider' => 'openai',
            'anthropic_base_url' => 'https://api.anthropic.com/v1',
            'gemini_base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'deepseek_base_url' => 'https://api.deepseek.com/v1',
            'embeddings_provider' => 'auto',
        ];
        foreach ($providerdefaults as $name => $value) {
            if (get_config('block_openaiagent', $name) === false) {
                set_config($name, $value, 'block_openaiagent');
            }
        }

        // Server-side conversation chaining (previous_response_id) was an
        // OpenAI Responses feature; continuity now always comes from replaying
        // local history, so the toggle is obsolete.
        unset_config('openai_store', 'block_openaiagent');

        // The default tutor prompt no longer references File Search: the tutor
        // is grounded in excerpts retrieved from the local knowledge base. The
        // tutor is an internal agent with no editing UI, so refresh it
        // unconditionally (same rationale as the 2026060704 router refresh).
        $now = time();
        foreach ($DB->get_records('block_openaiagent_agents', ['agenttype' => 'tutor']) as $tutor) {
            $DB->update_record('block_openaiagent_agents', (object) [
                'id' => $tutor->id,
                'baseprompt' => \block_openaiagent\local\defaults::TUTOR_PROMPT,
                'timemodified' => $now,
            ]);
        }

        upgrade_block_savepoint(true, 2026070700, 'openaiagent');
    }

    if ($oldversion < 2026070800) {
        // Chunks now carry a "[Section: ...]" breadcrumb naming the document
        // heading they belong to, so the tutor can cite real sections instead
        // of inventing chapter names. Chunk content is immutable per
        // contenthash, so existing rows must be dropped and re-indexed; the
        // queued ad-hoc tasks re-extract, re-chunk and re-embed each course
        // knowledge base within minutes of the upgrade.
        $courseids = $DB->get_fieldset_sql('SELECT DISTINCT courseid FROM {block_openaiagent_chunks}');
        $DB->delete_records('block_openaiagent_chunks');
        foreach ($courseids as $courseid) {
            \block_openaiagent\task\index_tutordocs_task::queue((int)$courseid);
        }

        upgrade_block_savepoint(true, 2026070800, 'openaiagent');
    }

    if ($oldversion < 2026070900) {
        // Per-course agent toggles: a course can now disable the tutor or the
        // platform assistant. With only one content agent enabled the router is
        // skipped and every message goes straight to that agent.
        $table = new xmldb_table('block_openaiagent_courseconfig');

        $field = new xmldb_field('tutorenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('assistantenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'tutorenabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // The remote MCP endpoint (mcp/v1/mcp/) has been retired: since the
        // multi-provider release the orchestrator executes the Moodle tools
        // locally in-process, so nothing issues or consumes MCP session tokens
        // any more. Drop the obsolete settings (and the signing secret) so no
        // stale configuration or secret lingers.
        unset_config('mcp_enabled', 'block_openaiagent');
        unset_config('mcp_endpoint', 'block_openaiagent');
        unset_config('mcp_token_ttl', 'block_openaiagent');
        unset_config('mcp_signing_secret', 'block_openaiagent');

        // The per-block daily conversation limit was replaced by the global
        // per-user rate limits long ago; nothing reads this setting any more.
        unset_config('default_dailylimit', 'block_openaiagent');

        upgrade_block_savepoint(true, 2026070900, 'openaiagent');
    }

    if ($oldversion < 2026070901) {
        // Knowledge-base grounding overhaul. Chunks now carry a hierarchical
        // "[Section: Unidad › Paso › ... | p. N]" breadcrumb (page-aware for
        // PDFs) so the tutor cites the real unit/section/page instead of
        // inventing one, and the tutor prompt is much stricter about never
        // using outside knowledge or guessing locations. Chunk content is
        // immutable per contenthash, so existing rows must be dropped and
        // re-indexed; the queued ad-hoc tasks rebuild each course knowledge
        // base within minutes.
        $now = time();
        foreach ($DB->get_records('block_openaiagent_agents', ['agenttype' => 'tutor']) as $tutor) {
            $DB->update_record('block_openaiagent_agents', (object) [
                'id' => $tutor->id,
                'baseprompt' => \block_openaiagent\local\defaults::TUTOR_PROMPT,
                'timemodified' => $now,
            ]);
        }

        $courseids = $DB->get_fieldset_sql('SELECT DISTINCT courseid FROM {block_openaiagent_chunks}');
        $DB->delete_records('block_openaiagent_chunks');
        foreach ($courseids as $courseid) {
            \block_openaiagent\task\index_tutordocs_task::queue((int)$courseid);
        }

        upgrade_block_savepoint(true, 2026070901, 'openaiagent');
    }

    if ($oldversion < 2026070903) {
        // Reinforced router prompt: questions about where a topic is inside the
        // course documents/guide (in which unit/chapter/page it is explained,
        // what the guide says about it) now route to the tutor instead of the
        // platform assistant. The router is an internal agent with no editing UI,
        // so refresh its stored prompt unconditionally; a per-course router
        // prompt override (block_openaiagent_courseconfig.routerprompt) lives
        // separately and is left untouched.
        $now = time();
        foreach ($DB->get_records('block_openaiagent_agents', ['agenttype' => 'router']) as $router) {
            $DB->update_record('block_openaiagent_agents', (object) [
                'id' => $router->id,
                'baseprompt' => \block_openaiagent\local\defaults::ROUTER_PROMPT,
                'timemodified' => $now,
            ]);
        }

        upgrade_block_savepoint(true, 2026070903, 'openaiagent');
    }

    if ($oldversion < 2026070908) {
        // Reinforced assistant prompt: questions about why a section/week/tab or an
        // activity is locked ("why can't I access week 3") must be answered from live
        // tool data, not from memory. The assistant now has explicit instructions to
        // resolve the section via moodle.get_course_outline and read the section-level
        // gate via moodle.get_section_gate_status (both of which now surface
        // section-level access restrictions, not only per-activity ones).
        //
        // The assistant baseprompt lives in the agents table and has no editing UI, so
        // refresh the seeded default unconditionally (same rationale as the router and
        // tutor refreshes above). The editable per-course override
        // (block_openaiagent_courseconfig.assistantprompt) is stored separately and is
        // left untouched.
        $now = time();
        foreach ($DB->get_records('block_openaiagent_agents', ['agenttype' => 'assistant']) as $assistant) {
            $DB->update_record('block_openaiagent_agents', (object) [
                'id' => $assistant->id,
                'baseprompt' => \block_openaiagent\local\defaults::ASSISTANT_PROMPT,
                'timemodified' => $now,
            ]);
        }

        upgrade_block_savepoint(true, 2026070908, 'openaiagent');
    }

    if ($oldversion < 2026071000) {
        // Retrieval quality release: hybrid semantic+lexical ranking, conditional
        // retrieval-query rewriting via a cheap model, and a relaxed tutor
        // grounding policy (general knowledge may frame the answer; course facts
        // still come only from the excerpts).
        $defaults = [
            'enable_query_rewrite' => 1,
            'query_rewrite_model' => '',
        ];
        foreach ($defaults as $name => $value) {
            if (get_config('block_openaiagent', $name) === false) {
                set_config($name, $value, 'block_openaiagent');
            }
        }

        // The tutor baseprompt lives in the agents table and has no editing UI,
        // so refresh the seeded default unconditionally (same rationale as the
        // previous router/tutor/assistant refreshes). Per-course overrides live
        // in block_openaiagent_courseconfig and are left untouched.
        $now = time();
        foreach ($DB->get_records('block_openaiagent_agents', ['agenttype' => 'tutor']) as $tutor) {
            $DB->update_record('block_openaiagent_agents', (object) [
                'id' => $tutor->id,
                'baseprompt' => \block_openaiagent\local\defaults::TUTOR_PROMPT,
                'timemodified' => $now,
            ]);
        }

        upgrade_block_savepoint(true, 2026071000, 'openaiagent');
    }

    if ($oldversion < 2026071100) {
        // Multiple assistants per course: every configuration subsystem that used
        // to be keyed by course alone is now keyed by (course, block instance), so
        // each block instance is an independently configured, isolated assistant
        // (its own prompts, agents, tools, knowledge base, vector store and
        // conversation history). A new "blockinstanceid" column (0 = the legacy
        // course-wide profile) discriminates the profiles.

        // 1. Add the blockinstanceid column to every keyed table.
        $tablefields = [
            'block_openaiagent_courseconfig',
            'block_openaiagent_coursetools',
            'block_openaiagent_conversations',
            'block_openaiagent_chunks',
        ];
        foreach ($tablefields as $tablename) {
            $table = new xmldb_table($tablename);
            $field = new xmldb_field(
                'blockinstanceid',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'courseid'
            );
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // 2. Rework keys/indexes so the profile discriminator is part of them.
        // courseconfig: the course was uniquely keyed; make it a plain foreign key
        // and move the uniqueness to (courseid, blockinstanceid).
        $table = new xmldb_table('block_openaiagent_courseconfig');
        $oldkey = new xmldb_key('courseid', XMLDB_KEY_FOREIGN_UNIQUE, ['courseid'], 'course', ['id']);
        $dbman->drop_key($table, $oldkey);
        $newkey = new xmldb_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $dbman->add_key($table, $newkey);
        $index = new xmldb_index('course_block_uq', XMLDB_INDEX_UNIQUE, ['courseid', 'blockinstanceid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Coursetools: unique per (course, tool) -> per (course, block, tool).
        $table = new xmldb_table('block_openaiagent_coursetools');
        $oldindex = new xmldb_index('course_tool_uq', XMLDB_INDEX_UNIQUE, ['courseid', 'toolname']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }
        $newindex = new xmldb_index('course_block_tool_uq', XMLDB_INDEX_UNIQUE, ['courseid', 'blockinstanceid', 'toolname']);
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        // Conversations: (course, user) lookup -> (course, block, user).
        $table = new xmldb_table('block_openaiagent_conversations');
        $oldindex = new xmldb_index('course_user_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }
        $newindex = new xmldb_index('course_block_user_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'blockinstanceid', 'userid']);
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        // Chunks: (course, hash) lookup -> (course, block, hash).
        $table = new xmldb_table('block_openaiagent_chunks');
        $oldindex = new xmldb_index('course_hash_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'contenthash']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }
        $newindex = new xmldb_index('course_block_hash_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'blockinstanceid', 'contenthash']);
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        // 3. Attach the existing course-level data (blockinstanceid = 0) to the
        // OLDEST block instance of the course, so the current single assistant
        // keeps working unchanged under its block id. Courses with several block
        // instances today shared one config: only the oldest inherits it; the
        // others become fresh assistants (defaults). Courses with no block
        // instance keep their data at 0 (harmless: nothing resolves to it).
        $sql = "SELECT bi.id AS blockid, ctx.instanceid AS courseid
                  FROM {block_instances} bi
                  JOIN {context} ctx ON ctx.id = bi.parentcontextid
                 WHERE bi.blockname = :blockname AND ctx.contextlevel = :courselevel
              ORDER BY ctx.instanceid ASC, bi.id ASC";
        $rs = $DB->get_recordset_sql($sql, ['blockname' => 'openaiagent', 'courselevel' => CONTEXT_COURSE]);
        $oldest = [];
        foreach ($rs as $row) {
            $cid = (int)$row->courseid;
            if (!isset($oldest[$cid])) {
                $oldest[$cid] = (int)$row->blockid;
            }
        }
        $rs->close();

        $fs = get_file_storage();
        $fileareas = [
            \block_openaiagent\local\tutordocs::AREA_CITABLE,
            \block_openaiagent\local\tutordocs::AREA_INTERNAL,
        ];
        foreach ($oldest as $cid => $blockid) {
            // Reassign the stored rows from the course-wide profile (0) to the block.
            foreach ($tablefields as $tablename) {
                $DB->set_field($tablename, 'blockinstanceid', $blockid, [
                    'courseid' => $cid,
                    'blockinstanceid' => 0,
                ]);
            }

            // Move the knowledge-base files from itemid 0 to itemid = block id.
            // Files are content-addressed, so this is a cheap metadata operation.
            try {
                $coursecontext = \context_course::instance($cid, IGNORE_MISSING);
            } catch (\Throwable $e) {
                $coursecontext = false;
            }
            if ($coursecontext) {
                foreach ($fileareas as $area) {
                    $files = $fs->get_area_files(
                        $coursecontext->id,
                        \block_openaiagent\local\tutordocs::COMPONENT,
                        $area,
                        0,
                        'id',
                        false
                    );
                    foreach ($files as $file) {
                        $exists = $fs->file_exists(
                            $coursecontext->id,
                            \block_openaiagent\local\tutordocs::COMPONENT,
                            $area,
                            $blockid,
                            $file->get_filepath(),
                            $file->get_filename()
                        );
                        if (!$exists) {
                            $fs->create_file_from_storedfile(['itemid' => $blockid], $file);
                        }
                        $file->delete();
                    }
                }
            }

            // Rebuild the block's knowledge base from its (moved) files. This is
            // idempotent (existing chunks are kept) but guarantees consistency for
            // installs that passed through an earlier chunk-clearing upgrade step.
            \block_openaiagent\task\index_tutordocs_task::queue($cid, $blockid);
        }

        upgrade_block_savepoint(true, 2026071100, 'openaiagent');
    }

    if ($oldversion < 2026071200) {
        // Analytics dashboard: pre-aggregated daily rollup tables so the dashboard
        // reads compact summaries instead of scanning the messages table live.

        // Message facts (one row per course/block/day/role/route/agent).
        $table = new xmldb_table('block_openaiagent_msgstats');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('daterecorded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('role', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('route', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
            $table->add_field('agentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('nummessages', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('numerrors', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('prompttokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('completiontokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('totaltokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_index('date_ix', XMLDB_INDEX_NOTUNIQUE, ['daterecorded']);
            $table->add_index('course_date_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'daterecorded']);
            $dbman->create_table($table);
        }

        // Per-user-per-day presence (distinct-user and recurrence metrics).
        $table = new xmldb_table('block_openaiagent_userstats');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('daterecorded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('numquestions', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_index('date_ix', XMLDB_INDEX_NOTUNIQUE, ['daterecorded']);
            $table->add_index('course_date_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'daterecorded']);
            $dbman->create_table($table);
        }

        // MCP tool-call facts (one row per course/day/tool).
        $table = new xmldb_table('block_openaiagent_toolstats');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('daterecorded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('toolname', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('numcalls', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('numok', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('numfail', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('date_ix', XMLDB_INDEX_NOTUNIQUE, ['daterecorded']);
            $table->add_index('course_date_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'daterecorded']);
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026071200, 'openaiagent');
    }

    if ($oldversion < 2026071203) {
        // The PDF text extractor used to discard valid text when the extracted
        // bytes were not valid UTF-8 (normalize_text() returned null under a
        // PCRE /u pattern) and the built-in fallback regex failed to compile.
        // Both are fixed now, so re-queue every file previously recorded as
        // empty or failed to extract so it is re-processed with the fixed
        // extractor on the next indexing run. Genuinely empty files simply go
        // back to empty; nothing is lost.
        $DB->execute(
            "UPDATE {block_openaiagent_filetext}
                SET status = ?, extractedtext = NULL, errormsg = '', charcount = 0, pagecount = 0, timeindexed = 0
              WHERE status IN (?, ?)",
            [
                \block_openaiagent\local\filetext_store::STATUS_PENDING,
                \block_openaiagent\local\filetext_store::STATUS_EMPTY,
                \block_openaiagent\local\filetext_store::STATUS_FAILED,
            ]
        );

        // Surgically update agent prompts still carrying the old shipped defaults:
        // (a) the "update the conversation summary" instruction leaked internal
        // state into replies, and (b) the calculation rule withheld results even
        // for practical, non-graded cases. Only the exact known sentences are
        // replaced, so any customised prompt is left untouched.
        foreach ($DB->get_records('block_openaiagent_agents') as $agent) {
            $prompt = (string)$agent->baseprompt;
            $updated = preg_replace(
                '/^\s*\d+\.\s*Update the conversation summary[^\n]*$/mi',
                '13. Never output internal state, a conversation summary, or lines beginning '
                    . 'with "state." or "Resumen:"; reply only with the content addressed to the user.',
                $prompt
            );
            $updated = preg_replace(
                '/For calculation exercises, explain formulas and conceptual steps, but do not give\s+'
                    . 'the final numerical result if it appears evaluative\./',
                'For a calculation the user brings as a practical or illustrative case, explain the '
                    . 'formulas and steps AND give the final numerical result. Withhold the final answer '
                    . 'only for an actual graded assessment item of this course (a quiz/exam question '
                    . 'with options, a true/false item, or a deliverable to be submitted).',
                $updated ?? $prompt
            );
            if ($updated !== null && $updated !== $prompt) {
                $DB->set_field('block_openaiagent_agents', 'baseprompt', $updated, ['id' => $agent->id]);
            }
        }

        upgrade_block_savepoint(true, 2026071203, 'openaiagent');
    }

    if ($oldversion < 2026071204) {
        // Drop dead columns from the course configuration table. They were
        // written by the config forms/webservices but never read anywhere:
        // the visible name and welcome message actually shown come from the
        // block instance settings, and vectorstoreid is a leftover from the
        // abandoned OpenAI File Search integration.
        $table = new xmldb_table('block_openaiagent_courseconfig');
        foreach (['assistantname', 'welcomemessage', 'vectorstoreid'] as $fieldname) {
            $field = new xmldb_field($fieldname);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }

        upgrade_block_savepoint(true, 2026071204, 'openaiagent');
    }

    if ($oldversion < 2026072300) {
        // Repair the tool selections wrecked by the checkbox-name bug. The
        // course configuration form named its tool checkboxes after the raw
        // tool names ("tool_moodle.get_context"), but PHP rewrites dots to
        // underscores in $_POST, so no checkbox ever found its submitted value
        // and every save stored the whole tool set as disabled. Profiles left
        // with zero enabled tools can only be the product of that bug (the form
        // was the only way to write these rows), so their rows are deleted and
        // the profile falls back to the default tool set.
        $rs = $DB->get_recordset_sql(
            "SELECT courseid, blockinstanceid
               FROM {block_openaiagent_coursetools}
           GROUP BY courseid, blockinstanceid
             HAVING SUM(enabled) = 0"
        );
        $profiles = [];
        foreach ($rs as $profile) {
            $profiles[] = $profile;
        }
        $rs->close();

        foreach ($profiles as $profile) {
            $DB->delete_records('block_openaiagent_coursetools', [
                'courseid' => $profile->courseid,
                'blockinstanceid' => $profile->blockinstanceid,
            ]);
        }

        upgrade_block_savepoint(true, 2026072300, 'openaiagent');
    }

    if ($oldversion < 2026072801) {
        // Refresh the router and ambiguity prompts already stored in the DB.
        //
        // Router: the prompt taught "ok" -> ambiguous as a worked example while the
        // runtime context line asked the model to reuse the previous route for a
        // contentless follow-up. A small model follows the concrete example, so
        // plain acceptances ("sí, por favor", "yes please") were classified as
        // ambiguous and answered with another question instead of the offer the
        // user had just accepted. Fragments naming an activity ("actividad 2.6")
        // landed there too.
        //
        // Ambiguity: its prompt never forbade answering, so with a course prompt in
        // context it started citing sections and chapters of course documents it has
        // no access to, and emitting the tutor's out-of-scope fallback.
        //
        // Both edits are surgical and idempotent: a prompt an admin has already
        // rewritten does not contain these literals and is left untouched.
        $oldambiguousbullet = '- "ambiguous": the message is too short or contentless to tell -- e.g. "yes", "ok",'
            . "\n" . '  "help", "I don\'t understand", "continue", "explain".';
        $newambiguousbullet = '- "ambiguous": the message carries no question of its own AND no previous route is'
            . "\n" . '  supplied below -- e.g. an opening "hola", "help", "I don\'t understand".'
            . "\n" . '  IMPORTANT: when a previous route IS supplied below, a contentless follow-up ("yes",'
            . "\n" . '  "sí", "ok", "sí por favor", "yes please", "tell me more", "es un foro sí") is NOT'
            . "\n" . '  ambiguous. It is the user accepting the offer you just made them, so it inherits that'
            . "\n" . '  previous route. Choosing "ambiguous" there answers a question with another question'
            . "\n" . '  and wastes the user\'s turn.';

        $oldexample = '"ok" -> {"intent":"ambiguous","confidence":0.3,"needs_clarification":true}';
        $newexamples = '"hola" (no previous route) -> {"intent":"ambiguous","confidence":0.3,"needs_clarification":true}'
            . "\n" . '"ok" (no previous route) -> {"intent":"ambiguous","confidence":0.3,"needs_clarification":true}'
            . "\n" . '"sí, por favor" (previous route "assistant") -> {"intent":"assistant","confidence":0.85,'
            . '"needs_clarification":false}'
            . "\n" . '"yes please" (previous route "assistant") -> {"intent":"assistant","confidence":0.85,'
            . '"needs_clarification":false}'
            . "\n" . '"es un foro sí" (previous route "assistant") -> {"intent":"assistant","confidence":0.85,'
            . '"needs_clarification":false}'
            . "\n" . '"sí" (previous route "tutor") -> {"intent":"tutor","confidence":0.85,'
            . '"needs_clarification":false}'
            . "\n" . '"actividad 2.5 y 2.6" -> {"intent":"assistant","confidence":0.85,"needs_clarification":false}';

        $oldrule = '- Judge the message on its own content.';
        $newrule = $oldrule
            . "\n" . '- A message that names an activity, week, section or resource is "assistant", even when'
            . "\n" . '  it is only a fragment ("actividad 2.6", "el foro de la semana 2"). It is never'
            . "\n" . '  "ambiguous": naming a course object IS content.'
            . "\n" . '- Reserve "ambiguous" for a message with no content AND no previous route. When in doubt'
            . "\n" . '  between "ambiguous" and reusing the previous route, reuse the previous route.';

        foreach ($DB->get_records('block_openaiagent_agents', ['agenttype' => 'router']) as $agent) {
            $prompt = (string)$agent->baseprompt;
            $updated = str_replace(
                [$oldambiguousbullet, $oldexample],
                [$newambiguousbullet, $newexamples],
                $prompt
            );
            // Only add the rules when they are not there yet, so re-running is safe.
            if (strpos($updated, 'naming a course object IS content') === false) {
                $updated = str_replace($oldrule, $newrule, $updated);
            }
            if ($updated !== $prompt) {
                $DB->set_field('block_openaiagent_agents', 'baseprompt', $updated, ['id' => $agent->id]);
            }
        }

        // The ambiguity prompt was four lines, so replace it wholesale -- but only
        // when it is still verbatim the old default. Anything customised is kept.
        $oldambiguityprompt = "You clarify the user's intent without answering the underlying question. Ask a short,"
            . "\n" . 'useful question to disambiguate, for example: "Do you mean the academic content of the'
            . "\n" . 'course, or the virtual classroom operation, such as progress, grades, dates, or'
            . "\n" . 'activities?" Answer in the same language as the user. Keep it to one or two sentences.';

        foreach ($DB->get_records('block_openaiagent_agents', ['agenttype' => 'ambiguity']) as $agent) {
            if (trim((string)$agent->baseprompt) !== trim($oldambiguityprompt)) {
                continue;
            }
            $DB->set_field(
                'block_openaiagent_agents',
                'baseprompt',
                \block_openaiagent\local\defaults::AMBIGUITY_PROMPT,
                ['id' => $agent->id]
            );
        }

        upgrade_block_savepoint(true, 2026072801, 'openaiagent');
    }

    if ($oldversion < 2026080100) {
        // Record the model actually called and the cached share of the input, so
        // the cost dashboard stops inferring both. Until now the panel grouped by
        // the agent's seeded defaultmodel (which orchestrator::resolve_model()
        // overrides with the admin setting, so the real model could never show)
        // and priced every input token at the full rate even when the provider
        // served it from its prompt cache at a fraction of it.
        $messages = new xmldb_table('block_openaiagent_messages');

        // No DEFAULT: XMLDB rejects '' as the default of a CHAR NOT NULL column
        // ("must have one meaningful DEFAULT declared or none"), warns loudly
        // during the upgrade and drops it anyway. Every insert path sets this
        // column explicitly, so the column never needs a default.
        $field = new xmldb_field('model', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null, 'agentid');
        if (!$dbman->field_exists($messages, $field)) {
            $dbman->add_field($messages, $field);
        }
        $field = new xmldb_field('cachedtokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'prompttokens');
        if (!$dbman->field_exists($messages, $field)) {
            $dbman->add_field($messages, $field);
        }

        $msgstats = new xmldb_table('block_openaiagent_msgstats');

        // No DEFAULT: XMLDB rejects '' as the default of a CHAR NOT NULL column
        // ("must have one meaningful DEFAULT declared or none"), warns loudly
        // during the upgrade and drops it anyway. Every insert path sets this
        // column explicitly, so the column never needs a default.
        $field = new xmldb_field('model', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null, 'agentid');
        if (!$dbman->field_exists($msgstats, $field)) {
            $dbman->add_field($msgstats, $field);
        }
        $field = new xmldb_field('cachedtokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'prompttokens');
        if (!$dbman->field_exists($msgstats, $field)) {
            $dbman->add_field($msgstats, $field);
        }

        // Existing rollup rows carry no model, and only recent day buckets are
        // rebuilt on a normal run. Clearing the watermark makes the next build a
        // full backfill, which relabels the whole history: turns recorded before
        // this upgrade fall back to their agent's defaultmodel (the only model
        // information those rows ever had) instead of staying blank.
        unset_config('analytics_watermark', 'block_openaiagent');

        upgrade_block_savepoint(true, 2026080100, 'openaiagent');
    }

    if ($oldversion < 2026080200) {
        // Enable moodle.get_activity_configuration on the profiles that already
        // have tool rows.
        //
        // course_config::enabled_tools() falls back to the plugin defaults ONLY
        // when a profile has no rows at all; the moment one exists it returns
        // exactly the rows flagged enabled. So every profile whose tools were
        // ever saved from the course form would silently ignore a newly added
        // default: no error, no warning, the tool simply never reaches the model
        // and the feature looks broken. Profiles with no rows need nothing —
        // they read the defaults, which now include it.
        $now = time();
        $rs = $DB->get_recordset_sql(
            "SELECT courseid, blockinstanceid
               FROM {block_openaiagent_coursetools}
           GROUP BY courseid, blockinstanceid"
        );
        $profiles = [];
        foreach ($rs as $profile) {
            $profiles[] = $profile;
        }
        $rs->close();

        foreach ($profiles as $profile) {
            $exists = $DB->record_exists('block_openaiagent_coursetools', [
                'courseid' => $profile->courseid,
                'blockinstanceid' => $profile->blockinstanceid,
                'toolname' => 'moodle.get_activity_configuration',
            ]);
            if ($exists) {
                continue;
            }
            $DB->insert_record('block_openaiagent_coursetools', (object) [
                'courseid' => $profile->courseid,
                'blockinstanceid' => $profile->blockinstanceid,
                'toolname' => 'moodle.get_activity_configuration',
                'enabled' => 1,
                'requirescapability' => '',
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        upgrade_block_savepoint(true, 2026080200, 'openaiagent');
    }

    if ($oldversion < 2026080400) {
        // Two grounding rules changed in the tutor base prompt and, as with every
        // previous prompt fix, the text lives in the agents table with no editing
        // UI, so editing defaults.php alone would reach nobody. Refreshed
        // unconditionally; the per-course tutor prompt (courseconfig) is untouched.
        //
        // First: the "[Section: ...]" marker is now explicitly internal. The old
        // wording ordered the model to quote the location "EXACTLY as they appear
        // in that marker", and the retrieval block appended after the course
        // prompt said the same, so the last and most specific instruction in the
        // system message told the model to copy the marker verbatim -- including
        // heading paths the breadcrumb had cut off mid-sentence. gpt-4.1-mini
        // ignored it; gpt-5-mini complies, and the marker started leaking into
        // 17% of answers.
        //
        // Second: acronyms may no longer be expanded unless the expansion is
        // literally in an excerpt. An excerpt that carries a tool's steps does
        // not carry what its letters stand for: asked four times what "IAR"
        // means, the tutor invented four different Spanish backronyms while
        // getting the steps right every time. The real expansion ("In Action
        // Review") lives in different chunks than the step table.
        $now = time();
        foreach ($DB->get_records('block_openaiagent_agents', ['agenttype' => 'tutor']) as $tutor) {
            $DB->update_record('block_openaiagent_agents', (object) [
                'id' => $tutor->id,
                'baseprompt' => \block_openaiagent\local\defaults::TUTOR_PROMPT,
                'timemodified' => $now,
            ]);
        }

        upgrade_block_savepoint(true, 2026080400, 'openaiagent');
    }

    if ($oldversion < 2026080500) {
        // Router and ambiguity prompts, same story as every other prompt fix:
        // they live in the agents table with no editing UI, so defaults.php on
        // its own reaches nobody.
        //
        // The router was sending to "ambiguous" any message that opened with a
        // greeting, a politeness filler or typos, even when it carried a perfectly
        // clear question ("ola, una consulta rapida xfa, tengo q darle feedback a
        // un compa..."). And the ambiguity agent is forbidden from answering, so
        // the turn was spent on a canned "could you clarify?" for a question that
        // needed no clarifying. Off-topic requests landed there too, which meant
        // the out-of-scope gate never fired: a request for restaurant
        // recommendations got a clarifying question instead of a polite refusal.
        // The router now strips greetings/typos/preamble before classifying and
        // sends off-topic to the tutor, which owns the out-of-scope reply; the
        // ambiguity agent gets one narrow permission to decline a topic no course
        // could cover, rather than implying it might help.
        $now = time();
        $refresh = [
            'router' => \block_openaiagent\local\defaults::ROUTER_PROMPT,
            'ambiguity' => \block_openaiagent\local\defaults::AMBIGUITY_PROMPT,
        ];
        foreach ($refresh as $agenttype => $prompt) {
            foreach ($DB->get_records('block_openaiagent_agents', ['agenttype' => $agenttype]) as $agent) {
                $DB->update_record('block_openaiagent_agents', (object) [
                    'id' => $agent->id,
                    'baseprompt' => $prompt,
                    'timemodified' => $now,
                ]);
            }
        }

        upgrade_block_savepoint(true, 2026080500, 'openaiagent');
    }

    if ($oldversion < 2026080600) {
        // Tutor and router prompts again, for the usual reason: they live in the
        // agents table and defaults.php alone reaches nobody.
        //
        // Tutor: the new acronym rule worked -- it stopped inventing what "IAR"
        // stands for -- but the model started explaining the rule to the
        // participant ("las instrucciones del curso prohiben expandir
        // acronimos"). It is now told to leave the expansion out silently and
        // never to describe its own instructions.
        //
        // Router: the three worked examples were over the 132-character line
        // limit and are shortened. The classification they teach is unchanged.
        $now = time();
        $refresh = [
            'tutor' => \block_openaiagent\local\defaults::TUTOR_PROMPT,
            'router' => \block_openaiagent\local\defaults::ROUTER_PROMPT,
        ];
        foreach ($refresh as $agenttype => $prompt) {
            foreach ($DB->get_records('block_openaiagent_agents', ['agenttype' => $agenttype]) as $agent) {
                $DB->update_record('block_openaiagent_agents', (object) [
                    'id' => $agent->id,
                    'baseprompt' => $prompt,
                    'timemodified' => $now,
                ]);
            }
        }

        upgrade_block_savepoint(true, 2026080600, 'openaiagent');
    }

    if ($oldversion < 2026080900) {
        // The per-course tutor prompt now REPLACES the default one instead of
        // being appended to it, so the tutor works like the assistant: one
        // prompt, one authority, one place to look. Appending meant the course
        // author was writing a supplement to a prompt they could not read, and
        // the two drifted into contradicting each other on answer length, on
        // language, and on whether general knowledge could be used at all.
        //
        // That switch would silently strip the default grounding rules from
        // every course whose prompt was written as a supplement, so those
        // courses are migrated first: the prompt they were actually running is
        // reconstructed by prepending the old default, which is still sitting in
        // the agents table at this point. Behaviour is therefore unchanged for
        // them, and the author can delete the prepended half once reviewed.
        // Courses with an empty prompt need nothing: empty still means "use the
        // agent default", which is the new one.
        $now = time();
        $olddefault = '';
        $tutoragent = $DB->get_record('block_openaiagent_agents', ['agenttype' => 'tutor'], '*', IGNORE_MULTIPLE);
        if ($tutoragent) {
            $olddefault = trim((string)$tutoragent->baseprompt);
        }

        if ($olddefault !== '') {
            $header = "// Migrated automatically: this half was the plugin's default tutor prompt.\n"
                . "// Your course prompt now REPLACES the default instead of extending it, so it was\n"
                . "// prepended here to keep this course behaving exactly as before. Review it and\n"
                . "// delete whatever your own instructions below already cover.\n\n";
            $rs = $DB->get_recordset_select(
                'block_openaiagent_courseconfig',
                $DB->sql_isnotempty('block_openaiagent_courseconfig', 'courseprompt', true, true)
            );
            foreach ($rs as $profile) {
                $DB->update_record('block_openaiagent_courseconfig', (object) [
                    'id' => $profile->id,
                    'courseprompt' => $header . $olddefault . "\n\n" . trim((string)$profile->courseprompt),
                    'timemodified' => $now,
                ]);
            }
            $rs->close();
        }

        // Only now refresh the seeded default, so the migration above could still
        // read the prompt those courses were really running.
        foreach ($DB->get_records('block_openaiagent_agents', ['agenttype' => 'tutor']) as $tutor) {
            $DB->update_record('block_openaiagent_agents', (object) [
                'id' => $tutor->id,
                'baseprompt' => \block_openaiagent\local\defaults::TUTOR_PROMPT,
                'timemodified' => $now,
            ]);
        }

        upgrade_block_savepoint(true, 2026080900, 'openaiagent');
    }

    if ($oldversion < 2026081400) {
        // Support escalation requests. One row per incident the participant
        // chooses to send to the support team: it is created as a draft when the
        // assistant offers the escalation, and only leaves that state when the
        // participant confirms with an explicit click.
        // No CHAR NOT NULL column declares '' as its default: XMLDB rejects that
        // combination, rewrites it to NULL and asks for it to be fixed at source.
        // Every insert supplies these values explicitly, so there is nothing to
        // default to.
        $table = new xmldb_table('block_openaiagent_supportreq');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('conversationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('category', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'otro');
            $table->add_field('summary', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('summaryhash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'draft');
            $table->add_field('token', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('tokenexpiry', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('ticketref', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
            $table->add_field('recipients', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('attempts', XMLDB_TYPE_INTEGER, '5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('errormsg', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timesent', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            // Not unique on purpose: the token is random and single use is
            // enforced by the status, so blanking a consumed token can never
            // collide with another row.
            $table->add_index('token_ix', XMLDB_INDEX_NOTUNIQUE, ['token']);
            $table->add_index('user_course_time_ix', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid', 'timecreated']);
            $table->add_index('course_time_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'timecreated']);
            $table->add_index('status_ix', XMLDB_INDEX_NOTUNIQUE, ['status']);
            $table->add_index('hash_user_ix', XMLDB_INDEX_NOTUNIQUE, ['summaryhash', 'userid']);
            $table->add_index('conversation_ix', XMLDB_INDEX_NOTUNIQUE, ['conversationid']);

            $dbman->create_table($table);
        }

        // Seed the support escalation settings on sites that already have the
        // plugin: defaults::install() only ever runs on a fresh install, so
        // without this the feature would come up with an empty template and no
        // limits at all until an administrator saved the settings page once.
        // Only missing keys are written, so an administrator who has already
        // configured something never gets it overwritten.
        $supportdefaults = [
            'support_email_enabled' => 0,
            'support_email_to' => '',
            'support_email_cc' => '',
            'support_subject_template' => \block_openaiagent\local\defaults::SUPPORT_SUBJECT_DEFAULT,
            'support_body_template' => \block_openaiagent\local\defaults::SUPPORT_BODY_DEFAULT,
            'support_signature' => '',
            'support_sla_text' => '',
            'support_include_transcript' => 1,
            'support_transcript_turns' => 6,
            'support_copy_to_user' => 0,
            'support_allowed_domains' => '',
            'support_reference_prefix' => \block_openaiagent\local\supportrequest::REFERENCE_PREFIX_DEFAULT,
            'support_max_per_user_day' => 3,
            'support_cooldown_minutes' => 10,
            'support_offer_cooldown_turns' => 5,
            'support_dedupe_hours' => 24,
            'support_max_per_course_day' => 200,
            'support_digest_mode' => 0,
            'support_digest_minutes' => 30,
        ];
        foreach ($supportdefaults as $name => $value) {
            if (get_config('block_openaiagent', $name) === false) {
                set_config($name, $value, 'block_openaiagent');
            }
        }

        upgrade_block_savepoint(true, 2026081400, 'openaiagent');
    }

    if ($oldversion < 2026081401) {
        // Per-course support escalation settings. Every text column inherits the
        // site value when it is empty, and the tri-state integers use -1 for
        // "inherit" so that a deliberate "no" stays distinguishable from "not
        // configured".
        $table = new xmldb_table('block_openaiagent_courseconfig');

        $columns = [
            new xmldb_field('supportmode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'inherit'),
            new xmldb_field('supportemailto', XMLDB_TYPE_TEXT, null, null, null, null, null),
            new xmldb_field('supportemailcc', XMLDB_TYPE_TEXT, null, null, null, null, null),
            new xmldb_field('supportcategorymap', XMLDB_TYPE_TEXT, null, null, null, null, null),
            new xmldb_field('supportsubject', XMLDB_TYPE_TEXT, null, null, null, null, null),
            new xmldb_field('supportbody', XMLDB_TYPE_TEXT, null, null, null, null, null),
            new xmldb_field('supportsignature', XMLDB_TYPE_TEXT, null, null, null, null, null),
            new xmldb_field('supportslatext', XMLDB_TYPE_TEXT, null, null, null, null, null),
            new xmldb_field('supportincludetranscript', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '-1'),
            new xmldb_field('supportcopytouser', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '-1'),
        ];

        foreach ($columns as $column) {
            if (!$dbman->field_exists($table, $column)) {
                $dbman->add_field($table, $column);
            }
        }

        upgrade_block_savepoint(true, 2026081401, 'openaiagent');
    }

    if ($oldversion < 2026082302) {
        // The ambiguity agent answered a greeting, and even a "gracias", with a
        // bare request for clarification and no name. Measured on a course run,
        // that route took one turn in four and produced every tone complaint:
        // the course prompt never reaches it, so no amount of course-side
        // editing could fix it. The refreshed prompt greets, acknowledges
        // courtesy without demanding clarification, and builds its question on
        // what the conversation has already covered instead of asking in the
        // abstract.
        //
        // Refreshed unconditionally, as the router and tutor prompts are above:
        // these are internal agents with no editing UI, and there is no
        // per-course override for this one.
        $now = time();
        foreach ($DB->get_records('block_openaiagent_agents', ['agenttype' => 'ambiguity']) as $agent) {
            $DB->update_record('block_openaiagent_agents', (object) [
                'id' => $agent->id,
                'baseprompt' => \block_openaiagent\local\defaults::AMBIGUITY_PROMPT,
                'timemodified' => $now,
            ]);
        }

        upgrade_block_savepoint(true, 2026082302, 'openaiagent');
    }

    if ($oldversion < 2026082500) {
        // The evaluation window did not exist before this release, so sites
        // upgrading into it get one starting now. start_trial() never resets a
        // window that is already running, and a site with a valid key never
        // consults it at all.
        \block_openaiagent\license\validator::start_trial();

        upgrade_block_savepoint(true, 2026082500, 'openaiagent');
    }

    return true;
}
