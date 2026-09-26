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
 * Platform tool: the participant's certificates and badges, in one list.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\mcp\platform\tools;

use block_openaiagent\local\scope;
use block_openaiagent\mcp\platform\base_tool;
use block_openaiagent\mcp\platform\credentials;

/**
 * Certificates and badges from every installed source, as a single list.
 *
 * The participant does not care which plugin issued something, so the model is
 * given one list. A badge that reaches the participant through two sources
 * (the same badge issued in Moodle and on Open Badge Factory) is listed once.
 */
class get_my_credentials extends base_tool {
    /** @var int Same-name badges issued this close together are the same badge. */
    public const DUPLICATE_WINDOW = 48 * HOURSECS;

    /** @var int Most credentials returned. */
    private const MAX_CREDENTIALS = 50;

    /**
     * Tool name.
     *
     * @return string
     */
    public function name(): string {
        return 'moodle.get_my_credentials';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public function description(): string {
        return 'Return the participant\'s own certificates and badges from every source on this site, newest '
            . 'first: type, name, course, issuer, issue date, expiry (expired true when it has passed), '
            . 'verification code and url to download or view it. These links work even when the course is '
            . 'hidden or the enrolment has ended. Use it for "where is my certificate", "download my diploma", '
            . '"which badges do I have". When requires_course_access is true, the link only works from inside '
            . 'the course: offer to contact support instead of giving it.';
    }

    /**
     * Input schema.
     *
     * @return array
     */
    public function input_schema(): array {
        return self::schema([
            'type' => [
                'type' => 'string',
                'enum' => ['all', 'certificate', 'badge'],
                'description' => 'Optional filter. Default all.',
            ],
        ]);
    }

    /**
     * Available when at least one source is.
     *
     * @return bool
     */
    public function is_available(): bool {
        return (bool)self::sources();
    }

    /**
     * The installed sources.
     *
     * @return credentials\source[]
     */
    public static function sources(): array {
        $sources = [
            new credentials\moodle_badges(),
            new credentials\customcert(),
            new credentials\tool_certificate(),
            new credentials\obf(),
        ];
        return array_values(array_filter($sources, static fn(credentials\source $source) => $source->is_available()));
    }

    /**
     * Run the tool.
     *
     * @param array $input Arguments.
     * @param scope $scope Turn scope.
     * @return array
     */
    public function execute(array $input, scope $scope): array {
        $filter = in_array($input['type'] ?? '', ['certificate', 'badge'], true) ? $input['type'] : 'all';

        $all = [];
        $unavailable = [];
        foreach (self::sources() as $source) {
            try {
                $all = array_merge($all, $source->credentials($scope->userid));
            } catch (\Throwable $e) {
                // One source failing (e.g. the OBF service is down) must not hide the others.
                $unavailable[] = $source->name();
            }
        }

        $all = self::without_duplicates($all);
        if ($filter !== 'all') {
            $all = array_values(array_filter($all, static fn(array $c) => $c['type'] === $filter));
        }
        usort($all, static fn(array $a, array $b) => $b['issued'] <=> $a['issued']);

        $now = time();
        $out = [];
        foreach (array_slice($all, 0, self::MAX_CREDENTIALS) as $credential) {
            $out[] = [
                'type' => $credential['type'],
                'name' => $credential['name'],
                'course' => $credential['course'] ?: null,
                'issuer' => $credential['issuer'] ?: null,
                'issued' => self::date($credential['issued']),
                'expires' => self::date($credential['expires']),
                'expired' => $credential['expires'] > 0 && $credential['expires'] < $now,
                'code' => $credential['code'] ?: null,
                'url' => $credential['url'] ?: null,
                'verify_url' => $credential['verify_url'] ?: null,
                'requires_course_access' => $credential['requires_course_access'],
            ];
        }

        $result = ['total' => count($all), 'credentials' => $out];
        if ($unavailable) {
            $result['some_sources_unavailable'] = true;
        }
        return $result;
    }

    /**
     * Drop badges that are the same badge reported by two sources.
     *
     * Same normalised name and issue dates within the window: the first one
     * kept is the one with a link, preferring Moodle's own. Names are the last
     * resort the plan allows, so accents, case and punctuation are ignored but
     * nothing looser is.
     *
     * @param array[] $credentials All credentials.
     * @return array[]
     */
    public static function without_duplicates(array $credentials): array {
        usort($credentials, static function (array $a, array $b): int {
            return [$a['source'] !== 'moodle', $a['url'] === ''] <=> [$b['source'] !== 'moodle', $b['url'] === ''];
        });
        $kept = [];
        foreach ($credentials as $credential) {
            $duplicate = false;
            if ($credential['type'] === 'badge') {
                $key = self::normalise($credential['name']);
                foreach ($kept as $other) {
                    if (
                        $other['type'] === 'badge'
                        && $other['source'] !== $credential['source']
                        && self::normalise($other['name']) === $key
                        && abs($other['issued'] - $credential['issued']) <= self::DUPLICATE_WINDOW
                    ) {
                        $duplicate = true;
                        break;
                    }
                }
            }
            if (!$duplicate) {
                $kept[] = $credential;
            }
        }
        return $kept;
    }

    /**
     * Name reduced to letters and digits, without accents or case.
     *
     * @param string $name Name.
     * @return string
     */
    private static function normalise(string $name): string {
        $name = \core_text::strtolower(\core_text::specialtoascii($name));
        return preg_replace('/[^a-z0-9]+/', '', $name);
    }
}
