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
 * Offline asymmetric (RSA-SHA256) license validator for block_openaiagent.
 *
 * Key format:   {base64url(json_payload)}.{base64url(rsa_sha256_signature)}
 * Payload:      {"wwwroot": "...", "expires": "YYYY-MM-DD"?, "edition": "..."}
 *
 * The full $CFG->wwwroot is bound into the signed payload, so a key issued for
 * one site never validates on another. Only the PUBLIC key ships here; the
 * private key (license_private.key) stays with the vendor and signs keys via
 * generate_license.php. Each plugin has its own keypair.
 *
 * Enforcement: when the license is missing, invalid, or expired the assistant
 * is blocked (the block shows a message and the orchestrator refuses to run).
 * A site administrator can always reach the plugin settings to paste a key.
 *
 * Evaluation period: a fresh install starts a TRIAL_DAYS evaluation window
 * (timestamp in the `trial_started` plugin setting) during which the assistant
 * is fully functional with no key at all. This is what lets a Moodle
 * Marketplace reviewer, or a prospective customer, run the product end to end
 * without us knowing their site URL in advance. When the window closes the
 * assistant blocks exactly as it would for a missing key. Entering a valid key
 * at any point takes precedence over the trial.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\license;

/**
 * Offline RSA-SHA256 license validator for block_openaiagent.
 */
class validator {
    /** @var string Frankenstyle component — used for get_config() and get_string(). */
    const COMPONENT = 'block_openaiagent';

    /**
     * RSA public key (base64 DER) used to verify license signatures.
     * Safe to ship; the matching private key never leaves the vendor.
     */
    const PUBLIC_KEY =
            'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA22iaOZMPA0Am8eE/wXbEiSfDI60WLaB/11+Eykurp4xVUWxo16q5'
            . 'zDYPSaK6M/TS7We7/JXq/nA3VWMS+ErT1ejRRSrxu+xYzOd/uqrW7XciWRXRNTAqiUQ2EVmUk33GSvUtWFnpCJYxB2wG9n0r'
            . 'WbQVdwFEg2wjmYNqGDcACWz4RF0R0q6UQ710wuFDyxTG1ikvgRzloR4EFs+3mTy/B8MMvWedOS5Pws70lQpqtdsxDytC67LG'
            . 'HsotuUrq/rrXTZ7W8Aa/JnmzApo3uCLDUV51nMkjbkWOIjwx9pY9/8TbnqrO2bSQ6S59xac0IEyLnLhhDplClT1l0Fkb5UD5'
            . 'NQIDAQAB';

    /** License is present and cryptographically valid. */
    const STATUS_VALID   = 'valid';

    /** Signature verification failed, or wwwroot does not match. */
    const STATUS_INVALID = 'invalid';

    /** Signature valid and wwwroot matches, but the expiry date has passed. */
    const STATUS_EXPIRED = 'expired';

    /** No license key has been entered in plugin settings. */
    const STATUS_MISSING = 'missing';

    /** No key yet, but the post-install evaluation period is still running. */
    const STATUS_TRIAL = 'trial';

    /** No key, and the evaluation period has run out. */
    const STATUS_TRIAL_EXPIRED = 'trialexpired';

    /** Length of the post-install evaluation period, in days. */
    const TRIAL_DAYS = 15;

    /** Plugin setting holding the timestamp the evaluation period started. */
    const TRIAL_SETTING = 'trial_started';

    /**
     * Validate the currently-configured license key against this Moodle installation.
     *
     * @return \stdClass {string status; string|null expires; string|null edition}
     */
    public static function check(): \stdClass {
        $key = trim((string) get_config(self::COMPONENT, 'license_key'));

        if ($key === '') {
            return self::trial_state();
        }

        return self::validate_key($key);
    }

    /**
     * Start the evaluation period, unless it has already started.
     *
     * Called once from the install script and once from the upgrade step that
     * introduced the trial. Never resets a period that is already running.
     *
     * @return void
     */
    public static function start_trial(): void {
        if ((int) get_config(self::COMPONENT, self::TRIAL_SETTING) <= 0) {
            set_config(self::TRIAL_SETTING, time(), self::COMPONENT);
        }
    }

    /**
     * Timestamp at which the evaluation period ends, or 0 if it never started.
     *
     * @return int
     */
    public static function trial_ends(): int {
        $started = (int) get_config(self::COMPONENT, self::TRIAL_SETTING);

        return $started > 0 ? $started + (self::TRIAL_DAYS * DAYSECS) : 0;
    }

    /**
     * Whole days left in the evaluation period, floored at zero.
     *
     * A part-day counts as a day, so the first day of a 15-day trial reads
     * "15 days left" rather than "14".
     *
     * @return int
     */
    public static function trial_days_left(): int {
        $ends = self::trial_ends();
        if ($ends <= 0) {
            return 0;
        }

        return max(0, (int) ceil(($ends - time()) / DAYSECS));
    }

    /**
     * State of the evaluation period, used when no key has been entered.
     *
     * @return \stdClass {string status; string|null expires; string|null edition}
     */
    private static function trial_state(): \stdClass {
        $ends = self::trial_ends();

        if ($ends <= 0) {
            // The trial never started: an install that predates it, or a site
            // where the setting was cleared. Treat it as an ordinary missing
            // key rather than silently granting a fresh period.
            return (object) ['status' => self::STATUS_MISSING, 'expires' => null, 'edition' => null];
        }

        $expires = userdate($ends, get_string('strftimedate', 'langconfig'));

        if (time() >= $ends) {
            return (object) ['status' => self::STATUS_TRIAL_EXPIRED, 'expires' => $expires, 'edition' => 'trial'];
        }

        return (object) ['status' => self::STATUS_TRIAL, 'expires' => $expires, 'edition' => 'trial'];
    }

    /**
     * Convenience boolean: true only when the license is present and valid.
     *
     * Automated test runs (PHPUnit/Behat) bypass the gate so the suite can
     * exercise the assistant without a signed key. These constants are only ever
     * defined in test environments, never in production, so enforcement is
     * unaffected on real sites.
     *
     * @return bool
     */
    public static function is_valid(): bool {
        if ((defined('PHPUNIT_TEST') && PHPUNIT_TEST) || (defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING)) {
            return true;
        }
        return in_array(self::check()->status, [self::STATUS_VALID, self::STATUS_TRIAL], true);
    }

    /**
     * Return a user-facing message for non-valid license states, or null when
     * the license is valid. Shown in the block and on AJAX errors.
     *
     * @return string|null
     */
    public static function get_banner(): ?string {
        global $CFG;

        $result = self::check();

        switch ($result->status) {
            case self::STATUS_TRIAL:
                // The trial is fully functional, so there is nothing to warn a
                // participant about. The countdown lives on the settings page,
                // where the person who can act on it will see it.
                return null;

            case self::STATUS_TRIAL_EXPIRED:
                return get_string('license_banner_trialexpired', self::COMPONENT, $result->expires);

            case self::STATUS_MISSING:
                return get_string('license_banner_missing', self::COMPONENT);

            case self::STATUS_INVALID:
                return get_string('license_banner_invalid', self::COMPONENT, rtrim($CFG->wwwroot, '/'));

            case self::STATUS_EXPIRED:
                return get_string('license_banner_expired', self::COMPONENT, $result->expires);

            default: // STATUS_VALID.
                return null;
        }
    }

    /**
     * Return a short status string and CSS class for the settings page display.
     *
     * @return array{text: string, css: string}
     */
    public static function get_settings_status(): array {
        $result = self::check();

        switch ($result->status) {
            case self::STATUS_VALID:
                $text = $result->expires
                    ? get_string('license_status_valid', self::COMPONENT, $result->expires)
                    : get_string('license_status_valid_lifetime', self::COMPONENT);
                return ['text' => $text, 'css' => 'text-success fw-bold'];

            case self::STATUS_EXPIRED:
                return [
                    'text' => get_string('license_status_expired', self::COMPONENT, $result->expires),
                    'css'  => 'text-warning fw-bold',
                ];

            case self::STATUS_INVALID:
                return [
                    'text' => get_string('license_status_invalid', self::COMPONENT),
                    'css'  => 'text-danger fw-bold',
                ];

            case self::STATUS_TRIAL:
                return [
                    'text' => get_string('license_status_trial', self::COMPONENT, (object) [
                        'days' => self::trial_days_left(),
                        'date' => $result->expires,
                    ]),
                    'css'  => 'text-info fw-bold',
                ];

            case self::STATUS_TRIAL_EXPIRED:
                return [
                    'text' => get_string('license_status_trialexpired', self::COMPONENT, $result->expires),
                    'css'  => 'text-danger fw-bold',
                ];

            default: // MISSING.
                return [
                    'text' => get_string('license_status_missing', self::COMPONENT),
                    'css'  => 'text-muted',
                ];
        }
    }

    // Internals.

    /**
     * Perform cryptographic validation of a non-empty license key string.
     *
     * @param  string $key  Trimmed license key.
     * @return \stdClass
     */
    private static function validate_key(string $key): \stdClass {
        $dotpos = strrpos($key, '.');
        if ($dotpos === false || $dotpos === 0 || $dotpos === strlen($key) - 1) {
            return (object) ['status' => self::STATUS_INVALID, 'expires' => null, 'edition' => null];
        }

        $payloadb64 = substr($key, 0, $dotpos);
        $sigpart    = substr($key, $dotpos + 1);

        // Step 1: RSA-SHA256 signature verification with the embedded public key.
        $signature = base64_decode(strtr($sigpart, '-_', '+/'), true);
        $publickey = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(self::PUBLIC_KEY, 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        if (
            $signature === false
                || !function_exists('openssl_verify')
                || openssl_verify($payloadb64, $signature, $publickey, OPENSSL_ALGO_SHA256) !== 1
        ) {
            return (object) ['status' => self::STATUS_INVALID, 'expires' => null, 'edition' => null];
        }

        // Step 2: Decode payload.
        $payloadjson = base64_decode(strtr($payloadb64, '-_', '+/'), true);
        if ($payloadjson === false) {
            return (object) ['status' => self::STATUS_INVALID, 'expires' => null, 'edition' => null];
        }

        $payload = json_decode($payloadjson, true);
        if (!is_array($payload) || empty($payload['wwwroot'])) {
            return (object) ['status' => self::STATUS_INVALID, 'expires' => null, 'edition' => null];
        }

        // Step 3: wwwroot binding — exact match against $CFG->wwwroot.
        global $CFG;
        $siteroot = rtrim($CFG->wwwroot, '/');
        $keyroot  = rtrim($payload['wwwroot'], '/');

        if ($siteroot !== $keyroot) {
            return (object) ['status' => self::STATUS_INVALID, 'expires' => null, 'edition' => null];
        }

        // Step 4: Expiry check. Absent 'expires' means lifetime license.
        $expires = null;
        $edition = $payload['edition'] ?? null;

        if (!empty($payload['expires'])) {
            try {
                $expirydate = new \DateTime($payload['expires']);
                $expires     = $expirydate->format('Y-m-d');

                if (new \DateTime() > $expirydate) {
                    return (object) [
                        'status'  => self::STATUS_EXPIRED,
                        'expires' => $expires,
                        'edition' => $edition,
                    ];
                }
            } catch (\Exception $e) {
                return (object) ['status' => self::STATUS_INVALID, 'expires' => null, 'edition' => null];
            }
        }

        return (object) [
            'status'  => self::STATUS_VALID,
            'expires' => $expires,
            'edition' => $edition,
        ];
    }
}
