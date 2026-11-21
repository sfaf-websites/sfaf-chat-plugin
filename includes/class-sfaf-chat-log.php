<?php
/**
 * One de-identified, topic-tagged row per question, in a database table. A table
 * has no URL, so it cannot be fetched over HTTP.
 *
 * WHAT IS RECORDED: time, the page it was asked on (origin and path, never the query or
 * fragment), topic, in_scope, flag, which record id was linked, which
 * pinned answer was used, and how the call went: HTTP status, model and number of
 * attempts. Question AND answer text only while raw logging is on, which is for
 * reviewing exchanges during testing (SFAF_Chat_Settings::log_raw_questions()).
 * The answer is what the visitor was shown, after any pinned answer. Its only
 * personal content is what a visitor typed and the assistant echoed.
 *
 * WHAT IS DELIBERATELY *NOT* RECORDED: no IP address, no user id, no session id,
 * no cookie, no user agent, and no thread identifier. Rows cannot be tied to a
 * person and cannot be stitched back into a conversation. This is intentional
 * and load-bearing: these are health-adjacent questions asked by visitors to a
 * health organization, and a log that could re-identify them would be a liability
 * far outweighing its usefulness. Do not add identifiers here.
 *
 * Failure is always silent. Logging must never break a visitor's answer.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Log {

    const RETENTION_DAYS = 60;
    const CRON_HOOK      = 'sfaf_chat_prune_logs';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'sfaf_chat_log';
    }

    public static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        // ts is UTC. The Insights screen converts to Pacific when it reads.
        dbDelta( "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ts datetime NOT NULL,
  topic varchar(40) DEFAULT NULL,
  in_scope tinyint(1) DEFAULT NULL,
  flag varchar(32) DEFAULT NULL,
  link varchar(120) DEFAULT NULL,
  pin varchar(40) DEFAULT NULL,
  http smallint(5) unsigned DEFAULT NULL,
  model varchar(64) DEFAULT NULL,
  attempts tinyint(3) unsigned DEFAULT NULL,
  question text DEFAULT NULL,
  answer text DEFAULT NULL,
  page varchar(255) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY ts (ts)
) $charset;" );
    }

    public static function table_exists() {
        global $wpdb;
        $table = self::table();
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    /**
     * @param array $entry topic, in_scope, flag, link, pin, http, model, attempts, question, answer, page.
     * @return int The new row's id, or 0 when it wasn't written.
     */
    public static function write( array $entry ) {
        global $wpdb;

        $in_scope = $entry['in_scope'] ?? null;
        $raw      = SFAF_Chat_Settings::log_raw_questions();

        $row = array(
            'ts'       => gmdate( 'Y-m-d H:i:s' ),
            'topic'    => $entry['topic'] ?? null,
            'in_scope' => null === $in_scope ? null : ( $in_scope ? 1 : 0 ),
            'flag'     => $entry['flag'] ?? null,
            'link'     => $entry['link'] ?? null,
            'pin'      => $entry['pin'] ?? null,
            'http'     => isset( $entry['http'] ) ? (int) $entry['http'] : null,
            'model'    => $entry['model'] ?? null,
            'attempts' => isset( $entry['attempts'] ) ? (int) $entry['attempts'] : null,
            'question' => $raw ? (string) ( $entry['question'] ?? '' ) : null,
            'answer'   => $raw && isset( $entry['answer'] ) ? (string) $entry['answer'] : null,
            // The page it was asked on: origin and path only (SFAF_Chat_Core::clean_page_url).
            'page'     => isset( $entry['page'] ) && '' !== (string) $entry['page'] ? mb_substr( (string) $entry['page'], 0, 255 ) : null,
        );

        $suppress = $wpdb->suppress_errors( true );
        $wpdb->insert( self::table(), $row );
        $wpdb->suppress_errors( $suppress );
        $id = (int) $wpdb->insert_id;

        // Backstop for the daily job: WordPress cron only runs when something
        // triggers it, and 60 days is a promise. Roughly 1 request in 50 pays.
        try {
            if ( 1 === random_int( 1, 50 ) ) {
                self::prune();
            }
        } catch ( Exception $e ) {
            // random_int can throw with no entropy source. Skip it, never fail.
            return $id;
        }
        return $id;
    }

    /** Delete rows older than RETENTION_DAYS. */
    public static function prune() {
        global $wpdb;
        $cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );
        $table    = self::table();
        $suppress = $wpdb->suppress_errors( true );
        $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE ts < %s", $cutoff ) );
        $wpdb->suppress_errors( $suppress );
    }

    /**
     * Rows from $from to $to (Y-m-d, Pacific, inclusive), oldest first. Admin screens only.
     * With a team, only the questions the insights report sorted to it (through the stats
     * row's log_id), so errors, which the report never counts, drop out.
     */
    public static function between( $from, $to, $team = '' ) {
        global $wpdb;
        $tz    = SFAF_Chat_Core::timezone();
        $utc   = new DateTimeZone( 'UTC' );
        $start = ( new DateTimeImmutable( $from . ' 00:00:00', $tz ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
        $end   = ( new DateTimeImmutable( $to . ' 00:00:00', $tz ) )->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
        $table = self::table();
        $cols  = 'l.id, l.ts, l.topic, l.in_scope, l.flag, l.link, l.pin, l.http, l.model, l.attempts, l.question, l.answer, l.page';
        if ( '' === (string) $team ) {
            $sql = $wpdb->prepare( "SELECT $cols FROM $table l WHERE l.ts >= %s AND l.ts < %s ORDER BY l.ts ASC, l.id ASC", $start, $end );
        } else {
            $stats = SFAF_Chat_Stats::table();
            $sql   = $wpdb->prepare(
                "SELECT DISTINCT $cols FROM $table l INNER JOIN $stats s ON s.log_id = l.id AND s.kind = 'question' WHERE l.ts >= %s AND l.ts < %s AND s.team = %s ORDER BY l.ts ASC, l.id ASC",
                $start, $end, (string) $team
            );
        }
        $suppress = $wpdb->suppress_errors( true );
        $rows     = $wpdb->get_results( $sql, ARRAY_A );
        $wpdb->suppress_errors( $suppress );
        return is_array( $rows ) ? $rows : array();
    }
}
