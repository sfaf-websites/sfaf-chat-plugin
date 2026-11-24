<?php
/**
 * Scheduled alerts: short notices staff write ahead of time, such as "Closed today
 * due to weather" or "Strut hours changed this week". NOT calendar closures, which
 * the SFAF Calendar owns (see SFAF_Chat_Closures).
 *
 * Each has a start date and an end date, Pacific, both inclusive. An alert is
 * ACTIVE from the start of its first day through the end of its last.
 *
 * ONE DAY BY DEFAULT (1.31.0). Saved with no end date, an alert is for its start
 * day only (end = start), and with no start date it starts the day it is saved.
 * Before 1.31.0 no end date meant "until deleted". Alerts stored that way (end '')
 * keep that meaning until an administrator applies the new rule on the screen,
 * which first lists each one and what it would become (legacy(), migrate()).
 * Applying it deletes nothing: those alerts are marked "kept", and the daily
 * cleanup leaves them alone. Active alerts are
 * shown as a notice when the chat opens (through the /starters route, which is
 * never cached) and are handed to the model as current information, so answers
 * don't contradict them. Scheduled and ended alerts do nothing.
 *
 * CLEARING THEMSELVES. An alert stops showing the day after its end date, by the
 * date check alone. The daily cleanup also deletes alerts that ended more than
 * KEEP_ENDED_DAYS ago, so the list doesn't fill with old ones.
 *
 * Stored in the sfaf_chat_alerts option, not autoloaded. Written only through
 * save(), delete() and migrate(), from the Welcome & Alerts screen. THE TEXT IS
 * STORED EXACTLY AS TYPED (1.31.0; before, tags were stripped and whitespace
 * collapsed): text with HTML tags, or over MAX_TEXT, is refused rather than changed.
 * Safe as given: only administrators write it, the admin screen escapes it, and the
 * widget renders it with textContent. Edit (1.31.0) updates an alert in place.
 *
 * ONE LINK (1.28.0): an optional web address and link text. Staff write it, so it is
 * trusted: the widget shows it as given, outside the link allowlist that holds the
 * model's replies. That trust is this field's alone. The address must be http(s),
 * mailto: or tel:; link text with no address is refused. No text: the address itself
 * is the label. The model is told the alert's text only, never its link.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Alerts {

    const OPTION          = 'sfaf_chat_alerts';
    const MAX             = 30;
    const MAX_TEXT        = 280;
    const MAX_LINK_TEXT   = 80;
    const MAX_URL         = 500;
    const KEEP_ENDED_DAYS = 14;

    /** Today, Y-m-d, Pacific. */
    public static function today() {
        return wp_date( 'Y-m-d', null, SFAF_Chat_Core::timezone() );
    }

    /**
     * Every alert, soonest start first.
     *
     * @return array[] id, text, start, end ('' = saved before 1.31.0 with no end date:
     *                 until deleted), url, link_text ('' = none), kept (moved to the
     *                 one-day rule by migrate(), never removed by the cleanup)
     */
    public static function all() {
        $raw = get_option( self::OPTION, array() );
        $out = array();
        foreach ( is_array( $raw ) ? $raw : array() as $id => $a ) {
            if ( ! is_array( $a ) ) {
                continue;
            }
            // As stored, never reworded. An all-blank text is no alert.
            $text  = is_string( $a['text'] ?? null ) && '' !== trim( $a['text'] ) ? $a['text'] : '';
            $start = self::clean_date( $a['start'] ?? '' );
            $end   = self::clean_date( $a['end'] ?? '' );
            if ( '' === $text || '' === $start ) {
                continue;
            }
            if ( '' !== $end && $end < $start ) {
                $end = $start;
            }
            $url   = self::clean_url( $a['url'] ?? '' );
            $out[] = array( 'id' => (string) $id, 'text' => $text, 'start' => $start, 'end' => $end,
                'url' => $url, 'link_text' => '' === $url ? '' : self::clean_link_text( $a['link_text'] ?? '' ),
                'kept' => ! empty( $a['kept'] ) );
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
     * Create an alert, or with $must_exist update the one with this id in place
     * (Edit). No start date: today. No end date: the start date, so one day.
     *
     * @return string|WP_Error The id.
     */
    public static function save( $id, $text, $start, $end = '', $url = '', $link_text = '', $must_exist = false ) {
        $text      = (string) $text;
        $raw_start = trim( (string) $start );
        $start     = '' === $raw_start ? self::today() : self::clean_date( $raw_start );
        $raw_end   = trim( (string) $end );
        $end       = '' === $raw_end ? $start : self::clean_date( $raw_end );
        $raw_url   = trim( (string) $url );
        $url       = self::clean_url( $raw_url );
        $link_text = self::clean_link_text( $link_text );

        if ( '' === trim( $text ) ) {
            return new WP_Error( 'sfaf_chat_alert_text', 'Write the alert text.' );
        }
        // Refused, never changed: the text is saved exactly as typed.
        if ( ! preg_match( '//u', $text ) ) {
            return new WP_Error( 'sfaf_chat_alert_text', 'The alert text has characters that could not be read. Type it again.' );
        }
        if ( mb_strlen( $text ) > self::MAX_TEXT ) {
            return new WP_Error( 'sfaf_chat_alert_long', 'The alert text is longer than ' . self::MAX_TEXT . ' characters. Shorten it.' );
        }
        if ( preg_match( '#</?[a-z!][^>]*>#i', $text ) ) {
            return new WP_Error( 'sfaf_chat_alert_markup', 'The alert text can\'t contain HTML tags. Use plain text.' );
        }
        if ( '' === $start || '' === $end ) {
            return new WP_Error( 'sfaf_chat_alert_date', 'That date is not a real date.' );
        }
        if ( '' !== $end && $end < $start ) {
            return new WP_Error( 'sfaf_chat_alert_backwards', 'The end date is before the start date.' );
        }
        if ( '' !== $raw_url && '' === $url ) {
            return new WP_Error( 'sfaf_chat_alert_url', 'The link address must be a full web address starting with https:// (or http://, mailto: or tel:).' );
        }
        if ( '' === $url && '' !== $link_text ) {
            return new WP_Error( 'sfaf_chat_alert_link_text', 'Add the web address for the link text, or clear the link text.' );
        }

        $raw = get_option( self::OPTION, array() );
        $raw = is_array( $raw ) ? $raw : array();

        $id = (string) $id;
        if ( $must_exist && ( '' === $id || ! isset( $raw[ $id ] ) ) ) {
            return new WP_Error( 'sfaf_chat_alert_gone', 'That alert no longer exists, so nothing was changed.' );
        }
        if ( '' === $id || ! isset( $raw[ $id ] ) ) {
            if ( count( $raw ) >= self::MAX ) {
                return new WP_Error( 'sfaf_chat_alert_full', 'There are already ' . self::MAX . ' alerts. Delete an old one first.' );
            }
            $id = 'a' . str_replace( '-', '', $start ) . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
        }

        $raw[ $id ] = array( 'text' => $text, 'start' => $start, 'end' => $end, 'url' => $url, 'link_text' => '' === $url ? '' : $link_text );
        update_option( self::OPTION, $raw, false );
        return $id;
    }

    /** Alerts saved before 1.31.0 with no end date (still "until deleted"). */
    public static function legacy() {
        return array_values( array_filter( self::all(), function ( $a ) {
            return '' === $a['end'];
        } ) );
    }

    /**
     * The one-day rule for the alerts legacy() lists: end = start. Nothing is
     * deleted, now or later: they are marked kept, which the cleanup skips.
     *
     * @return int How many changed.
     */
    public static function migrate() {
        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) ) {
            return 0;
        }
        $n = 0;
        foreach ( $raw as $id => $a ) {
            if ( ! is_array( $a ) || '' !== self::clean_date( $a['end'] ?? '' ) || '' === self::clean_date( $a['start'] ?? '' ) ) {
                continue;
            }
            $raw[ $id ]['end']  = self::clean_date( $a['start'] );
            $raw[ $id ]['kept'] = true;
            $n++;
        }
        if ( $n > 0 ) {
            update_option( self::OPTION, $raw, false );
        }
        return $n;
    }

    /**
     * The dates for the screen, in the brand's style (no ordinals): "December 31,
     * 2026" for one day, "December 21, 2026 to January 3, 2027" for a range.
     */
    public static function dates_label( array $a ) {
        if ( '' === $a['end'] ) {
            return 'From ' . self::day( $a['start'] ) . ', until deleted';
        }
        return $a['end'] === $a['start'] ? self::day( $a['start'] ) : self::day( $a['start'] ) . ' to ' . self::day( $a['end'] );
    }

    /** "December 31, 2026" for a Y-m-d date. */
    public static function day( $ymd ) {
        $d = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd, SFAF_Chat_Core::timezone() );
        return $d ? $d->format( 'F j, Y' ) : (string) $ymd;
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

    /** Daily: delete alerts that ended more than KEEP_ENDED_DAYS ago, never a kept one. */
    public static function prune() {
        $raw = get_option( self::OPTION, array() );
        if ( ! is_array( $raw ) || array() === $raw ) {
            return;
        }
        $cutoff  = wp_date( 'Y-m-d', time() - self::KEEP_ENDED_DAYS * DAY_IN_SECONDS, SFAF_Chat_Core::timezone() );
        $changed = false;
        foreach ( $raw as $id => $a ) {
            $end = self::clean_date( is_array( $a ) ? ( $a['end'] ?? '' ) : '' );
            if ( '' !== $end && $end < $cutoff && empty( $a['kept'] ) ) {
                unset( $raw[ $id ] );
                $changed = true;
            }
        }
        if ( $changed ) {
            update_option( self::OPTION, $raw, false );
        }
    }

    /**
     * The alert's link for the chat, or null: {url, label}. The label is the link text,
     * or the address itself without its scheme.
     */
    public static function link( array $a ) {
        $url = self::clean_url( $a['url'] ?? '' );
        if ( '' === $url ) {
            return null;
        }
        $label = self::clean_link_text( $a['link_text'] ?? '' );
        return array( 'url' => $url, 'label' => '' !== $label ? $label : $url );
    }

    /** An http(s), mailto: or tel: address, as given, or ''. No spaces, no markup. */
    public static function clean_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url || strlen( $url ) > self::MAX_URL || preg_match( '/[\s<>"\x00-\x1f\x7f]/', $url ) ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $url ) ) {
            $host = wp_parse_url( $url, PHP_URL_HOST );
            return is_string( $host ) && '' !== $host && false === strpos( $host, '@' ) ? $url : '';
        }
        if ( preg_match( '/^mailto:[^@\s]+@[^@\s]+$/i', $url ) || preg_match( '/^tel:\+?[0-9().\-]{3,20}$/i', $url ) ) {
            return $url;
        }
        return '';
    }

    /** Plain text, one line, capped. */
    public static function clean_link_text( $text ) {
        $text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
        return mb_substr( $text, 0, self::MAX_LINK_TEXT );
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
