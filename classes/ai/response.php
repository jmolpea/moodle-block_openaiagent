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
 * Provider-neutral chat completion response.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\ai;

/**
 * Immutable, normalized provider response.
 */
class response {
    /** @var bool Whether the call succeeded. */
    public bool $success = false;

    /** @var string Provider response id (when the provider returns one). */
    public string $id = '';

    /** @var string Concatenated output text. */
    public string $text = '';

    /** @var array[] Tool calls requested by the model: [['id','name','arguments'], ...]. */
    public array $toolcalls = [];

    /** @var int Prompt/input tokens. */
    public int $prompttokens = 0;

    /**
     * @var int Prompt tokens the provider served from its cache.
     *
     * Always a SUBSET of {@see $prompttokens}: adapters whose provider reports
     * cached tokens outside the input count normalize them into it, so the cost
     * model can uniformly bill (prompttokens - cachedtokens) at the full input
     * rate and the rest at the (much cheaper) cached rate.
     */
    public int $cachedtokens = 0;

    /** @var int Completion/output tokens. */
    public int $completiontokens = 0;

    /** @var int Total tokens. */
    public int $totaltokens = 0;

    /** @var string Machine-readable error code, empty on success. */
    public string $errorcode = '';

    /** @var string Technical error message (never shown to students). */
    public string $errormessage = '';

    /** @var int HTTP status code. */
    public int $httpcode = 0;

    /**
     * Build a successful response.
     *
     * @param string $id Provider response id.
     * @param string $text Output text.
     * @param array $toolcalls Normalized tool calls: [['id','name','arguments'], ...].
     * @param int $prompttokens Prompt/input tokens.
     * @param int $completiontokens Completion/output tokens.
     * @param int $totaltokens Total tokens (0 = sum of the other two).
     * @param int $cachedtokens Prompt tokens served from the provider cache.
     * @return self
     */
    public static function success(
        string $id,
        string $text,
        array $toolcalls = [],
        int $prompttokens = 0,
        int $completiontokens = 0,
        int $totaltokens = 0,
        int $cachedtokens = 0
    ): self {
        $response = new self();
        $response->success = true;
        $response->id = $id;
        $response->text = $text;
        $response->toolcalls = $toolcalls;
        $response->prompttokens = $prompttokens;
        // Never let a provider quirk report more cache than input: the cost model
        // subtracts one from the other, and a negative uncached count would show
        // up as a credit on the dashboard.
        $response->cachedtokens = max(0, min($cachedtokens, $prompttokens));
        $response->completiontokens = $completiontokens;
        $response->totaltokens = $totaltokens > 0 ? $totaltokens : ($prompttokens + $completiontokens);
        return $response;
    }

    /**
     * Build a failure response.
     *
     * @param string $errorcode Machine-readable code.
     * @param string $errormessage Technical message.
     * @param int $httpcode HTTP status code.
     * @return self
     */
    public static function failure(string $errorcode, string $errormessage = '', int $httpcode = 0): self {
        $response = new self();
        $response->success = false;
        $response->errorcode = $errorcode;
        $response->errormessage = $errormessage;
        $response->httpcode = $httpcode;
        return $response;
    }

    /**
     * Whether the model asked for one or more tool executions.
     *
     * @return bool
     */
    public function has_tool_calls(): bool {
        return !empty($this->toolcalls);
    }
}
