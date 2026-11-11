<?php
/**
 * Scheduled alerts: short notices staff write ahead of time, such as "Closed today
 * due to weather" or "Strut hours changed this week". NOT calendar closures, which
 * the SFAF Calendar owns (see SFAF_Chat_Closures).
 *
 * Each has a start date and an optional end date, Pacific, both inclusive. An
 * alert is ACTIVE from the start of its first day through the end of its last;
 * with no end date it stays active until someone deletes it. Active alerts are
 * shown as a notice when the chat opens (through the /starters route, which is
 * never cached) and are handed to the model as current information, so answers
 * don't contradict them. Scheduled and ended alerts do nothing.
 *
 * CLEARING THEMSELVES. An alert stops showing the day after its end date, by the
 * date check alone. The daily cleanup also deletes alerts that ended more than
 * KEEP_ENDED_DAYS ago, so the list doesn't fill with old ones.
 *
 * Stored in the sfaf_chat_alerts option, not autoloaded. Written only through
 * save() and delete(), from the Welcome & Alerts screen. The text is plain: tags
 * are stripped and whitespace collapsed on the way in, and the widget renders it
 * with textContent.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Alerts {

    const OPTION          = 'sfaf_chat_alerts';
    const MAX             = 30;
    const MAX_TEXT        = 280;
    const KEEP_ENDED_DAYS = 14;

    /** Today, Y-m-d, Pacific. */
    public static function today() {
        return wp_date( 'Y-m-d', null, SFAF_Chat_Core::timezone() );
    }

    /**
     * Every alert, soonest start first.
     *
     * @return array[] id, text, start, end ('' = no end date)
     */
    public static function all() {
        $raw = get_option( self::OPTION, array() );
        $out = array();
        foreach ( is_array( $raw ) ? $raw : array() as $id => $a ) {
            if ( ! is_array( $a ) ) {
                continue;
            }
            $text  = self::clean_text( $a['text'] ?? '' );
            $start = self::clean_date( $a['start'] ?? '' );
            $end   = self::clean_date( $a['end'] ?? '' );
            if ( '' === $text || '' === $start ) {
                continue;
            }
            if ( '' !== $end && $end < $start ) {
                $end = $start;
            }
            $out[] = array( 'id' => (string) $id, 'text' => $text, 'start' => $start, 'end' => $end );
        }
        usort( $out, function ( $a, $b ) {
            return array( $a['start'], $a['id'] ) <=> array( $b['start'], $b['id'] );
        } );
        return $out;
    }

    /** 'active', 'scheduled' or 'ended', for $today (Y-m-d). */
    public static function status( array $a, $today = null ) {
        $today = $today ?: self::today();
        if ( $a['start'] > $today ) {
            return 'scheduled';
        }
        if ( '' !== $a['end'] && $a['end'] < $today ) {
            return 'ended';
        }
        return 'active';
    }

    /** The alerts showing right now. */
    public static function active() {
        $today = self::today();
        return array_values( array_filter( self::all(), function ( $a ) use ( $today ) {
            return 'active' === self::status( $a, $today );
        } ) );
    }

    /**
     * Create or update an alert.
     *
     * @return string|WP_Error The id.
     */
    public static function save( $id, $text, $start, $end = '' ) {
        $text  = self::clean_text( $text );
        $start = self::clean_date( $start );
        $end   = self::clean_date( $end );

        if ( '' === $text ) {
            return new WP_Error( 'sfaf_chat_alert_text', 'Write the alert text.' );
        }
        if ( '' === $start ) {
            return new WP_Error( 'sfaf_chat_alert_start', 'Give the alert a start date.' );
        }
        if ( '' !== $end && $end < $start ) {
            return new WP_Error( 'sfaf_chat_alert_backwards', 'The end date is before the start date.' );
        }

        $raw = get_option( self::OPTION, array() );
        $raw = is_array( $raw ) ? $raw : array();

        $id = (string) $id;
        if ( '' === $id || ! isset( $raw[ $id ] ) ) {
            if ( count( $raw ) >= self::MAX ) {
                return new WP_Error( 'sfaf_chat_alert_full', 'There are already ' . self::MAX . ' alerts. Delete an old one first.' );
            }
            $id = 'a' . str_replace( '-', '', $start ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
        }

        $raw[ $id ] = array( 'text' => $text, 'start' => $start, 'end' => $end );
        update_option( self::OPTION, $raw, false );
        return $id;
    }

    public static function delete( $id ) {
        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) || ! isset( $raw[ (string) $id ] ) ) {
            return false;
        }
        unset( $raw[ (string) $id ] );
        update_option( self::OPTION, $raw, false );
        return true;
    }

    /** Daily: delete alerts that ended more than KEEP_ENDED_DAYS ago. */
    public static function prune() {
        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) || array() === $raw ) {
            return;
        }
        $cutoff  = wp_date( 'Y-m-d', time() - self::KEEP_ENDED_DAYS * DAY_IN_SECONDS, SFAF_Chat_Core::timezone() );
        $changed = false;
        foreach ( $raw as $id => $a ) {
            $end = self::clean_date( is_array( $a ) ? ( $a['end'] ?? '' ) : '' );
            if ( '' !== $end && $end < $cutoff ) {
                unset( $raw[ $id ] );
                $changed = true;
            }
        }
        if ( $changed ) {
            update_option( self::OPTION, $raw, false );
        }
    }

    /** Plain text, one paragraph, capped. */
    public static function clean_text( $text ) {
        $text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
        return mb_substr( $text, 0, self::MAX_TEXT );
    }

    /** A real Y-m-d date, or ''. */
    public static function clean_date( $date ) {
        $date = trim( (string) $date );
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
            return '';
        }
        return $date;
    }
}
