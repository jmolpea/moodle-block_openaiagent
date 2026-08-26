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
 * Factory resolving the configured AI provider client.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\ai;

/**
 * Builds provider clients from plugin configuration.
 */
class factory {
    /** @var string[] Supported provider keys. */
    public const PROVIDERS = ['openai', 'anthropic', 'gemini', 'deepseek'];

    /**
     * The active provider key from plugin configuration.
     *
     * @return string One of {@see self::PROVIDERS}.
     */
    public static function provider(): string {
        $provider = (string)get_config('block_openaiagent', 'provider');
        return in_array($provider, self::PROVIDERS, true) ? $provider : 'openai';
    }

    /**
     * Build a client for a provider.
     *
     * @param string|null $provider Provider key, or null for the configured one.
     * @return client_base
     */
    public static function client(?string $provider = null): client_base {
        $provider = $provider ?? self::provider();

        switch ($provider) {
            case 'anthropic':
                return new anthropic_client(
                    (string)get_config('block_openaiagent', 'anthropic_apikey'),
                    (string)get_config('block_openaiagent', 'anthropic_base_url')
                );
            case 'gemini':
                return new gemini_client(
                    (string)get_config('block_openaiagent', 'gemini_apikey'),
                    (string)get_config('block_openaiagent', 'gemini_base_url')
                );
            case 'deepseek':
                return new openai_compatible_client(
                    'deepseek',
                    (string)get_config('block_openaiagent', 'deepseek_apikey'),
                    (string)get_config('block_openaiagent', 'deepseek_base_url')
                );
            default:
                // The OpenAI key/base URL keep their legacy setting names so
                // configured secrets survive the upgrade to multi-provider.
                return new openai_compatible_client(
                    'openai',
                    (string)get_config('block_openaiagent', 'apikey'),
                    (string)get_config('block_openaiagent', 'openai_base_url')
                );
        }
    }

    /**
     * Resolve a configured model id against a client, guarding against
     * model ids left over from another provider.
     *
     * @param string $configured Configured model id (may be empty).
     * @param client_base $client Provider client.
     * @return string A model id usable with the client's provider.
     */
    public static function resolve_model(string $configured, client_base $client): string {
        $configured = trim($configured);
        if ($configured !== '' && $client->owns_model($configured)) {
            return $configured;
        }
        return $client->default_model();
    }
}
