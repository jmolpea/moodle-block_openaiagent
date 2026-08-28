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
 * Analytics rollup builder and read model for the usage dashboard.
 *
 * @package    block_openaiagent
 * @copyright  2026 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Pre-aggregates raw messages / tool-call logs into compact daily rollup tables
 * and answers the queries the dashboard needs from those rollups.
 *
 * The rollups keep dashboard reads cheap and predictable regardless of how large
 * the raw message history grows: the nightly task rebuilds only recent day
 * buckets (plus a one-off full backfill on first run), and every read is a small
 * GROUP BY over the summary tables rather than a scan of the messages table.
 */
class analytics {
    /** @var string Message-facts rollup table. */
    const MSGSTATS = 'block_openaiagent_msgstats';
    /** @var string Per-user-per-day rollup table. */
    const USERSTATS = 'block_openaiagent_userstats';
    /** @var string Tool-call rollup table. */
    const TOOLSTATS = 'block_openaiagent_toolstats';
    /** @var string Fully-qualified MCP tool-call event name in the standard log store. */
    const TOOLEVENT = '\\block_openaiagent\\event\\mcp_tool_called';

    /**
     * Return the local-midnight timestamp for the day containing $ts.
     *
     * Day bucketing is done in PHP (not in SQL) so it stays database-portable and
     * consistent with the site timezone regardless of the DB engine.
     *
     * @param int $ts Unix timestamp.
     * @return int Midnight timestamp of that day in the server timezone.
     */
    public static function day_start(int $ts): int {
        $tz = \core_date::get_server_timezone_object();
        $dt = new \DateTime('@' . $ts);
        $dt->setTimezone($tz);
        $dt->setTime(0, 0, 0);
        return $dt->getTimestamp();
    }

    // ------------------------------------------------------------------
    // Rollup building (invoked by the scheduled task).

    /**
     * Rebuild the rollup tables incrementally.
     *
     * On the first run (watermark unset) the whole history is backfilled. On later
     * runs only the day buckets from the stored watermark onward are rebuilt, which
     * covers any late-arriving rows for recent days at negligible cost.
     *
     * @return void
     */
    public static function build(): void {
        global $DB;

        $watermark = (int)get_config('block_openaiagent', 'analytics_watermark');
        if ($watermark <= 0) {
            // First run: start from the earliest message (or today if none).
            $earliest = (int)$DB->get_field_sql(
                'SELECT MIN(timecreated) FROM {block_openaiagent_messages}'
            );
            $fromday = self::day_start($earliest > 0 ? $earliest : time());
        } else {
            // Rebuild from the previous day onward to absorb late writes.
            $fromday = self::day_start($watermark - DAYSECS);
        }

        self::rebuild_range($fromday, time());

        // Advance the watermark to the start of today; the next run re-does today
        // plus yesterday, keeping recent buckets eventually consistent.
        set_config('analytics_watermark', self::day_start(time()), 'block_openaiagent');
    }

    /**
     * Rebuild every day bucket that overlaps [$fromday, $tots].
     *
     * @param int $fromday Midnight timestamp of the first day to rebuild.
     * @param int $tots Upper bound timestamp (inclusive day is rebuilt).
     * @return void
     */
    public static function rebuild_range(int $fromday, int $tots): void {
        global $DB;

        $fromday = self::day_start($fromday);
        $endexclusive = self::day_start($tots) + DAYSECS;
        if ($endexclusive <= $fromday) {
            return;
        }

        // Clear the affected buckets so the rebuild is idempotent.
        $lastday = $endexclusive - DAYSECS;
        $select = 'daterecorded >= :from AND daterecorded <= :to';
        $params = ['from' => $fromday, 'to' => $lastday];
        $DB->delete_records_select(self::MSGSTATS, $select, $params);
        $DB->delete_records_select(self::USERSTATS, $select, $params);
        $DB->delete_records_select(self::TOOLSTATS, $select, $params);

        self::rebuild_messages($fromday, $endexclusive);
        self::rebuild_tools($fromday, $endexclusive);
    }

    /**
     * Aggregate raw messages in [$fromts, $endexclusive) into the message/user rollups.
     *
     * @param int $fromts Inclusive lower bound (midnight).
     * @param int $endexclusive Exclusive upper bound (midnight).
     * @return void
     */
    protected static function rebuild_messages(int $fromts, int $endexclusive): void {
        global $DB;

        // The model is read from the message itself. Rows written before the
        // model column existed have none, so they fall back to their agent's
        // defaultmodel -- the only model information those rows ever carried --
        // rather than being lumped into an "unknown" bucket.
        $sql = "SELECT m.id, m.role, m.route, m.agentid, m.model, a.defaultmodel,
                       m.prompttokens, m.cachedtokens, m.completiontokens,
                       m.totaltokens, m.errormessage, m.timecreated,
                       c.courseid, c.blockinstanceid, c.userid
                  FROM {block_openaiagent_messages} m
                  JOIN {block_openaiagent_conversations} c ON c.id = m.conversationid
             LEFT JOIN {block_openaiagent_agents} a ON a.id = m.agentid
                 WHERE m.timecreated >= :from AND m.timecreated < :to";
        $rs = $DB->get_recordset_sql($sql, ['from' => $fromts, 'to' => $endexclusive]);

        $msg = [];   // Keyed aggregate for MSGSTATS.
        $user = [];  // Keyed aggregate for USERSTATS.
        foreach ($rs as $r) {
            $day = self::day_start((int)$r->timecreated);
            $role = (string)$r->role;
            $route = trim((string)$r->route);
            $agentid = (int)$r->agentid;
            $model = trim((string)$r->model);
            if ($model === '') {
                $model = trim((string)($r->defaultmodel ?? ''));
            }

            $mk = $r->courseid . '|' . $r->blockinstanceid . '|' . $day . '|' . $role . '|' . $route
                . '|' . $agentid . '|' . $model;
            if (!isset($msg[$mk])) {
                $msg[$mk] = (object)[
                    'courseid' => (int)$r->courseid,
                    'blockinstanceid' => (int)$r->blockinstanceid,
                    'daterecorded' => $day,
                    'role' => $role,
                    'route' => $route,
                    'agentid' => $agentid,
                    'model' => \core_text::substr($model, 0, 100),
                    'nummessages' => 0,
                    'numerrors' => 0,
                    'prompttokens' => 0,
                    'cachedtokens' => 0,
                    'completiontokens' => 0,
                    'totaltokens' => 0,
                ];
            }
            $msg[$mk]->nummessages++;
            if (trim((string)$r->errormessage) !== '') {
                $msg[$mk]->numerrors++;
            }
            $msg[$mk]->prompttokens += (int)$r->prompttokens;
            $msg[$mk]->cachedtokens += (int)$r->cachedtokens;
            $msg[$mk]->completiontokens += (int)$r->completiontokens;
            $msg[$mk]->totaltokens += (int)$r->totaltokens;

            if ($role === 'user') {
                $uk = $r->courseid . '|' . $r->blockinstanceid . '|' . $day . '|' . $r->userid;
                if (!isset($user[$uk])) {
                    $user[$uk] = (object)[
                        'courseid' => (int)$r->courseid,
                        'blockinstanceid' => (int)$r->blockinstanceid,
                        'daterecorded' => $day,
                        'userid' => (int)$r->userid,
                        'numquestions' => 0,
                    ];
                }
                $user[$uk]->numquestions++;
            }
        }
        $rs->close();

        if ($msg) {
            $DB->insert_records(self::MSGSTATS, array_values($msg));
        }
        if ($user) {
            $DB->insert_records(self::USERSTATS, array_values($user));
        }
    }

    /**
     * Aggregate MCP tool-call events from the standard log store into the tool rollup.
     *
     * The standard log store is optional; when it is disabled or empty this simply
     * produces no rows. The event 'other' payload is parsed in PHP so no
     * database-specific JSON functions are required.
     *
     * @param int $fromts Inclusive lower bound (midnight).
     * @param int $endexclusive Exclusive upper bound (midnight).
     * @return void
     */
    protected static function rebuild_tools(int $fromts, int $endexclusive): void {
        global $DB;

        if (!$DB->get_manager()->table_exists('logstore_standard_log')) {
            return;
        }

        $sql = "SELECT id, courseid, other, timecreated
                  FROM {logstore_standard_log}
                 WHERE eventname = :ev AND timecreated >= :from AND timecreated < :to";
        $rs = $DB->get_recordset_sql($sql, [
            'ev' => self::TOOLEVENT,
            'from' => $fromts,
            'to' => $endexclusive,
        ]);

        $agg = [];
        foreach ($rs as $r) {
            $other = self::decode_other((string)$r->other);
            $tool = isset($other['tool']) ? (string)$other['tool'] : 'unknown';
            $ok = !empty($other['ok']);
            $day = self::day_start((int)$r->timecreated);

            $key = $r->courseid . '|' . $day . '|' . $tool;
            if (!isset($agg[$key])) {
                $agg[$key] = (object)[
                    'courseid' => (int)$r->courseid,
                    'daterecorded' => $day,
                    'toolname' => \core_text::substr($tool, 0, 100),
                    'numcalls' => 0,
                    'numok' => 0,
                    'numfail' => 0,
                ];
            }
            $agg[$key]->numcalls++;
            if ($ok) {
                $agg[$key]->numok++;
            } else {
                $agg[$key]->numfail++;
            }
        }
        $rs->close();

        if ($agg) {
            $DB->insert_records(self::TOOLSTATS, array_values($agg));
        }
    }

    /**
     * Decode a log-store 'other' payload, tolerating both JSON and PHP serialize.
     *
     * @param string $raw Raw column value.
     * @return array Decoded associative array (empty on failure).
     */
    protected static function decode_other(string $raw): array {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Legacy rows stored a serialized array. Only attempt it when the value
        // actually looks like one: unserialize() raises a notice on garbage, and
        // the '@' that used to hide that also hid real problems. Classes are
        // never instantiated from log data.
        if (preg_match('/^a:\d+:\{/', $raw) !== 1) {
            return [];
        }

        $decoded = unserialize($raw, ['allowed_classes' => false]);

        return is_array($decoded) ? $decoded : [];
    }

    // ------------------------------------------------------------------
    // Read model (consumed by the dashboard).

    /**
     * Course ids that currently have the assistant enabled.
     *
     * @return int[] Course ids.
     */
    public static function configured_courseids(): array {
        global $DB;
        return $DB->get_fieldset_sql(
            'SELECT DISTINCT courseid FROM {block_openaiagent_courseconfig} WHERE enabled = 1'
        );
    }

    /**
     * Restrict a rollup query to a set of courses.
     *
     * Returns an empty fragment when nothing is selected, so an unfiltered
     * dashboard keeps running exactly the query it ran before.
     *
     * @param int[] $courseids Course ids (empty = no restriction).
     * @param string $prefix Parameter-name prefix, to keep two clauses apart.
     * @param string $column Qualified column holding the course id.
     * @return array{0: string, 1: array} SQL fragment (with a leading AND) and params.
     */
    private static function course_clause(
        array $courseids,
        string $prefix = 'cf',
        string $column = 'courseid'
    ): array {
        global $DB;

        if (!$courseids) {
            return ['', []];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, $prefix);

        return [" AND {$column} {$insql}", $params];
    }

    /**
     * Course ids whose name or short name contains the given text.
     *
     * The dashboard filter is a free-text box rather than a course picker, so
     * it has to be resolved to ids before it can restrict anything.
     *
     * @param string $namefilter Case-insensitive substring (empty = no filter).
     * @return int[] Matching course ids; empty array when no filter was given.
     */
    public static function courseids_matching(string $namefilter): array {
        global $DB;

        $namefilter = trim($namefilter);
        if ($namefilter === '') {
            return [];
        }

        $like = '%' . $DB->sql_like_escape($namefilter) . '%';
        $full = $DB->sql_like('fullname', ':n1', false, false);
        $short = $DB->sql_like('shortname', ':n2', false, false);

        $ids = $DB->get_fieldset_sql(
            "SELECT id FROM {course} WHERE {$full} OR {$short}",
            ['n1' => $like, 'n2' => $like]
        );

        // A filter that matches nothing must not silently widen to everything,
        // so it resolves to an id that cannot exist rather than an empty list.
        return $ids ?: [-1];
    }

    /**
     * Institutional headline figures for the range.
     *
     * @param int $from Midnight timestamp of the first day (inclusive).
     * @param int $to Midnight timestamp of the last day (inclusive).
     * @param int[] $courseids Restrict to these courses (empty = whole site).
     * @return \stdClass Overview counters.
     */
    public static function get_overview(int $from, int $to, array $courseids = []): \stdClass {
        global $DB;

        [$cwhere, $cparams] = self::course_clause($courseids);
        $range = 'daterecorded >= :from AND daterecorded <= :to' . $cwhere;
        $params = ['from' => $from, 'to' => $to] + $cparams;

        // Message-derived counters.
        $msgrow = $DB->get_record_sql(
            "SELECT
                COALESCE(SUM(CASE WHEN role = 'user' THEN nummessages ELSE 0 END), 0) AS questions,
                COALESCE(SUM(CASE WHEN role = 'assistant' THEN nummessages ELSE 0 END), 0) AS answers,
                COALESCE(SUM(CASE WHEN role = 'assistant' THEN numerrors ELSE 0 END), 0) AS errors,
                COALESCE(SUM(prompttokens), 0) AS prompttokens,
                COALESCE(SUM(cachedtokens), 0) AS cachedtokens,
                COALESCE(SUM(completiontokens), 0) AS completiontokens,
                COALESCE(SUM(totaltokens), 0) AS totaltokens
               FROM {" . self::MSGSTATS . "} WHERE $range",
            $params
        );

        $o = new \stdClass();
        $o->questions = (int)$msgrow->questions;
        $o->answers = (int)$msgrow->answers;
        $o->errors = (int)$msgrow->errors;
        $o->prompttokens = (int)$msgrow->prompttokens;
        $o->cachedtokens = min((int)$msgrow->cachedtokens, (int)$msgrow->prompttokens);
        $o->completiontokens = (int)$msgrow->completiontokens;
        $o->totaltokens = (int)$msgrow->totaltokens;
        $o->cachehitrate = $o->prompttokens > 0 ? $o->cachedtokens / $o->prompttokens : 0.0;

        // Distinct users and courses with real use.
        $o->distinctusers = (int)$DB->get_field_sql(
            "SELECT COUNT(DISTINCT userid) FROM {" . self::USERSTATS . "} WHERE $range",
            $params
        );
        $o->courseswithuse = (int)$DB->get_field_sql(
            "SELECT COUNT(DISTINCT courseid) FROM {" . self::USERSTATS . "} WHERE $range",
            $params
        );

        // Recurrent users: active on 2+ distinct days in the range.
        $o->recurrentusers = (int)$DB->get_field_sql(
            "SELECT COUNT(*) FROM (
                SELECT userid FROM {" . self::USERSTATS . "}
                 WHERE $range
                 GROUP BY userid
                HAVING COUNT(DISTINCT daterecorded) >= 2
             ) sub",
            $params
        );

        // Conversations started within the range (live: the conversations table is
        // small relative to messages).
        [$convwhere, $convparams] = self::course_clause($courseids, 'cv');
        $o->conversations = (int)$DB->get_field_sql(
            "SELECT COUNT(*) FROM {block_openaiagent_conversations}
              WHERE timecreated >= :from AND timecreated < :to" . $convwhere,
            ['from' => $from, 'to' => $to + DAYSECS] + $convparams
        );

        // Reach: configured courses and exposed (active-enrolled) participants.
        // Narrowed to the selection too, so the adoption rate compares the
        // filtered courses against their own enrolments and not the site's.
        $configured = self::configured_courseids();
        if ($courseids) {
            $configured = array_values(array_intersect($configured, $courseids));
        }
        $o->coursesconfigured = count($configured);
        $o->exposedparticipants = self::exposed_participants($configured);

        $o->adoptionrate = $o->exposedparticipants > 0
            ? $o->distinctusers / $o->exposedparticipants : 0.0;
        $o->recurrencerate = $o->distinctusers > 0
            ? $o->recurrentusers / $o->distinctusers : 0.0;
        $o->errorrate = $o->answers > 0 ? $o->errors / $o->answers : 0.0;
        $o->questionsperuser = $o->distinctusers > 0
            ? $o->questions / $o->distinctusers : 0.0;
        $o->questionsperenrolled = $o->exposedparticipants > 0
            ? $o->questions / $o->exposedparticipants : 0.0;
        $o->turnsperconversation = $o->conversations > 0
            ? $o->questions / $o->conversations : 0.0;

        return $o;
    }

    /**
     * Distinct active-enrolled users across the given courses.
     *
     * @param int[] $courseids Course ids (empty returns 0).
     * @return int Count of distinct exposed participants.
     */
    public static function exposed_participants(array $courseids): int {
        global $DB;
        if (!$courseids) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
        $now = time();
        $params['now1'] = $now;
        $params['now2'] = $now;
        return (int)$DB->get_field_sql(
            "SELECT COUNT(DISTINCT ue.userid)
               FROM {enrol} e
               JOIN {user_enrolments} ue ON ue.enrolid = e.id
               JOIN {user} u ON u.id = ue.userid
              WHERE e.courseid $insql
                AND e.status = 0 AND ue.status = 0
                AND u.deleted = 0 AND u.suspended = 0
                AND (ue.timestart = 0 OR ue.timestart <= :now1)
                AND (ue.timeend = 0 OR ue.timeend >= :now2)",
            $params
        );
    }

    /**
     * Distinct active-enrolled users, counted per course in a single query.
     *
     * The dashboard needs this figure for a whole page of courses at once.
     * Calling exposed_participants() per course turns that into an N+1 pattern,
     * so the counts are grouped server-side and returned keyed by course id.
     *
     * @param int[] $courseids Course ids (empty returns an empty array).
     * @return array Course id => count of distinct exposed participants.
     */
    public static function exposed_participants_by_course(array $courseids): array {
        global $DB;
        if (!$courseids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
        $now = time();
        $params['now1'] = $now;
        $params['now2'] = $now;
        $rows = $DB->get_records_sql(
            "SELECT e.courseid, COUNT(DISTINCT ue.userid) AS n
               FROM {enrol} e
               JOIN {user_enrolments} ue ON ue.enrolid = e.id
               JOIN {user} u ON u.id = ue.userid
              WHERE e.courseid $insql
                AND e.status = 0 AND ue.status = 0
                AND u.deleted = 0 AND u.suspended = 0
                AND (ue.timestart = 0 OR ue.timestart <= :now1)
                AND (ue.timeend = 0 OR ue.timeend >= :now2)
           GROUP BY e.courseid",
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row->courseid] = (int)$row->n;
        }
        return $out;
    }

    /**
     * Assistant-answer counts grouped by route (Tutor / Assistant / Ambiguous / …).
     *
     * @param int $from Midnight timestamp (inclusive).
     * @param int $to Midnight timestamp (inclusive).
     * @param int[] $courseids Restrict to these courses (empty = whole site).
     * @return array Route => answer count.
     */
    public static function get_route_breakdown(int $from, int $to, array $courseids = []): array {
        global $DB;
        [$cwhere, $cparams] = self::course_clause($courseids);
        $rows = $DB->get_records_sql(
            "SELECT route, SUM(nummessages) AS n
               FROM {" . self::MSGSTATS . "}
              WHERE role = 'assistant' AND daterecorded >= :from AND daterecorded <= :to" . $cwhere . "
              GROUP BY route",
            ['from' => $from, 'to' => $to] + $cparams
        );
        $out = [];
        foreach ($rows as $r) {
            $route = trim((string)$r->route);
            if ($route === '') {
                $route = 'unknown';
            }
            $out[$route] = ($out[$route] ?? 0) + (int)$r->n;
        }
        arsort($out);
        return $out;
    }

    /**
     * Daily time series of questions and distinct users, with empty days filled.
     *
     * @param int $from Midnight timestamp (inclusive).
     * @param int $to Midnight timestamp (inclusive).
     * @param int[] $courseids Restrict to these courses (empty = whole site).
     * @return array Ordered list of {day, questions, users}.
     */
    public static function get_timeseries(int $from, int $to, array $courseids = []): array {
        global $DB;
        [$cwhere, $cparams] = self::course_clause($courseids);
        $range = 'daterecorded >= :from AND daterecorded <= :to' . $cwhere;
        $params = ['from' => $from, 'to' => $to] + $cparams;

        $q = $DB->get_records_sql(
            "SELECT daterecorded AS d, SUM(numquestions) AS q, COUNT(DISTINCT userid) AS u
               FROM {" . self::USERSTATS . "} WHERE $range GROUP BY daterecorded",
            $params
        );
        $byday = [];
        foreach ($q as $r) {
            $byday[(int)$r->d] = $r;
        }

        // Iterate calendar days with DateTime so DST transitions never drift the
        // day boundary (a plain +86400 would misalign the keys twice a year).
        $tz = \core_date::get_server_timezone_object();
        $cursor = new \DateTime('@' . self::day_start($from));
        $cursor->setTimezone($tz);
        $end = self::day_start($to);

        $series = [];
        while ($cursor->getTimestamp() <= $end) {
            $day = $cursor->getTimestamp();
            $series[] = (object)[
                'day' => $day,
                'questions' => isset($byday[$day]) ? (int)$byday[$day]->q : 0,
                'users' => isset($byday[$day]) ? (int)$byday[$day]->u : 0,
            ];
            $cursor->modify('+1 day');
        }
        return $series;
    }

    /**
     * Recurrence distribution: users bucketed by number of active days.
     *
     * @param int $from Midnight timestamp (inclusive).
     * @param int $to Midnight timestamp (inclusive).
     * @param int[] $courseids Restrict to these courses (empty = whole site).
     * @return array{1:int,'2-3':int,'4+':int} Bucket => user count.
     */
    public static function get_recurrence(int $from, int $to, array $courseids = []): array {
        global $DB;
        [$cwhere, $cparams] = self::course_clause($courseids);
        $rows = $DB->get_records_sql(
            "SELECT days, COUNT(*) AS n FROM (
                SELECT userid, COUNT(DISTINCT daterecorded) AS days
                  FROM {" . self::USERSTATS . "}
                 WHERE daterecorded >= :from AND daterecorded <= :to" . $cwhere . "
                 GROUP BY userid
             ) sub GROUP BY days",
            ['from' => $from, 'to' => $to] + $cparams
        );
        $buckets = ['1' => 0, '2-3' => 0, '4+' => 0];
        foreach ($rows as $r) {
            $days = (int)$r->days;
            $n = (int)$r->n;
            if ($days <= 1) {
                $buckets['1'] += $n;
            } else if ($days <= 3) {
                $buckets['2-3'] += $n;
            } else {
                $buckets['4+'] += $n;
            }
        }
        return $buckets;
    }

    /**
     * Token consumption and estimated cost grouped by the model actually called.
     *
     * Cached input is billed separately: providers serve a repeated prompt
     * prefix from their cache at a fraction of the input price (a tenth on the
     * gpt-5 family), so charging every input token at the full rate
     * systematically overstates the bill of a plugin whose system prompt is
     * identical on every turn.
     *
     * @param int $from Midnight timestamp (inclusive).
     * @param int $to Midnight timestamp (inclusive).
     * @param int[] $courseids Restrict to these courses (empty = whole site).
     * @return array Ordered list of {model, input, cached, output, total, cost}.
     */
    public static function get_cost_by_model(int $from, int $to, array $courseids = []): array {
        global $DB;
        [$cwhere, $cparams] = self::course_clause($courseids, 'cf', 's.courseid');
        $rows = $DB->get_records_sql(
            "SELECT s.model AS model,
                    SUM(s.prompttokens) AS input,
                    SUM(s.cachedtokens) AS cached,
                    SUM(s.completiontokens) AS output,
                    SUM(s.totaltokens) AS total
               FROM {" . self::MSGSTATS . "} s
              WHERE s.daterecorded >= :from AND s.daterecorded <= :to
                AND s.totaltokens > 0" . $cwhere . "
              GROUP BY s.model",
            ['from' => $from, 'to' => $to] + $cparams
        );

        $prices = self::get_price_map();
        $out = [];
        foreach ($rows as $r) {
            $model = trim((string)$r->model) !== '' ? (string)$r->model : 'unknown';
            $input = (int)$r->input;
            $cached = min((int)$r->cached, $input);
            $output = (int)$r->output;
            $price = $prices[strtolower($model)] ?? null;
            $cost = $price
                ? (($input - $cached) / 1000000 * $price[0])
                    + ($cached / 1000000 * $price[2])
                    + ($output / 1000000 * $price[1])
                : 0.0;
            $out[] = (object)[
                'model' => $model,
                'input' => $input,
                'cached' => $cached,
                'output' => $output,
                'total' => (int)$r->total,
                'cost' => $cost,
                'haspricing' => $price !== null,
            ];
        }
        usort($out, fn($a, $b) => $b->total <=> $a->total);
        return $out;
    }

    /**
     * MCP tool-call totals grouped by tool.
     *
     * @param int $from Midnight timestamp (inclusive).
     * @param int $to Midnight timestamp (inclusive).
     * @param int[] $courseids Restrict to these courses (empty = whole site).
     * @return array Ordered list of {toolname, calls, ok, fail}.
     */
    public static function get_tool_breakdown(int $from, int $to, array $courseids = []): array {
        global $DB;
        [$cwhere, $cparams] = self::course_clause($courseids);
        $rows = $DB->get_records_sql(
            "SELECT toolname, SUM(numcalls) AS calls, SUM(numok) AS ok, SUM(numfail) AS fail
               FROM {" . self::TOOLSTATS . "}
              WHERE daterecorded >= :from AND daterecorded <= :to" . $cwhere . "
              GROUP BY toolname
              ORDER BY calls DESC",
            ['from' => $from, 'to' => $to] + $cparams
        );
        return array_values($rows);
    }

    /**
     * Per-course rows for the comparative table.
     *
     * @param int $from Midnight timestamp (inclusive).
     * @param int $to Midnight timestamp (inclusive).
     * @param string $namefilter Optional case-insensitive course-name filter.
     * @param int $limit Maximum rows (0 = all).
     * @param int[] $courseids Restrict to these courses (empty = whole site).
     * @return array Course rows with usage and adoption figures.
     */
    public static function get_course_rows(
        int $from,
        int $to,
        string $namefilter = '',
        int $limit = 0,
        array $courseids = []
    ): array {
        global $DB;
        [$cwhere, $cparams] = self::course_clause($courseids);
        $range = 'daterecorded >= :from AND daterecorded <= :to' . $cwhere;
        $params = ['from' => $from, 'to' => $to] + $cparams;

        // Questions, distinct users and errors per course (from the rollups).
        $usage = $DB->get_records_sql(
            "SELECT courseid, SUM(numquestions) AS questions, COUNT(DISTINCT userid) AS users
               FROM {" . self::USERSTATS . "} WHERE $range GROUP BY courseid",
            $params
        );
        if (!$usage) {
            return [];
        }
        $errrows = $DB->get_records_sql(
            "SELECT courseid,
                    SUM(CASE WHEN role = 'assistant' THEN nummessages ELSE 0 END) AS answers,
                    SUM(CASE WHEN role = 'assistant' THEN numerrors ELSE 0 END) AS errors,
                    SUM(totaltokens) AS tokens
               FROM {" . self::MSGSTATS . "} WHERE $range GROUP BY courseid",
            $params
        );

        // Recurrent users per course.
        $recur = $DB->get_records_sql(
            "SELECT courseid, COUNT(*) AS n FROM (
                SELECT courseid, userid, COUNT(DISTINCT daterecorded) AS days
                  FROM {" . self::USERSTATS . "} WHERE $range
                 GROUP BY courseid, userid
             ) sub WHERE days >= 2 GROUP BY courseid",
            $params
        );

        $courseids = array_keys($usage);
        [$insql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
        $courses = $DB->get_records_select('course', "id $insql", $cparams, '', 'id, fullname, shortname, category');

        $rows = [];
        foreach ($usage as $cid => $u) {
            if (!isset($courses[$cid])) {
                continue; // Deleted course.
            }
            $course = $courses[$cid];
            if ($namefilter !== '') {
                $hay = \core_text::strtolower($course->fullname . ' ' . $course->shortname);
                if (\core_text::strpos($hay, \core_text::strtolower($namefilter)) === false) {
                    continue;
                }
            }
            $answers = isset($errrows[$cid]) ? (int)$errrows[$cid]->answers : 0;
            $errors = isset($errrows[$cid]) ? (int)$errrows[$cid]->errors : 0;
            $rows[] = (object)[
                'courseid' => (int)$cid,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'users' => (int)$u->users,
                'questions' => (int)$u->questions,
                'enrolled' => 0,
                'adoption' => 0.0,
                'recurrent' => isset($recur[$cid]) ? (int)$recur[$cid]->n : 0,
                'answers' => $answers,
                'errors' => $errors,
                'errorrate' => $answers > 0 ? $errors / $answers : 0.0,
                'tokens' => isset($errrows[$cid]) ? (int)$errrows[$cid]->tokens : 0,
            ];
        }

        // Order by questions descending — the busiest courses first.
        usort($rows, fn($a, $b) => $b->questions <=> $a->questions);
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        // Enrolment is only needed for the rows that survive the display limit,
        // and it is fetched for all of them in one grouped query rather than
        // one query per course.
        if ($rows) {
            $enrolled = self::exposed_participants_by_course(array_column($rows, 'courseid'));
            foreach ($rows as $row) {
                $row->enrolled = $enrolled[$row->courseid] ?? 0;
                $row->adoption = $row->enrolled > 0 ? $row->users / $row->enrolled : 0.0;
            }
        }

        return $rows;
    }

    /**
     * Parse the admin-configured model price map, merged over sensible defaults.
     *
     * Format (one per line): "model|input|output" or "model|input|output|cached",
     * all in USD per one million tokens. A line without the fourth value bills
     * cached input at the full input price: the admin stated what they wanted
     * charged, so no discount is invented on their behalf.
     *
     * @return array Lowercased model => [input, output, cachedinput].
     */
    public static function get_price_map(): array {
        // Public list prices (USD per 1M tokens) as defaults, including the
        // cached-input rate. Prices change: admins override per model via the
        // plugin setting, and a model with no entry is counted in tokens but
        // excluded from the cost estimate rather than guessed at.
        $defaults = [
            'gpt-5.6-luna' => [0.2, 1.2, 0.02],
            'gpt-5' => [1.25, 10.0, 0.125],
            'gpt-5-mini' => [0.25, 2.0, 0.025],
            'gpt-5-nano' => [0.05, 0.4, 0.005],
            'gpt-4.1' => [2.0, 8.0, 0.5],
            'gpt-4.1-mini' => [0.4, 1.6, 0.1],
            'gpt-4.1-nano' => [0.1, 0.4, 0.025],
            'gpt-4o' => [2.5, 10.0, 1.25],
            'gpt-4o-mini' => [0.15, 0.6, 0.075],
            'o4-mini' => [1.1, 4.4, 0.275],
        ];

        $raw = (string)get_config('block_openaiagent', 'analytics_prices');
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '|') === false) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 3 || $parts[0] === '') {
                continue;
            }
            $input = (float)$parts[1];
            $defaults[strtolower($parts[0])] = [
                $input,
                (float)$parts[2],
                isset($parts[3]) && $parts[3] !== '' ? (float)$parts[3] : $input,
            ];
        }
        return $defaults;
    }

    /**
     * Headline figures for the support escalations in a window.
     *
     * The ratio is the number that matters. Every escalation is a question the
     * assistant could not answer, so the share of conversations that end in one
     * is the most honest quality measure the block has — and the one to watch
     * fall as the course documents improve.
     *
     * Two ratios, because they answer different questions and get confused for
     * each other. `confirmedratio` is how many of the offers the participants
     * actually went through with: an offer left unconfirmed usually means the
     * conversation resolved itself, so it should pull the number down.
     * `ratio` is the share of conversations that ended in a real escalation,
     * which is the quality signal about the assistant.
     *
     * Both are proper subsets of their denominators. `ratio` in particular
     * counts only conversations *started* inside the window, on both sides of
     * the division: counting escalations from older conversations against a
     * denominator of new ones used to let it climb past 100%.
     *
     * @param int $from Window start (timestamp).
     * @param int $to Window end (timestamp).
     * @param int[] $courseids Restrict to these courses (empty = whole site).
     * @return \stdClass Counters and the two ratios.
     */
    public static function get_support_summary(int $from, int $to, array $courseids = []): \stdClass {
        global $DB;

        [$cwhere, $cparams] = self::course_clause($courseids);
        $params = ['from' => $from, 'to' => $to] + $cparams;

        $row = $DB->get_record_sql(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END), 0) AS sent,
                COALESCE(SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END), 0) AS queued,
                COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
                COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled,
                COALESCE(SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END), 0) AS draft,
                COALESCE(SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END), 0) AS expired
               FROM {block_openaiagent_supportreq}
              WHERE timecreated >= :from AND timecreated <= :to" . $cwhere,
            $params
        );

        // Escalated conversations, restricted to conversations that also began
        // inside the window so the ratio below can never exceed 100%. The clause
        // is rebuilt against the aliased column rather than rewritten, so
        // renaming anything here fails loudly instead of silently.
        [$rwhere, $rparams] = self::course_clause($courseids, 'cr', 'r.courseid');
        $escalated = (int)$DB->get_field_sql(
            "SELECT COUNT(DISTINCT r.conversationid)
               FROM {block_openaiagent_supportreq} r
               JOIN {block_openaiagent_conversations} c ON c.id = r.conversationid
              WHERE r.status IN ('queued', 'sent', 'failed')
                AND r.timecreated >= :from AND r.timecreated <= :to
                AND c.timecreated >= :cfrom AND c.timecreated <= :cto" . $rwhere,
            $params + $rparams + ['cfrom' => $from, 'cto' => $to]
        );

        // Every conversation in scope, not only the ones that were ever offered
        // an escalation: the point of the ratio is what share of all the
        // assistant's conversations it could not finish on its own.
        [$convwhere, $convparams] = self::course_clause($courseids, 'cv');
        $conversations = (int)$DB->count_records_select(
            'block_openaiagent_conversations',
            'timecreated >= :from AND timecreated <= :to' . $convwhere,
            ['from' => $from, 'to' => $to] + $convparams
        );

        $summary = new \stdClass();
        $summary->total = (int)($row->total ?? 0);
        $summary->sent = (int)($row->sent ?? 0);
        $summary->queued = (int)($row->queued ?? 0);
        $summary->failed = (int)($row->failed ?? 0);
        $summary->cancelled = (int)($row->cancelled ?? 0);
        $summary->draft = (int)($row->draft ?? 0);
        $summary->expired = (int)($row->expired ?? 0);
        $summary->escalated = $escalated;
        $summary->conversations = $conversations;

        // A request that was confirmed but bounced is still a confirmed one:
        // the participant did their part, the mail server is a separate story.
        $summary->confirmed = $summary->sent + $summary->queued + $summary->failed;
        $summary->pending = $summary->draft;
        $summary->confirmedratio = $summary->total > 0
            ? round(($summary->confirmed / $summary->total) * 100, 1)
            : 0.0;

        $summary->ratio = $conversations > 0
            ? round(($summary->escalated / $conversations) * 100, 1)
            : 0.0;

        return $summary;
    }

    /**
     * The most recent support escalations, for the audit view.
     *
     * Answers the question a course administrator actually asks, which is "was
     * this participant's query really sent?", without anybody having to open the
     * database.
     *
     * @param int $from Window start (timestamp).
     * @param int $to Window end (timestamp).
     * @param int $limit Maximum rows (0 = no limit).
     * @param array $filters Optional courseid, status and participant name.
     * @param int $offset Rows to skip, for paging.
     * @return array[] Rows ready to render.
     */
    public static function get_support_rows(
        int $from,
        int $to,
        int $limit = 100,
        array $filters = [],
        int $offset = 0
    ): array {
        global $DB;

        // With the leading comma, so it appends cleanly to the select list.
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', true)->selects;

        [$where, $params] = self::support_filter_sql($from, $to, $filters);

        $records = $DB->get_records_sql(
            "SELECT r.id, r.ticketref, r.category, r.status, r.timecreated, r.timesent,
                    r.recipients, r.errormsg, r.courseid, c.fullname AS coursename {$namefields}
               FROM {block_openaiagent_supportreq} r
               JOIN {user} u ON u.id = r.userid
          LEFT JOIN {course} c ON c.id = r.courseid
              WHERE {$where}
           ORDER BY r.timecreated DESC",
            $params,
            $offset,
            $limit
        );

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'reference' => (string)$record->ticketref,
                'course' => (string)($record->coursename ?? ''),
                'courseid' => (int)$record->courseid,
                'participant' => fullname($record),
                'category' => (string)$record->category,
                'status' => (string)$record->status,
                'created' => userdate((int)$record->timecreated),
                'sent' => (int)$record->timesent > 0 ? userdate((int)$record->timesent) : '',
                'recipients' => (string)$record->recipients,
                'error' => (string)$record->errormsg,
            ];
        }

        return $rows;
    }

    /**
     * How many escalations match the filters, for the paging bar.
     *
     * @param int $from Window start (timestamp).
     * @param int $to Window end (timestamp).
     * @param array $filters Optional courseid, status and participant name.
     * @return int
     */
    public static function count_support_rows(int $from, int $to, array $filters = []): int {
        global $DB;

        [$where, $params] = self::support_filter_sql($from, $to, $filters);

        return (int)$DB->get_field_sql(
            "SELECT COUNT(1)
               FROM {block_openaiagent_supportreq} r
               JOIN {user} u ON u.id = r.userid
          LEFT JOIN {course} c ON c.id = r.courseid
              WHERE {$where}",
            $params
        );
    }

    /**
     * The WHERE clause shared by the listing and its count, so a filter can
     * never apply to one and not the other.
     *
     * @param int $from Window start (timestamp).
     * @param int $to Window end (timestamp).
     * @param array $filters Optional courseid, status and participant name.
     * @return array{0: string, 1: array} Clause and parameters.
     */
    private static function support_filter_sql(int $from, int $to, array $filters): array {
        global $DB;

        $where = ['r.timecreated >= :from', 'r.timecreated <= :to'];
        $params = ['from' => $from, 'to' => $to];

        if (!empty($filters['courseid'])) {
            $where[] = 'r.courseid = :courseid';
            $params['courseid'] = (int)$filters['courseid'];
        }

        if (!empty($filters['courseids'])) {
            [$insql, $inparams] = $DB->get_in_or_equal($filters['courseids'], SQL_PARAMS_NAMED, 'cf');
            $where[] = 'r.courseid ' . $insql;
            $params += $inparams;
        }

        if (!empty($filters['status'])) {
            $where[] = 'r.status = :status';
            $params['status'] = (string)$filters['status'];
        }

        $name = trim((string)($filters['name'] ?? ''));
        if ($name !== '') {
            // Matches the reference too: an administrator chasing a specific
            // request usually has the reference in front of them, not a name.
            $first = $DB->sql_like('u.firstname', ':n1', false, false);
            $last = $DB->sql_like('u.lastname', ':n2', false, false);
            $ref = $DB->sql_like('r.ticketref', ':n3', false, false);
            $where[] = "({$first} OR {$last} OR {$ref})";
            $params['n1'] = '%' . $DB->sql_like_escape($name) . '%';
            $params['n2'] = '%' . $DB->sql_like_escape($name) . '%';
            $params['n3'] = '%' . $DB->sql_like_escape($name) . '%';
        }

        return [implode(' AND ', $where), $params];
    }
}
