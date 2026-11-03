<?php
/**
 * Shared helpers: file locations, JSON file IO, the SFAF host allowlist, and the
 * tokenizer and keyword ranking behind the knowledge pre-filter.
 *
 * WHERE GENERATED FILES LIVE. A plugin update replaces the whole plugin folder,
 * so nothing generated may be written inside it. The page index goes in
 * uploads/sfaf-chat/ (see data_dir()). What ships in data/ is read-only.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Core {

    const STOPWORDS = array(
        // English
        'a','about','after','all','also','am','an','and','any','are','as','at','be','been','but',
        'by','can','could','did','do','does','for','from','get','got','had','has','have','how',
        'i','if','in','into','is','it','its','just','me','more','my','no','not','of','on','or',
        'our','out','so','some','than','that','the','their','them','then','there','these','they',
        'this','to','up','us','was','we','were','what','when','where','which','who','why','will',
        'with','would','you','your','yours','im','dont','there','here','want','need','know',
        'please','thanks','thank','hi','hello','tell','find','like','one','sfaf','san','francisco',
        // Spanish (common function words only)
        'el','la','los','las','de','del','y','en','un','una','que','por','para','con','es','se',
        'su','al','lo','como','mas','pero','sus','le','ya','o','este','si','porque','esta','son',
    );

    /** SFAF is in San Francisco. Dates shown to the model and to staff are local. */
    public static function timezone() {
        return new DateTimeZone( 'America/Los_Angeles' );
    }

    /* ============================== LOCATIONS ============================= */

    /** uploads/sfaf-chat, created on demand. Survives plugin updates. */
    public static function data_dir() {
        $uploads = wp_upload_dir( null, false );
        $dir     = trailingslashit( $uploads['basedir'] ) . 'sfaf-chat';

        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        // Holds public page text only, but nothing here needs serving over HTTP.
        if ( is_dir( $dir ) && ! is_file( $dir . '/.htaccess' ) ) {
            @file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
            @file_put_contents( $dir . '/index.html', '' );
        }
        return $dir;
    }

    public static function index_path() {
        return self::data_dir() . '/page-index.json';
    }

    public static function prompt_path() {
        return SFAF_CHAT_DIR . 'data/system-prompt.txt';
    }

    public static function kb_path() {
        return SFAF_CHAT_DIR . 'data/knowledge-base.json';
    }

    /* ============================== FILE HELPERS ========================== */

    /** Decode a JSON file to an array, or null if missing or invalid. */
    public static function read_json( $path ) {
        if ( ! is_file( $path ) ) {
            return null;
        }
        $data = json_decode( (string) @file_get_contents( $path ), true );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Write JSON via temp file + rename, so a reader (the endpoint) never sees a
     * half-written file mid-crawl.
     */
    public static function write_json_atomic( $path, array $data ) {
        $tmp  = $path . '.tmp';
        $json = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $json || false === @file_put_contents( $tmp, $json, LOCK_EX ) ) {
            return false;
        }
        return @rename( $tmp, $path );
    }

    /** Every curated entry that has an id. Used by the endpoint and the pin form. */
    public static function kb_entries() {
        $kb = self::read_json( self::kb_path() );
        if ( ! isset( $kb['entries'] ) || ! is_array( $kb['entries'] ) ) {
            return array();
        }
        return array_values( array_filter( $kb['entries'], function ( $e ) {
            return is_array( $e ) && isset( $e['id'] );
        } ) );
    }

    /* ============================ SFAF HOST ALLOWLIST ===================== */

    /**
     * Card images may only come from SFAF domains: sfaf.org, *.sfaf.org,
     * tweaker.org (+ www). HTTPS only. widget.js repeats this check, so keep the
     * two lists identical.
     */
    public static function is_sfaf_url( $url ) {
        if ( ! is_string( $url ) || '' === $url ) {
            return false;
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' || empty( $parts['host'] ) ) {
            return false;
        }
        $host = strtolower( $parts['host'] );

        return 'sfaf.org' === $host
            || '.sfaf.org' === substr( $host, -9 )
            || 'tweaker.org' === $host
            || 'www.tweaker.org' === $host;
    }

    /* ======================= TOKENIZING + PRE-FILTER ====================== */

    /**
     * Lowercase word tokens with stopwords removed and a crude plural fold
     * ("services" to "service"), applied identically to questions and records.
     */
    public static function tokenize( $text ) {
        static $stop = null;
        if ( null === $stop ) {
            $stop = array_flip( self::STOPWORDS );
        }

        $words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
        $out   = array();
        foreach ( $words ?: array() as $w ) {
            if ( mb_strlen( $w ) < 2 || isset( $stop[ $w ] ) ) {
                continue;
            }
            if ( mb_strlen( $w ) > 4 && 's' === substr( $w, -1 ) && 'ss' !== substr( $w, -2 ) ) {
                $w = substr( $w, 0, -1 );
            }
            $out[] = $w;
        }
        return $out;
    }

    /**
     * Score records by keyword overlap with weighted query tokens and return the
     * top $top_n with a positive score, best first.
     *
     * $query: token => weight (the live question at 1.0, recent user turns lower,
     * so a follow-up like "what about walk-ins?" still matches its subject).
     *
     * Per query token: title hit 3, keyword hit 2, og description hit 1, plus up
     * to about 2 for term frequency in the body text (log-damped, so long pages
     * don't win just by being long).
     */
    public static function rank_records( array $records, array $query, $top_n ) {
        if ( array() === $query || array() === $records ) {
            return array();
        }

        $scored = array();
        foreach ( $records as $i => $r ) {
            $title = array_flip( self::tokenize( (string) ( $r['title'] ?? '' ) ) );
            $kw    = array_flip( self::tokenize( implode( ' ', (array) ( $r['keywords'] ?? array() ) ) ) );
            $desc  = array_flip( self::tokenize( (string) ( $r['og']['description'] ?? '' ) ) );
            $tf    = array_count_values( self::tokenize( (string) ( $r['text'] ?? $r['content'] ?? '' ) ) );

            $score = 0.0;
            foreach ( $query as $tok => $weight ) {
                $s = 0.0;
                if ( isset( $title[ $tok ] ) ) { $s += 3; }
                if ( isset( $kw[ $tok ] ) )    { $s += 2; }
                if ( isset( $desc[ $tok ] ) )  { $s += 1; }
                if ( isset( $tf[ $tok ] ) )    { $s += min( 2.0, log( 1 + $tf[ $tok ] ) ); }
                $score += $s * $weight;
            }
            if ( $score > 0 ) {
                $scored[] = array( $score, $i );
            }
        }

        usort( $scored, function ( $a, $b ) {
            return $b[0] <=> $a[0] ?: $a[1] <=> $b[1];
        } );

        $out = array();
        foreach ( array_slice( $scored, 0, $top_n ) as $pair ) {
            $out[] = $records[ $pair[1] ];
        }
        return $out;
    }
}
