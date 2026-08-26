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
 * Support escalation report for the Smart Tutor & Support AI block.
 *
 * Separate from the analytics dashboard on purpose. The dashboard is a rollup
 * that should stay the same size whatever the site does; this list grows one
 * row per request forever, and on a large installation it would swamp the page
 * it used to live on. It is also consulted for a different reason: to account
 * for one specific request, which is what the filters and the paging are for.
 *
 * @package    block_openaiagent
 * @copyright  2026 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use block_openaiagent\local\analytics;
use block_openaiagent\local\support_composer;
use block_openaiagent\local\supportrequest;

admin_externalpage_setup('block_openaiagent_supportreport');

$context = context_system::instance();
require_capability('block/openaiagent:manageglobalconfig', $context);

$url = new moodle_url('/blocks/openaiagent/supportreport.php');

$preset = optional_param('preset', '30', PARAM_ALPHANUM);
$courseid = optional_param('courseid', 0, PARAM_INT);
$status = optional_param('status', '', PARAM_ALPHA);
$namefilter = optional_param('name', '', PARAM_RAW_TRIMMED);
$page = optional_param('page', 0, PARAM_INT);

$perpage = 50;

// The same presets the dashboard offers, so moving between the two pages does
// not silently change the period under the reader's feet.
$todaymid = analytics::day_start(time());
switch ($preset) {
    case '7':
        $from = $todaymid - 6 * DAYSECS;
        break;
    case '90':
        $from = $todaymid - 89 * DAYSECS;
        break;
    case 'year':
        $from = analytics::day_start(make_timestamp((int)date('Y'), 1, 1));
        break;
    case '30':
    default:
        $preset = '30';
        $from = $todaymid - 29 * DAYSECS;
        break;
}

// Up to the end of today, not its midnight. The rollup sections of the
// dashboard compare against a day-keyed column, where a midnight bound is
// right; these rows carry a real timestamp, so the same bound would hide
// everything raised today - which is exactly when somebody comes looking.
$to = $todaymid + DAYSECS - 1;

$filters = [
    'courseid' => $courseid,
    'status' => $status,
    'name' => $namefilter,
];

$baseparams = array_filter([
    'preset' => $preset,
    'courseid' => $courseid ?: null,
    'status' => $status ?: null,
    'name' => $namefilter !== '' ? $namefilter : null,
]);
$baseurl = new moodle_url($url, $baseparams);

$total = analytics::count_support_rows($from, $to, $filters);
$rows = analytics::get_support_rows($from, $to, $perpage, $filters, $page * $perpage);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('analytics_support_heading', 'block_openaiagent'));
echo html_writer::tag('p', get_string('analytics_support_intro', 'block_openaiagent'));

// Filter form. Deliberately a plain GET form: every state of this page is then
// a URL an administrator can bookmark or paste into a ticket.
$statusoptions = ['' => get_string('supportreport_anystatus', 'block_openaiagent')];
foreach (['draft', 'queued', 'sent', 'failed', 'cancelled', 'expired'] as $key) {
    $statusoptions[$key] = get_string('support_status_' . $key, 'block_openaiagent');
}

$presetoptions = [
    '7' => get_string('analytics_range_7', 'block_openaiagent'),
    '30' => get_string('analytics_range_30', 'block_openaiagent'),
    '90' => get_string('analytics_range_90', 'block_openaiagent'),
    'year' => get_string('analytics_range_year', 'block_openaiagent'),
];

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out(false), 'class' => 'mb-3']);
echo html_writer::start_div('form-inline d-flex flex-wrap align-items-end');

echo html_writer::div(
    html_writer::label(get_string('supportreport_period', 'block_openaiagent'), 'preset', true, ['class' => 'mr-1'])
    . html_writer::select($presetoptions, 'preset', $preset, false, ['id' => 'preset', 'class' => 'custom-select']),
    'mr-3 mb-2'
);

echo html_writer::div(
    html_writer::label(get_string('analytics_support_status', 'block_openaiagent'), 'status', true, ['class' => 'mr-1'])
    . html_writer::select($statusoptions, 'status', $status, false, ['id' => 'status', 'class' => 'custom-select']),
    'mr-3 mb-2'
);

echo html_writer::div(
    html_writer::label(get_string('supportreport_search', 'block_openaiagent'), 'name', true, ['class' => 'mr-1'])
    . html_writer::empty_tag('input', [
        'type' => 'text',
        'id' => 'name',
        'name' => 'name',
        'value' => $namefilter,
        'class' => 'form-control',
        'placeholder' => get_string('supportreport_search_placeholder', 'block_openaiagent'),
    ]),
    'mr-3 mb-2'
);

if ($courseid) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
}

echo html_writer::div(
    html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('supportreport_apply', 'block_openaiagent'),
    ])
    . ' '
    . html_writer::link($url, get_string('supportreport_clear', 'block_openaiagent'), ['class' => 'btn btn-secondary']),
    'mb-2'
);

echo html_writer::end_div();
echo html_writer::end_tag('form');

if ($courseid) {
    echo $OUTPUT->notification(
        get_string('supportreport_coursefilter', 'block_openaiagent', (string)$courseid),
        \core\output\notification::NOTIFY_INFO
    );
}

if (empty($rows)) {
    echo $OUTPUT->notification(
        get_string('analytics_support_none', 'block_openaiagent'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('p', get_string('supportreport_count', 'block_openaiagent', $total));

$table = new html_table();
$table->head = [
    get_string('analytics_support_reference', 'block_openaiagent'),
    get_string('analytics_support_course', 'block_openaiagent'),
    get_string('analytics_support_participant', 'block_openaiagent'),
    get_string('analytics_support_category', 'block_openaiagent'),
    get_string('analytics_support_status', 'block_openaiagent'),
    get_string('analytics_support_created', 'block_openaiagent'),
    get_string('analytics_support_sent', 'block_openaiagent'),
    get_string('supportreport_recipients', 'block_openaiagent'),
];
$table->attributes['class'] = 'generaltable table-sm';

foreach ($rows as $row) {
    $statuslabel = get_string('support_status_' . $row['status'], 'block_openaiagent');
    // A failure is the row somebody has to act on, so it is the one that
    // carries its reason with it.
    if ($row['status'] === 'failed' && $row['error'] !== '') {
        $statuslabel .= html_writer::empty_tag('br')
            . html_writer::tag('small', s($row['error']), ['class' => 'text-danger']);
    }

    $coursename = $row['course'] !== ''
        ? format_string($row['course'], true, ['context' => $context])
        : (string)$row['courseid'];

    $table->data[] = [
        s($row['reference']),
        html_writer::link(new moodle_url('/course/view.php', ['id' => $row['courseid']]), $coursename),
        s($row['participant']),
        s(support_composer::category_name($row['category'])),
        $statuslabel,
        s($row['created']),
        s($row['sent']),
        s($row['recipients']),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);

echo html_writer::tag(
    'p',
    get_string('supportreport_prefixnote', 'block_openaiagent', s(supportrequest::reference_prefix())),
    ['class' => 'text-muted small']
);

echo $OUTPUT->footer();
