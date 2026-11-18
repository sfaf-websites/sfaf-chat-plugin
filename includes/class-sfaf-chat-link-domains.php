<?php
/**
 * Approved link domains (1.21.0): which web addresses in a reply become clickable.
 *
 * A web address the model writes in an answer is a link ONLY when its host is one of
 * these, or a subdomain of one:
 *   - the approved list staff keep on the Indexing screen (this option), seeded once
 *     with SFAF's own properties and the content sources already in use;
 *   - the sites the chat indexes (SFAF_Chat_Indexer sites, and the hosts of the pages
 *     added by hand);
 *   - links already built into the plugin (HARD_CODED: the healow booking links).
 * Anything else stays plain text, so a made-up or unknown address is never one click
 * away. Emails and phone numbers are linked whatever their domain (widget.js).
 *
 * An entry may carry a friendly label ("SFAF Shop"). For a site other than sfaf.org it
 * replaces the raw host wherever the chat names the site: on a link button to one of
 * its pages, and as the site's name to the model.
 *
 * Stored in sfaf_chat_link_domains, not autoloaded: domain => label ('' for none).
 * Updates never rewrite it; deleting the plugin removes it.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Link_Domains {

    const OPTION    = 'sfaf_chat_link_domains';
    const MAX       = 50;
    const MAX_LABEL = 60;

    /** First-run list. Seeded once; staff edit it afterwards. */
    const SEED = array(
        'sfaf.org'                                    => '',
        'tweaker.org'                                 => '',
        'cycletozero.org'                             => '',
        'san-francisco-aids-foundation.myshopify.com' => 'SFAF Shop',
        'job-boards.greenhouse.io'                    => '',
        'donate.sfaf.org'                             => '',
        'annualreport.sfaf.org'                       => '',
    );

    /** Built into the plugin: the appointment chooser's healow links. */
    const HARD_CODED = array( 'healow.com' );

    public static function seed() {
        if ( false === get_option( self::OPTION, false ) ) {
            add_option( self::OPTION, self::SEED, '', 'no' );
        }
    }

    /** domain => label, in the order they were added. */
    public static function all() {
        $raw = get_option( self::OPTION, self::SEED );
        $out = array();
        foreach ( is_array( $raw ) ? $raw : array() as $domain => $label ) {
            $domain = self::clean_domain( (string) $domain );
            if ( '' !== $domain ) {
                $out[ $domain ] = self::clean_label( (string) $label );
            }
        }
        return $out;
    }

    /**
     * A domain from what staff typed: lower case, no scheme, path, port or "www.".
     * '' when it isn't a plain host name with a dot.
     */
    public static function clean_domain( $raw ) {
        $d = strtolower( trim( (string) $raw ) );
        $d = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $d );
        $d = (string) preg_replace( '#[/?\#:].*$#', '', $d );
        $d = (string) preg_replace( '#^www\.#', '', $d );
        return preg_match( '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $d ) ? $d : '';
    }

    private static function clean_label( $raw ) {
        return mb_substr( trim( sanitize_text_field( (string) $raw ) ), 0, self::MAX_LABEL );
    }

    /**
     * Save the list from the screen: one per line, "domain" or "domain | Label".
     * Nothing is stored when any line is bad, so a typo can't silently drop a domain.
     *
     * @return array{0:int, 1:string[]} [count saved, rejected lines]
     */
    public static function set_list( array $lines ) {
        $list     = array();
        $rejected = array();
        foreach ( $lines as $line ) {
            $line = trim( (string) $line );
            if ( '' === $line ) {
                continue;
            }
            $parts  = explode( '|', $line, 2 );
            $domain = self::clean_domain( $parts[0] );
            if ( '' === $domain ) {
                $rejected[] = $line;
                continue;
            }
            $list[ $domain ] = self::clean_label( $parts[1] ?? '' );
        }
        if ( count( $list ) > self::MAX ) {
            $rejected[] = sprintf( 'More than %d domains: the list was not saved.', self::MAX );
        }
        if ( $rejected ) {
            return array( 0, $rejected );
        }
        update_option( self::OPTION, $list, 'no' );
        return array( count( $list ), array() );
    }

    /** Is this host an entry, or a subdomain of one? */
    private static function covers( $entry, $host ) {
        return $host === $entry || substr( $host, -strlen( '.' . $entry ) ) === '.' . $entry;
    }

    /**
     * The friendly label for a host, from the most specific approved entry that covers
     * it and has one. '' for none.
     */
    public static function label_for( $host ) {
        $host = self::clean_domain( (string) $host );
        if ( '' === $host ) {
            return '';
        }
        $best = '';
        $len  = 0;
        foreach ( self::all() as $domain => $label ) {
            if ( '' !== $label && self::covers( $domain, $host ) && strlen( $domain ) > $len ) {
                $best = $label;
                $len  = strlen( $domain );
            }
        }
        return $best;
    }

    /**
     * Every host whose addresses the widget may link: approved, indexed and built in.
     * Sent to the widget in sfafChatConfig.links.
     *
     * @return string[]
     */
    public static function linkable_hosts() {
        $hosts = array_keys( self::all() );
        foreach ( SFAF_Chat_Indexer::configured_sites() as $site ) {
            $hosts[] = self::clean_domain( (string) ( $site['source'] ?? '' ) );
        }
        foreach ( array_keys( SFAF_Chat_Extra_Urls::all() ) as $url ) {
            $hosts[] = self::clean_domain( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        }
        $hosts = array_merge( $hosts, self::HARD_CODED );
        $hosts = array_values( array_unique( array_filter( $hosts ) ) );
        sort( $hosts );
        return $hosts;
    }
}
