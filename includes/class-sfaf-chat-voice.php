<?php
/**
 * Read aloud with Google Cloud Text-to-Speech (1.27.0).
 *
 * INERT UNTIL A KEY IS SET. Without one, ready() is false, no ask reply carries a
 * speech token, the /speak route refuses, and the widget reads with the browser's
 * own voice exactly as before.
 *
 * THE KEY is its own credential ('tts_key' in SFAF_Chat_Credentials: write-only, not
 * autoloaded), never the Gemini key. Or, when nothing is saved there, 'tts_api_key'
 * in a config.php outside the web root, which wp-config.php names with the constant
 * SFAF_CHAT_CONFIG (a full path). No constant, no lookup: the server's layout is not
 * written into the plugin. Every Google call is made from this server, with the key in the
 * x-goog-api-key header. Nothing here returns, logs or prints it.
 *
 * NOT AN OPEN SPEECH PROXY. Each ask reply carries `speech`, an HMAC over the
 * answer it came with and an expiry (TOKEN_HOURS), signed with this site's salt.
 * /speak voices only text that matches a token, so it says only what the chat said.
 * There is no session to tie it to (none exists anywhere in the chat), so "this
 * conversation" is approximated by the expiry. The spoken form, the language and
 * the parts are all worked out here from the answer, never taken from the browser.
 *
 * COST. MAX_ANSWER_CHARS per answer, PART_CHARS per Google request, and a rate
 * limit: CLIENT_MAX parts per client and SITE_MAX for everyone, per RATE_WINDOW.
 * The client is a keyed hash of the IP address, held only as the name of a
 * ten-minute transient: never logged, never stored past the window, never sent to
 * Google.
 *
 * WHAT GOOGLE GETS: the spoken text of one part, the voice name and the language.
 * No IP address, page, session or identifier. Nothing is kept here, and no audio is
 * cached.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Voice {

    /** Chosen voices: {en, es}. Not autoloaded. */
    const OPTION = 'sfaf_chat_voice';
    /** Google's voice list, filtered: {voices, checked}. Not autoloaded. */
    const LIST_OPTION = 'sfaf_chat_voices';
    const LIST_MAX_AGE = 12 * HOUR_IN_SECONDS;

    const API = 'https://texttospeech.googleapis.com/v1/';

    /** The languages offered, and the locales each may use. */
    const LOCALES = array(
        'en' => array( 'en-US' ),
        'es' => array( 'es-US', 'es-ES' ),
    );

    /** Used only when no voice is saved and Google's list has never been read. */
    const FALLBACK = array(
        'en' => 'en-US-Chirp3-HD-Aoede',
        'es' => 'es-US-Chirp3-HD-Aoede',
    );

    /** What a Preview plays. */
    const SAMPLES = array(
        'en' => 'Hi! This is how the San Francisco AIDS Foundation chat sounds when it reads an answer aloud.',
        'es' => '¡Hola! Así suena el chat de la San Francisco AIDS Foundation cuando lee una respuesta en voz alta.',
    );

    const TOKEN_HOURS      = 3;
    const MAX_ANSWER_CHARS = 4000;
    const FIRST_PART_CHARS = 300;   // short, so the first words start soon
    const PART_CHARS       = 900;   // well inside Google's 5000-byte input limit
    const MAX_PARTS        = 12;
    const MAX_BUTTONS      = 6;     // a reply shows four at most
    const MAX_BUTTON_CHARS = 200;
    const TIMEOUT_SECONDS  = 15;

    const RATE_WINDOW = 10 * MINUTE_IN_SECONDS;
    const CLIENT_MAX  = 40;    // parts per client per window
    const SITE_MAX    = 400;   // parts for everyone per window
    const RATE_SITE   = 'sfaf_chat_voice_rate';
    const RATE_CLIENT = 'sfaf_chat_voice_rc_';

    /** The order voice types are listed in, best first. */
    const TYPES = array(
        'Chirp3-HD' => 'Chirp 3: HD',
        'Chirp-HD'  => 'Chirp HD',
        'Studio'    => 'Studio',
        'Neural2'   => 'Neural2',
        'Wavenet'   => 'WaveNet',
        'Polyglot'  => 'Polyglot',
        'News'      => 'News',
        'Casual'    => 'Casual',
        'Standard'  => 'Standard',
    );

    /* ================================== KEY =============================== */

    /** The TTS key: the saved one, else config.php's. Never the Gemini key. */
    /** The key in use, for the server's own calls and SFAF_Chat_Key_Status. Never output. */
    public static function api_key() {
        return self::key();
    }

    private static function key() {
        $saved = trim( SFAF_Chat_Credentials::get( 'tts_key' ) );
        return '' !== $saved ? $saved : self::config_key();
    }

    /** The config.php that wp-config.php names in SFAF_CHAT_CONFIG, or ''. */
    public static function config_path() {
        $path = defined( 'SFAF_CHAT_CONFIG' ) ? (string) constant( 'SFAF_CHAT_CONFIG' ) : '';
        return (string) apply_filters( 'sfaf_chat_private_config', $path );
    }

    private static function config_key() {
        static $key = null;
        if ( null !== $key ) {
            return $key;
        }
        $key  = '';
        $path = self::config_path();
        if ( '' === $path || ! is_readable( $path ) ) {
            return $key;
        }
        try {
            $config = ( static function ( $file ) {
                ob_start();
                $c = include $file;
                ob_end_clean();
                return $c;
            } )( $path );
        } catch ( Throwable $e ) {
            return $key;
        }
        if ( is_array( $config ) && is_string( $config['tts_api_key'] ?? null ) ) {
            $key = trim( $config['tts_api_key'] );
        }
        return $key;
    }

    /** 'settings', 'config' or '' : where the key comes from, for the admin screen. */
    public static function key_source() {
        if ( SFAF_Chat_Credentials::has( 'tts_key' ) ) {
            return 'settings';
        }
        return '' !== self::config_key() ? 'config' : '';
    }

    public static function ready() {
        return '' !== self::key();
    }

    /* ================================ VOICES ============================== */

    public static function saved() {
        $v = get_option( self::OPTION, array() );
        return is_array( $v ) ? $v : array();
    }

    /** A voice name that belongs to the language, or ''. */
    public static function clean_voice( $lang, $name ) {
        $name = trim( (string) $name );
        if ( ! isset( self::LOCALES[ $lang ] ) || ! preg_match( '/^([a-z]{2}-[A-Z]{2})-[A-Za-z0-9-]{1,60}$/', $name, $m ) ) {
            return '';
        }
        return in_array( $m[1], self::LOCALES[ $lang ], true ) ? $name : '';
    }

    /**
     * Save the two choices. Blank (a disabled dropdown sends nothing) keeps what is
     * saved; a name from another language is refused and keeps it too.
     *
     * @return string[] Problems for the notice.
     */
    public static function save( $en, $es ) {
        $out      = self::saved();
        $problems = array();
        foreach ( array( 'en' => $en, 'es' => $es ) as $lang => $name ) {
            if ( '' === trim( (string) $name ) ) {
                continue;
            }
            $clean = self::clean_voice( $lang, $name );
            if ( '' === $clean ) {
                $problems[] = 'en' === $lang ? 'That English voice is not an English (US) voice, so it was not saved.' : 'That Spanish voice is not a Spanish (US or Spain) voice, so it was not saved.';
                continue;
            }
            $out[ $lang ] = $clean;
        }
        update_option( self::OPTION, array_intersect_key( $out, self::LOCALES ), false );
        return $problems;
    }

    /** "Chirp 3: HD" and so on, from the name. */
    public static function type_of( $name ) {
        foreach ( self::TYPES as $part => $label ) {
            if ( false !== strpos( (string) $name, '-' . $part . '-' ) ) {
                return $label;
            }
        }
        return 'Other';
    }

    /**
     * The cached list, read again from Google when older than LIST_MAX_AGE or when
     * forced. A failed read keeps whatever was cached.
     *
     * @return array{voices:array,checked:int,error:string}
     */
    public static function voice_list( $force = false ) {
        $cached = get_option( self::LIST_OPTION, array() );
        $cached = is_array( $cached ) ? $cached : array();
        $list   = array(
            'voices'  => (array) ( $cached['voices'] ?? array() ),
            'checked' => (int) ( $cached['checked'] ?? 0 ),
            'error'   => '',
            'http'    => 0,
        );
        if ( ! self::ready() ) {
            return $list;
        }
        if ( ! $force && $list['checked'] && time() - $list['checked'] < self::LIST_MAX_AGE ) {
            return $list;
        }

        $reply = wp_remote_get( self::API . 'voices', array(
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => array( 'x-goog-api-key' => self::key() ),
        ) );
        $http = is_wp_error( $reply ) ? 0 : (int) wp_remote_retrieve_response_code( $reply );
        $list['http'] = $http;
        $data = is_wp_error( $reply ) ? null : json_decode( (string) wp_remote_retrieve_body( $reply ), true );
        if ( 200 !== $http || ! is_array( $data ) || ! isset( $data['voices'] ) ) {
            $list['error'] = 0 === $http
                ? 'Google could not be reached.'
                : sprintf( 'Google answered HTTP %d%s.', $http, is_array( $data ) && is_string( $data['error']['message'] ?? null ) ? ': ' . self::short( $data['error']['message'] ) : '' );
            return $list;
        }

        $voices = array();
        foreach ( (array) $data['voices'] as $v ) {
            $name = (string) ( $v['name'] ?? '' );
            foreach ( self::LOCALES as $lang => $locales ) {
                if ( '' !== self::clean_voice( $lang, $name ) ) {
                    $voices[] = array(
                        'name'   => $name,
                        'lang'   => $lang,
                        'locale' => substr( $name, 0, 5 ),
                        'type'   => self::type_of( $name ),
                        'gender' => ucfirst( strtolower( (string) ( $v['ssmlGender'] ?? '' ) ) ),
                    );
                }
            }
        }
        usort( $voices, array( __CLASS__, 'rank' ) );
        $list['voices']  = $voices;
        $list['checked'] = time();
        update_option( self::LIST_OPTION, array( 'voices' => $voices, 'checked' => $list['checked'] ), false );
        return $list;
    }

    /** Google's message, plain and short. Never contains the key. */
    private static function short( $text ) {
        $text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
        return mb_substr( $text, 0, 160 );
    }

    /** US Spanish before Spain, then the best type, then by name. */
    public static function rank( $a, $b ) {
        $order = array_values( self::TYPES );
        $la    = array_search( $a['locale'], self::LOCALES[ $a['lang'] ], true );
        $lb    = array_search( $b['locale'], self::LOCALES[ $b['lang'] ], true );
        $ta    = array_search( $a['type'], $order, true );
        $tb    = array_search( $b['type'], $order, true );
        return array( $a['lang'], $la, false === $ta ? 99 : $ta, $a['name'] ) <=> array( $b['lang'], $lb, false === $tb ? 99 : $tb, $b['name'] );
    }

    /** The voice a language speaks with: saved, else the best listed, else FALLBACK. */
    public static function voice_for( $lang ) {
        $saved = self::clean_voice( $lang, self::saved()[ $lang ] ?? '' );
        if ( '' !== $saved ) {
            return $saved;
        }
        return self::default_voice( $lang, (array) ( get_option( self::LIST_OPTION, array() )['voices'] ?? array() ) );
    }

    /** The first Chirp 3: HD voice listed for the language, else the first listed. */
    public static function default_voice( $lang, array $voices ) {
        $first = '';
        foreach ( $voices as $v ) {
            if ( ( $v['lang'] ?? '' ) !== $lang ) {
                continue;
            }
            if ( 'Chirp 3: HD' === ( $v['type'] ?? '' ) ) {
                return (string) $v['name'];
            }
            $first = $first ?: (string) $v['name'];
        }
        return $first ?: self::FALLBACK[ $lang ];
    }

    /* ================================ TOKENS ============================== */

    private static function secret() {
        return wp_salt( 'auth' ) . '|sfaf-chat-voice';
    }

    /**
     * What a token signs: the answer, and the labels of the buttons shown with it
     * (1.29.0), so the closing "You can also:" line is the chat's words as well. With
     * no buttons it is the answer alone, as in 1.27.0. /speak refuses both separators
     * in what it is sent, so one signed text can't be passed off as another.
     */
    private static function signed( $answer, array $buttons ) {
        return array() === $buttons ? (string) $answer : (string) $answer . "\x1F" . implode( "\x1E", $buttons );
    }

    /** The token an ask reply carries, or null while no key is set. */
    public static function token( $answer, array $buttons = array() ) {
        if ( ! self::ready() ) {
            return null;
        }
        $exp = time() + self::TOKEN_HOURS * HOUR_IN_SECONDS;
        return $exp . '.' . hash_hmac( 'sha256', $exp . '|' . self::signed( $answer, $buttons ), self::secret() );
    }

    public static function verify( $answer, $token, array $buttons = array() ) {
        if ( ! is_string( $answer ) || ! is_string( $token ) || ! preg_match( '/^(\d{10})\.([0-9a-f]{64})$/', $token, $m ) ) {
            return false;
        }
        if ( (int) $m[1] < time() ) {
            return false;
        }
        return hash_equals( hash_hmac( 'sha256', $m[1] . '|' . self::signed( $answer, $buttons ), self::secret() ), $m[2] );
    }

    /* ============================= THE BUTTONS ============================ */

    /**
     * The labels of the buttons the widget shows under a reply, as it shows them and
     * in its order: the card's button or the link, then Donate, then the contact offer.
     * The same fallbacks as addCard, addLink, addDonate and addContact in widget.js,
     * and, as there, only for an https address. Not the booking link: that sits in
     * the answer's text and is read there already.
     *
     * @return string[]
     */
    public static function buttons( $link, $card, $donate, $contact ) {
        $https = function ( $item ) {
            return is_array( $item ) && 1 === preg_match( '#^https://#i', (string) ( $item['url'] ?? '' ) );
        };
        $text = function ( $item, $key ) {
            return is_array( $item ) && is_scalar( $item[ $key ] ?? null ) ? (string) $item[ $key ] : '';
        };
        $labels = array();
        if ( is_array( $card ) ) {
            if ( $https( $card ) ) {
                $labels[] = '' !== $text( $card, 'cta' ) ? $text( $card, 'cta' ) : ( 'event' === $text( $card, 'type' ) ? 'Event details' : 'Read more' );
            }
        } elseif ( $https( $link ) ) {
            $labels[] = '' !== $text( $link, 'label' ) ? $text( $link, 'label' ) : 'Learn more';
        }
        if ( $https( $donate ) ) {
            $labels[] = '' !== $text( $donate, 'label' ) ? $text( $donate, 'label' ) : 'Donate';
        }
        if ( is_array( $contact ) && is_string( $contact['label'] ?? null ) && '' !== $contact['label'] ) {
            $labels[] = $contact['label'];
        }
        return array_slice( $labels, 0, self::MAX_BUTTONS );
    }

    /**
     * The closing sentence that names those buttons for a listener: "You can also:
     * Donate, Contact our team." Empty with no buttons. The same as closingLine() in
     * widget.js; keep the two in step.
     *
     * REVIEW:spanish. "También puedes:" waits on a native speaker's review.
     */
    public static function closing( array $buttons, $lang = 'en' ) {
        if ( array() === $buttons ) {
            return '';
        }
        $line = ( 'es' === $lang ? 'También puedes: ' : 'You can also: ' ) . implode( ', ', $buttons );
        return preg_match( '/[.!?]$/u', $line ) ? $line : $line . '.';
    }

    /* ============================ WHAT IS SAID ============================ */

    /**
     * An answer as it should be heard. The same steps as speakable() in widget.js,
     * which the browser voice still uses: link labels kept, list markers and markup
     * dropped, a bare address said as "the link", phone numbers digit by digit, en
     * dash ranges as "to". In Spanish, "el enlace" and "a" instead.
     */
    public static function spoken( $text, $lang = 'en' ) {
        $es = 'es' === $lang;
        $t  = (string) $text;
        $t = preg_replace( '/\[([^\]]+)\]\([^)]*\)/u', '$1', $t );
        $t = preg_replace( '/^\s*(?:[-*•]|\d{1,2}[.)])\s+/mu', '', $t );
        $t = preg_replace( '/([^.!?:;\s])[ \t]*\n/u', "\$1.\n", $t );
        $t = preg_replace( '#\b(?:https?://|www\.)[^\s)]+#iu', $es ? 'el enlace' : 'the link', $t );
        $t = preg_replace_callback( '/\(?(\d{3})\)?[\s.-](\d{3})[\s.-](\d{4})/u', function ( $m ) {
            return implode( ', ', array_map( function ( $g ) {
                return implode( ' ', str_split( $g ) );
            }, array( $m[1], $m[2], $m[3] ) ) );
        }, $t );
        $t = preg_replace( '/(\w)\s?\x{2013}\s?(\w)/u', $es ? '$1 a $2' : '$1 to $2', $t );
        $t = preg_replace( '/\s*\x{2014}\s*/u', ', ', $t );
        $t = preg_replace( '/[\x{1F000}-\x{10FFFF}]|[\x{2600}-\x{27BF}]/u', '', $t );
        $t = preg_replace( '/[*_#`~<>|]/u', ' ', $t );
        $t = preg_replace( '/\s+/u', ' ', $t );
        return trim( (string) $t );
    }

    /**
     * 'es', 'en', or '' for another language (the browser voice reads those).
     *
     * Simple on purpose: letters from another script, or two or more marks of another
     * Latin-script language (Portuguese, French, German, Vietnamese, Tagalog...), mean
     * another language. Otherwise common Spanish words against common English ones,
     * with ¿ and ¡ counting for Spanish; a tie is English.
     */
    public static function language( $text ) {
        $t = ' ' . mb_strtolower( (string) $text ) . ' ';

        $letters = preg_match_all( '/\p{L}/u', $t );
        $foreign = preg_match_all( '/(?!\p{Latin})\p{L}/u', $t );
        if ( $letters && $foreign >= 3 && $foreign * 5 >= $letters ) {
            return '';
        }
        $marks = preg_match_all( '/[ãõçàèêâîôûëïßäö\x{1E00}-\x{1EFF}ơưđ]/u', $t )
            + preg_match_all( '/\b(você|não|obrigad[oa]|vous|nous|avec|est|les|être|und|nicht|ist|mit|sono|grazie|della|ang|mga|ng)\b/u', $t );
        if ( $marks >= 2 ) {
            return '';
        }
        $es = preg_match_all( '/\b(el|la|los|las|de|del|que|y|es|en|para|con|una?|por|puede|puedes|nuestr[oa]s?|más|está|están|usted|hay|también|sí|cuando|donde|dosis|gracias)\b/u', $t )
            + 3 * preg_match_all( '/[¿¡]/u', $t );
        $en = preg_match_all( '/\b(the|and|is|are|of|to|for|with|you|your|it|can|our|we|more|this|that|at|on|be|have|dose|in)\b/u', $t );
        return $es > $en ? 'es' : 'en';
    }

    /**
     * The spoken text in parts: whole sentences, the first part short so speech
     * starts soon, the rest up to PART_CHARS. A sentence longer than that is cut at
     * a space.
     *
     * @return string[]
     */
    public static function parts( $spoken ) {
        preg_match_all( '/[^.!?]+(?:[.!?]+(?=\s|$)|$)|[.!?]+/u', (string) $spoken, $m );
        $parts = array();
        $cur   = '';
        foreach ( $m[0] as $sentence ) {
            $sentence = trim( $sentence );
            if ( '' === $sentence ) {
                continue;
            }
            $limit = array() === $parts ? self::FIRST_PART_CHARS : self::PART_CHARS;
            while ( mb_strlen( $sentence ) > self::PART_CHARS ) {
                $cut = mb_strrpos( mb_substr( $sentence, 0, self::PART_CHARS ), ' ' );
                $cut = $cut ? $cut : self::PART_CHARS;
                if ( '' !== $cur ) {
                    $parts[] = $cur;
                    $cur     = '';
                }
                $parts[]  = trim( mb_substr( $sentence, 0, $cut ) );
                $sentence = trim( mb_substr( $sentence, $cut ) );
            }
            if ( '' !== $cur && mb_strlen( $cur ) + 1 + mb_strlen( $sentence ) > $limit ) {
                $parts[] = $cur;
                $cur     = '';
            }
            $cur = '' === $cur ? $sentence : $cur . ' ' . $sentence;
        }
        if ( '' !== $cur ) {
            $parts[] = $cur;
        }
        return array_slice( $parts, 0, self::MAX_PARTS );
    }

    /* ================================ GOOGLE ============================== */

    /**
     * One part as MP3, base64, or null on any failure. Google gets the text, the
     * voice and its locale, and nothing else.
     */
    public static function synthesize( $text, $voice ) {
        if ( ! self::ready() || '' === trim( (string) $text ) || ! preg_match( '/^[a-z]{2}-[A-Z]{2}/', (string) $voice, $loc ) ) {
            return null;
        }
        $reply = wp_remote_post( self::API . 'text:synthesize', array(
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => array( 'Content-Type' => 'application/json', 'x-goog-api-key' => self::key() ),
            'body'    => wp_json_encode( array(
                'input'       => array( 'text' => (string) $text ),
                'voice'       => array( 'languageCode' => $loc[0], 'name' => (string) $voice ),
                'audioConfig' => array( 'audioEncoding' => 'MP3' ),
            ) ),
        ) );
        if ( is_wp_error( $reply ) || 200 !== (int) wp_remote_retrieve_response_code( $reply ) ) {
            return null;
        }
        $data  = json_decode( (string) wp_remote_retrieve_body( $reply ), true );
        $audio = is_array( $data ) ? ( $data['audioContent'] ?? '' ) : '';
        return is_string( $audio ) && '' !== $audio && preg_match( '#^[A-Za-z0-9+/=]+$#', $audio ) ? $audio : null;
    }

    /* =============================== THE ROUTE ============================ */

    /** The transient that counts one client: a keyed hash, never the address itself. */
    public static function client_key( $ip ) {
        return self::RATE_CLIENT . substr( hash_hmac( 'sha256', (string) $ip, self::secret() ), 0, 20 );
    }

    /** Counts one part against the client and the site, or says no. */
    private static function allow() {
        $client = self::client_key( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        $site   = (int) get_transient( self::RATE_SITE );
        $mine   = (int) get_transient( $client );
        if ( $site >= self::SITE_MAX || $mine >= self::CLIENT_MAX ) {
            return false;
        }
        set_transient( self::RATE_SITE, $site + 1, self::RATE_WINDOW );
        set_transient( $client, $mine + 1, self::RATE_WINDOW );
        return true;
    }

    /**
     * POST /speak {text, buttons, token, part}: one part of a signed answer, as audio.
     * After the answer, a closing line names the reply's buttons (1.29.0); their
     * labels are signed with it, so that line is the chat's words too.
     * The widget reads with the browser's voice on any answer but 200.
     *
     * @return array{status:int,body:array}
     */
    public static function speak( array $body ) {
        $no = function ( $status, $why ) {
            return array( 'status' => $status, 'body' => array( 'ok' => false, 'error' => $why ) );
        };
        if ( ! self::ready() ) {
            return $no( 503, 'Not set up' );
        }
        $text = $body['text'] ?? null;
        if ( ! is_string( $text ) || '' === trim( $text ) || mb_strlen( $text ) > self::MAX_ANSWER_CHARS ) {
            return $no( 400, 'Nothing to read' );
        }
        if ( preg_match( '/[\x1E\x1F]/', $text ) ) {
            return $no( 400, 'Nothing to read' );
        }
        $buttons = $body['buttons'] ?? array();
        if ( ! is_array( $buttons ) || count( $buttons ) > self::MAX_BUTTONS || array_values( $buttons ) !== $buttons ) {
            return $no( 400, 'Bad buttons' );
        }
        foreach ( $buttons as $b ) {
            if ( ! is_string( $b ) || '' === $b || mb_strlen( $b ) > self::MAX_BUTTON_CHARS || preg_match( '/[\x1E\x1F]/', $b ) ) {
                return $no( 400, 'Bad buttons' );
            }
        }
        if ( ! self::verify( $text, $body['token'] ?? null, $buttons ) ) {
            return $no( 403, 'Not a reply from the chat' );
        }
        // The answer decides the language, and the closing line follows it.
        $lang = self::language( self::spoken( $text ) );
        if ( '' === $lang ) {
            return $no( 422, 'Browser voice' );
        }
        $closing = self::closing( $buttons, $lang );
        $parts   = self::parts( self::spoken( '' === $closing ? $text : $text . "\n\n" . $closing, $lang ) );
        $i     = $body['part'] ?? 0;
        if ( ! is_int( $i ) || $i < 0 || $i >= count( $parts ) ) {
            return $no( 400, 'No such part' );
        }
        if ( ! self::allow() ) {
            return $no( 429, 'Too many' );
        }
        $audio = self::synthesize( $parts[ $i ], self::voice_for( $lang ) );
        if ( null === $audio ) {
            return $no( 502, 'Unavailable' );
        }
        return array( 'status' => 200, 'body' => array(
            'ok'    => true,
            'audio' => $audio,
            'type'  => 'audio/mpeg',
            'part'  => $i,
            'parts' => count( $parts ),
            // What every part says, so the browser's voice can finish if a later one fails.
            'texts' => $parts,
        ) );
    }

    /** A Preview in wp-admin: the sample for the voice's language, or null. */
    public static function preview( $voice ) {
        foreach ( array_keys( self::LOCALES ) as $lang ) {
            $clean = self::clean_voice( $lang, $voice );
            if ( '' !== $clean ) {
                return self::synthesize( self::SAMPLES[ $lang ], $clean );
            }
        }
        return null;
    }
}
