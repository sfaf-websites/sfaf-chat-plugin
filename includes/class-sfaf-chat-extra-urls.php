<?php
/**
 * Pages to index that no sitemap lists: Classy (donate.sfaf.org) campaign and event
 * pages, one-off pages. Staff paste them on the Indexing screen.
 *
 * Each URL is fetched on every indexer run (so daily, with the 12-hour run gap),
 * tagged with its host as its source, and kept in the index like any other page.
 *
 * A URL THAT DIES is dropped from the index and flagged here: at once on 404 or
 * 410, and after FAIL_LIMIT failed fetches in a row for anything else (a timeout,
 * a 5xx, a 403). A dead URL is not fetched again until someone presses Re-check.
 *
 * Stored in sfaf_chat_extra_urls, not autoloaded:
 *   url => {added, status: pending|ok|dead, fails, error, checked, title}
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Extra_Urls {

    const OPTION     = 'sfaf_chat_extra_urls';
    const MAX        = 50;
    const FAIL_LIMIT = 3;

    /** First-run list, so a fresh install can answer about it. Seeded once. */
    const SEED = array( 'https://donate.sfaf.org/event/santa-skivvies-2026/e773343' );

    public static function seed() {
        if ( false === get_option( self::OPTION, false ) ) {
            $list = array();
            foreach ( self::SEED as $url ) {
                $list[ $url ] = self::fresh();
            }
            add_option( self::OPTION, $list, '', 'no' );
        }
    }

    private static function fresh() {
        return array( 'added' => gmdate( 'c' ), 'status' => 'pending', 'fails' => 0, 'error' => '', 'checked' => '', 'title' => '' );
    }

    /** url => meta, in the order they were added. */
    public static function all() {
        $raw = get_option( self::OPTION, array() );
        $out = array();
        foreach ( is_array( $raw ) ? $raw : array() as $url => $meta ) {
            $url = self::clean_url( $url );
            if ( '' !== $url ) {
                $out[ $url ] = array_merge( self::fresh(), is_array( $meta ) ? $meta : array() );
            }
        }
        return $out;
    }

    /** The URLs the indexer should fetch this run: everything not dead. */
    public static function live() {
        return array_keys( array_filter( self::all(), function ( $m ) {
            return 'dead' !== $m['status'];
        } ) );
    }

    /**
     * Replace the list with these URLs. New ones start as pending, ones already
     * listed keep their status, missing ones are removed (and leave the index on
     * the next run). Returns [saved count, rejected lines].
     */
    public static function set_list( array $lines ) {
        $old      = self::all();
        $new      = array();
        $rejected = array();
        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( '' === $line ) {
                continue;
            }
            $url = self::clean_url( $line );
            if ( '' === $url ) {
                $rejected[] = $line;
                continue;
            }
            if ( count( $new ) >= self::MAX ) {
                $rejected[] = $line . ' (over the limit of ' . self::MAX . ')';
                continue;
            }
            $new[ $url ] = $old[ $url ] ?? self::fresh();
        }
        update_option( self::OPTION, $new, false );
        return array( count( $new ), $rejected );
    }

    /**
     * Record how a fetch went. $error is '' on success, else the indexer's reason
     * ("HTTP 404", "fetch failed: ..."). Returns the new status.
     */
    public static function report( $url, $error, $title = '' ) {
        $all = self::all();
        if ( ! isset( $all[ $url ] ) ) {
            return '';
        }
        $m            = $all[ $url ];
        $m['checked'] = gmdate( 'c' );
        if ( '' === (string) $error ) {
            $m['status'] = 'ok';
            $m['fails']  = 0;
            $m['error']  = '';
            $m['title']  = (string) $title;
        } else {
            $m['fails'] = (int) $m['fails'] + 1;
            $m['error'] = substr( (string) $error, 0, 200 );
            $gone       = (bool) preg_match( '/^HTTP (404|410)\b/', (string) $error );
            $m['status'] = ( $gone || $m['fails'] >= self::FAIL_LIMIT ) ? 'dead' : ( 'ok' === $m['status'] ? 'ok' : 'pending' );
        }
        $all[ $url ] = $m;
        update_option( self::OPTION, $all, false );
        return $m['status'];
    }

    /** Fetch a dead URL again on the next run. */
    public static function retry( $url ) {
        $all = self::all();
        if ( ! isset( $all[ $url ] ) ) {
            return false;
        }
        $all[ $url ]['status'] = 'pending';
        $all[ $url ]['fails']  = 0;
        update_option( self::OPTION, $all, false );
        return true;
    }

    /** The source tag for a URL: its host without "www.". */
    public static function source_for( $url ) {
        $host = strtolower( (string) parse_url( (string) $url, PHP_URL_HOST ) );
        return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
    }

    /** https only, no fragment, no credentials, sensible length. '' if unusable. */
    public static function clean_url( $url ) {
        $url = trim( (string) $url );
        if ( strlen( $url ) > 500 || ! preg_match( '#^https://#i', $url ) ) {
            return '';
        }
        $url   = (string) preg_replace( '/#.*$/', '', $url );
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] )
            || ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $parts['host'] ) ) {
            return '';
        }
        return $url;
    }
}
