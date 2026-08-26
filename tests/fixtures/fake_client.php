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
 * Scripted AI provider client used by orchestrator unit tests.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent;

use block_openaiagent\ai\client_base;
use block_openaiagent\ai\request;
use block_openaiagent\ai\response;

/**
 * A deterministic client double that records requests and returns scripted output.
 */
class fake_client extends client_base {
    /** @var request[] All requests received, in order. */
    public array $requests = [];

    /** @var string Raw JSON the router (jsonmode) call should return. */
    public string $routerjson = '{"intent":"tutor","confidence":0.95,"needs_clarification":false}';

    /** @var string Text the agent call should return. */
    public string $agenttext = 'Here is a helpful answer.';

    /** @var bool When true, agent (non-router) calls report failure. */
    public bool $failagent = false;

    /** @var string Error code used when $failagent is true. */
    public string $agenterrorcode = 'apierror';

    /** @var array[] Tool calls the FIRST agent call should return (then none). */
    public array $agenttoolcalls = [];

    /**
     * @var bool Keep asking for tools for as long as tools are offered.
     *
     * Simulates the model that never stops exploring: it returns tool calls on
     * every request that carries tools and prose only once they are taken away.
     * That is the shape of a turn that exhausts the tool budget.
     */
    public bool $alwaystoolcalls = false;

    /** @var bool Whether the scripted tool calls were already returned. */
    private bool $toolcallsreturned = false;

    /**
     * Constructor with a dummy key so is_configured() is true.
     */
    public function __construct() {
        parent::__construct('test-key');
    }

    /**
     * The adapter's default model id.
     *
     * @return string
     */
    public function default_model(): string {
        return 'gpt-4.1-mini';
    }

    /**
     * The adapter's default API base URL.
     *
     * @return string
     */
    public function default_base_url(): string {
        return 'https://fake.invalid/v1';
    }

    /**
     * The fake accepts any model id.
     *
     * @param string $model Model id.
     * @return bool
     */
    public function owns_model(string $model): bool {
        return true;
    }

    /**
     * Return scripted responses based on the request shape.
     *
     * @param request $request Neutral request.
     * @return response
     */
    public function complete(request $request): response {
        $this->requests[] = clone $request;

        if ($request->jsonmode) {
            return response::success('resp_router', $this->routerjson, [], 5, 5);
        }

        if ($this->failagent) {
            return response::failure($this->agenterrorcode, 'scripted failure', 500);
        }

        if ($this->alwaystoolcalls && !empty($this->agenttoolcalls)) {
            if (!empty($request->tools)) {
                // Fresh ids per round: the orchestrator must not be able to pass
                // by reusing them, and the provider would reject duplicates.
                $calls = [];
                foreach ($this->agenttoolcalls as $i => $call) {
                    $call['id'] = 'call_' . count($this->requests) . '_' . $i;
                    $calls[] = $call;
                }
                return response::success('resp_toolcall', '', $calls, 10, 5);
            }
            return response::success('resp_agent', $this->agenttext, [], 10, 20);
        }

        if (!empty($this->agenttoolcalls) && !$this->toolcallsreturned) {
            $this->toolcallsreturned = true;
            return response::success('resp_toolcall', '', $this->agenttoolcalls, 10, 5);
        }

        return response::success('resp_agent', $this->agenttext, [], 10, 20);
    }

    /**
     * Return the last agent (non-router) request, or null.
     *
     * @return request|null
     */
    public function last_agent_request(): ?request {
        for ($i = count($this->requests) - 1; $i >= 0; $i--) {
            if (!$this->requests[$i]->jsonmode) {
                return $this->requests[$i];
            }
        }
        return null;
    }

    /**
     * Return the last router (jsonmode) request, or null.
     *
     * @return request|null
     */
    public function last_router_request(): ?request {
        for ($i = count($this->requests) - 1; $i >= 0; $i--) {
            if ($this->requests[$i]->jsonmode) {
                return $this->requests[$i];
            }
        }
        return null;
    }
}
