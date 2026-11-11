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
     * Replaces em dashes with a comma, for text from outside the curated files: indexed
     * pages, the events feed, earlier replies in the thread. Source text teaches the
     * model its style, and titles also reach visitors as link labels.
     *
     * Never use this on pinned answers, which are verbatim, or on what a visitor typed.
     */
    public static function no_em_dash( $text ) {
        $text = (string) $text;
        if ( false === strpos( $text, "\xE2\x80\x94" ) ) {
            return $text;
        }
        $clean = preg_replace( '/[\s,]*\x{2014}[\s,]*/u', ', ', $text );
        return trim( null === $clean ? str_replace( "\xE2\x80\x94", ', ', $text ) : $clean, ", \t\r\n" );
    }

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

    /* ===================== MATCHING: STEMS AND SYNONYMS ==================== */

    /*
     * WHY THESE EXIST. tokenize() is literal, so "props" never met "Proposition C"
     * and "Spanish" never met "Programa Latino". Ranking now compares STEMS (so
     * "voting", "voted" and "vote" meet) and expands the QUESTION with the groups
     * below (so "election props" also looks for "ballot" and "measure").
     *
     * tokenize() itself is unchanged on purpose: the event word lists and the
     * indexer's keywords are written against its exact output.
     *
     * SYNONYMS: one group per idea, single words only, any spelling a visitor or a
     * page might use. A question word pulls in the rest of its group at
     * SYNONYM_WEIGHT, so a literal hit always outranks a synonym hit. Keep groups
     * tight: a loose group makes every question match everything. Add to this list
     * when a real question misses content that exists; each group is a judgment.
     */
    const SYNONYMS = array(
        array( 'prop', 'proposition', 'ballot', 'measure', 'initiative' ),
        array( 'election', 'vote', 'voter', 'voting', 'ballot', 'endorse', 'endorsement', 'candidate', 'elecciones', 'votar' ),
        array( 'spanish', 'español', 'espanol', 'latino', 'latina', 'latine', 'latinx', 'hispanic', 'hispano', 'hispanohablante' ),
        array( 'trans', 'transgender', 'nonbinary', 'tgnci' ),
        array( 'meth', 'methamphetamine', 'crystal', 'tina', 'stimulant' ),
        array( 'hepatitis', 'hep', 'hcv' ),
        array( 'sti', 'std' ),
        array( 'overdose', 'narcan', 'naloxone' ),
        array( 'syringe', 'needle', 'sharps' ),
        array( 'donate', 'donation', 'donor' ),
        array( 'job', 'career', 'hiring', 'employment' ),
        array( 'aging', 'older', 'senior', 'elder' ),
        array( 'mental', 'therapy', 'counseling', 'therapist' ),
        array( 'housing', 'homeless', 'homelessness', 'shelter' ),
        array( 'insurance', 'medicare', 'medicaid', 'medi' ),
        array( 'advocacy', 'policy', 'legislation', 'law', 'lobby' ),
        array( 'hiv', 'vih' ),
        array( 'test', 'testing', 'prueba', 'screening' ),
        array( 'black', 'african' ),
    );

    const SYNONYM_WEIGHT = 0.6;

    /**
     * A light suffix fold on top of tokenize(): -ing, -ed, -ie to -y, and a final
     * -e, so "voting", "voted" and "vote" all become "vot". Crude on purpose. It is
     * only ever compared with itself, so a word folded oddly still meets its kin.
     */
    public static function stem( $w ) {
        $w   = (string) $w;
        $len = mb_strlen( $w );
        if ( $len > 5 && 'ing' === substr( $w, -3 ) ) {
            $w = substr( $w, 0, -3 );
        } elseif ( $len > 4 && 'ed' === substr( $w, -2 ) ) {
            $w = substr( $w, 0, -2 );
        }
        if ( mb_strlen( $w ) > 3 && 'ie' === substr( $w, -2 ) ) {
            $w = substr( $w, 0, -2 ) . 'y';
        }
        if ( mb_strlen( $w ) >= 4 && 'e' === substr( $w, -1 ) ) {
            $w = substr( $w, 0, -1 );
        }
        return $w;
    }

    /** tokenize(), then stem(). What ranking and passage picking compare. */
    public static function terms( $text ) {
        return array_map( array( __CLASS__, 'stem' ), self::tokenize( $text ) );
    }

    /**
     * Tokenized (not stemmed) synonyms of each token, for the event matcher, which
     * works on tokenize() output. token => [other tokens in its groups].
     */
    public static function synonyms_of( array $tokens ) {
        static $by_token = null;
        if ( null === $by_token ) {
            $by_token = array();
            foreach ( self::SYNONYMS as $group ) {
                $toks = array();
                foreach ( $group as $word ) {
                    $toks = array_merge( $toks, self::tokenize( $word ) );
                }
                $toks = array_values( array_unique( $toks ) );
                foreach ( $toks as $t ) {
                    $by_token[ $t ] = array_merge( $by_token[ $t ] ?? array(), $toks );
                }
            }
        }
        $out = array();
        foreach ( $tokens as $t ) {
            foreach ( $by_token[ $t ] ?? array() as $s ) {
                if ( $s !== $t ) {
                    $out[ $t ][] = $s;
                }
            }
        }
        return $out;
    }

    /**
     * Question tokens (token => weight) as weighted stems, with synonyms added at
     * SYNONYM_WEIGHT of the word that pulled them in. A literal word keeps its own
     * weight even if it is also another word's synonym.
     */
    public static function query_terms( array $query ) {
        $out = array();
        foreach ( $query as $tok => $weight ) {
            $s         = self::stem( (string) $tok );
            $out[ $s ] = max( $out[ $s ] ?? 0, (float) $weight );
        }
        foreach ( self::synonyms_of( array_map( 'strval', array_keys( $query ) ) ) as $tok => $syns ) {
            $w = (float) $query[ $tok ] * self::SYNONYM_WEIGHT;
            foreach ( $syns as $syn ) {
                $s         = self::stem( $syn );
                $out[ $s ] = max( $out[ $s ] ?? 0, $w );
            }
        }
        return $out;
    }

    /**
     * Score records by overlap with the question and return the top $top_n with a
     * positive score, best first.
     *
     * $query: token => weight (the live question at 1.0, recent user turns lower,
     * so a follow-up like "what about walk-ins?" still matches its subject). It is
     * stemmed and synonym-expanded here (query_terms()).
     *
     * Per term: title hit 3, keyword hit 2, og description hit 1, plus up to about 2
     * for frequency in the body (log-damped, so long records don't win by length).
     * Each term's points are then multiplied by how RARE it is among these records
     * (log(1 + N / records containing it)): "prop" in 12 posts of 653 counts far
     * more than "hiv" in hundreds, so telling words decide the ranking.
     */
    public static function rank_records( array $records, array $query, $top_n ) {
        $out = array();
        foreach ( array_slice( self::rank_scored( $records, $query ), 0, $top_n ) as $pair ) {
            $out[] = $records[ $pair[1] ];
        }
        return $out;
    }

    /** rank_records() with its scores: [[score, index into $records], ...], best first. */
    public static function rank_scored( array $records, array $query ) {
        $terms = self::query_terms( $query );
        if ( array() === $terms || array() === $records ) {
            return array();
        }

        // A record can only contain a term if the term's stem appears in its raw text:
        // every fold above only cuts a word's end (and -ie to -y, hence the probe drops
        // a final y). So a record with none of the probes is skipped before the costly
        // split into words. Same result, a fraction of the work on 600+ long posts.
        $probes = array();
        foreach ( array_keys( $terms ) as $t ) {
            $t = (string) $t;
            $probes[] = ( mb_strlen( $t ) > 3 && 'y' === substr( $t, -1 ) ) ? substr( $t, 0, -1 ) : $t;
        }

        // Stem sets per record, built once, then document frequency of each term.
        $docs = array();
        $df   = array();
        foreach ( $records as $i => $r ) {
            $haystack = mb_strtolower( (string) ( $r['title'] ?? '' ) . ' ' . implode( ' ', (array) ( $r['keywords'] ?? array() ) )
                . ' ' . (string) ( $r['og']['description'] ?? '' ) . ' ' . (string) ( $r['text'] ?? $r['content'] ?? '' ) );
            $present  = false;
            foreach ( $probes as $probe ) {
                if ( '' !== $probe && false !== strpos( $haystack, $probe ) ) {
                    $present = true;
                    break;
                }
            }
            if ( ! $present ) {
                continue;
            }

            $d = array(
                'title' => array_flip( self::terms( (string) ( $r['title'] ?? '' ) ) ),
                'kw'    => array_flip( self::terms( implode( ' ', (array) ( $r['keywords'] ?? array() ) ) ) ),
                'desc'  => array_flip( self::terms( (string) ( $r['og']['description'] ?? '' ) ) ),
                'tf'    => array_count_values( self::terms( (string) ( $r['text'] ?? $r['content'] ?? '' ) ) ),
            );
            foreach ( $terms as $t => $unused ) {
                if ( isset( $d['title'][ $t ] ) || isset( $d['kw'][ $t ] ) || isset( $d['desc'][ $t ] ) || isset( $d['tf'][ $t ] ) ) {
                    $df[ $t ] = ( $df[ $t ] ?? 0 ) + 1;
                }
            }
            $docs[ $i ] = $d;
        }

        $n   = count( $records );
        $idf = array();
        foreach ( $df as $t => $count ) {
            $idf[ $t ] = log( 1 + $n / $count );
        }

        $scored = array();
        foreach ( $docs as $i => $d ) {
            $score = 0.0;
            foreach ( $terms as $t => $weight ) {
                if ( ! isset( $idf[ $t ] ) ) {
                    continue;
                }
                $s = 0.0;
                if ( isset( $d['title'][ $t ] ) ) { $s += 3; }
                if ( isset( $d['kw'][ $t ] ) )    { $s += 2; }
                if ( isset( $d['desc'][ $t ] ) )  { $s += 1; }
                if ( isset( $d['tf'][ $t ] ) )    { $s += min( 2.0, log( 1 + $d['tf'][ $t ] ) ); }
                $score += $s * $weight * $idf[ $t ];
            }
            if ( $score > 0 ) {
                $scored[] = array( $score, $i );
            }
        }

        usort( $scored, function ( $a, $b ) {
            return $b[0] <=> $a[0] ?: $a[1] <=> $b[1];
        } );
        return $scored;
    }

    /**
     * The $len-word stretch of $text where the question's terms are densest, so a
     * long post is represented by the part that is about the question, not by its
     * opening. Falls back to the start when nothing matches. Marks cut ends with an
     * ellipsis.
     *
     * $terms: stem => weight, as from query_terms().
     */
    public static function best_passage( $text, array $terms, $len ) {
        $words = preg_split( '/\s+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
        $n     = count( $words );
        if ( $n <= $len ) {
            return implode( ' ', $words );
        }

        $hit = array();
        foreach ( $words as $i => $w ) {
            $hit[ $i ] = 0.0;
            foreach ( self::terms( $w ) as $t ) {
                $hit[ $i ] = max( $hit[ $i ], (float) ( $terms[ $t ] ?? 0 ) );
            }
        }

        // Sliding window: best total weight of hits in $len consecutive words.
        $sum   = array_sum( array_slice( $hit, 0, $len ) );
        $best  = $sum;
        $start = 0;
        for ( $i = 1; $i + $len <= $n; $i++ ) {
            $sum += $hit[ $i + $len - 1 ] - $hit[ $i - 1 ];
            if ( $sum > $best + 1e-9 ) {
                $best  = $sum;
                $start = $i;
            }
        }

        // The first best window ends right on its last hit, which can cut a sentence
        // mid-name ("no on Prop"). Centre the window on its hits instead, so there is
        // context on both sides.
        if ( $best > 0 ) {
            $first = null;
            $last  = null;
            for ( $i = $start; $i < $start + $len; $i++ ) {
                if ( $hit[ $i ] > 0 ) {
                    $first = null === $first ? $i : $first;
                    $last  = $i;
                }
            }
            $start = (int) max( 0, min( $n - $len, intdiv( $first + $last, 2 ) - intdiv( $len, 2 ) ) );
        }

        return ( $start > 0 ? '… ' : '' )
            . implode( ' ', array_slice( $words, $start, $len ) )
            . ( $start + $len < $n ? ' …' : '' );
    }
}
