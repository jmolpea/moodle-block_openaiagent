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
 * Admin connectivity test tools for OpenAI and MCP.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use block_openaiagent\external\test_openai_connection;
use block_openaiagent\local\support_mailer;

admin_externalpage_setup('block_openaiagent_testtools');

$context = context_system::instance();
require_capability('block/openaiagent:manageglobalconfig', $context);

$url = new moodle_url('/blocks/openaiagent/testtools.php');
$test = optional_param('test', '', PARAM_ALPHA);

if ($test !== '' && confirm_sesskey()) {
    if ($test === 'openai') {
        $result = test_openai_connection::execute();
    } else if ($test === 'supportmail') {
        $result = support_mailer::send_test();
    } else {
        $result = null;
    }

    if ($result !== null) {
        $type = $result['ok']
            ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_ERROR;
        redirect($url, $result['message'], null, $type);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('testtools', 'block_openaiagent'));

$openaiurl = new moodle_url($url, ['test' => 'openai', 'sesskey' => sesskey()]);

echo html_writer::tag('p', get_string('testtools_intro', 'block_openaiagent'));
echo $OUTPUT->single_button($openaiurl, get_string('testopenai_button', 'block_openaiagent'), 'get');

// Support escalation mailbox probe. A destination that bounces is invisible to
// Moodle -- the bounce goes to the site no-reply address -- so this button is
// the only chance to find out the address is wrong before participants depend
// on it.
echo $OUTPUT->heading(get_string('testtools_supportmail_heading', 'block_openaiagent'), 3);
echo html_writer::tag('p', get_string('testtools_supportmail_intro', 'block_openaiagent'));

$supportto = (string)get_config('block_openaiagent', 'support_email_to');
$supportcc = (string)get_config('block_openaiagent', 'support_email_cc');
$configured = trim($supportto . ' ' . $supportcc);

if ($configured === '') {
    echo $OUTPUT->notification(
        get_string('support_test_norecipients', 'block_openaiagent'),
        \core\output\notification::NOTIFY_WARNING
    );
} else {
    echo html_writer::tag('p', html_writer::tag('code', s($configured)));
    $supportmailurl = new moodle_url($url, ['test' => 'supportmail', 'sesskey' => sesskey()]);
    echo $OUTPUT->single_button(
        $supportmailurl,
        get_string('testtools_supportmail_button', 'block_openaiagent'),
        'get'
    );
}

// Knowledge base / embeddings status: shows, per course, how many chunks are
// indexed and how many carry an embedding for the current model, so admins can
// verify that semantic retrieval is actually in use.
echo $OUTPUT->heading(get_string('testtools_rag_heading', 'block_openaiagent'), 3);

$embprovider = \block_openaiagent\ai\embeddings::provider();
$embmodel = \block_openaiagent\ai\embeddings::model();
if ($embprovider === '') {
    echo $OUTPUT->notification(
        get_string('testtools_rag_lexical', 'block_openaiagent'),
        \core\output\notification::NOTIFY_WARNING
    );
} else {
    echo html_writer::tag('p', get_string(
        'testtools_rag_semantic',
        'block_openaiagent',
        $embprovider . ' / ' . $embmodel
    ));
}

global $DB;
$rows = $DB->get_records_sql(
    "SELECT " . $DB->sql_concat('courseid', "'-'", 'blockinstanceid') . " AS profilekey,
            courseid,
            blockinstanceid,
            COUNT(id) AS chunks,
            SUM(CASE WHEN embeddingmodel = :model AND embedding IS NOT NULL THEN 1 ELSE 0 END) AS embedded
       FROM {block_openaiagent_chunks}
   GROUP BY courseid, blockinstanceid
   ORDER BY courseid, blockinstanceid",
    ['model' => $embmodel]
);

if (empty($rows)) {
    echo html_writer::tag('p', get_string('testtools_rag_nochunks', 'block_openaiagent'));
} else {
    $table = new html_table();
    $table->head = [
        get_string('course'),
        get_string('testtools_rag_block', 'block_openaiagent'),
        get_string('testtools_rag_chunks', 'block_openaiagent'),
        get_string('testtools_rag_embedded', 'block_openaiagent'),
    ];
    $pending = false;
    foreach ($rows as $row) {
        $coursename = $DB->get_field('course', 'fullname', ['id' => $row->courseid]);
        $embedded = (int)$row->embedded;
        if ($embprovider !== '' && $embedded < (int)$row->chunks) {
            $pending = true;
        }
        $table->data[] = [
            ($coursename !== false ? format_string($coursename) : '#' . $row->courseid),
            (int)$row->blockinstanceid === 0 ? '—' : '#' . (int)$row->blockinstanceid,
            (int)$row->chunks,
            $embedded,
        ];
    }
    echo html_writer::table($table);
    if ($pending) {
        echo $OUTPUT->notification(
            get_string('testtools_rag_pending', 'block_openaiagent'),
            \core\output\notification::NOTIFY_WARNING
        );
    }
}

// Retrieval inspector: run a query against a course knowledge base and show
// which chunks (with their section breadcrumb, score and selected flag) the
// tutor would receive. Lets an admin see whether a "not found" is a retrieval
// miss (the section is not ranking) or a genuine indexing gap.
echo $OUTPUT->heading(get_string('testtools_retrieval_heading', 'block_openaiagent'), 3);

$rcourseid = optional_param('rcourseid', 0, PARAM_INT);
$rblockid = optional_param('rblockid', 0, PARAM_INT);
$rquery = optional_param('rquery', '', PARAM_TEXT);

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out(false), 'class' => 'form-inline']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('label', get_string('testtools_retrieval_course', 'block_openaiagent') . ' ', [
    'for' => 'oaa_rcourseid',
    'class' => 'mr-1',
]);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'id' => 'oaa_rcourseid',
    'name' => 'rcourseid',
    'value' => $rcourseid ?: '',
    'class' => 'form-control mr-2',
    'style' => 'width:8em',
]);
echo html_writer::tag('label', get_string('testtools_retrieval_block', 'block_openaiagent') . ' ', [
    'for' => 'oaa_rblockid',
    'class' => 'mr-1',
]);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'id' => 'oaa_rblockid',
    'name' => 'rblockid',
    'value' => $rblockid ?: '',
    'class' => 'form-control mr-2',
    'style' => 'width:8em',
]);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'rquery',
    'value' => $rquery,
    'placeholder' => get_string('testtools_retrieval_query', 'block_openaiagent'),
    'class' => 'form-control mr-2',
    'style' => 'width:28em',
]);
echo html_writer::tag('button', get_string('testtools_retrieval_run', 'block_openaiagent'), [
    'type' => 'submit',
    'class' => 'btn btn-secondary',
]);
echo html_writer::end_tag('form');

if ($rcourseid > 0 && trim($rquery) !== '' && confirm_sesskey()) {
    $maxresults = (int)get_config('block_openaiagent', 'file_search_max_results');
    $limit = $maxresults > 0 ? $maxresults : 8;
    $inspection = \block_openaiagent\local\rag::inspect($rcourseid, $rquery, $limit, 15, $rblockid);

    if ($inspection['total'] === 0) {
        echo $OUTPUT->notification(
            get_string('testtools_retrieval_nochunks', 'block_openaiagent'),
            \core\output\notification::NOTIFY_WARNING
        );
    } else {
        echo html_writer::tag('p', get_string(
            'testtools_retrieval_summary',
            'block_openaiagent',
            (object) [
                'mode' => $inspection['semantic']
                    ? get_string('testtools_retrieval_semantic', 'block_openaiagent')
                    : get_string('testtools_retrieval_lexical', 'block_openaiagent'),
                'total' => $inspection['total'],
                'limit' => $limit,
            ]
        ));

        $itable = new html_table();
        $itable->head = [
            '#',
            get_string('testtools_retrieval_selected', 'block_openaiagent'),
            get_string('testtools_retrieval_score', 'block_openaiagent'),
            get_string('testtools_retrieval_breadcrumb', 'block_openaiagent'),
            get_string('testtools_retrieval_snippet', 'block_openaiagent'),
        ];
        $i = 1;
        foreach ($inspection['rows'] as $r) {
            $flag = $r['selected'] ? '✔' : '';
            $bc = format_string($r['breadcrumb']);
            if ($r['headinghit']) {
                $bc = html_writer::tag('strong', $bc);
            }
            $itable->data[] = [
                $i++,
                $flag,
                $r['score'],
                $bc,
                s($r['snippet']),
            ];
        }
        echo html_writer::table($itable);
    }
}

// Access-restriction inspector: dump, for a given course and target user, the raw
// per-section availability flags (visible / available / uservisible / reason) exactly
// as Moodle computes them for that user, plus the raw output of the two restriction
// tools. Lets an admin see whether a "no restriction" answer is a section-number
// mismatch, a genuinely available section, or a tool bug -- without guessing.
echo $OUTPUT->heading('Inspector de restricciones de acceso', 3);

$scourseid = optional_param('scourseid', 0, PARAM_INT);
$suserid = optional_param('suserid', 0, PARAM_INT);
$ssection = optional_param('ssection', -1, PARAM_INT);

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out(false), 'class' => 'form-inline']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::tag('label', 'Course id ', ['class' => 'mr-1']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'scourseid', 'value' => $scourseid ?: '',
    'class' => 'form-control mr-2', 'style' => 'width:8em',
]);
echo html_writer::tag('label', 'User id (alumno) ', ['class' => 'mr-1']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'suserid', 'value' => $suserid ?: '',
    'class' => 'form-control mr-2', 'style' => 'width:8em',
]);
echo html_writer::tag('label', 'Section number (opcional) ', ['class' => 'mr-1']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'name' => 'ssection', 'value' => $ssection >= 0 ? $ssection : '',
    'class' => 'form-control mr-2', 'style' => 'width:8em',
]);
echo html_writer::tag('button', 'Inspeccionar', ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

if ($scourseid > 0 && $suserid > 0 && confirm_sesskey()) {
    try {
        $scourse = get_course($scourseid);
        $smodinfo = get_fast_modinfo($scourse, $suserid);

        $stable = new html_table();
        $stable->head = ['#', 'name', 'visible', 'available', 'uservisible', 'availableinfo (motivo)'];
        foreach ($smodinfo->get_section_info_all() as $s) {
            try {
                $avail = (bool)$s->available;
            } catch (\Throwable $e) {
                $avail = '(error)';
            }
            $info = '';
            try {
                $raw = $s->availableinfo;
                if ($raw !== null && $raw !== '') {
                    if (is_object($raw)) {
                        $raw = \core_availability\info::format_info($raw, $scourseid);
                    }
                    $info = trim(html_to_text((string)$raw));
                }
            } catch (\Throwable $e) {
                $info = '(ERROR ' . get_class($e) . ': ' . $e->getMessage() . ')';
            }
            $stable->data[] = [
                (int)$s->section,
                s((string)($s->name ?? '')),
                $s->visible ? '1' : '0',
                is_bool($avail) ? ($avail ? '1' : '0') : $avail,
                $s->uservisible ? '1' : '0',
                s($info),
            ];
        }
        echo html_writer::tag('h4', 'Secciones (crudo, como las ve el alumno ' . (int)$suserid . ')');
        echo html_writer::table($stable);

        // Raw tool output as that user.
        $outline = \block_openaiagent\mcp\tool_registry::call(
            'moodle.get_course_outline',
            ['user_id' => $suserid],
            $suserid,
            $scourseid
        );
        echo html_writer::tag('h4', 'moodle.get_course_outline (salida)');
        echo html_writer::tag(
            'pre',
            s(json_encode($outline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            ['style' => 'max-height:20em;overflow:auto;background:#f6f6f6;padding:1em']
        );

        if ($ssection >= 0) {
            $gate = \block_openaiagent\mcp\tool_registry::call(
                'moodle.get_section_gate_status',
                ['user_id' => $suserid, 'section_number' => $ssection],
                $suserid,
                $scourseid
            );
            echo html_writer::tag('h4', 'moodle.get_section_gate_status (section ' . (int)$ssection . ')');
            echo html_writer::tag(
                'pre',
                s(json_encode($gate, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
                ['style' => 'max-height:20em;overflow:auto;background:#f6f6f6;padding:1em']
            );
        }
    } catch (\Throwable $e) {
        echo $OUTPUT->notification('Error: ' . $e->getMessage(), \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->footer();
