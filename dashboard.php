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
 * Usage analytics dashboard for the Smart Tutor & Support AI block.
 *
 * Reads the pre-aggregated rollup tables (built nightly by build_analytics_task)
 * so it stays cheap even on large installs, and renders an institutional overview
 * plus a comparable per-course breakdown.
 *
 * @package    block_openaiagent
 * @copyright  2026 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use block_openaiagent\local\analytics;
use block_openaiagent\output\dashboard;

admin_externalpage_setup('block_openaiagent_dashboard');

$context = context_system::instance();
require_capability('block/openaiagent:manageglobalconfig', $context);

$url = new moodle_url('/blocks/openaiagent/dashboard.php');

$preset = optional_param('preset', '30', PARAM_ALPHANUM);
$fromraw = optional_param('from', '', PARAM_RAW_TRIMMED);
$toraw = optional_param('to', '', PARAM_RAW_TRIMMED);
$namefilter = optional_param('name', '', PARAM_RAW_TRIMMED);
$action = optional_param('action', '', PARAM_ALPHA);

// Admin-triggered rebuild (useful right after install, before the nightly task runs).
if ($action === 'rebuild' && confirm_sesskey()) {
    core_php_time_limit::raise(300);
    analytics::build();
    redirect(
        new moodle_url($url, ['preset' => $preset, 'name' => $namefilter]),
        get_string('analytics_rebuilt', 'block_openaiagent'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Resolve the date range (inclusive day midnights).
$todaymid = analytics::day_start(time());
switch ($preset) {
    case '7':
        $to = $todaymid;
        $from = $to - 6 * DAYSECS;
        break;
    case '90':
        $to = $todaymid;
        $from = $to - 89 * DAYSECS;
        break;
    case 'year':
        $to = $todaymid;
        $from = analytics::day_start(make_timestamp((int)date('Y'), 1, 1));
        break;
    case 'custom':
        $fromts = $fromraw !== '' ? strtotime($fromraw . ' 00:00:00') : false;
        $tots = $toraw !== '' ? strtotime($toraw . ' 00:00:00') : false;
        $to = $tots ? analytics::day_start($tots) : $todaymid;
        $from = $fromts ? analytics::day_start($fromts) : ($to - 29 * DAYSECS);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        break;
    case '30':
    default:
        $preset = '30';
        $to = $todaymid;
        $from = $to - 29 * DAYSECS;
        break;
}

$meta = [
    'url' => $url,
    'from' => $from,
    'to' => $to,
    'preset' => $preset,
    'name' => $namefilter,
    'lastbuilt' => (int)get_config('block_openaiagent', 'analytics_watermark'),
];

// The course filter applies to the whole page, not just the table at the
// bottom. Leaving the headline figures site-wide while the table below showed a
// single course made those figures read as if they belonged to that course.
$scope = analytics::courseids_matching($namefilter);

$data = [
    'meta' => $meta,
    'overview' => analytics::get_overview($from, $to, $scope),
    'series' => analytics::get_timeseries($from, $to, $scope),
    'routes' => analytics::get_route_breakdown($from, $to, $scope),
    'recurrence' => analytics::get_recurrence($from, $to, $scope),
    'cost' => analytics::get_cost_by_model($from, $to, $scope),
    'tools' => analytics::get_tool_breakdown($from, $to, $scope),
    'courses' => analytics::get_course_rows($from, $to, $namefilter, 200, $scope),
];

echo $OUTPUT->header();

// Title row with a manual "refresh data" action.
$rebuildurl = new moodle_url($url, [
    'action' => 'rebuild', 'sesskey' => sesskey(), 'preset' => $preset, 'name' => $namefilter,
]);
echo html_writer::start_div('d-flex justify-content-between align-items-center flex-wrap');
echo $OUTPUT->heading(get_string('analytics_dashboard', 'block_openaiagent'));
echo $OUTPUT->single_button($rebuildurl, get_string('analytics_rebuild', 'block_openaiagent'), 'get');
echo html_writer::end_div();

echo dashboard::render($data);

// Support escalations. Only the headline numbers live here: the list itself is
// its own page, because it grows one row per request forever and this dashboard
// is meant to stay the same size whatever the site does.
//
// End of day, not midnight: the rollup sections above compare against a
// day-keyed column, but these rows carry a real timestamp, so a midnight bound
// would hide everything raised today.
$supportto = $to + DAYSECS - 1;
$supportsummary = analytics::get_support_summary($from, $supportto, $scope);

echo $OUTPUT->heading(get_string('analytics_support_heading', 'block_openaiagent'), 3);
echo html_writer::tag('p', get_string('analytics_support_intro', 'block_openaiagent'));

echo html_writer::tag('p', get_string('analytics_support_summary', 'block_openaiagent', (object)[
    'total' => $supportsummary->total,
    'sent' => $supportsummary->sent,
    'failed' => $supportsummary->failed,
    'cancelled' => $supportsummary->cancelled,
    'pending' => $supportsummary->pending,
    'confirmedratio' => format_float($supportsummary->confirmedratio, 1),
    'ratio' => format_float($supportsummary->ratio, 1),
]), ['class' => 'lead']);

$reporturl = new moodle_url('/blocks/openaiagent/supportreport.php', array_filter([
    'preset' => $preset,
    'name' => $namefilter !== '' ? $namefilter : null,
]));
echo html_writer::tag(
    'p',
    html_writer::link($reporturl, get_string('analytics_support_viewreport', 'block_openaiagent'))
);

echo $OUTPUT->footer();
