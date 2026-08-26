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
 * Tests for the offline license validator.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\license;

/**
 * Unit tests for {@see validator}.
 *
 * The cryptographically-valid path requires the private key, which never ships,
 * so these cover the states reachable without signing: missing and invalid keys.
 * The full valid/expired/wwwroot-mismatch paths are verified out of band when a
 * key is minted.
 *
 * @covers \block_openaiagent\license\validator
 */
final class validator_test extends \advanced_testcase {
    /**
     * An empty license key resolves to the "missing" state.
     */
    public function test_missing_when_unset(): void {
        $this->resetAfterTest();
        unset_config('license_key', 'block_openaiagent');
        unset_config(validator::TRIAL_SETTING, 'block_openaiagent');

        $this->assertSame(validator::STATUS_MISSING, validator::check()->status);
        $this->assertNotNull(validator::get_banner());
    }

    /**
     * A malformed or wrongly-signed key resolves to the "invalid" state.
     */
    public function test_invalid_when_garbage(): void {
        $this->resetAfterTest();

        set_config('license_key', 'not-a-real-key', 'block_openaiagent');
        $this->assertSame(validator::STATUS_INVALID, validator::check()->status);

        // Well-formed shape (payload.signature) but a signature that does not verify.
        $payload = rtrim(strtr(base64_encode(json_encode(['wwwroot' => 'https://x'])), '+/', '-_'), '=');
        set_config(
            'license_key',
            $payload . '.' . rtrim(strtr(base64_encode('bogus'), '+/', '-_'), '='),
            'block_openaiagent'
        );
        $this->assertSame(validator::STATUS_INVALID, validator::check()->status);
    }

    /**
     * Settings status returns a text/CSS pair for each state.
     */
    public function test_settings_status_shape(): void {
        $this->resetAfterTest();
        unset_config('license_key', 'block_openaiagent');

        $status = validator::get_settings_status();
        $this->assertArrayHasKey('text', $status);
        $this->assertArrayHasKey('css', $status);
        $this->assertNotSame('', $status['text']);
    }

    /**
     * Installing starts the evaluation window, and it runs for TRIAL_DAYS days.
     */
    public function test_trial_is_open_after_install(): void {
        $this->resetAfterTest();
        unset_config('license_key', 'block_openaiagent');
        set_config(validator::TRIAL_SETTING, time(), 'block_openaiagent');

        $result = validator::check();
        $this->assertSame(validator::STATUS_TRIAL, $result->status);
        $this->assertSame(validator::TRIAL_DAYS, validator::trial_days_left());

        // A running trial says nothing to participants: it is fully functional,
        // so there is no warning to show. (is_valid() is not asserted here: it
        // short-circuits to true under PHPUNIT_TEST, so it proves nothing.)
        $this->assertNull(validator::get_banner());
    }

    /**
     * The window closes exactly TRIAL_DAYS days after it opened.
     */
    public function test_trial_expires_after_the_window(): void {
        $this->resetAfterTest();
        unset_config('license_key', 'block_openaiagent');
        set_config(
            validator::TRIAL_SETTING,
            time() - ((validator::TRIAL_DAYS + 1) * DAYSECS),
            'block_openaiagent'
        );

        $this->assertSame(validator::STATUS_TRIAL_EXPIRED, validator::check()->status);
        $this->assertSame(0, validator::trial_days_left());

        // And now it blocks, with a message that says why. The date has to be
        // interpolated: an unsubstituted {$a} reaching a participant is the kind
        // of thing that only shows up when you actually render the string.
        $banner = validator::get_banner();
        $this->assertNotNull($banner);
        $this->assertStringNotContainsString('{$a}', $banner);
        $this->assertStringContainsString('julio@rsmax.es', validator::get_settings_status()['text']);
    }

    /**
     * Boundary: one day left is still open, one second past the end is not.
     */
    public function test_trial_boundary(): void {
        $this->resetAfterTest();
        unset_config('license_key', 'block_openaiagent');

        set_config(
            validator::TRIAL_SETTING,
            time() - ((validator::TRIAL_DAYS - 1) * DAYSECS),
            'block_openaiagent'
        );
        $this->assertSame(validator::STATUS_TRIAL, validator::check()->status);
        $this->assertSame(1, validator::trial_days_left());

        set_config(
            validator::TRIAL_SETTING,
            time() - (validator::TRIAL_DAYS * DAYSECS) - 1,
            'block_openaiagent'
        );
        $this->assertSame(validator::STATUS_TRIAL_EXPIRED, validator::check()->status);
    }

    /**
     * start_trial() opens a window once and never resets a running one.
     */
    public function test_start_trial_is_idempotent(): void {
        $this->resetAfterTest();
        unset_config(validator::TRIAL_SETTING, 'block_openaiagent');

        validator::start_trial();
        $first = (int) get_config('block_openaiagent', validator::TRIAL_SETTING);
        $this->assertGreaterThan(0, $first);

        // Simulate a window opened ten days ago, then upgrade again: the ten
        // days already spent must not be handed back.
        $tendaysago = time() - (10 * DAYSECS);
        set_config(validator::TRIAL_SETTING, $tendaysago, 'block_openaiagent');
        validator::start_trial();
        $this->assertSame($tendaysago, (int) get_config('block_openaiagent', validator::TRIAL_SETTING));
    }

    /**
     * A key that fails validation is reported as invalid, not quietly demoted
     * to a trial. Entering something wrong must not look like it worked.
     */
    public function test_invalid_key_beats_a_running_trial(): void {
        $this->resetAfterTest();
        set_config(validator::TRIAL_SETTING, time(), 'block_openaiagent');
        set_config('license_key', 'not-a-real-key', 'block_openaiagent');

        $this->assertSame(validator::STATUS_INVALID, validator::check()->status);
    }
}
