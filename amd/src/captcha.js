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
 * Invisible captcha for visitors of the assistant.
 *
 * Loads the provider's script only when the site configured one, and only on
 * the pages of an assistant open to visitors. Every message gets a fresh token,
 * which the server verifies with the provider before doing anything else.
 *
 * @module     block_openaiagent/captcha
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    'use strict';

    /** @var {Object} Script loads in progress or done, by URL. */
    var scripts = {};

    /** @var {Object|null} Rendered Turnstile widget state. */
    var turnstile = null;

    /**
     * Load a provider script once.
     *
     * @param {String} src Script URL.
     * @return {Promise}
     */
    var loadScript = function(src) {
        if (!scripts[src]) {
            scripts[src] = new Promise(function(resolve, reject) {
                var script = document.createElement('script');
                script.src = src;
                script.async = true;
                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }
        return scripts[src];
    };

    /**
     * A token from Cloudflare Turnstile, rendered once in invisible execution mode.
     *
     * @param {Object} config Captcha settings from the server.
     * @return {Promise<String>}
     */
    var turnstileToken = function(config) {
        return loadScript('https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit').then(function() {
            return new Promise(function(resolve, reject) {
                if (!turnstile) {
                    var container = document.createElement('div');
                    container.className = 'openaiagent-captcha';
                    document.body.appendChild(container);
                    turnstile = {container: container, resolve: null, reject: null};
                    turnstile.id = window.turnstile.render(container, {
                        sitekey: config.sitekey,
                        execution: 'execute',
                        appearance: 'interaction-only',
                        callback: function(token) {
                            if (turnstile.resolve) {
                                turnstile.resolve(token);
                            }
                        },
                        'error-callback': function() {
                            if (turnstile.reject) {
                                turnstile.reject(new Error('captcha'));
                            }
                        }
                    });
                } else {
                    // Tokens are single use: start a fresh challenge.
                    window.turnstile.reset(turnstile.id);
                }
                turnstile.resolve = resolve;
                turnstile.reject = reject;
                window.turnstile.execute(turnstile.container);
            });
        });
    };

    /**
     * A token from reCAPTCHA v3.
     *
     * @param {Object} config Captcha settings from the server.
     * @return {Promise<String>}
     */
    var recaptchaToken = function(config) {
        var src = 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(config.sitekey);
        return loadScript(src).then(function() {
            return new Promise(function(resolve, reject) {
                window.grecaptcha.ready(function() {
                    window.grecaptcha.execute(config.sitekey, {action: config.action}).then(resolve, reject);
                });
            });
        });
    };

    return {
        /**
         * Get a token for the next message, or an empty string when no captcha is configured.
         *
         * @param {Object|null} config Captcha settings from the server.
         * @return {Promise<String>}
         */
        token: function(config) {
            if (!config || !config.sitekey) {
                return Promise.resolve('');
            }
            if (config.provider === 'turnstile') {
                return turnstileToken(config);
            }
            if (config.provider === 'recaptchav3') {
                return recaptchaToken(config);
            }
            return Promise.resolve('');
        }
    };
});
