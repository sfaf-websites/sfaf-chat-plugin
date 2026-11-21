<?php
/**
 * Insights report data (1.23.0): what people ask about, where the chat struggled, and
 * what they did next. Aggregate and anonymous by construction.
 *
 * One row per categorized question or tracked action, in {prefix}sfaf_chat_stats:
 *   ts     UTC
 *   kind   'question', or an action from ACTIONS
 *   team   a team from the Contact screen, or 'General' (NULL for an action that came
 *          before any question)
 *   sub    a short, broad label within the team ("PrEP access")
 *   gap    for a question the chat struggled with: a key of GAPS; NULL otherwise
 *   conv   1 on the first question of a conversation in this team and label, and on
 *          the first action of a conversation in it. That pair is the conversion view.
 *   log_id the question's row in the log, so the backfill never counts it twice
 *
 * WHAT IS NEVER HERE: question or answer text, names, emails, phone numbers, IP
 * addresses, session ids, cookies, user agents or pages. A conversation is never
 * identified: "conv" is worked out by the widget in memory and sent as a yes/no.
 *
 * Kept for RETENTION_DAYS, pruned by the daily job; Reset on the Insights screen empties
 * it. The log keeps its own 60-day rule and its own raw-text switch, untouched here.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Stats {

    const RETENTION_DAYS = 400;              // a year of monthly reports, plus a month
    const SINCE_OPTION   = 'sfaf_chat_stats_since';
    const RATE_KEY       = 'sfaf_chat_track_rate';
    const RATE_MAX       = 600;              // tracked actions per ten minutes, site-wide
    const GENERAL        = 'General';
    const OTHER          = 'Other';
    const MAX_SUB        = 40;
    const MAX_SUBS_TEAM  = 20;               // labels per team before new ones become "Other"
    const PROMPT_SUBS    = 15;               // labels per team shown to the model for reuse

    /** Actions the report counts, in report order. */
    const ACTIONS = array(
        'contact'     => 'Contact form sent',
        'appointment' => 'Opened the appointment chooser',
        'healow'      => 'Clicked toward booking (healow)',
        'donate'      => 'Clicked Donate',
        'calendar'    => 'Clicked an event or the calendar',
        'phone'       => 'Tapped a phone number',
    );

    /** Why a question counts as one the chat struggled with. Crisis replies never do. */
    const GAPS = array(
        'no_info'  => 'Not in what the chat knows',
        'partial'  => 'Only partly answered',
        'off_topic' => 'Declined: not about SFAF',
        'personal' => 'Declined: personal health advice',
    );

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'sfaf_chat_stats';
    }

    public static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ts datetime NOT NULL,
  kind varchar(16) NOT NULL,
  team varchar(60) DEFAULT NULL,
  sub varchar(60) DEFAULT NULL,
  gap varchar(16) DEFAULT NULL,
  conv tinyint(1) NOT NULL DEFAULT 0,
  log_id bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY ts (ts),
  KEY log_id (log_id)
) $charset;" );
    }

    /* ============================== TEAMS ============================== */

    /** The configured teams: [{team, handles}]. */
    public static function teams() {
        return SFAF_Chat_Contact::teams_for_model();
    }

    /** A team name as configured (any case), or General. */
    public static function team( $raw ) {
        $want = strtolower( trim( (string) $raw ) );
        if ( '' !== $want ) {
            foreach ( self::teams() as $t ) {
                if ( strtolower( $t['team'] ) === $want ) {
                    return $t['team'];
                }
            }
        }
        return self::GENERAL;
    }

    /** Labels in use per team, most used first: team => [label, ...]. */
    public static function labels( $per_team = 0 ) {
        global $wpdb;
        $table    = self::table();
        $suppress = $wpdb->suppress_errors( true );
        $rows     = $wpdb->get_results( "SELECT team, sub, COUNT(*) AS n FROM $table WHERE kind = 'question' AND team IS NOT NULL AND sub IS NOT NULL GROUP BY team, sub ORDER BY n DESC, sub ASC", ARRAY_A );
        $wpdb->suppress_errors( $suppress );
        $out = array();
        foreach ( (array) $rows as $r ) {
            if ( $per_team > 0 && count( $out[ $r['team'] ] ?? array() ) >= $per_team ) {
                continue;
            }
            $out[ $r['team'] ][] = (string) $r['sub'];
        }
        return $out;
    }

    /** "PrEP access", "prep  ACCESS" and "PrEP accesses" all compare equal. */
    private static function key( $label ) {
        $k = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', ' ', $label ) );
        $words = array();
        foreach ( preg_split( '/\s+/', trim( $k ) ) as $w ) {
            if ( '' === $w || in_array( $w, array( 'and', 'or', 'the', 'a', 'an', 'of', 'for', 'to', 'about' ), true ) ) {
                continue;
            }
            // Plurals only: "accesses" and "access" meet, "costs" and "cost" meet, and a
            // word ending in "ss" ("access", "process") is left whole.
            if ( preg_match( '/(ss|x|ch|sh)es$/', $w ) ) {
                $w = substr( $w, 0, -2 );
            } elseif ( preg_match( '/[^s]s$/', $w ) ) {
                $w = substr( $w, 0, -1 );
            }
            $words[] = $w;
        }
        return implode( ' ', $words );
    }

    /**
     * The model's label, cleaned and folded into an existing one when it means the
     * same thing, so the list stays short. A team at MAX_SUBS_TEAM gets "Other" for
     * anything new.
     */
    public static function label( $team, $raw ) {
        $s = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( SFAF_Chat_Core::no_em_dash( (string) $raw ) ) ) );
        $s = trim( (string) preg_replace( '/[^\p{L}\p{N} &\/,\'\-]+/u', '', $s ), " ,-/" );
        $s = mb_substr( $s, 0, self::MAX_SUB );
        if ( '' === $s || '' === self::key( $s ) ) {
            return self::OTHER;
        }
        $s = mb_strtoupper( mb_substr( $s, 0, 1 ) ) . mb_substr( $s, 1 );
        $existing = self::labels()[ $team ] ?? array();
        foreach ( $existing as $e ) {
            if ( self::key( $e ) === self::key( $s ) ) {
                return $e;
            }
        }
        $distinct = array_diff( $existing, array( self::OTHER ) );
        return count( $distinct ) >= self::MAX_SUBS_TEAM ? self::OTHER : $s;
    }

    /** Why the chat struggled, from its own judgment of the reply; null when it didn't. */
    public static function gap( $flag, $in_scope, $fully_answered ) {
        if ( 'crisis' === $flag ) {
            return null;
        }
        if ( 'unanswered' === $flag ) {
            return 'no_info';
        }
        if ( 'personal_advice' === $flag ) {
            return 'personal';
        }
        if ( 'out_of_scope' === $flag || ! $in_scope ) {
            return 'off_topic';
        }
        return $fully_answered ? null : 'partial';
    }

    /* ============================== WRITING ============================ */

    private static function insert( array $row ) {
        global $wpdb;
        $suppress = $wpdb->suppress_errors( true );
        $ok       = $wpdb->insert( self::table(), $row );
        $wpdb->suppress_errors( $suppress );
        return (bool) $ok;
    }

    /** A live question, after the model has judged it. Silent on failure. */
    public static function record_question( $team, $sub, $gap, $first, $log_id, $ts = null ) {
        return self::insert( array(
            'ts'     => $ts ?: gmdate( 'Y-m-d H:i:s' ),
            'kind'   => 'question',
            'team'   => $team,
            'sub'    => $sub,
            'gap'    => isset( self::GAPS[ (string) $gap ] ) ? $gap : null,
            'conv'   => $first ? 1 : 0,
            'log_id' => $log_id ? (int) $log_id : null,
        ) );
    }

    /**
     * An action from the widget (or the contact form). Only a known action counts,
     * and only a team and label that already exist for a question: anything else is
     * kept without them, so a forged call can't add categories. Site-wide throttle.
     */
    public static function record_action( $kind, $team, $sub, $first ) {
        if ( ! isset( self::ACTIONS[ (string) $kind ] ) ) {
            return false;
        }
        $rate = get_transient( self::RATE_KEY );
        $rate = is_array( $rate ) ? $rate : array( 'n' => 0 );
        if ( (int) $rate['n'] >= self::RATE_MAX ) {
            return false;
        }
        set_transient( self::RATE_KEY, array( 'n' => (int) $rate['n'] + 1 ), 10 * MINUTE_IN_SECONDS );

        list( $team, $sub ) = self::known( $team, $sub );
        return self::insert( array(
            'ts'    => gmdate( 'Y-m-d H:i:s' ),
            'kind'  => (string) $kind,
            'team'  => $team,
            'sub'   => $sub,
            'conv'  => ( $first && null !== $team ) ? 1 : 0,
        ) );
    }

    /** [team, sub] if that pair has been recorded for a question, else [null, null]. */
    private static function known( $team, $sub ) {
        $team = trim( (string) $team );
        $sub  = trim( (string) $sub );
        if ( '' === $team ) {
            return array( null, null );
        }
        global $wpdb;
        $table = self::table();
        $hit   = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE kind = 'question' AND team = %s AND sub = %s", $team, $sub ) );
        return (int) $hit > 0 ? array( $team, $sub ) : array( null, null );
    }

    /** Delete rows older than RETENTION_DAYS. */
    public static function prune() {
        global $wpdb;
        $table    = self::table();
        $suppress = $wpdb->suppress_errors( true );
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE ts < %s", gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );
        $wpdb->suppress_errors( $suppress );
    }

    /**
     * Reset (the Insights screen): every report row goes, and the backfill won't bring
     * back questions logged before now. The log itself is not touched.
     */
    public static function purge() {
        global $wpdb;
        $table = self::table();
        $wpdb->query( "DELETE FROM $table" );
        update_option( self::SINCE_OPTION, gmdate( 'Y-m-d H:i:s' ), false );
        delete_transient( self::RATE_KEY );
    }

    /* ============================== BACKFILL =========================== */

    const BATCH = 20;

    /** Log rows still to categorize: [with text, without text]. */
    public static function pending_counts() {
        global $wpdb;
        $log   = SFAF_Chat_Log::table();
        $stats = self::table();
        $since = (string) get_option( self::SINCE_OPTION, '1970-01-01 00:00:00' );
        $base  = "FROM $log l WHERE l.ts >= %s AND ( l.flag IS NULL OR l.flag NOT IN ('api_error','parse_error') ) AND NOT EXISTS ( SELECT 1 FROM $stats s WHERE s.log_id = l.id )";
        $with  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $base AND l.question IS NOT NULL AND l.question <> ''", $since ) );
        $none  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $base AND ( l.question IS NULL OR l.question = '' )", $since ) );
        return array( $with, $none );
    }

    /**
     * One batch of the backfill: up to BATCH logged questions sorted in one model
     * call. Returns {done, left, error}. Called again and again by the Insights screen.
     */
    public static function backfill_batch() {
        global $wpdb;
        $log   = SFAF_Chat_Log::table();
        $stats = self::table();
        $since = (string) get_option( self::SINCE_OPTION, '1970-01-01 00:00:00' );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.id, l.ts, l.question, l.answer, l.flag, l.in_scope FROM $log l WHERE l.ts >= %s AND l.question IS NOT NULL AND l.question <> ''
             AND ( l.flag IS NULL OR l.flag NOT IN ('api_error','parse_error') )
             AND NOT EXISTS ( SELECT 1 FROM $stats s WHERE s.log_id = l.id ) ORDER BY l.id ASC LIMIT %d",
            $since, self::BATCH
        ), ARRAY_A );
        if ( empty( $rows ) ) {
            return array( 'done' => true, 'left' => 0, 'error' => '' );
        }

        $sorted = self::sort_with_model( $rows );
        if ( is_string( $sorted ) ) {
            return array( 'done' => false, 'left' => self::pending_counts()[0], 'error' => $sorted );
        }
        foreach ( $rows as $i => $r ) {
            $got  = $sorted[ $i ] ?? array();
            $team = self::team( $got['report_team'] ?? '' );
            $sub  = self::label( $team, $got['subcategory'] ?? '' );
            $gap  = self::gap( $r['flag'], null === $r['in_scope'] || 1 === (int) $r['in_scope'], false !== ( $got['answered_well'] ?? true ) );
            self::record_question( $team, $sub, $gap, false, (int) $r['id'], (string) $r['ts'] );
        }
        $left = self::pending_counts()[0];
        return array( 'done' => 0 === $left, 'left' => $left, 'error' => '' );
    }

    /**
     * Ask the model to sort a batch. Returns index => {report_team, subcategory,
     * answered_well}, or a message for staff when the call failed.
     */
    private static function sort_with_model( array $rows ) {
        $api_key = trim( SFAF_Chat_Credentials::get( 'api_key' ) );
        if ( '' === $api_key ) {
            return 'No Gemini key is saved on the General screen.';
        }
        $items = array();
        foreach ( $rows as $i => $r ) {
            $items[] = array(
                'i' => $i,
                'q' => mb_substr( (string) $r['question'], 0, 500 ),
                'a' => mb_substr( (string) ( $r['answer'] ?? '' ), 0, 500 ),
            );
        }
        $teams = array_merge( self::teams(), array( array( 'team' => self::GENERAL, 'handles' => 'Anything no team above clearly handles, and anything not about SFAF.' ) ) );
        $sys   = "You sort questions visitors asked San Francisco AIDS Foundation's website chat, for a monthly report. "
            . "Nobody sees your output except staff counting totals.\n"
            . "For each item (\"q\" is the question, \"a\" the chat's reply, which may be empty):\n"
            . "- \"report_team\": the team below whose work the question is about, by its subject, exactly as named.\n"
            . "- \"subcategory\": a short, broad label (two to four words) for what they asked about, within that team. "
            . "Reuse a label from LABELS IN USE when one fits, even loosely; make a new one only for a clearly different "
            . "subject. Keep labels broad, never one per question. Never put names, contact details or anything personal "
            . "in a label. No em dashes.\n"
            . "- \"answered_well\": false when the reply declined, said it didn't have the information, or sent them "
            . "elsewhere for lack of an answer; true otherwise, and true when the reply is empty.\n\n"
            . "TEAMS\n" . wp_json_encode( $teams, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n\n"
            . "LABELS IN USE\n" . wp_json_encode( (object) self::labels( self::PROMPT_SUBS ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

        $payload = array(
            'systemInstruction' => array( 'parts' => array( array( 'text' => $sys ) ) ),
            'contents'          => array( array( 'role' => 'user', 'parts' => array( array( 'text' => wp_json_encode( $items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) ) ) ),
            'generationConfig'  => array(
                'temperature'      => 0.1,
                'responseMimeType' => 'application/json',
                'responseSchema'   => array(
                    'type'       => 'object',
                    'properties' => array(
                        'items' => array(
                            'type'  => 'array',
                            'items' => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'i'             => array( 'type' => 'integer' ),
                                    'report_team'   => array( 'type' => 'string', 'nullable' => true ),
                                    'subcategory'   => array( 'type' => 'string' ),
                                    'answered_well' => array( 'type' => 'boolean' ),
                                ),
                                'required'   => array( 'i', 'subcategory' ),
                            ),
                        ),
                    ),
                    'required'   => array( 'items' ),
                ),
            ),
        );

        $api_base = rtrim( (string) apply_filters( 'sfaf_chat_api_base', 'https://generativelanguage.googleapis.com/v1beta' ), '/' );
        $endpoint = $api_base . '/models/' . rawurlencode( SFAF_Chat_Credentials::model() ) . ':generateContent';
        $call     = SFAF_Chat_Endpoint::call_gemini( $endpoint, array(
            'headers' => array( 'Content-Type' => 'application/json', 'x-goog-api-key' => $api_key ),
            'body'    => wp_json_encode( $payload ),
        ) );
        if ( is_wp_error( $call['reply'] ) || $call['http'] >= 400 ) {
            return 'The model could not be reached (HTTP ' . (int) $call['http'] . '). Nothing was lost; try again in a minute.';
        }
        $data   = json_decode( (string) wp_remote_retrieve_body( $call['reply'] ), true );
        $parsed = json_decode( (string) ( $data['candidates'][0]['content']['parts'][0]['text'] ?? '' ), true );
        if ( ! is_array( $parsed ) || ! is_array( $parsed['items'] ?? null ) ) {
            return 'The model gave a reply that could not be read. Try again.';
        }
        $out = array();
        foreach ( $parsed['items'] as $it ) {
            if ( is_array( $it ) && isset( $it['i'] ) && is_numeric( $it['i'] ) ) {
                $out[ (int) $it['i'] ] = $it;
            }
        }
        return $out;
    }

    /* ============================== READING ============================ */

    /**
     * Everything the report shows for a range (Y-m-d, Pacific, inclusive) and a team
     * ('' for all teams). Counts only.
     */
    public static function report( $from, $to, $team = '' ) {
        global $wpdb;
        $tz    = SFAF_Chat_Core::timezone();
        $utc   = new DateTimeZone( 'UTC' );
        $start = ( new DateTimeImmutable( $from . ' 00:00:00', $tz ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
        $end   = ( new DateTimeImmutable( $to . ' 00:00:00', $tz ) )->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
        $table = self::table();

        $where = $wpdb->prepare( 'ts >= %s AND ts < %s', $start, $end );
        if ( '' !== $team ) {
            $where .= $wpdb->prepare( ' AND team = %s', $team );
        }
        // Overall views group by team, a team's view by its labels.
        $group = '' === $team ? 'team' : 'sub';

        $q = function ( $sql ) use ( $wpdb ) {
            $suppress = $wpdb->suppress_errors( true );
            $rows     = $wpdb->get_results( $sql, ARRAY_A );
            $wpdb->suppress_errors( $suppress );
            return is_array( $rows ) ? $rows : array();
        };
        $pairs = function ( array $rows ) {
            $out = array();
            foreach ( $rows as $r ) {
                $out[ (string) ( $r['k'] ?? '' ) ] = (int) $r['n'];
            }
            return $out;
        };

        $questions = (int) ( $q( "SELECT COUNT(*) AS n FROM $table WHERE kind = 'question' AND $where" )[0]['n'] ?? 0 );
        $struggled = (int) ( $q( "SELECT COUNT(*) AS n FROM $table WHERE kind = 'question' AND gap IS NOT NULL AND $where" )[0]['n'] ?? 0 );

        $demand = $pairs( $q( "SELECT $group AS k, COUNT(*) AS n FROM $table WHERE kind = 'question' AND $where GROUP BY $group ORDER BY n DESC, k ASC" ) );
        $top    = array();
        if ( '' === $team ) {
            foreach ( $q( "SELECT team, sub, COUNT(*) AS n FROM $table WHERE kind = 'question' AND $where GROUP BY team, sub ORDER BY n DESC, team ASC, sub ASC LIMIT 12" ) as $r ) {
                $top[ $r['team'] . ': ' . $r['sub'] ] = (int) $r['n'];
            }
        }
        $gaps        = $pairs( $q( "SELECT $group AS k, COUNT(*) AS n FROM $table WHERE kind = 'question' AND gap IS NOT NULL AND $where GROUP BY $group ORDER BY n DESC, k ASC" ) );
        $gap_reasons = $pairs( $q( "SELECT gap AS k, COUNT(*) AS n FROM $table WHERE kind = 'question' AND gap IS NOT NULL AND $where GROUP BY gap ORDER BY n DESC" ) );

        $actions = array_fill_keys( array_keys( self::ACTIONS ), 0 );
        foreach ( $q( "SELECT kind AS k, COUNT(*) AS n FROM $table WHERE kind <> 'question' AND $where GROUP BY kind" ) as $r ) {
            if ( isset( $actions[ $r['k'] ] ) ) {
                $actions[ $r['k'] ] = (int) $r['n'];
            }
        }

        // Conversations in each group, and how many of them took an action.
        $conv = array();
        foreach ( $q( "SELECT $group AS k, SUM(kind = 'question') AS c, SUM(kind <> 'question') AS a FROM $table WHERE conv = 1 AND $where GROUP BY $group" ) as $r ) {
            $c = (int) $r['c'];
            if ( $c > 0 ) {
                $conv[ (string) $r['k'] ] = array( 'conversations' => $c, 'acted' => min( $c, (int) $r['a'] ) );
            }
        }
        uasort( $conv, function ( $a, $b ) {
            return $b['conversations'] <=> $a['conversations'];
        } );

        return array(
            'questions'   => $questions,
            'struggled'   => $struggled,
            'demand'      => $demand,
            'top'         => $top,
            'gaps'        => $gaps,
            'gap_reasons' => $gap_reasons,
            'actions'     => $actions,
            'conversion'  => $conv,
        );
    }

    /** Teams for the dropdown: configured ones, General, and any in the data. */
    public static function report_teams() {
        global $wpdb;
        $names = array_column( self::teams(), 'team' );
        $names[] = self::GENERAL;
        $table    = self::table();
        $suppress = $wpdb->suppress_errors( true );
        $seen     = (array) $wpdb->get_col( "SELECT DISTINCT team FROM $table WHERE team IS NOT NULL" );
        $wpdb->suppress_errors( $suppress );
        foreach ( $seen as $t ) {
            if ( ! in_array( $t, $names, true ) ) {
                $names[] = (string) $t;
            }
        }
        return array_values( array_unique( $names ) );
    }
}
