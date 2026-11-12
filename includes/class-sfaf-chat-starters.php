<?php
/**
 * Starter questions: the two or three tappable questions the widget shows when it
 * opens. The widget sends its page address and gets back the set for that page.
 *
 * A starter is only a shortcut for typing. It goes through the ask route like any
 * other question, so the guardrails, pins and logging all apply unchanged.
 *
 * Staff manage the sets on the Suggested Questions screen. The option is created by
 * the first save, so until then defaults() applies, and an update never rewrites it.
 *
 *   'default' => list of questions, used when no set matches
 *   'sets'    => list of array( 'id', 'label', 'patterns' => list, 'questions' => list ),
 *                checked in order, and the first set with a matching pattern wins
 *
 * PATTERNS. Case and a trailing slash don't matter.
 *   /get-involved/volunteer           that page, on any host
 *   /get-involved/*                   that page and everything under it
 *   https://www.sfaf.org/about/*      the same, on that host only (www. is optional)
 *
 * Only the host and path are ever used. A query string can hold personal details,
 * so the widget doesn't send one and for_url() ignores one. The address is matched
 * and forgotten: it is never logged or stored.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Starters {

    const OPTION = 'sfaf_chat_starters';

    const MIN_QUESTIONS    = 2;
    const MAX_SHOWN        = 3;
    const MAX_QUESTION_LEN = 200;
    const MAX_URL_LEN      = 2048;
    const MAX_SETS         = 50;

    /** Weglot serves translations under /es/, which is the same page for matching. */
    const LANGUAGE_PREFIXES = array( 'es' );

    /** What ships. Every question here is one the curated knowledge base can answer. */
    public static function defaults() {
        return array(
            'default' => array(
                'Where can I get tested for HIV and STIs?',
                'How do I get PrEP?',
                'What are your locations and hours?',
            ),
            'sets'    => array(
                array(
                    'id'        => 'set-1',
                    'label'     => 'Health services',
                    'patterns'  => array( '/health-services/*' ),
                    'questions' => array(
                        'How do I make a clinic appointment?',
                        'Do I need insurance to be seen?',
                        'Where is the SFAF pharmacy?',
                    ),
                ),
                array(
                    'id'        => 'set-2',
                    'label'     => 'Get involved',
                    'patterns'  => array( '/get-involved/*' ),
                    'questions' => array(
                        'How can I volunteer?',
                        'What are the ways to give?',
                        'How do I sign up for SFAF news?',
                    ),
                ),
            ),
        );
    }

    public static function config() {
        $stored = get_option( self::OPTION );
        return apply_filters( 'sfaf_chat_starter_config', is_array( $stored ) ? $stored : self::defaults() );
    }

    public static function save( array $config ) {
        update_option( self::OPTION, $config, false );
    }

    /* ============================== FROM THE FORM ========================= */

    /** Why a pattern line can't work, or null if it can. */
    public static function pattern_problem( $line ) {
        if ( preg_match( '/\s/', $line ) ) {
            return 'it has a space in it';
        }
        if ( '/' !== substr( $line, 0, 1 ) && ! preg_match( '#^https?://[^/]+#i', $line ) ) {
            return 'it must start with / or with https://';
        }
        return null;
    }

    private static function questions_from( $list ) {
        $out = array();
        foreach ( (array) $list as $q ) {
            $q = sanitize_text_field( SFAF_Chat_Core::no_em_dash( is_string( $q ) ? $q : '' ) );
            if ( '' !== $q ) {
                $out[] = mb_substr( $q, 0, self::MAX_QUESTION_LEN );
            }
        }
        return $out;
    }

    private static function count_problem( array $questions, $name ) {
        $n = count( $questions );
        if ( $n < self::MIN_QUESTIONS || $n > self::MAX_SHOWN ) {
            return sprintf( '%s needs 2 or 3 questions, and has %d.', $name, $n );
        }
        return null;
    }

    /**
     * A form post to array( 'config' => ..., 'errors' => list ). The config comes back
     * even with errors, so what was typed can go back on the screen.
     */
    public static function from_input( array $input ) {
        $errors  = array();
        $default = self::questions_from( $input['default'] ?? array() );

        $problem = self::count_problem( $default, 'The default set' );
        if ( null !== $problem ) {
            $errors[] = $problem;
        }

        $sets = array();
        foreach ( array_values( (array) ( $input['sets'] ?? array() ) ) as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $label     = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
            $questions = self::questions_from( $row['questions'] ?? array() );
            $patterns  = array_values( array_filter( array_map( 'trim', explode( "\n", str_replace( "\r", '', (string) ( $row['patterns'] ?? '' ) ) ) ), 'strlen' ) );

            // A row left blank is not a set.
            if ( '' === $label && array() === $questions && array() === $patterns ) {
                continue;
            }

            $n    = count( $sets ) + 1;
            $name = '' !== $label ? '"' . $label . '"' : 'Set ' . $n;

            if ( array() === $patterns ) {
                $errors[] = $name . ' needs at least one page address.';
            }
            foreach ( $patterns as $line ) {
                $why = self::pattern_problem( $line );
                if ( null !== $why ) {
                    $errors[] = sprintf( '%s: the address "%s" won\'t work, because %s.', $name, $line, $why );
                }
            }
            $problem = self::count_problem( $questions, $name );
            if ( null !== $problem ) {
                $errors[] = $problem;
            }

            $sets[] = array( 'id' => 'set-' . $n, 'label' => $label, 'patterns' => $patterns, 'questions' => $questions );
        }

        if ( count( $sets ) > self::MAX_SETS ) {
            $errors[] = 'There can be at most ' . self::MAX_SETS . ' sets.';
        }

        return array( 'config' => array( 'default' => $default, 'sets' => $sets ), 'errors' => $errors );
    }

    /* =============================== MATCHING ============================= */

    /** The set for a page: array( 'set', 'label', 'questions' ). */
    public static function for_url( $url ) {
        $config = self::config();
        $page   = self::page( $url );

        if ( null !== $page ) {
            foreach ( (array) ( $config['sets'] ?? array() ) as $set ) {
                foreach ( (array) ( $set['patterns'] ?? array() ) as $pattern ) {
                    if ( self::matches( (string) $pattern, $page['host'], $page['path'] ) ) {
                        return array(
                            'set'       => (string) ( $set['id'] ?? '' ),
                            'label'     => (string) ( $set['label'] ?? '' ),
                            'questions' => self::clean( $set['questions'] ?? array() ),
                        );
                    }
                }
            }
        }
        return array( 'set' => 'default', 'label' => 'Default', 'questions' => self::clean( $config['default'] ?? array() ) );
    }

    /**
     * Does this page address match this pattern? The same rules as the question
     * sets (a path, /x/* or a full URL; /es/ ignored), for contact routing too.
     */
    public static function page_matches( $pattern, $url ) {
        $page = self::page( $url );
        return null !== $page && self::matches( (string) $pattern, $page['host'], $page['path'] );
    }

    /** Host and normalized path of a page address, or null if it isn't one. */
    private static function page( $url ) {
        if ( ! is_string( $url ) || '' === $url || strlen( $url ) > self::MAX_URL_LEN ) {
            return null;
        }
        $parts = wp_parse_url( strtolower( $url ) );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
            return null;
        }

        $path = self::normalize_path( (string) ( $parts['path'] ?? '/' ) );
        foreach ( self::LANGUAGE_PREFIXES as $lang ) {
            if ( '/' . $lang === $path ) {
                $path = '/';
            } elseif ( 0 === strpos( $path, '/' . $lang . '/' ) ) {
                $path = substr( $path, strlen( $lang ) + 1 );
            }
        }
        return array( 'host' => self::bare_host( $parts['host'] ), 'path' => $path );
    }

    private static function matches( $pattern, $host, $path ) {
        $pattern = strtolower( trim( $pattern ) );
        if ( '' === $pattern ) {
            return false;
        }

        if ( preg_match( '#^https?://#', $pattern ) ) {
            $parts = wp_parse_url( $pattern );
            if ( ! is_array( $parts ) || empty( $parts['host'] ) || self::bare_host( $parts['host'] ) !== $host ) {
                return false;
            }
            $pattern = (string) ( $parts['path'] ?? '/' );
        }

        $pattern = self::normalize_path( $pattern );

        // "/x/*" covers /x itself as well as what is under it.
        $tail = '';
        if ( '/*' === substr( $pattern, -2 ) ) {
            $pattern = substr( $pattern, 0, -2 );
            $tail    = '(?:/.*)?';
        }
        $regex = str_replace( '\*', '.*', preg_quote( $pattern, '#' ) );

        return 1 === preg_match( '#^' . $regex . $tail . '$#', $path );
    }

    /** Always a leading slash, and no trailing one. The home page is "/". */
    private static function normalize_path( $path ) {
        return '/' . trim( $path, '/' );
    }

    private static function bare_host( $host ) {
        return preg_replace( '/^www\./', '', strtolower( (string) $host ) );
    }

    /** Non-empty strings, cut to size, no em dashes, at most MAX_SHOWN. */
    private static function clean( $questions ) {
        $out = array();
        foreach ( (array) $questions as $q ) {
            $q = is_string( $q ) ? trim( SFAF_Chat_Core::no_em_dash( $q ) ) : '';
            if ( '' !== $q ) {
                $out[] = mb_substr( $q, 0, self::MAX_QUESTION_LEN );
            }
        }
        return array_slice( $out, 0, self::MAX_SHOWN );
    }
}
