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
 * HTML/SVG renderer for the analytics dashboard.
 *
 * Self-contained: emits a scoped style block and inline SVG so it needs no
 * external chart library and works offline inside the Moodle admin chrome. Colours
 * follow a validated, colourblind-safe categorical palette (blue / aqua / yellow),
 * and both light and dark surfaces are themed.
 *
 * @package    block_openaiagent
 * @copyright  2026 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\output;

/**
 * Pure presentation helper: consumes prepared aggregates and returns HTML strings.
 */
class dashboard {
    /** @var string Component for language strings. */
    const C = 'block_openaiagent';

    /**
     * Render the whole dashboard body.
     *
     * @param array $d Prepared data (overview, series, routes, recurrence, cost, tools, courses, meta).
     * @return string HTML.
     */
    public static function render(array $d): string {
        $out = self::styles();
        $out .= \html_writer::start_div('oaa-dash');
        $out .= self::filters($d['meta']);
        $out .= self::kpi_cards($d['overview']);
        $out .= self::timeseries_section($d['series']);
        $out .= \html_writer::start_div('oaa-grid-2');
        $out .= self::routes_section($d['routes']);
        $out .= self::recurrence_section($d['recurrence'], $d['overview']);
        $out .= \html_writer::end_div();
        $out .= self::cost_section($d['cost'], $d['overview']);
        $out .= self::tools_section($d['tools']);
        $out .= self::courses_section($d['courses'], $d['meta']);
        $out .= self::footnote($d['meta']);
        $out .= \html_writer::end_div();
        return $out;
    }

    // ------------------------------------------------------------------
    // Filters bar.

    /**
     * Date-range presets, custom range and course-name filter form.
     *
     * @param array $meta Meta info (url, from, to, preset, name, presets).
     * @return string HTML.
     */
    protected static function filters(array $meta): string {
        $presets = [
            '7' => get_string('analytics_range_7', self::C),
            '30' => get_string('analytics_range_30', self::C),
            '90' => get_string('analytics_range_90', self::C),
            'year' => get_string('analytics_range_year', self::C),
        ];
        $chips = '';
        foreach ($presets as $key => $label) {
            $url = new \moodle_url($meta['url'], ['preset' => $key, 'name' => $meta['name']]);
            $active = ($meta['preset'] === $key) ? ' oaa-chip--active' : '';
            $chips .= \html_writer::link($url, $label, ['class' => 'oaa-chip' . $active]);
        }

        $form = \html_writer::start_tag('form', [
            'method' => 'get',
            'action' => $meta['url']->out_omit_querystring(),
            'class' => 'oaa-custom',
        ]);
        $form .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'preset', 'value' => 'custom']);
        $form .= \html_writer::empty_tag('input', [
            'type' => 'date', 'name' => 'from', 'value' => date('Y-m-d', $meta['from']),
            'class' => 'oaa-input', 'aria-label' => get_string('analytics_from', self::C),
        ]);
        $form .= \html_writer::tag('span', '→', ['class' => 'oaa-arrow']);
        $form .= \html_writer::empty_tag('input', [
            'type' => 'date', 'name' => 'to', 'value' => date('Y-m-d', $meta['to']),
            'class' => 'oaa-input', 'aria-label' => get_string('analytics_to', self::C),
        ]);
        $form .= \html_writer::empty_tag('input', [
            'type' => 'search', 'name' => 'name', 'value' => $meta['name'],
            'placeholder' => get_string('analytics_course_filter', self::C), 'class' => 'oaa-input oaa-input--search',
        ]);
        $form .= \html_writer::tag(
            'button',
            get_string('analytics_apply', self::C),
            ['type' => 'submit', 'class' => 'oaa-btn']
        );
        $form .= \html_writer::end_tag('form');

        $rangelabel = userdate($meta['from'], get_string('strftimedate', 'langconfig'))
            . ' — ' . userdate($meta['to'], get_string('strftimedate', 'langconfig'));

        $bar = \html_writer::div(
            \html_writer::div($chips, 'oaa-chips') . $form,
            'oaa-filters'
        );
        $bar .= \html_writer::div($rangelabel, 'oaa-rangelabel');
        return $bar;
    }

    // ------------------------------------------------------------------
    // KPI cards.

    /**
     * Headline KPI cards.
     *
     * @param \stdClass $o Overview aggregates.
     * @return string HTML.
     */
    protected static function kpi_cards(\stdClass $o): string {
        $cards = [
            self::card(
                self::pct($o->adoptionrate),
                get_string('analytics_kpi_adoption', self::C),
                get_string(
                    'analytics_kpi_adoption_sub',
                    self::C,
                    ['users' => self::int($o->distinctusers), 'enrolled' => self::int($o->exposedparticipants)]
                )
            ),
            self::card(
                self::int($o->distinctusers),
                get_string('analytics_kpi_users', self::C),
                get_string('analytics_kpi_users_sub', self::C, self::int($o->courseswithuse))
            ),
            self::card(
                self::int($o->questions),
                get_string('analytics_kpi_questions', self::C),
                get_string('analytics_kpi_questions_sub', self::C, self::dec($o->questionsperuser))
            ),
            self::card(
                self::int($o->conversations),
                get_string('analytics_kpi_conversations', self::C),
                get_string('analytics_kpi_conversations_sub', self::C, self::dec($o->turnsperconversation))
            ),
            self::card(
                self::pct($o->recurrencerate),
                get_string('analytics_kpi_recurrence', self::C),
                get_string('analytics_kpi_recurrence_sub', self::C, self::int($o->recurrentusers))
            ),
            self::card(
                self::pct($o->errorrate),
                get_string('analytics_kpi_errorrate', self::C),
                get_string('analytics_kpi_errorrate_sub', self::C, self::int($o->answers)),
                self::error_state($o->errorrate)
            ),
        ];
        return \html_writer::div(implode('', $cards), 'oaa-cards');
    }

    /**
     * A single KPI card.
     *
     * @param string $value Big value (already formatted & escaped-safe).
     * @param string $label Small caps label.
     * @param string $sub Sub-label (denominator / context).
     * @param string $stateclass Optional status modifier class.
     * @return string HTML.
     */
    protected static function card(string $value, string $label, string $sub, string $stateclass = ''): string {
        $inner = \html_writer::div(s($value), 'oaa-card__value' . ($stateclass ? ' ' . $stateclass : ''));
        $inner .= \html_writer::div(s($label), 'oaa-card__label');
        $inner .= \html_writer::div($sub, 'oaa-card__sub');
        return \html_writer::div($inner, 'oaa-card');
    }

    // ------------------------------------------------------------------
    // Time series (small multiples: questions above, users below).

    /**
     * Two stacked area charts sharing the x-axis (single-scale each).
     *
     * @param array $series List of {day, questions, users}.
     * @return string HTML.
     */
    protected static function timeseries_section(array $series): string {
        $body = self::area_chart(
            $series,
            'questions',
            'var(--series-1)',
            get_string('analytics_kpi_questions', self::C)
        );
        $body .= self::area_chart(
            $series,
            'users',
            'var(--series-2)',
            get_string('analytics_kpi_users', self::C)
        );
        return self::panel(
            get_string('analytics_section_trend', self::C),
            get_string('analytics_section_trend_desc', self::C),
            \html_writer::div($body, 'oaa-multiples')
        );
    }

    /**
     * Render one area/line chart as inline SVG (single measure, single axis).
     *
     * @param array $series List of point objects.
     * @param string $key Measure key on each point.
     * @param string $color CSS colour expression for the line/fill.
     * @param string $label Measure label (also the legend).
     * @return string HTML.
     */
    protected static function area_chart(array $series, string $key, string $color, string $label): string {
        $n = count($series);
        $w = 720;
        $h = 170;
        $padl = 44;
        $padr = 12;
        $padt = 16;
        $padb = 24;
        $plotw = $w - $padl - $padr;
        $ploth = $h - $padt - $padb;

        $max = 1;
        foreach ($series as $p) {
            $max = max($max, (int)$p->{$key});
        }
        // Round the axis max up to a "nice" number.
        $max = self::nice_ceil($max);

        $x = function ($i) use ($padl, $plotw, $n) {
            return $n <= 1 ? $padl + $plotw / 2 : $padl + ($i / ($n - 1)) * $plotw;
        };
        $y = function ($v) use ($padt, $ploth, $max) {
            return $padt + $ploth - ($v / $max) * $ploth;
        };

        // Grid + y labels (0, mid, max).
        $grid = '';
        foreach ([0, 0.5, 1] as $frac) {
            $gy = $padt + $ploth - $frac * $ploth;
            $grid .= '<line x1="' . $padl . '" y1="' . round($gy, 1) . '" x2="' . ($w - $padr)
                . '" y2="' . round($gy, 1) . '" class="oaa-grid"/>';
            $grid .= '<text x="' . ($padl - 8) . '" y="' . round($gy + 3, 1)
                . '" class="oaa-axis" text-anchor="end">' . self::int((int)round($max * $frac)) . '</text>';
        }

        // Line + area.
        $line = '';
        $area = '';
        $lastlabel = '';
        if ($n > 0) {
            $pts = [];
            foreach ($series as $i => $p) {
                $pts[] = round($x($i), 1) . ',' . round($y((int)$p->{$key}), 1);
            }
            $line = '<polyline class="oaa-line" points="' . implode(' ', $pts)
                . '" style="stroke:' . $color . '"/>';
            $areapts = $x(0) . ',' . ($padt + $ploth) . ' ' . implode(' ', $pts)
                . ' ' . round($x($n - 1), 1) . ',' . ($padt + $ploth);
            $area = '<polygon class="oaa-area" points="' . $areapts . '" style="fill:' . $color . '"/>';

            // Direct label on the last point (avoids a number on every point).
            $lp = $series[$n - 1];
            $lx = round($x($n - 1), 1);
            $ly = round($y((int)$lp->{$key}), 1);
            $line .= '<circle cx="' . $lx . '" cy="' . $ly . '" r="3.5" style="fill:' . $color . '"/>';
            $lastlabel = '<text x="' . ($lx - 4) . '" y="' . max($padt + 10, $ly - 8)
                . '" class="oaa-pointlabel" text-anchor="end">' . self::int((int)$lp->{$key}) . '</text>';
        }

        // X labels: first / middle / last day.
        $xlabels = '';
        if ($n > 0) {
            $idxs = $n === 1 ? [0] : [0, intdiv($n - 1, 2), $n - 1];
            $anchors = ['start', 'middle', 'end'];
            foreach ($idxs as $j => $i) {
                $xlabels .= '<text x="' . round($x($i), 1) . '" y="' . ($h - 6)
                    . '" class="oaa-axis" text-anchor="' . ($anchors[$j] ?? 'middle') . '">'
                    . userdate($series[$i]->day, get_string('strftimedateshort', 'langconfig')) . '</text>';
            }
        }

        $legend = '<span class="oaa-legend"><span class="oaa-swatch" style="background:' . $color . '"></span>'
            . s($label) . '</span>';

        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="oaa-svg" preserveAspectRatio="none" role="img" '
            . 'aria-label="' . s($label) . '">'
            . $grid . $area . $line . $lastlabel . $xlabels . '</svg>';

        return \html_writer::div($legend . $svg, 'oaa-chart');
    }

    // ------------------------------------------------------------------
    // Routes (100% stacked bar).

    /**
     * Tutor / Assistant / Ambiguous share as a 100% stacked bar.
     *
     * @param array $routes Route => count.
     * @return string HTML.
     */
    protected static function routes_section(array $routes): string {
        $total = array_sum($routes);
        if ($total <= 0) {
            return self::panel(
                get_string('analytics_section_routes', self::C),
                get_string('analytics_section_routes_desc', self::C),
                self::empty_state()
            );
        }

        $colors = [
            'tutor' => 'var(--series-1)',
            'assistant' => 'var(--series-2)',
            'ambiguous' => 'var(--series-3)',
            'ambiguity' => 'var(--series-3)',
            'unknown' => 'var(--muted)',
        ];

        $segs = '';
        $legend = '';
        $x = 0.0;
        foreach ($routes as $route => $count) {
            $pct = $count / $total * 100;
            $color = $colors[$route] ?? 'var(--muted)';
            $label = self::route_label($route);
            // 2px surface gap between segments via a thin stroke of surface colour.
            $segs .= '<rect x="' . round($x, 2) . '%" y="0" width="' . round($pct, 2) . '%" height="100%" '
                . 'fill="' . $color . '"><title>' . s($label) . ': ' . self::int($count)
                . ' (' . self::pct($count / $total) . ')</title></rect>';
            $x += $pct;
            $legend .= '<span class="oaa-legend"><span class="oaa-swatch" style="background:' . $color . '"></span>'
                . s($label) . ' · ' . self::pct($count / $total) . '</span>';
        }

        $bar = '<div class="oaa-stack"><svg width="100%" height="34" preserveAspectRatio="none" '
            . 'class="oaa-stack__svg">' . $segs . '</svg></div>';
        $bar .= \html_writer::div($legend, 'oaa-legends');

        return self::panel(
            get_string('analytics_section_routes', self::C),
            get_string('analytics_section_routes_desc', self::C),
            $bar
        );
    }

    // ------------------------------------------------------------------
    // Recurrence distribution.

    /**
     * Users bucketed by active days (1 / 2-3 / 4+).
     *
     * @param array $buckets Bucket => count.
     * @param \stdClass $o Overview (for total).
     * @return string HTML.
     */
    protected static function recurrence_section(array $buckets, \stdClass $o): string {
        $total = array_sum($buckets);
        if ($total <= 0) {
            return self::panel(
                get_string('analytics_section_recurrence', self::C),
                get_string('analytics_section_recurrence_desc', self::C),
                self::empty_state()
            );
        }
        $labels = [
            '1' => get_string('analytics_recur_1', self::C),
            '2-3' => get_string('analytics_recur_23', self::C),
            '4+' => get_string('analytics_recur_4', self::C),
        ];
        $colors = ['1' => 'var(--muted)', '2-3' => 'var(--series-2)', '4+' => 'var(--series-1)'];
        $items = [];
        foreach ($buckets as $k => $v) {
            $items[] = [
                'label' => $labels[$k] ?? $k,
                'value' => $v,
                'display' => self::int($v) . '  ·  ' . self::pct($total > 0 ? $v / $total : 0),
                'color' => $colors[$k] ?? 'var(--series-1)',
                'max' => $total,
            ];
        }
        return self::panel(
            get_string('analytics_section_recurrence', self::C),
            get_string('analytics_section_recurrence_desc', self::C),
            self::hbars($items)
        );
    }

    // ------------------------------------------------------------------
    // Cost & tokens.

    /**
     * Token totals and estimated cost by model.
     *
     * @param array $cost List of model cost objects.
     * @param \stdClass $o Overview (for totals / cost per question).
     * @return string HTML.
     */
    protected static function cost_section(array $cost, \stdClass $o): string {
        $totalcost = 0.0;
        $anyestimate = false;
        foreach ($cost as $c) {
            $totalcost += $c->cost;
            if (!$c->haspricing) {
                $anyestimate = true;
            }
        }
        $costperq = $o->questions > 0 ? $totalcost / $o->questions : 0.0;

        $mini = \html_writer::div(
            self::minicard(self::int($o->totaltokens), get_string('analytics_tokens_total', self::C))
            . self::minicard(self::int($o->prompttokens), get_string('analytics_tokens_in', self::C))
            . self::minicard(self::int($o->completiontokens), get_string('analytics_tokens_out', self::C))
            . self::minicard(
                self::pct($o->cachehitrate),
                get_string('analytics_tokens_cached', self::C)
            )
            . self::minicard(self::money($totalcost), get_string('analytics_cost_est', self::C))
            . self::minicard(self::money($costperq, 4), get_string('analytics_cost_per_q', self::C)),
            'oaa-minicards'
        );

        // Per-model table. Cached input is shown next to total input because it
        // is a subset of it, not an extra: it is the share billed at the cheap
        // cached rate.
        $rows = '';
        foreach ($cost as $c) {
            $costcell = $c->haspricing
                ? self::money($c->cost)
                : '<span class="oaa-muted" title="' . s(get_string('analytics_no_pricing', self::C)) . '">—</span>';
            $cachedcell = $c->input > 0
                ? self::int($c->cached) . ' <span class="oaa-muted">(' . self::pct($c->cached / $c->input) . ')</span>'
                : '<span class="oaa-muted">—</span>';
            $rows .= '<tr>'
                . '<td>' . s($c->model) . '</td>'
                . '<td class="oaa-num">' . self::int($c->input) . '</td>'
                . '<td class="oaa-num">' . $cachedcell . '</td>'
                . '<td class="oaa-num">' . self::int($c->output) . '</td>'
                . '<td class="oaa-num">' . self::int($c->total) . '</td>'
                . '<td class="oaa-num">' . $costcell . '</td>'
                . '</tr>';
        }
        $table = $rows === '' ? self::empty_state() : '<div class="oaa-tablewrap"><table class="oaa-table"><thead><tr>'
            . '<th>' . get_string('analytics_col_model', self::C) . '</th>'
            . '<th class="oaa-num">' . get_string('analytics_tokens_in', self::C) . '</th>'
            . '<th class="oaa-num">' . get_string('analytics_col_cached', self::C) . '</th>'
            . '<th class="oaa-num">' . get_string('analytics_tokens_out', self::C) . '</th>'
            . '<th class="oaa-num">' . get_string('analytics_tokens_total', self::C) . '</th>'
            . '<th class="oaa-num">' . get_string('analytics_cost_est', self::C) . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';

        $desc = get_string('analytics_section_cost_desc', self::C);
        if ($anyestimate) {
            $desc .= ' ' . get_string('analytics_cost_estimate_note', self::C);
        }
        return self::panel(get_string('analytics_section_cost', self::C), $desc, $mini . $table);
    }

    // ------------------------------------------------------------------
    // MCP tools.

    /**
     * Tool-call success/failure per tool.
     *
     * @param array $tools List of {toolname, calls, ok, fail}.
     * @return string HTML.
     */
    protected static function tools_section(array $tools): string {
        if (!$tools) {
            return self::panel(
                get_string('analytics_section_tools', self::C),
                get_string('analytics_section_tools_desc', self::C),
                self::empty_state(get_string('analytics_tools_empty', self::C))
            );
        }
        $max = 1;
        foreach ($tools as $t) {
            $max = max($max, (int)$t->calls);
        }
        $rows = '';
        foreach ($tools as $t) {
            $calls = (int)$t->calls;
            $ok = (int)$t->ok;
            $fail = (int)$t->fail;
            $okw = $calls > 0 ? $ok / $max * 100 : 0;
            $failw = $calls > 0 ? $fail / $max * 100 : 0;
            $successpct = $calls > 0 ? self::pct($ok / $calls) : '—';
            $bar = '<div class="oaa-toolbar">'
                . '<span class="oaa-toolbar__ok" style="width:' . round($okw, 2) . '%"></span>'
                . '<span class="oaa-toolbar__fail" style="width:' . round($failw, 2) . '%"></span>'
                . '</div>';
            $rows .= '<div class="oaa-toolrow">'
                . '<div class="oaa-toolrow__name" title="' . s($t->toolname) . '">' . s($t->toolname) . '</div>'
                . '<div class="oaa-toolrow__bar">' . $bar . '</div>'
                . '<div class="oaa-toolrow__meta">' . self::int($calls) . ' · ' . $successpct . '</div>'
                . '</div>';
        }
        $legend = '<div class="oaa-legends">'
            . '<span class="oaa-legend"><span class="oaa-swatch oaa-swatch--ok"></span>'
            . get_string('analytics_tool_ok', self::C) . '</span>'
            . '<span class="oaa-legend"><span class="oaa-swatch oaa-swatch--fail"></span>'
            . get_string('analytics_tool_fail', self::C) . '</span></div>';
        return self::panel(
            get_string('analytics_section_tools', self::C),
            get_string('analytics_section_tools_desc', self::C),
            $legend . $rows
        );
    }

    // ------------------------------------------------------------------
    // Per-course portfolio table.

    /**
     * Comparative per-course table with inline adoption bars.
     *
     * @param array $courses Course rows.
     * @param array $meta Meta info.
     * @return string HTML.
     */
    protected static function courses_section(array $courses, array $meta): string {
        if (!$courses) {
            return self::panel(
                get_string('analytics_section_courses', self::C),
                get_string('analytics_section_courses_desc', self::C),
                self::empty_state(get_string('analytics_courses_empty', self::C))
            );
        }
        $head = '<tr>'
            . '<th>' . get_string('analytics_col_course', self::C) . '</th>'
            . '<th class="oaa-num">' . get_string('analytics_col_users', self::C) . '</th>'
            . '<th class="oaa-num">' . get_string('analytics_col_enrolled', self::C) . '</th>'
            . '<th>' . get_string('analytics_col_adoption', self::C) . '</th>'
            . '<th class="oaa-num">' . get_string('analytics_col_questions', self::C) . '</th>'
            . '<th class="oaa-num">' . get_string('analytics_col_recurrent', self::C) . '</th>'
            . '<th class="oaa-num">' . get_string('analytics_col_errorrate', self::C) . '</th>'
            . '<th class="oaa-num">' . get_string('analytics_col_tokens', self::C) . '</th>'
            . '</tr>';

        $rows = '';
        foreach ($courses as $c) {
            $courseurl = new \moodle_url('/course/view.php', ['id' => $c->courseid]);
            $adoptw = round(min(1, $c->adoption) * 100, 2);
            $adoptbar = '<div class="oaa-inlinebar"><span style="width:' . $adoptw . '%"></span>'
                . '<em>' . self::pct($c->adoption) . '</em></div>';
            $errcls = self::error_state($c->errorrate);
            $rows .= '<tr>'
                . '<td class="oaa-course"><a href="' . $courseurl->out(false) . '">'
                    . s(format_string($c->fullname)) . '</a>'
                    . '<span class="oaa-course__short">' . s($c->shortname) . '</span></td>'
                . '<td class="oaa-num">' . self::int($c->users) . '</td>'
                . '<td class="oaa-num">' . self::int($c->enrolled) . '</td>'
                . '<td>' . $adoptbar . '</td>'
                . '<td class="oaa-num">' . self::int($c->questions) . '</td>'
                . '<td class="oaa-num">' . self::int($c->recurrent) . '</td>'
                . '<td class="oaa-num ' . $errcls . '">' . ($c->answers > 0 ? self::pct($c->errorrate) : '—') . '</td>'
                . '<td class="oaa-num">' . self::int($c->tokens) . '</td>'
                . '</tr>';
        }
        $table = '<div class="oaa-tablewrap"><table class="oaa-table oaa-table--courses">'
            . '<thead>' . $head . '</thead><tbody>' . $rows . '</tbody></table></div>';

        $desc = get_string('analytics_section_courses_desc', self::C);
        if ($meta['name'] !== '') {
            $desc .= ' · ' . get_string('analytics_filtered_by', self::C, s($meta['name']));
        }
        return self::panel(get_string('analytics_section_courses', self::C), $desc, $table);
    }

    // ------------------------------------------------------------------
    // Shared building blocks.

    /**
     * A titled section panel.
     *
     * @param string $title Section title.
     * @param string $desc Section description / reading rule.
     * @param string $body Section body HTML.
     * @return string HTML.
     */
    protected static function panel(string $title, string $desc, string $body): string {
        $head = \html_writer::tag('h3', s($title), ['class' => 'oaa-panel__title'])
            . \html_writer::div($desc, 'oaa-panel__desc');
        return \html_writer::div(\html_writer::div($head, 'oaa-panel__head') . $body, 'oaa-panel');
    }

    /**
     * Horizontal bars for labelled magnitudes.
     *
     * @param array $items Each: label, value, display, color, max.
     * @return string HTML.
     */
    protected static function hbars(array $items): string {
        $out = '';
        foreach ($items as $it) {
            $w = $it['max'] > 0 ? round($it['value'] / $it['max'] * 100, 2) : 0;
            $out .= '<div class="oaa-hbar">'
                . '<div class="oaa-hbar__label">' . s($it['label']) . '</div>'
                . '<div class="oaa-hbar__track"><span style="width:' . $w . '%;background:' . $it['color'] . '"></span></div>'
                . '<div class="oaa-hbar__value">' . $it['display'] . '</div>'
                . '</div>';
        }
        return \html_writer::div($out, 'oaa-hbars');
    }

    /**
     * A small stat tile (used in the cost section).
     *
     * @param string $value Value.
     * @param string $label Label.
     * @return string HTML.
     */
    protected static function minicard(string $value, string $label): string {
        return \html_writer::div(
            \html_writer::div(s($value), 'oaa-minicard__value')
            . \html_writer::div(s($label), 'oaa-minicard__label'),
            'oaa-minicard'
        );
    }

    /**
     * Empty-state placeholder.
     *
     * @param string $msg Optional message.
     * @return string HTML.
     */
    protected static function empty_state(string $msg = ''): string {
        if ($msg === '') {
            $msg = get_string('analytics_no_data', self::C);
        }
        return \html_writer::div(s($msg), 'oaa-empty');
    }

    /**
     * Footnote with methodological caveats and last-build time.
     *
     * @param array $meta Meta info.
     * @return string HTML.
     */
    protected static function footnote(array $meta): string {
        $built = $meta['lastbuilt'] > 0
            ? userdate($meta['lastbuilt'], get_string('strftimedatetimeshort', 'langconfig'))
            : get_string('analytics_never_built', self::C);
        $text = get_string('analytics_footnote', self::C)
            . ' · ' . get_string('analytics_last_built', self::C, $built);
        return \html_writer::div($text, 'oaa-footnote');
    }

    /**
     * Status class for an error rate (recessive by default; escalates with severity).
     *
     * @param float $rate Error rate 0..1.
     * @return string CSS class.
     */
    protected static function error_state(float $rate): string {
        if ($rate >= 0.15) {
            return 'oaa-state--critical';
        }
        if ($rate >= 0.05) {
            return 'oaa-state--warning';
        }
        return '';
    }

    /**
     * Human label for a route key.
     *
     * @param string $route Route key.
     * @return string Localised label.
     */
    protected static function route_label(string $route): string {
        $map = [
            'tutor' => get_string('analytics_route_tutor', self::C),
            'assistant' => get_string('analytics_route_assistant', self::C),
            'ambiguous' => get_string('analytics_route_ambiguous', self::C),
            'ambiguity' => get_string('analytics_route_ambiguous', self::C),
            'unknown' => get_string('analytics_route_unknown', self::C),
        ];
        return $map[$route] ?? ucfirst($route);
    }

    /**
     * Round a max value up to a readable axis bound.
     *
     * @param int $v Raw max.
     * @return int Nice ceiling.
     */
    protected static function nice_ceil(int $v): int {
        if ($v <= 5) {
            return 5;
        }
        $mag = (int)pow(10, floor(log10($v)));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            $step = $mag * $m;
            if ($v <= $step) {
                return (int)ceil($step);
            }
        }
        return (int)($mag * 10);
    }

    // Formatting helpers.

    /**
     * Format an integer with the locale thousands separator.
     *
     * @param int|float $n Value.
     * @return string
     */
    protected static function int($n): string {
        return number_format((float)$n, 0, '.', "\u{202f}");
    }

    /**
     * Format a decimal to one place.
     *
     * @param float $n Value.
     * @return string
     */
    protected static function dec(float $n): string {
        return number_format($n, 1, ',', "\u{202f}");
    }

    /**
     * Format a 0..1 ratio as a percentage.
     *
     * @param float $n Ratio.
     * @return string
     */
    protected static function pct(float $n): string {
        return number_format($n * 100, 1, ',', "\u{202f}") . '%';
    }

    /**
     * Format a monetary value in USD.
     *
     * @param float $n Amount.
     * @param int $decimals Decimal places.
     * @return string
     */
    protected static function money(float $n, int $decimals = 2): string {
        return '$' . number_format($n, $decimals, '.', "\u{202f}");
    }

    // ------------------------------------------------------------------
    // Scoped styles (light + dark surfaces).

    /**
     * Return the scoped style block.
     *
     * @return string HTML <style>.
     */
    protected static function styles(): string {
        return <<<CSS
<style>
.oaa-dash {
  --surface: #ffffff; --plane: #f6f6f4; --ink: #16130f; --ink-2: #52514e;
  --muted: #8a8781; --grid: #ecece6; --border: rgba(11,11,11,0.10);
  --series-1: #2a78d6; --series-2: #1baf7a; --series-3: #eda100;
  --ok: #0ca30c; --warn: #fab219; --crit: #d03b3b;
  --radius: 14px;
  color: var(--ink);
  font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  max-width: 1240px;
}
@media (prefers-color-scheme: dark) {
  .oaa-dash {
    --surface: #1c1c1a; --plane: #131312; --ink: #f4f3ee; --ink-2: #c3c2b7;
    --muted: #9a988f; --grid: #2c2c2a; --border: rgba(255,255,255,0.12);
    --series-1: #3987e5; --series-2: #199e70; --series-3: #c98500;
  }
}
.oaa-dash a { color: var(--series-1); }
.oaa-filters { display:flex; flex-wrap:wrap; gap:16px; align-items:center; justify-content:space-between; margin:4px 0 6px; }
.oaa-chips { display:flex; gap:6px; flex-wrap:wrap; }
.oaa-chip { display:inline-block; padding:6px 13px; border-radius:999px; border:1px solid var(--border);
  color:var(--ink-2); text-decoration:none; font-size:13px; font-weight:500; background:var(--surface); }
.oaa-chip:hover { border-color:var(--series-1); color:var(--series-1); }
.oaa-chip--active { background:var(--series-1); border-color:var(--series-1); color:#fff; }
.oaa-custom { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.oaa-input { padding:6px 10px; border:1px solid var(--border); border-radius:8px; background:var(--surface);
  color:var(--ink); font-size:13px; font-family:inherit; }
.oaa-input--search { min-width:180px; }
.oaa-arrow { color:var(--muted); }
.oaa-btn { padding:7px 14px; border:none; border-radius:8px; background:var(--ink); color:var(--surface);
  font-size:13px; font-weight:600; cursor:pointer; font-family:inherit; }
.oaa-btn:hover { opacity:.88; }
.oaa-rangelabel { color:var(--muted); font-size:12.5px; margin-bottom:14px; }
.oaa-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-bottom:18px; }
.oaa-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:18px 18px 16px; }
.oaa-card__value { font-size:31px; font-weight:650; letter-spacing:-0.02em; font-variant-numeric:tabular-nums; line-height:1.05; }
.oaa-card__label { font-size:11px; text-transform:uppercase; letter-spacing:.07em;
    color:var(--muted); margin-top:8px; font-weight:600; }
.oaa-card__sub { font-size:12.5px; color:var(--ink-2); margin-top:5px; }
.oaa-state--warning { color:var(--warn); }
.oaa-state--critical { color:var(--crit); }
.oaa-panel { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius);
    padding:20px 20px 18px; margin-bottom:18px; }
.oaa-panel__head { margin-bottom:14px; }
.oaa-panel__title { font-size:15px; font-weight:650; margin:0; color:var(--ink); }
.oaa-panel__desc { font-size:12.5px; color:var(--muted); margin-top:4px; line-height:1.45; }
.oaa-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
.oaa-grid-2 .oaa-panel { margin-bottom:0; }
@media (max-width:820px){ .oaa-grid-2 { grid-template-columns:1fr; gap:0; } .oaa-grid-2 .oaa-panel { margin-bottom:18px; } }
.oaa-multiples { display:flex; flex-direction:column; gap:14px; }
.oaa-chart { }
.oaa-svg { width:100%; height:auto; display:block; overflow:visible; }
.oaa-grid { stroke:var(--grid); stroke-width:1; }
.oaa-axis { fill:var(--muted); font-size:10px; font-family:inherit; }
.oaa-line { fill:none; stroke-width:2; stroke-linejoin:round; stroke-linecap:round; vector-effect:non-scaling-stroke; }
.oaa-area { opacity:.10; }
.oaa-pointlabel { fill:var(--ink); font-size:11px; font-weight:600; font-family:inherit; }
.oaa-legend { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:var(--ink-2); margin-right:14px; }
.oaa-legends { display:flex; flex-wrap:wrap; gap:6px 4px; margin-top:12px; }
.oaa-swatch { width:11px; height:11px; border-radius:3px; display:inline-block; }
.oaa-swatch--ok { background:var(--series-2); }
.oaa-swatch--fail { background:var(--crit); }
.oaa-stack { border-radius:8px; overflow:hidden; }
.oaa-stack__svg { display:block; border-radius:8px; }
.oaa-stack__svg rect { stroke:var(--surface); stroke-width:2; }
.oaa-hbars { display:flex; flex-direction:column; gap:12px; }
.oaa-hbar { display:grid; grid-template-columns:64px 1fr auto; align-items:center; gap:12px; }
.oaa-hbar__label { font-size:12.5px; color:var(--ink-2); }
.oaa-hbar__track { background:var(--plane); border-radius:6px; height:16px; overflow:hidden; }
.oaa-hbar__track span { display:block; height:100%; border-radius:6px; }
.oaa-hbar__value { font-size:12.5px; color:var(--ink-2); font-variant-numeric:tabular-nums; white-space:nowrap; }
.oaa-minicards { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:12px; margin-bottom:16px; }
.oaa-minicard { background:var(--plane); border-radius:10px; padding:13px 14px; }
.oaa-minicard__value { font-size:20px; font-weight:650; font-variant-numeric:tabular-nums; letter-spacing:-0.01em; }
.oaa-minicard__label { font-size:11px; text-transform:uppercase; letter-spacing:.05em;
    color:var(--muted); margin-top:4px; font-weight:600; }
.oaa-tablewrap { overflow-x:auto; }
.oaa-table { width:100%; border-collapse:collapse; font-size:13px; }
.oaa-table th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:var(--muted);
  font-weight:600; padding:8px 10px; border-bottom:1px solid var(--border); white-space:nowrap; }
.oaa-table td { padding:9px 10px; border-bottom:1px solid var(--grid); vertical-align:middle; }
.oaa-table tbody tr:hover { background:var(--plane); }
.oaa-num { text-align:right; font-variant-numeric:tabular-nums; }
.oaa-muted { color:var(--muted); }
.oaa-course a { text-decoration:none; font-weight:500; }
.oaa-course a:hover { text-decoration:underline; }
.oaa-course__short { display:block; font-size:11px; color:var(--muted); }
.oaa-inlinebar { display:flex; align-items:center; gap:8px; min-width:120px; }
.oaa-inlinebar span { display:block; height:8px; background:var(--series-1); border-radius:4px; min-width:2px; }
.oaa-inlinebar em { font-style:normal; font-size:12px; color:var(--ink-2); font-variant-numeric:tabular-nums; }
.oaa-toolrow { display:grid; grid-template-columns:200px 1fr 110px; align-items:center; gap:12px; padding:6px 0; }
.oaa-toolrow__name { font-size:13px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.oaa-toolbar { display:flex; height:14px; background:var(--plane); border-radius:5px; overflow:hidden; }
.oaa-toolbar__ok { background:var(--series-2); }
.oaa-toolbar__fail { background:var(--crit); }
.oaa-toolrow__meta { font-size:12.5px; color:var(--ink-2); text-align:right; font-variant-numeric:tabular-nums; }
@media (max-width:640px){ .oaa-toolrow { grid-template-columns:120px 1fr; } .oaa-toolrow__meta { grid-column:2; text-align:left; } }
.oaa-empty { color:var(--muted); font-size:13px; padding:22px; text-align:center; background:var(--plane); border-radius:10px; }
.oaa-footnote { color:var(--muted); font-size:11.5px; line-height:1.5; margin-top:6px; }
</style>
CSS;
    }
}
