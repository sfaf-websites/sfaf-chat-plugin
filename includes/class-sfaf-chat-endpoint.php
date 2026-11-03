<?php
/**
 * The REST routes: asking a question, the stable widget script, and the indexer.
 *
 *   POST /wp-json/sfaf-chat/v1/ask        { question, history?, scope? }
 *   GET  /wp-json/sfaf-chat/v1/widget.js  the one URL the sfaf.org script tag uses
 *   GET  /wp-json/sfaf-chat/v1/index      token required, see SFAF_Chat_Indexer
 *
 * The ask route:
 *   - PRE-FILTERS the page index by keyword overlap so only relevant pages go to
 *     the model (the curated core always goes in full)
 *   - stamps today's date from the SERVER clock (the model never computes dates)
 *   - replays the recent thread so follow-up questions work
 *   - calls Gemini for a STRICT JSON answer
 *   - resolves the model's record id to a REAL url, and builds a card from stored
 *     Open Graph data (see LINK RESOLUTION, a safety mechanism, not a convenience)
 *   - swaps in a pinned answer verbatim where one applies
 *   - writes one de-identified, topic-tagged log row
 *
 * Response: { ok, answer, link:{label,url}|null,
 *             card:{type,title,subtitle,image_url,url,cta}|null, in_scope }
 *
 * NO NONCE ON THE PUBLIC PATH, DELIBERATELY. The page cache would serve visitors a
 * stale nonce. The route is public by design; the widget switch and the origin
 * check below are what gate it. Admins-only mode does use a nonce, because pages
 * for logged-in users are not cached.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Endpoint {

    const REST_NAMESPACE = 'sfaf-chat/v1';

    // Max characters accepted for a single question (basic abuse guard).
    const MAX_QUESTION_LEN = 600;

    // How many prior messages of the thread to carry as context.
    // KEEP THIS EQUAL TO `MAX_HISTORY` IN public/js/widget.js. If they drift, the
    // widget and the server disagree about what the model actually saw.
    const MAX_HISTORY_TURNS = 6;

    // Knowledge pre-filter: how many indexed pages (tier 2) to send per question,
    // and how much of each page's text. The curated core is always sent in full.
    const PREFILTER_TOP_N       = 12;
    const MAX_RECORD_WORDS_SENT = 500;

    // Live events feed. NOT ACTIVE YET. See fetch_upcoming_events().
    const EVENTS_ENABLED = false;

    // Topic vocabulary the model must choose from (also listed in
    // data/system-prompt.txt, keep the two in step). Anything else logs as "other".
    const TOPICS = array(
        'testing', 'prep-pep', 'hiv-care', 'sexual-health', 'appointments', 'pharmacy',
        'hepatitis-c', 'trans-health', 'substance-use', 'harm-reduction', 'overdose',
        'mental-health', 'support-groups', 'aging', 'black-health', 'latine-health',
        'housing-benefits', 'insurance-billing', 'locations-hours', 'contact', 'donate',
        'volunteer', 'events', 'newsletter', 'about', 'careers', 'news', 'off-topic', 'other',
    );

    // Scopes: which record `source` tags a bot may answer from. v1 uses "all".
    // A future narrower bot (e.g. Cycle to Zero) would add an entry like
    //   'ctz' => array( 'cycletozero.org' ),
    // and the widget on that site would send scope:"ctz". Unknown scopes fall back
    // to "all". null = every source.
    const SCOPES = array( 'all' => null );

    const ALLOWED_FLAGS = array( 'personal_advice', 'out_of_scope', 'crisis', 'unanswered' );

    public function register() {
        add_action( 'rest_api_init', array( $this, 'routes' ) );
        add_filter( 'rest_pre_serve_request', array( $this, 'finish' ), 20, 3 );
    }

    public function routes() {
        register_rest_route( self::REST_NAMESPACE, '/ask', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'ask' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( self::REST_NAMESPACE, '/widget\.js', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'widget_placeholder' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( self::REST_NAMESPACE, '/index', array(
            'methods'             => 'GET',
            'callback'            => array( 'SFAF_Chat_Indexer', 'handle' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /* ================================ ORIGINS ============================= */

    /**
     * The sites the widget may run on. The site's own origin is included, which on
     * production is resources.sfaf.org again and adds nothing; it is what lets a
     * local test copy of WordPress talk to itself.
     */
    public static function allowed_origins() {
        $origins = array(
            'https://www.sfaf.org',
            'https://sfaf.org',
            'https://resources.sfaf.org',
        );
        $home = wp_parse_url( home_url() );
        if ( ! empty( $home['scheme'] ) && ! empty( $home['host'] ) ) {
            $origins[] = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
        }
        return array_values( array_unique( $origins ) );
    }

    public static function origin_allowed( $origin ) {
        return in_array( (string) $origin, self::allowed_origins(), true );
    }

    /**
     * The last word on this namespace's headers, after core's CORS pass.
     *
     * Core's rest_send_cors_headers() reflects ANY origin and adds
     * Allow-Credentials. Left alone that would hand every website a green light,
     * so it is undone here, and only an allowlisted origin gets a header back.
     *
     * Also sends the raw JavaScript for the widget route, since the REST server
     * would otherwise JSON-encode it.
     */
    public function finish( $served, $result, $request ) {
        $route = $request->get_route();
        if ( 0 !== strpos( $route, '/' . self::REST_NAMESPACE . '/' ) ) {
            return $served;
        }

        header_remove( 'Access-Control-Allow-Origin' );
        header_remove( 'Access-Control-Allow-Credentials' );
        header_remove( 'Access-Control-Allow-Methods' );
        header_remove( 'Access-Control-Allow-Headers' );
        header_remove( 'Access-Control-Expose-Headers' );

        // Never a server-side page cache copy: the switch must take effect.
        header( 'X-LiteSpeed-Cache-Control: no-cache' );
        do_action( 'litespeed_control_set_nocache', 'sfaf-chat' );

        $is_ask    = '/' . self::REST_NAMESPACE . '/ask' === $route;
        $is_widget = '/' . self::REST_NAMESPACE . '/widget.js' === $route;

        if ( $is_ask ) {
            $origin = get_http_origin();
            if ( self::origin_allowed( $origin ) ) {
                header( 'Access-Control-Allow-Origin: ' . $origin );
                header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
                header( 'Access-Control-Allow-Headers: Content-Type' );
            }
            header( 'Vary: Origin', false );
        }

        if ( $is_widget && 'GET' === $request->get_method() ) {
            header( 'Content-Type: application/javascript; charset=utf-8' );
            header( 'Cache-Control: public, max-age=' . SFAF_Chat_Widget::BROWSER_CACHE_SECONDS );
            header( 'X-Robots-Tag: noindex' );
            echo SFAF_Chat_Widget::script_body(); // phpcs:ignore -- JavaScript, not HTML.
            return true;
        }

        if ( ! $is_widget ) {
            header( 'Cache-Control: no-store' );
            header( 'X-Robots-Tag: noindex' );
        }
        return $served;
    }

    /** The real body is written by finish(). This only gives the route a handler. */
    public function widget_placeholder() {
        return new WP_REST_Response( null, 200 );
    }

    /* ================================== ASK =============================== */

    /** An error in the shape the widget already understands. */
    private function fail( $message, $status ) {
        return new WP_REST_Response( array( 'ok' => false, 'error' => $message ), $status );
    }

    public function ask( WP_REST_Request $request ) {

        // The switch. Off means refused for everybody, whatever the page does.
        if ( ! SFAF_Chat_Settings::mode_allows_ask() ) {
            return $this->fail( 'The assistant is not available.', 403 );
        }

        // CORS only stops browsers from reading a reply. This stops the call.
        if ( ! self::origin_allowed( get_http_origin() ) ) {
            return $this->fail( 'Forbidden', 403 );
        }

        $api_key = SFAF_Chat_Credentials::get( 'api_key' );
        if ( '' === trim( $api_key ) ) {
            return $this->fail( 'The assistant is not configured.', 500 );
        }

        /* --------------------------- READ INPUT --------------------------- */

        $body = json_decode( (string) $request->get_body(), true );

        $question = is_array( $body ) ? trim( (string) ( $body['question'] ?? '' ) ) : '';

        if ( '' === $question ) {
            return $this->fail( 'No question provided', 400 );
        }
        if ( mb_strlen( $question ) > self::MAX_QUESTION_LEN ) {
            $question = mb_substr( $question, 0, self::MAX_QUESTION_LEN );
        }

        // Optional scope; unknown values fall back to "all".
        $scope = is_array( $body ) && is_string( $body['scope'] ?? null ) ? strtolower( trim( $body['scope'] ) ) : 'all';
        if ( ! array_key_exists( $scope, self::SCOPES ) ) {
            $scope = 'all';
        }
        $scope_sources = self::SCOPES[ $scope ];

        /* -------------------------- CONVERSATION -------------------------- */
        /*
         * The widget holds the thread in memory and sends the recent slice with
         * every question. Nothing about a thread is stored server-side. Trim to
         * the last MAX_HISTORY_TURNS messages, then map to Gemini's role
         * vocabulary: "assistant" to "model"; anything else to "user".
         */
        $history = array();
        if ( is_array( $body ) && isset( $body['history'] ) && is_array( $body['history'] ) ) {
            $history = array_slice( $body['history'], -self::MAX_HISTORY_TURNS );
        }

        $contents   = array();
        $user_turns = array();
        foreach ( $history as $msg ) {
            if ( ! is_array( $msg ) ) {
                continue;
            }
            $text = trim( (string) ( $msg['text'] ?? '' ) );
            if ( '' === $text ) {
                continue;
            }
            if ( mb_strlen( $text ) > self::MAX_QUESTION_LEN ) {
                $text = mb_substr( $text, 0, self::MAX_QUESTION_LEN );
            }
            $role = ( (string) ( $msg['role'] ?? '' ) === 'assistant' ) ? 'model' : 'user';
            if ( 'user' === $role ) {
                $user_turns[] = $text;
            }

            $contents[] = array( 'role' => $role, 'parts' => array( array( 'text' => $text ) ) );
        }

        // The live question always goes last.
        $contents[] = array( 'role' => 'user', 'parts' => array( array( 'text' => $question ) ) );

        /* --------------------- LOAD PROMPT + KNOWLEDGE -------------------- */

        $system_prompt = @file_get_contents( SFAF_Chat_Core::prompt_path() );
        $kb            = SFAF_Chat_Core::read_json( SFAF_Chat_Core::kb_path() );

        if ( false === $system_prompt || ! isset( $kb['entries'] ) || ! is_array( $kb['entries'] ) ) {
            return $this->fail( 'Server config error (missing prompt or knowledge base).', 500 );
        }

        // Is this record's source allowed under the request's scope?
        $in_scope_source = function ( array $r ) use ( $scope_sources ) {
            return null === $scope_sources || in_array( $r['source'] ?? '', $scope_sources, true );
        };

        // Comparable form of a URL, so an indexed copy of a curated page is skipped.
        $url_key = function ( $u ) {
            return rtrim( strtolower( (string) preg_replace( '#^https?://#i', '', (string) $u ) ), '/' );
        };

        // Tier 1: curated core, always sent in full.
        $core = array_values( array_filter( $kb['entries'], function ( $e ) use ( $in_scope_source ) {
            return is_array( $e ) && isset( $e['id'] ) && $in_scope_source( $e );
        } ) );
        $core_urls = array();
        foreach ( $core as $e ) {
            $core_urls[ $url_key( $e['url'] ?? '' ) ] = true;
        }

        // Tier 2: indexed pages, pre-filtered by keyword overlap with the question.
        // Blog posts (type "post") are indexed but excluded from answering for now.
        $related = array();
        $index   = SFAF_Chat_Core::read_json( SFAF_Chat_Core::index_path() );
        if ( isset( $index['records'] ) && is_array( $index['records'] ) ) {
            $candidates = array_values( array_filter( $index['records'], function ( $r ) use ( $in_scope_source, $core_urls, $url_key ) {
                return is_array( $r ) && isset( $r['id'], $r['url'] )
                    && ( $r['type'] ?? 'page' ) !== 'post'
                    && $in_scope_source( $r )
                    && ! isset( $core_urls[ $url_key( $r['url'] ) ] );
            } ) );

            // The live question counts fully; the last two user turns count half,
            // so "what about walk-ins?" still pulls in pages about the earlier subject.
            $query = array();
            foreach ( array_slice( $user_turns, -2 ) as $t ) {
                foreach ( SFAF_Chat_Core::tokenize( $t ) as $tok ) {
                    $query[ $tok ] = max( $query[ $tok ] ?? 0, 0.5 );
                }
            }
            foreach ( SFAF_Chat_Core::tokenize( $question ) as $tok ) {
                $query[ $tok ] = 1.0;
            }

            $related = SFAF_Chat_Core::rank_records( $candidates, $query, self::PREFILTER_TOP_N );
        }

        // Tier 3: live events, inactive until EVENTS_ENABLED.
        $events = array();
        if ( self::EVENTS_ENABLED ) {
            $events_url = (string) apply_filters( 'sfaf_chat_events_endpoint', '' );
            if ( '' !== $events_url ) {
                $events = array_values( array_filter( self::fetch_upcoming_events( $events_url ), $in_scope_source ) );
            }
        }

        // Every record the model can see, and therefore the ONLY ids it can link to.
        $record_map = array();
        foreach ( array_merge( $core, $related, $events ) as $r ) {
            $record_map[ $r['id'] ] = $r;
        }

        /* ------------------------ CONTEXT ASSEMBLY ------------------------ */
        /*
         * The model sees ids, titles and text, never URLs. It can only refer to a
         * record by id, and this class turns that id into a link afterwards.
         *
         * Today's date comes from the SERVER clock and is handed to the model as a
         * fact. The model has no reliable sense of "now" and must never infer or
         * compute a date itself.
         */
        $for_model = function ( array $r ) {
            $text  = (string) ( $r['content'] ?? $r['text'] ?? '' );
            $words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
            if ( count( $words ) > self::MAX_RECORD_WORDS_SENT ) {
                $text = implode( ' ', array_slice( $words, 0, self::MAX_RECORD_WORDS_SENT ) ) . ' …';
            }
            $out = array(
                'id'     => $r['id'],
                'title'  => (string) ( $r['title'] ?? '' ),
                'source' => (string) ( $r['source'] ?? '' ),
                'type'   => (string) ( $r['type'] ?? 'page' ),
            );
            foreach ( array( 'date', 'location' ) as $k ) {   // events only
                if ( ! empty( $r[ $k ] ) ) {
                    $out[ $k ] = (string) $r[ $k ];
                }
            }
            $out['content'] = $text;
            return $out;
        };

        $enc   = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $today = wp_date( 'l, F j, Y', null, SFAF_Chat_Core::timezone() );

        $system_instruction = $system_prompt
            . self::special_instructions_block()
            . "\n\n## TODAY'S DATE\n"
            . "Today is {$today}. This date is supplied by the server and is authoritative. "
            . "Never infer, guess, or calculate dates yourself.\n\n"
            . "## KNOWLEDGE BASE (curated)\n"
            . json_encode( array_map( $for_model, $core ), $enc );

        if ( array() !== $related ) {
            $system_instruction .= "\n\n## RELATED PAGES (site search results for this question)\n"
                . "Additional SFAF pages that matched the question. Same rules apply: answer only "
                . "from what they say, and link by id.\n"
                . json_encode( array_map( $for_model, $related ), $enc );
        }

        if ( array() !== $events ) {
            $system_instruction .= "\n\n## UPCOMING EVENTS (live)\n"
                . "This feed is live and has ALREADY been filtered server-side to future events only. "
                . "Treat every event listed here as upcoming. Do not compare dates, do not filter, "
                . "do not decide whether something has passed.\n"
                . json_encode( array_map( $for_model, $events ), $enc );
        }

        /* --------------------------- CALL GEMINI -------------------------- */

        $payload = array(
            'systemInstruction' => array(
                'parts' => array( array( 'text' => $system_instruction ) ),
            ),
            'contents'          => $contents,
            'generationConfig'  => array(
                'temperature'      => 0.2,
                'responseMimeType' => 'application/json',
                'responseSchema'   => array(
                    'type'       => 'object',
                    'properties' => array(
                        'answer'   => array( 'type' => 'string' ),
                        'link_id'  => array( 'type' => 'string', 'nullable' => true ),
                        'in_scope' => array( 'type' => 'boolean' ),
                        'flag'     => array( 'type' => 'string', 'nullable' => true ),
                        'topic'    => array( 'type' => 'string' ),
                    ),
                    'required'   => array( 'answer', 'in_scope', 'topic' ),
                ),
            ),
        );

        $api_base = rtrim( (string) apply_filters( 'sfaf_chat_api_base', 'https://generativelanguage.googleapis.com/v1beta' ), '/' );
        $endpoint = $api_base . '/models/' . rawurlencode( SFAF_Chat_Credentials::model() ) . ':generateContent';

        $reply = wp_remote_post( $endpoint, array(
            // The key travels in a header, not the query string: query strings are
            // the part most likely to end up in an access log or an error page.
            'headers' => array(
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => trim( $api_key ),
            ),
            'body'    => json_encode( $payload ),
            'timeout' => 25,
        ) );

        $http_code = is_wp_error( $reply ) ? 0 : (int) wp_remote_retrieve_response_code( $reply );

        if ( is_wp_error( $reply ) || $http_code >= 400 ) {
            // Log the failure, but never the key and never the upstream body
            // (which can echo request details back).
            SFAF_Chat_Log::write( array(
                'question' => $question,
                'topic'    => null,
                'in_scope' => null,
                'flag'     => 'api_error',
                'link'     => null,
                'http'     => $http_code,
            ) );
            return $this->fail( 'The assistant is unavailable right now. Please try again shortly.', 502 );
        }

        /* --------------------- PARSE + LINK RESOLUTION -------------------- */

        $data       = json_decode( (string) wp_remote_retrieve_body( $reply ), true );
        $model_text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $parsed     = json_decode( (string) $model_text, true );

        // Fail soft: the model returned something unparseable. Give a safe, friendly
        // answer pointing at Contact rather than an error the visitor can't act on.
        if ( ! is_array( $parsed ) || ! isset( $parsed['answer'] ) ) {
            SFAF_Chat_Log::write( array(
                'question' => $question,
                'topic'    => 'other',
                'in_scope' => null,
                'flag'     => 'parse_error',
                'link'     => 'contact',
            ) );

            return new WP_REST_Response( array(
                'ok'       => true,
                'answer'   => "I'm not sure about that one. Please reach out through our Contact page and we'll help.",
                'link'     => isset( $record_map['contact'] ) ? self::link_for( $record_map['contact'] ) : null,
                'card'     => null,
                'in_scope' => false,
            ), 200 );
        }

        /*
         * LINK RESOLUTION: SAFETY MECHANISM. Do not "simplify" this.
         *
         * The model never emits a URL. It returns only an `id` of a record it was
         * shown in this request, and this block resolves that id against those
         * records. An id that is unknown, or whose record has no https url, is
         * DROPPED SILENTLY: the answer still goes out, just without a link. There
         * is therefore no path by which a hallucinated, mistyped, or off-site URL
         * can reach a visitor. This is a health org, and a wrong link on a health
         * page is a real harm, not a cosmetic bug.
         *
         * Cards are built the same way: from the resolved record's STORED Open
         * Graph fields, never from model output, and the image is kept only if it
         * is on an SFAF domain.
         */
        $link      = null;
        $card      = null;
        $link_id   = null;
        $chosen_id = $parsed['link_id'] ?? null;

        if ( is_string( $chosen_id ) && self::linkable( $record_map, $chosen_id ) ) {
            $link_id = $chosen_id;
            $card    = self::card_for( $record_map[ $chosen_id ] );
            $link    = null === $card ? self::link_for( $record_map[ $chosen_id ] ) : null;
        }

        // Constrain the flag to the known vocabulary; anything else becomes null.
        $flag = $parsed['flag'] ?? null;
        $flag = ( is_string( $flag ) && in_array( $flag, self::ALLOWED_FLAGS, true ) ) ? $flag : null;

        // Constrain the topic the same way; anything else becomes "other".
        $topic = is_string( $parsed['topic'] ?? null ) ? strtolower( trim( $parsed['topic'] ) ) : '';
        $topic = in_array( $topic, self::TOPICS, true ) ? $topic : 'other';

        $answered_in_scope = (bool) ( $parsed['in_scope'] ?? true );
        $answer            = (string) $parsed['answer'];

        /*
         * PINNED ANSWERS. Only after the model has judged the message, so a crisis
         * or a personal question that happens to contain the trigger words still
         * gets the guarded reply. See SFAF_Chat_Pinned.
         */
        $pin_id = null;
        $pin    = SFAF_Chat_Pinned::match( $question );
        if ( null !== $pin && ! SFAF_Chat_Pinned::blocks_pin( $flag, $answered_in_scope ) ) {
            $pin_id            = (string) $pin['id'];
            $answer            = (string) $pin['answer'];
            $answered_in_scope = true;
            $flag              = null;
            $card              = null;
            $link              = null;
            $link_id           = null;

            // A pin links only to the record staff chose, by the same id rule.
            $pin_link = (string) ( $pin['link_id'] ?? '' );
            if ( '' !== $pin_link && self::linkable( $record_map, $pin_link ) ) {
                $link_id = $pin_link;
                $link    = self::link_for( $record_map[ $pin_link ] );
            }
        }

        SFAF_Chat_Log::write( array(
            'question' => $question,
            'topic'    => $topic,
            'in_scope' => $answered_in_scope,
            'flag'     => $flag,
            'link'     => $link_id,
            'pin'      => $pin_id,
        ) );

        return new WP_REST_Response( array(
            'ok'       => true,
            'answer'   => $answer,
            'link'     => $link,
            'card'     => $card,
            'in_scope' => $answered_in_scope,
        ), 200 );
    }

    /** Staff instructions, added after the base rules. Empty adds nothing at all. */
    private static function special_instructions_block() {
        $text = trim( (string) SFAF_Chat_Settings::get( 'special_instructions' ) );
        if ( '' === $text ) {
            return '';
        }
        return "\n\n## SPECIAL INSTRUCTIONS FROM SFAF STAFF\n"
            . "Follow these as well. They add to the rules above and never relax the scope, "
            . "safety, crisis, or output format rules.\n"
            . $text;
    }

    private static function linkable( array $record_map, $id ) {
        return isset( $record_map[ $id ] )
            && preg_match( '#^https://#i', (string) ( $record_map[ $id ]['url'] ?? '' ) );
    }

    /* ============================ LINKS + CARDS =========================== */

    /** Plain link button: {label, url}. */
    private static function link_for( array $r ) {
        return array(
            'label' => (string) ( $r['title'] ?? $r['id'] ),
            'url'   => (string) $r['url'],
        );
    }

    /**
     * Card for card-worthy records (events and articles) from STORED fields only.
     * Returns null for ordinary pages, which get a plain link.
     *
     * In v1 no card-worthy record reaches the model (blog posts are filtered out
     * and the events feed is off), so this always returns null today; the path is
     * wired for when either is turned on.
     */
    private static function card_for( array $r ) {
        $type = $r['type'] ?? 'page';
        if ( 'event' !== $type && 'post' !== $type ) {
            return null;
        }

        $og    = is_array( $r['og'] ?? null ) ? $r['og'] : array();
        $image = $og['image'] ?? ( $r['image'] ?? null );

        if ( 'event' === $type ) {
            $subtitle = trim( implode( ' · ', array_filter( array( (string) ( $r['date'] ?? '' ), (string) ( $r['location'] ?? '' ) ) ) ) );
        } else {
            $subtitle = (string) ( $og['description'] ?? '' );
            if ( mb_strlen( $subtitle ) > 140 ) {
                $subtitle = rtrim( mb_substr( $subtitle, 0, 137 ) ) . '…';
            }
        }

        return array(
            'type'      => 'event' === $type ? 'event' : 'article',
            'title'     => (string) ( ( $og['title'] ?? null ) ?: ( $r['title'] ?? '' ) ),
            'subtitle'  => '' !== $subtitle ? $subtitle : null,
            // SFAF domains only (sfaf.org, *.sfaf.org, tweaker.org), else no image.
            'image_url' => SFAF_Chat_Core::is_sfaf_url( $image ) ? $image : null,
            'url'       => (string) $r['url'],
            'cta'       => 'event' === $type ? 'Event details' : 'Read article',
        );
    }

    /* ================================ EVENTS ============================== */
    /**
     * NOT ACTIVE YET: EVENTS_ENABLED is false.
     *
     * Waiting on the events calendar to expose a machine-readable JSON endpoint.
     *
     * Expected feed: a JSON array (or {"events": [...]}) of objects with
     *   title, date, location, url (or registration_url), description, image (optional)
     * Each becomes a record of type "event", so the model can reference it by id
     * and the endpoint can render it as a card. Events without an https url are
     * skipped.
     *
     * DIVISION OF LABOUR. The calendar, or this function, does ALL date and tag
     * filtering server-side, and hands the model a list that is already correct.
     * The model must never compare dates, never decide whether an event has
     * passed, and never compute "next" anything.
     */
    private static function fetch_upcoming_events( $endpoint ) {
        $reply = wp_remote_get( $endpoint, array( 'timeout' => 4 ) );   // short: never stall an answer

        if ( is_wp_error( $reply ) || (int) wp_remote_retrieve_response_code( $reply ) >= 400 ) {
            return array();
        }

        $decoded = json_decode( (string) wp_remote_retrieve_body( $reply ), true );
        $list    = is_array( $decoded['events'] ?? null ) ? $decoded['events'] : $decoded;
        if ( ! is_array( $list ) ) {
            return array();
        }

        $out = array();
        foreach ( array_slice( $list, 0, 20 ) as $e ) {
            if ( ! is_array( $e ) ) {
                continue;
            }
            $url = (string) ( $e['url'] ?? $e['registration_url'] ?? '' );
            if ( ! preg_match( '#^https://#i', $url ) || empty( $e['title'] ) ) {
                continue;
            }
            $out[] = array(
                'id'       => 'evt-' . substr( sha1( $url . '|' . ( $e['date'] ?? '' ) ), 0, 10 ),
                'title'    => (string) $e['title'],
                'url'      => $url,
                // Tagged with the FEED's site, not the registration link's host
                // (which may be a ticketing service).
                'source'   => 'resources.sfaf.org',
                'type'     => 'event',
                'date'     => (string) ( $e['date'] ?? '' ),
                'location' => (string) ( $e['location'] ?? '' ),
                'content'  => (string) ( $e['description'] ?? '' ),
                'og'       => array(
                    'title'       => (string) $e['title'],
                    'description' => (string) ( $e['description'] ?? '' ),
                    'image'       => isset( $e['image'] ) ? (string) $e['image'] : null,
                ),
            );
        }
        return $out;
    }
}
