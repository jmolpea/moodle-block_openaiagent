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
 * What opening an assistant to visitors can cost, said before it is opened.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Worst-case daily cost of visitor messages, and what else the administrator should know.
 *
 * Deliberately pessimistic: every message is priced at the upper end measured
 * on real platform turns (router plus an assistant turn with tool calls), at
 * the assistant model's list price, with the whole daily ceiling used.
 */
final class visitor_cost {
    /** @var int Input tokens assumed per visitor message (upper end measured). */
    public const INPUT_TOKENS = 10000;

    /** @var int Output tokens assumed per visitor message. */
    public const OUTPUT_TOKENS = 500;

    /**
     * Maximum daily cost in USD, or null when the model has no known price.
     *
     * @param int $cap Daily message ceiling.
     * @param string $model Model id.
     * @return float|null
     */
    public static function daily_maximum(int $cap, string $model): ?float {
        $prices = analytics::get_price_map();
        $price = $prices[strtolower($model)] ?? null;
        if ($price === null) {
            return null;
        }
        return $cap * (self::INPUT_TOKENS * $price[0] + self::OUTPUT_TOKENS * $price[1]) / 1000000;
    }

    /**
     * The model visitors' answers are written with.
     *
     * @return string
     */
    public static function model(): string {
        $model = trim((string)get_config('block_openaiagent', 'default_assistant_model'));
        return $model !== '' ? $model : \block_openaiagent\ai\factory::client()->default_model();
    }

    /**
     * Text for the block settings: the estimate and any condition that keeps visitors out.
     *
     * @return string Escaped HTML, one line per fact.
     */
    public static function describe(): string {
        global $CFG;

        $cap = visitor_guard::setting('visitor_daily_cap', 300);
        $model = self::model();
        $lines = [];
        if ($cap === 0) {
            $lines[] = get_string('visitorcost_nocap', 'block_openaiagent');
        } else {
            $cost = self::daily_maximum($cap, $model);
            $lines[] = $cost === null
                ? get_string('visitorcost_unknown', 'block_openaiagent', (object)['cap' => $cap, 'model' => $model])
                : get_string('visitorcost_estimate', 'block_openaiagent', (object)[
                    'cap' => $cap,
                    'model' => $model,
                    'cost' => format_float($cost, 2),
                ]);
        }
        if (!empty($CFG->forcelogin)) {
            $lines[] = get_string('visitorcost_forcelogin', 'block_openaiagent');
        }
        if (visitor_guard::captcha() === null) {
            $lines[] = get_string('visitorcost_nocaptcha', 'block_openaiagent');
        }
        return implode('<br>', array_map('s', $lines));
    }
}
