<?php
/**
 * One de-identified, topic-tagged row per question, in a database table. A table
 * has no URL, so it cannot be fetched over HTTP.
 *
 * WHAT IS RECORDED: time, topic, in_scope, flag, which record id was linked, which
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

        // ts is UTC. The dashboard converts to Pacific when it reads.
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
     * @param array $entry topic, in_scope, flag, link, pin, http, model, attempts, question, answer.
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
        );

        $suppress = $wpdb->suppress_errors( true );
        $wpdb->insert( self::table(), $row );
        $wpdb->suppress_errors( $suppress );

        // Backstop for the daily job: WordPress cron only runs when something
        // triggers it, and 60 days is a promise. Roughly 1 request in 50 pays.
        try {
            if ( 1 === random_int( 1, 50 ) ) {
                self::prune();
            }
        } catch ( Exception $e ) {
            // random_int can throw with no entropy source. Skip it, never fail.
            return;
        }
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

    /** Rows newer than $days, oldest first. Admin screens only. */
    public static function since_days( $days ) {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) );
        $table  = self::table();
        $rows   = $wpdb->get_results(
            $wpdb->prepare( "SELECT ts, topic, in_scope, flag, link, pin, http, model, attempts, question, answer FROM $table WHERE ts >= %s ORDER BY ts ASC", $cutoff ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }
}
