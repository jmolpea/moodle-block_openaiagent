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
 * External function: test the AI provider connection from the admin tools page.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\external;

use block_openaiagent\ai\factory;
use block_openaiagent\ai\request;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Performs a minimal chat call against the active AI provider to verify connectivity.
 *
 * Kept under its historical name so existing web service consumers keep working.
 */
class test_openai_connection extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Run a minimal connectivity probe.
     *
     * @return array
     */
    public static function execute(): array {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('block/openaiagent:manageglobalconfig', $context);

        $client = factory::client();
        if (!$client->is_configured()) {
            return ['ok' => false, 'message' => get_string('testprovider_nokey', 'block_openaiagent')];
        }

        $request = new request();
        $request->model = factory::resolve_model(
            (string)get_config('block_openaiagent', 'default_router_model'),
            $client
        );
        $request->instructions = 'Reply with the single word: ok.';
        // Generous cap: reasoning models (e.g. Gemini 2.5) spend output budget on
        // thinking before emitting text, and a too-small cap yields an empty reply.
        $request->maxtokens = 256;
        $request->add_user_message('ping');

        $response = $client->complete($request);

        if ($response->success) {
            return ['ok' => true, 'message' => get_string(
                'testprovider_ok',
                'block_openaiagent',
                factory::provider() . ' / ' . $request->model
            )];
        }

        $detail = (int)get_config('block_openaiagent', 'debugmode') === 1
            ? ($response->errorcode . ': ' . $response->errormessage)
            : $response->errorcode;
        return ['ok' => false, 'message' => get_string('testprovider_failed', 'block_openaiagent', $detail)];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether the probe succeeded'),
            'message' => new external_value(PARAM_TEXT, 'Human-readable result'),
        ]);
    }
}
