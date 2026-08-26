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
 * Settings for the Smart Tutor & Support AI block.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use block_openaiagent\admin\setting_emaillist;

// Admin-only usage analytics dashboard.
$ADMIN->add('blocksettings', new admin_externalpage(
    'block_openaiagent_dashboard',
    get_string('analytics_dashboard', 'block_openaiagent'),
    new moodle_url('/blocks/openaiagent/dashboard.php'),
    'block/openaiagent:manageglobalconfig'
));

// Admin-only support escalation report. Its own page rather than a table inside
// the dashboard: on a large site this list grows without bound, and it is
// consulted to chase one request, not to read a rollup.
$ADMIN->add('blocksettings', new admin_externalpage(
    'block_openaiagent_supportreport',
    get_string('supportreport', 'block_openaiagent'),
    new moodle_url('/blocks/openaiagent/supportreport.php'),
    'block/openaiagent:manageglobalconfig'
));

// Admin-only test tools page (OpenAI / MCP connectivity probes).
$ADMIN->add('blocksettings', new admin_externalpage(
    'block_openaiagent_testtools',
    get_string('testtools', 'block_openaiagent'),
    new moodle_url('/blocks/openaiagent/testtools.php'),
    'block/openaiagent:manageglobalconfig'
));

if ($ADMIN->fulltree) {
    $component = 'block_openaiagent';

    // License.
    $settings->add(new admin_setting_heading(
        $component . '/license_heading',
        get_string('license_heading', $component),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/license_key',
        get_string('license_key', $component),
        get_string('license_key_desc', $component),
        '',
        PARAM_RAW_TRIMMED
    ));

    // License status indicator — computed inline at render time (offline, no network).
    $licenseresult = \block_openaiagent\license\validator::get_settings_status();
    $settings->add(new admin_setting_heading(
        $component . '/license_status_display',
        '',
        html_writer::tag('span', $licenseresult['text'], ['class' => $licenseresult['css']])
    ));

    // General.
    $settings->add(new admin_setting_heading(
        $component . '/general_heading',
        get_string('settings_general_heading', $component),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        $component . '/enabled',
        get_string('settings_enabled', $component),
        get_string('settings_enabled_desc', $component),
        1
    ));

    // AI provider.
    $settings->add(new admin_setting_heading(
        $component . '/provider_heading',
        get_string('settings_provider_heading', $component),
        get_string('settings_provider_heading_desc', $component)
    ));

    $settings->add(new admin_setting_configselect(
        $component . '/provider',
        get_string('settings_provider', $component),
        get_string('settings_provider_desc', $component) . ' ' . get_string('settings_provider_savenote', $component),
        'openai',
        [
            'openai' => get_string('provider_openai', $component),
            'anthropic' => get_string('provider_anthropic', $component),
            'gemini' => get_string('provider_gemini', $component),
            'deepseek' => get_string('provider_deepseek', $component),
        ]
    ));

    // Credential fields switch live with the provider select (via hide_if, no
    // save needed). The model dropdowns still depend on the SAVED provider,
    // because their option lists differ per provider: after changing the
    // provider, save so the page rebuilds the model lists to match.
    $provider = (string)get_config($component, 'provider');
    $providerkeys = [
        'openai' => 'apikey',
        'anthropic' => 'anthropic_apikey',
        'gemini' => 'gemini_apikey',
        'deepseek' => 'deepseek_apikey',
    ];
    if (!isset($providerkeys[$provider])) {
        $provider = 'openai';
    }
    $embprovider = (string)get_config($component, 'embeddings_provider');
    if (!in_array($embprovider, ['auto', 'openai', 'gemini', 'none'], true)) {
        $embprovider = 'auto';
    }

    if (empty(get_config($component, $providerkeys[$provider]))) {
        $settings->add(new admin_setting_heading(
            $component . '/apikey_warning',
            '',
            html_writer::div(get_string('error_noapikey', $component), 'alert alert-warning')
        ));
    }

    // Credential fields for every provider are added to the page, but only the
    // relevant ones are shown, switching live (no save needed) via hide_if on the
    // provider select. OpenAI and Gemini keys stay visible regardless of the chat
    // provider because they are the only providers that can serve the knowledge-
    // base embeddings; Anthropic and DeepSeek are chat-only, so their fields are
    // hidden unless that provider is the active one. Base URLs are admin-only,
    // which prevents SSRF via attacker-controlled endpoints. Hidden providers
    // keep their saved keys; the fields are only visually hidden.
    //
    // The OpenAI key/URL keep their legacy setting names ('apikey',
    // 'openai_base_url') to preserve configured secrets on upgrade.
    $credsettings = [
        'openai' => [
            'key' => 'apikey',
            'keylabel' => 'settings_apikey',
            'keydesc' => 'settings_apikey_desc',
            'url' => 'openai_base_url',
            'urllabel' => 'settings_openai_base_url',
            'urldesc' => 'settings_openai_base_url_desc',
            'urldefault' => 'https://api.openai.com/v1',
            'chatonly' => false,
        ],
        'gemini' => [
            'key' => 'gemini_apikey',
            'keylabel' => 'settings_gemini_apikey',
            'keydesc' => 'settings_gemini_apikey_desc',
            'url' => 'gemini_base_url',
            'urllabel' => 'settings_gemini_base_url',
            'urldesc' => 'settings_base_url_desc',
            'urldefault' => 'https://generativelanguage.googleapis.com/v1beta',
            'chatonly' => false,
        ],
        'anthropic' => [
            'key' => 'anthropic_apikey',
            'keylabel' => 'settings_anthropic_apikey',
            'keydesc' => 'settings_anthropic_apikey_desc',
            'url' => 'anthropic_base_url',
            'urllabel' => 'settings_anthropic_base_url',
            'urldesc' => 'settings_base_url_desc',
            'urldefault' => 'https://api.anthropic.com/v1',
            'chatonly' => true,
        ],
        'deepseek' => [
            'key' => 'deepseek_apikey',
            'keylabel' => 'settings_deepseek_apikey',
            'keydesc' => 'settings_deepseek_apikey_desc',
            'url' => 'deepseek_base_url',
            'urllabel' => 'settings_deepseek_base_url',
            'urldesc' => 'settings_base_url_desc',
            'urldefault' => 'https://api.deepseek.com/v1',
            'chatonly' => true,
        ],
    ];
    foreach ($credsettings as $credprovider => $cred) {
        $settings->add(new admin_setting_configpasswordunmask(
            $component . '/' . $cred['key'],
            get_string($cred['keylabel'], $component),
            get_string($cred['keydesc'], $component),
            ''
        ));
        $settings->add(new admin_setting_configtext(
            $component . '/' . $cred['url'],
            get_string($cred['urllabel'], $component),
            get_string($cred['urldesc'], $component),
            $cred['urldefault'],
            PARAM_URL
        ));
        // Chat-only providers (no embeddings API) are hidden unless selected, so
        // the page shows only the credentials that actually apply.
        if ($cred['chatonly']) {
            $settings->hide_if($component . '/' . $cred['key'], $component . '/provider', 'neq', $credprovider);
            $settings->hide_if($component . '/' . $cred['url'], $component . '/provider', 'neq', $credprovider);
        }
    }

    // Embeddings for the course knowledge base. Anthropic and DeepSeek expose
    // no embeddings API, so this is chosen independently of the chat provider;
    // without embeddings, retrieval degrades to keyword search.
    $settings->add(new admin_setting_configselect(
        $component . '/embeddings_provider',
        get_string('settings_embeddings_provider', $component),
        get_string('settings_embeddings_provider_desc', $component) . ' '
            . get_string('settings_provider_savenote', $component),
        'auto',
        [
            'auto' => get_string('embeddings_auto', $component),
            'openai' => get_string('provider_openai', $component),
            'gemini' => get_string('provider_gemini', $component),
            'none' => get_string('embeddings_none', $component),
        ]
    ));

    if ($embprovider !== 'none') {
        $embmodels = [];
        if ($embprovider === 'openai' || $embprovider === 'auto') {
            $embmodels['text-embedding-3-small'] = 'text-embedding-3-small';
            $embmodels['text-embedding-3-large'] = 'text-embedding-3-large';
        }
        if ($embprovider === 'gemini' || $embprovider === 'auto') {
            $embmodels['gemini-embedding-001'] = 'gemini-embedding-001';
            $embmodels['text-embedding-004'] = 'text-embedding-004';
        }
        $savedembmodel = trim((string)get_config($component, 'embeddings_model'));
        if ($savedembmodel !== '' && !isset($embmodels[$savedembmodel])) {
            $embmodels[$savedembmodel] = get_string('model_option_current', $component, $savedembmodel);
        }
        $settings->add(new admin_setting_configselect(
            $component . '/embeddings_model',
            get_string('settings_embeddings_model', $component),
            get_string('settings_embeddings_model_desc', $component),
            '',
            ['' => get_string('embeddings_model_default', $component)] + $embmodels
        ));
    }

    // Models.
    $settings->add(new admin_setting_heading(
        $component . '/models_heading',
        get_string('settings_models_heading', $component),
        get_string('settings_models_heading_desc', $component)
    ));

    // Curated model lists per provider. Anything already saved that is not in
    // the list stays selectable so upgrades never silently change the model.
    $providermodels = [
        'openai' => [
            'gpt-5.6-luna',
            'gpt-5', 'gpt-5-mini', 'gpt-5-nano',
            'gpt-4.1', 'gpt-4.1-mini', 'gpt-4.1-nano',
            'gpt-4o', 'gpt-4o-mini', 'o4-mini',
        ],
        'anthropic' => [
            'claude-sonnet-5', 'claude-haiku-4-5', 'claude-opus-4-8', 'claude-sonnet-4-5',
        ],
        'gemini' => [
            'gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.0-flash',
        ],
        'deepseek' => [
            'deepseek-chat', 'deepseek-reasoner',
        ],
    ];
    $modeldefaults = [
        // Key 'content' is the tutor and the assistant, where answer quality
        // shows; 'model' is the router, a JSON classifier that runs on every
        // single turn and is better served by a non-reasoning model that
        // answers instantly.
        'openai' => [
            'content' => 'gpt-5.6-luna',
            'model' => 'gpt-4.1-mini',
            'small' => 'gpt-4.1-nano',
        ],
        'anthropic' => ['model' => 'claude-haiku-4-5', 'small' => 'claude-haiku-4-5'],
        'gemini' => ['model' => 'gemini-2.5-flash', 'small' => 'gemini-2.5-flash-lite'],
        'deepseek' => ['model' => 'deepseek-chat', 'small' => 'deepseek-chat'],
    ];

    $models = [
        'default_router_model' => $modeldefaults[$provider]['model'],
        'default_tutor_model' => $modeldefaults[$provider]['content'] ?? $modeldefaults[$provider]['model'],
        'default_assistant_model' => $modeldefaults[$provider]['content'] ?? $modeldefaults[$provider]['model'],
        'default_ambiguity_model' => $modeldefaults[$provider]['small'],
    ];
    // Keep a genuine custom model of the ACTIVE provider selectable (e.g. a new
    // model not yet in the curated list), but never a model left over from a
    // different provider: after switching provider, a saved OpenAI id like
    // "gpt-4.1-mini" must not linger as the selected option under Gemini. The
    // active provider's client tells us which ids it owns.
    $providerclient = \block_openaiagent\ai\factory::client($provider);
    foreach ($models as $name => $default) {
        $options = array_combine($providermodels[$provider], $providermodels[$provider]);
        $saved = trim((string)get_config($component, $name));
        if ($saved !== '' && !isset($options[$saved]) && $providerclient->owns_model($saved)) {
            $options[$saved] = get_string('model_option_current', $component, $saved);
        }
        $settings->add(new admin_setting_configselect(
            $component . '/' . $name,
            get_string('settings_' . $name, $component),
            get_string('settings_' . $name . '_desc', $component),
            $default,
            $options
        ));
    }

    // How hard a reasoning-capable model is allowed to think before answering.
    // Neutral here; each adapter maps it to its provider's own mechanism and
    // ignores it on models that do not reason. Empty = send nothing, i.e. the
    // provider's own default, which is what every install did before this
    // setting existed.
    $settings->add(new admin_setting_configselect(
        $component . '/reasoning_effort',
        get_string('settings_reasoning_effort', $component),
        get_string('settings_reasoning_effort_desc', $component),
        '',
        [
            '' => get_string('reasoning_effort_provider_default', $component),
            'minimal' => get_string('reasoning_effort_minimal', $component),
            'low' => get_string('reasoning_effort_low', $component),
            'medium' => get_string('reasoning_effort_medium', $component),
            'high' => get_string('reasoning_effort_high', $component),
        ]
    ));

    $temps = [
        'router_temperature' => '0.1',
        'tutor_temperature' => '0.25',
        'assistant_temperature' => '0.2',
    ];
    foreach ($temps as $name => $default) {
        $settings->add(new admin_setting_configtext(
            $component . '/' . $name,
            get_string('settings_' . $name, $component),
            '',
            $default,
            PARAM_FLOAT
        ));
    }

    $maxtokens = [
        'max_output_tokens_router' => 300,
        'max_output_tokens_tutor' => 1800,
        'max_output_tokens_assistant' => 1000,
    ];
    foreach ($maxtokens as $name => $default) {
        $settings->add(new admin_setting_configtext(
            $component . '/' . $name,
            get_string('settings_' . $name, $component),
            '',
            $default,
            PARAM_INT
        ));
    }

    // Features.
    $settings->add(new admin_setting_heading(
        $component . '/features_heading',
        get_string('settings_features_heading', $component),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        $component . '/enable_guardrails',
        get_string('settings_enable_guardrails', $component),
        get_string('settings_enable_guardrails_desc', $component),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        $component . '/enable_file_search',
        get_string('settings_enable_file_search', $component),
        get_string('settings_enable_file_search_desc', $component),
        1
    ));

    // Caps how many chunks File Search injects into the model context. Lower = cheaper.
    // 0 lets the API decide (its default returns many chunks and is significantly costlier).
    $settings->add(new admin_setting_configtext(
        $component . '/file_search_max_results',
        get_string('settings_file_search_max_results', $component),
        get_string('settings_file_search_max_results_desc', $component),
        8,
        PARAM_INT
    ));

    // Conditional retrieval-query rewriting: a cheap model call expands vague or
    // weakly-matching questions before re-retrieving. It only fires when the
    // question is too vague or the first retrieval pass came back weak, so most
    // turns cost nothing extra.
    $settings->add(new admin_setting_configcheckbox(
        $component . '/enable_query_rewrite',
        get_string('settings_enable_query_rewrite', $component),
        get_string('settings_enable_query_rewrite_desc', $component),
        1
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/query_rewrite_model',
        get_string('settings_query_rewrite_model', $component),
        get_string('settings_query_rewrite_model_desc', $component),
        '',
        PARAM_TEXT
    ));
    $settings->hide_if($component . '/query_rewrite_model', $component . '/enable_query_rewrite', 'notchecked');

    // Caps how many prior messages are resent as context when OpenAI server-side
    // storage is off. Lower = cheaper, but less conversational memory.
    $settings->add(new admin_setting_configtext(
        $component . '/history_max_messages',
        get_string('settings_history_max_messages', $component),
        get_string('settings_history_max_messages_desc', $component),
        6,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/default_language_policy',
        get_string('settings_default_language_policy', $component),
        get_string('settings_default_language_policy_desc', $component),
        'auto',
        PARAM_TEXT
    ));

    // Rate limiting.
    $settings->add(new admin_setting_heading(
        $component . '/ratelimit_heading',
        get_string('settings_ratelimit_heading', $component),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/rate_limit_per_user_minute',
        get_string('settings_rate_limit_per_user_minute', $component),
        get_string('settings_rate_limit_per_user_minute_desc', $component),
        10,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/rate_limit_per_user_day',
        get_string('settings_rate_limit_per_user_day', $component),
        get_string('settings_rate_limit_per_user_day_desc', $component),
        200,
        PARAM_INT
    ));

    // Support escalation.
    $settings->add(new admin_setting_heading(
        $component . '/support_heading',
        get_string('settings_support_heading', $component),
        get_string('settings_support_heading_desc', $component)
    ));

    $settings->add(new admin_setting_configcheckbox(
        $component . '/support_email_enabled',
        get_string('settings_support_email_enabled', $component),
        get_string('settings_support_email_enabled_desc', $component),
        0
    ));

    // Kept as the degraded path: when escalation by email is off, unavailable or
    // over its limits, the assistant still has somewhere to point the user.
    $settings->add(new admin_setting_configtext(
        $component . '/support_url',
        get_string('settings_support_url', $component),
        get_string('settings_support_url_desc', $component),
        '',
        PARAM_URL
    ));

    $settings->add(new setting_emaillist(
        $component . '/support_email_to',
        get_string('settings_support_email_to', $component),
        get_string('settings_support_email_to_desc', $component),
        '',
        PARAM_RAW
    ));

    $settings->add(new setting_emaillist(
        $component . '/support_email_cc',
        get_string('settings_support_email_cc', $component),
        get_string('settings_support_email_cc_desc', $component),
        '',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/support_allowed_domains',
        get_string('settings_support_allowed_domains', $component),
        get_string('settings_support_allowed_domains_desc', $component),
        '',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/support_reference_prefix',
        get_string('settings_support_reference_prefix', $component),
        get_string('settings_support_reference_prefix_desc', $component),
        \block_openaiagent\local\supportrequest::REFERENCE_PREFIX_DEFAULT,
        PARAM_ALPHANUMEXT,
        12
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/support_subject_template',
        get_string('settings_support_subject_template', $component),
        get_string('settings_support_subject_template_desc', $component),
        \block_openaiagent\local\defaults::SUPPORT_SUBJECT_DEFAULT,
        PARAM_RAW,
        80
    ));

    $settings->add(new admin_setting_configtextarea(
        $component . '/support_body_template',
        get_string('settings_support_body_template', $component),
        get_string('settings_support_body_template_desc', $component),
        \block_openaiagent\local\defaults::SUPPORT_BODY_DEFAULT,
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtextarea(
        $component . '/support_signature',
        get_string('settings_support_signature', $component),
        get_string('settings_support_signature_desc', $component),
        '',
        PARAM_RAW
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/support_sla_text',
        get_string('settings_support_sla_text', $component),
        get_string('settings_support_sla_text_desc', $component),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configcheckbox(
        $component . '/support_include_transcript',
        get_string('settings_support_include_transcript', $component),
        get_string('settings_support_include_transcript_desc', $component),
        1
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/support_transcript_turns',
        get_string('settings_support_transcript_turns', $component),
        get_string('settings_support_transcript_turns_desc', $component),
        6,
        PARAM_INT
    ));

    // Off by default. The participant already learns their query went out
    // through the Moodle notification, which also covers the case where it did
    // not; adding a second email on top of that is noise, and on a course with
    // thousands of participants it doubles the outbound volume this feature was
    // built to keep down.
    $settings->add(new admin_setting_configcheckbox(
        $component . '/support_copy_to_user',
        get_string('settings_support_copy_to_user', $component),
        get_string('settings_support_copy_to_user_desc', $component),
        0
    ));

    // Anti-spam limits. These are what keep a massive course from turning a
    // platform-wide incident into thousands of emails, so the defaults are
    // deliberately conservative.
    $settings->add(new admin_setting_configtext(
        $component . '/support_max_per_user_day',
        get_string('settings_support_max_per_user_day', $component),
        get_string('settings_support_max_per_user_day_desc', $component),
        3,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/support_cooldown_minutes',
        get_string('settings_support_cooldown_minutes', $component),
        get_string('settings_support_cooldown_minutes_desc', $component),
        10,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/support_offer_cooldown_turns',
        get_string('settings_support_offer_cooldown_turns', $component),
        get_string('settings_support_offer_cooldown_turns_desc', $component),
        5,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/support_dedupe_hours',
        get_string('settings_support_dedupe_hours', $component),
        get_string('settings_support_dedupe_hours_desc', $component),
        24,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/support_max_per_course_day',
        get_string('settings_support_max_per_course_day', $component),
        get_string('settings_support_max_per_course_day_desc', $component),
        200,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        $component . '/support_digest_mode',
        get_string('settings_support_digest_mode', $component),
        get_string('settings_support_digest_mode_desc', $component),
        0
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/support_digest_minutes',
        get_string('settings_support_digest_minutes', $component),
        get_string('settings_support_digest_minutes_desc', $component),
        30,
        PARAM_INT
    ));

    // Logging & debug.
    $settings->add(new admin_setting_heading(
        $component . '/logging_heading',
        get_string('settings_logging_heading', $component),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        $component . '/log_messages',
        get_string('settings_log_messages', $component),
        get_string('settings_log_messages_desc', $component),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        $component . '/log_payloads',
        get_string('settings_log_payloads', $component),
        get_string('settings_log_payloads_desc', $component),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        $component . '/debugmode',
        get_string('settings_debugmode', $component),
        get_string('settings_debugmode_desc', $component),
        0
    ));

    $settings->add(new admin_setting_configtext(
        $component . '/conversation_retention_days',
        get_string('settings_conversation_retention_days', $component),
        get_string('settings_conversation_retention_days_desc', $component),
        0,
        PARAM_INT
    ));

    // Analytics.
    $settings->add(new admin_setting_heading(
        $component . '/analytics_heading',
        get_string('settings_analytics_heading', $component),
        get_string('settings_analytics_heading_desc', $component)
    ));

    $settings->add(new admin_setting_configtextarea(
        $component . '/analytics_prices',
        get_string('settings_analytics_prices', $component),
        get_string('settings_analytics_prices_desc', $component),
        '',
        PARAM_RAW
    ));
}
