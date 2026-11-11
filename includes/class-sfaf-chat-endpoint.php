<?php
/**
 * The REST routes: asking a question, the stable widget script, and the indexer.
 *
 *   POST /wp-json/sfaf-chat/v1/ask        { question, history?, scope? }
 *   POST /wp-json/sfaf-chat/v1/starters   { url }, the starter questions for a page
 *   GET  /wp-json/sfaf-chat/v1/widget.js  the one URL the sfaf.org script tag uses
 *   GET  /wp-json/sfaf-chat/v1/index      token required, see SFAF_Chat_Indexer
 *
 * The ask route:
 *   - PRE-FILTERS the page index by keyword overlap so only relevant pages go to
 *     the model (the curated core always goes in full)
 *   - stamps today's date from the SERVER clock (the model never computes dates)
 *   - replays the recent thread so follow-up questions work
 *   - calls Gemini for a STRICT JSON answer, retrying brief failures on the SAME model
 *   - resolves the model's record id to a REAL url, and builds a card from stored
 *     Open Graph data (see LINK RESOLUTION, a safety mechanism, not a convenience)
 *   - swaps in a pinned answer verbatim where one applies
 *   - writes one de-identified, topic-tagged log row
 *
 * Response: { ok, answer, link:{label,url}|null,
 *             card:{type,title,subtitle,image_url,url,cta}|null, in_scope }
 *
 * NO NONCE, DELIBERATELY. The page cache would serve visitors a stale nonce. The
 * route is public by design; the widget switch and the origin check below are
 * what gate it. Every page reaches it the same way, cookies omitted.
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

    /*
     * BLOG POSTS are a lower tier than pages, ranked and sent separately so they can
     * never crowd a page out of RELATED PAGES. The best few go as an excerpt, taken
     * from where the question's words are densest in the post (not its opening);
     * the next few as titles only, so a near miss can still be pointed to. The model
     * should point to a post, not recite it. A post can be old, and on health
     * topics old can mean wrong. See also POST_DECLINE_FLAGS.
     */
    const POST_TOP_N          = 3;
    const POST_TITLES_N       = 7;
    const MAX_POST_WORDS_SENT = 120;
    const POST_KIND           = 'Blog post';

    // A reply the model declined (or marked crisis) never links a blog post.
    const POST_DECLINE_FLAGS = array( 'personal_advice', 'out_of_scope', 'crisis' );

    /*
     * RETRIES. Google answers 503 under load, often for a moment, so one failure must
     * not reach the visitor. Same model every time: there is deliberately no second
     * model, because a different model hasn't had its refusals tested.
     *
     * RETRY_BUDGET_SECONDS caps the whole exchange. KEEP IT BELOW REQUEST_TIMEOUT_MS IN
     * public/js/widget.js, or the widget gives up on a retry that was about to work.
     */
    const MAX_ATTEMPTS            = 3;
    const RETRY_BUDGET_SECONDS    = 40;
    const ATTEMPT_TIMEOUT_SECONDS = 20;
    const MIN_ATTEMPT_SECONDS     = 5;   // not worth starting one with less time left

    // Live events, read from the SFAF Calendar on this site. See SFAF_Chat_Events.
    // Does nothing where the calendar isn't installed.
    const EVENTS_ENABLED = true;

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
        register_rest_route( self::REST_NAMESPACE, '/starters', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'starters' ),
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

        // The two routes the widget calls from another site. Both get the CORS rules.
        $is_called = in_array( $route, array( '/' . self::REST_NAMESPACE . '/ask', '/' . self::REST_NAMESPACE . '/starters' ), true );
        $is_widget = '/' . self::REST_NAMESPACE . '/widget.js' === $route;

        if ( $is_called ) {
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

    /* =============================== STARTERS ============================= */

    /**
     * The starter questions for a page. Held to the same switch and origin rules as
     * ask, so "off" still means off. POST, not GET, so the page address stays out of
     * access logs, where it would sit next to an IP address. Nothing is logged here
     * and no model is called.
     */
    public function starters( WP_REST_Request $request ) {
        if ( ! SFAF_Chat_Settings::is_on() ) {
            return $this->fail( 'The assistant is not available.', 403 );
        }
        if ( ! self::origin_allowed( get_http_origin() ) ) {
            return $this->fail( 'Forbidden', 403 );
        }

        $body = json_decode( (string) $request->get_body(), true );
        $set  = SFAF_Chat_Starters::for_url( is_array( $body ) ? ( $body['url'] ?? '' ) : '' );

        // Active alerts ride along: this call is made each time the chat opens and is
        // never cached, so an alert shows from its first day and clears after its last.
        $alerts = array_map( function ( $a ) {
            return array( 'id' => $a['id'], 'text' => $a['text'] );
        }, SFAF_Chat_Alerts::active() );

        return new WP_REST_Response( array( 'ok' => true, 'questions' => $set['questions'], 'alerts' => $alerts ), 200 );
    }

    /* ================================== ASK =============================== */

    /** An error in the shape the widget already understands. */
    private function fail( $message, $status ) {
        return new WP_REST_Response( array( 'ok' => false, 'error' => $message ), $status );
    }

    public function ask( WP_REST_Request $request ) {

        // The switch. Off means refused for everybody, whatever the page does.
        if ( ! SFAF_Chat_Settings::is_on() ) {
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
            } else {
                $text = SFAF_Chat_Core::no_em_dash( $text );
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
        // Blog posts (type "post") are ranked separately, as a lower tier: see POST_TOP_N.
        $related     = array();
        $posts       = array();
        $post_titles = array();
        $query       = array();
        $index       = SFAF_Chat_Core::read_json( SFAF_Chat_Core::index_path() );
        $index_pages = array();
        if ( isset( $index['records'] ) && is_array( $index['records'] ) ) {
            $candidates      = array();
            $post_candidates = array();
            foreach ( $index['records'] as $r ) {
                if ( ! is_array( $r ) || ! isset( $r['id'], $r['url'] ) || ! $in_scope_source( $r )
                    || isset( $core_urls[ $url_key( $r['url'] ) ] ) ) {
                    continue;
                }
                if ( 'post' !== ( $r['type'] ?? 'page' ) ) {
                    $index_pages[] = $r;
                }
                if ( 'post' === ( $r['type'] ?? 'page' ) ) {
                    $post_candidates[] = $r;
                } else {
                    $candidates[] = $r;
                }
            }

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
            $ranked      = SFAF_Chat_Core::rank_records( $post_candidates, $query, self::POST_TOP_N + self::POST_TITLES_N );
            $posts       = array_slice( $ranked, 0, self::POST_TOP_N );
            $post_titles = array_slice( $ranked, self::POST_TOP_N );
        }

        // Tier 3: live events from the calendar on this site, matched to the question.
        // A failure here costs the events, never the answer.
        $events      = array();
        $candidates  = array();
        $calendar    = null;
        $events_note = '';
        if ( self::EVENTS_ENABLED ) {
            try {
                $live = SFAF_Chat_Events::for_question( $question, $user_turns );
            } catch ( Throwable $e ) {
                $live = array( 'events' => array(), 'candidates' => array(), 'calendar' => null, 'note' => '' );
            }
            $events      = array_values( array_filter( $live['events'], $in_scope_source ) );
            $candidates  = array_values( array_filter( (array) ( $live['candidates'] ?? array() ), $in_scope_source ) );
            $calendar    = ( is_array( $live['calendar'] ) && $in_scope_source( $live['calendar'] ) ) ? $live['calendar'] : null;
            $events_note = SFAF_Chat_Core::no_em_dash( (string) $live['note'] );
        }

        // The program's own page, found from the matched events' organizer: it has the
        // program's fuller calendar, so it is the link beside a list of its events.
        $program = array() !== $events
            ? self::program_page( (array) ( $live['organizers'] ?? array() ), $core, $index_pages )
            : null;

        // Every record the model can see, and therefore the ONLY ids it can link to.
        $record_map = array();
        foreach ( array_merge( $core, $related, $posts, $post_titles, $events, $candidates, null === $calendar ? array() : array( $calendar ), null === $program ? array() : array( $program ) ) as $r ) {
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
            return array_map( array( 'SFAF_Chat_Core', 'no_em_dash' ), $out );
        };

        // A post goes to the model as its summary and a short excerpt, never in full.
        // The excerpt is the stretch of the post most about the question.
        $query_terms = SFAF_Chat_Core::query_terms( $query );
        $for_post    = function ( array $r ) use ( $query_terms ) {
            $text = SFAF_Chat_Core::best_passage( (string) ( $r['text'] ?? '' ), $query_terms, self::MAX_POST_WORDS_SENT );
            return array_map( array( 'SFAF_Chat_Core', 'no_em_dash' ), array(
                'id'      => $r['id'],
                'title'   => (string) ( $r['title'] ?? '' ),
                'source'  => (string) ( $r['source'] ?? '' ),
                'type'    => 'post',
                'summary' => (string) ( $r['og']['description'] ?? '' ),
                'excerpt' => $text,
            ) );
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

        if ( array() !== $posts ) {
            $system_instruction .= "\n\n## BLOG POSTS (lower priority, pointer only)\n"
                . "SFAF blog posts that matched the question, best match first. They are NOT official or "
                . "current fact, and older ones may be out of date. Use one only when the sections above do "
                . "not cover the question, and then point to it rather than recite it. Each shows a summary "
                . "and the excerpt most related to the question, not the whole post.\n"
                . json_encode( array_map( $for_post, $posts ), $enc );

            if ( array() !== $post_titles ) {
                $system_instruction .= "\nMore posts that matched, titles only. Same rules; link one by id if "
                    . "its title is clearly what the visitor asked about.\n"
                    . json_encode( array_map( function ( array $r ) {
                        return array_map( array( 'SFAF_Chat_Core', 'no_em_dash' ), array(
                            'id'    => $r['id'],
                            'title' => (string) ( $r['title'] ?? '' ),
                            'type'  => 'post',
                        ) );
                    }, $post_titles ), $enc );
            }
        }

        $calendar_hint = null === $calendar ? 'point to the events page'
            : 'link the events calendar (id "' . SFAF_Chat_Events::CALENDAR_ID . '")';

        if ( null !== $program ) {
            $system_instruction .= "\n\n## PROGRAM PAGE\n"
                . "The events below are run by a program with its own page, which has its fuller calendar. "
                . "When you list these events, link this page (not a single event, and not the general "
                . "calendar).\n"
                . json_encode( array( array_map( array( 'SFAF_Chat_Core', 'no_em_dash' ), array(
                    'id'    => $program['id'],
                    'title' => (string) ( $program['title'] ?? '' ),
                ) ) ), $enc );
        }
        $list_link = null !== $program
            ? 'link the program page (id "' . $program['id'] . '")'
            : $calendar_hint;

        if ( array() !== $events ) {
            $system_instruction .= "\n\n## UPCOMING EVENTS (live)\n"
                . "Read from the SFAF events calendar just now and ALREADY filtered server-side: published, "
                . "not cancelled, not private, not yet over, soonest first. Treat every event listed here as "
                . "upcoming. Do not compare dates, do not filter, do not decide whether something has passed "
                . "or which is next: the list is already in order, and \"date\" is exactly as the calendar "
                . "gives it. {$events_note}\n"
                . "If none of these is clearly what the visitor asked about, do not offer one as if it were; "
                . "{$calendar_hint} instead. When several are listed, list them all, one short line each "
                . "(title and date), in the order given, and {$list_link}. When only one is listed, link "
                . "it by its id" . ( null !== $program ? ', or the program page' : '' ) . ".\n"
                . json_encode( array_map( $for_model, $events ), $enc );
        }

        if ( array() !== $candidates ) {
            $system_instruction .= "\n\n## OTHER UPCOMING EVENTS (not matched, judge for yourself)\n"
                . "The visitor asked about events on a subject, and no event's words matched it. These are "
                . "the next upcoming events, already filtered server-side like UPCOMING EVENTS, one per "
                . "series, with their calendar labels. Offer one ONLY if its title or labels clearly show it "
                . "is what the visitor asked about, and describe it by what the calendar says (for example "
                . "\"from Programa Latino\"). Never say an event is in a particular language, or for a "
                . "particular group, unless its title or labels say so. If none clearly fits, say you don't "
                . "see one and {$calendar_hint}. Never compare or work out dates.\n"
                . json_encode( array_map( $for_model, $candidates ), $enc );
        }

        if ( null !== $calendar ) {
            $system_instruction .= "\n\n## EVENTS CALENDAR\n"
                . ( array() === $events ? $events_note . "\n" : '' )
                . json_encode( array_map( $for_model, array( $calendar ) ), $enc );
        }

        // Closures from the calendar and staff alerts, when there is something current
        // or the visitor asks whether we're open. Empty otherwise, so it adds nothing.
        $system_instruction .= SFAF_Chat_Closures::status_section( $question, SFAF_Chat_Alerts::active() );

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
        $model    = SFAF_Chat_Credentials::model();
        $endpoint = $api_base . '/models/' . rawurlencode( $model ) . ':generateContent';

        $call = self::call_gemini( $endpoint, array(
            // The key travels in a header, not the query string: query strings are
            // the part most likely to end up in an access log or an error page.
            'headers' => array(
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => trim( $api_key ),
            ),
            'body'    => json_encode( $payload ),
        ) );

        $reply     = $call['reply'];
        $http_code = $call['http'];
        $attempts  = $call['attempts'];

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
                'model'    => $model,
                'attempts' => $attempts,
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
            $fallback = "I'm not sure about that one. Please reach out through our Contact page and we'll help.";
            SFAF_Chat_Log::write( array(
                'question' => $question,
                'answer'   => $fallback,
                'topic'    => 'other',
                'in_scope' => null,
                'flag'     => 'parse_error',
                'link'     => 'contact',
                'model'    => $model,
                'attempts' => $attempts,
            ) );

            return new WP_REST_Response( array(
                'ok'       => true,
                'answer'   => $fallback,
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

        // A declined or crisis reply never points to a blog post: a post is a story or a
        // perspective, not a next step, and it may be out of date. The answer still goes.
        if ( null !== $link_id && 'post' === ( $record_map[ $link_id ]['type'] ?? '' )
            && ( ! $answered_in_scope || in_array( $flag, self::POST_DECLINE_FLAGS, true ) ) ) {
            $link_id = null;
            $card    = null;
            $link    = null;
        }

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
            'answer'   => $answer,
            'topic'    => $topic,
            'in_scope' => $answered_in_scope,
            'flag'     => $flag,
            'link'     => $link_id,
            'pin'      => $pin_id,
            'model'    => $model,
            'attempts' => $attempts,
        ) );

        return new WP_REST_Response( array(
            'ok'       => true,
            'answer'   => $answer,
            'link'     => $link,
            'card'     => $card,
            'in_scope' => $answered_in_scope,
        ), 200 );
    }

    /** Worth another try? Overload and connection trouble, never a bad request. */
    private static function retryable( $reply, $http_code ) {
        return is_wp_error( $reply ) || 429 === $http_code || $http_code >= 500;
    }

    /**
     * Call Gemini, retrying on the same model with short backoff and jitter.
     *
     * Stops at MAX_ATTEMPTS, or sooner if the time left couldn't fit another useful
     * attempt. A 404 or other 4xx comes straight back: it will never get better.
     *
     * @return array{reply:mixed,http:int,attempts:int}
     */
    private static function call_gemini( $endpoint, array $request ) {
        $budget  = (float) apply_filters( 'sfaf_chat_retry_budget', self::RETRY_BUDGET_SECONDS );
        $start   = microtime( true );
        $attempt = 0;

        // Only matters on hosts that count waiting toward PHP's limit.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( self::RETRY_BUDGET_SECONDS + 20 );
        }

        while ( true ) {
            $attempt++;
            $left  = $budget - ( microtime( true ) - $start );
            $reply = wp_remote_post( $endpoint, array_merge( $request, array(
                'timeout' => max( 1, min( self::ATTEMPT_TIMEOUT_SECONDS, $left ) ),
            ) ) );
            $http  = is_wp_error( $reply ) ? 0 : (int) wp_remote_retrieve_response_code( $reply );

            if ( ! self::retryable( $reply, $http ) || $attempt >= self::MAX_ATTEMPTS ) {
                break;
            }

            // Doubling base plus jitter: about 0.5 to 1s, then 1 to 1.5s.
            $delay = 0.5 * ( 2 ** ( $attempt - 1 ) ) + mt_rand( 0, 500 ) / 1000;
            $left  = $budget - ( microtime( true ) - $start );
            if ( $left < $delay + self::MIN_ATTEMPT_SECONDS ) {
                break;
            }
            usleep( (int) ( $delay * 1000000 ) );
        }

        return array( 'reply' => $reply, 'http' => $http, 'attempts' => $attempt );
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

    /**
     * The page of the program that runs these events, by INFERENCE, no mapping:
     * a curated entry or indexed page whose title contains every word of the
     * organizer's name. Preferred, in order: the page whose address ends in the
     * name ("/strut/" for Strut), a title that starts with the name, a curated
     * entry over an indexed copy, then the shortest title. Organizers are tried
     * most relevant first. null when none is found: the calendar is linked then.
     */
    private static function program_page( array $organizers, array $core, array $pages ) {
        $pool = array();
        foreach ( $core as $r ) {
            $pool[] = array( $r, 1 );
        }
        foreach ( $pages as $r ) {
            $pool[] = array( $r, 0 );
        }

        foreach ( $organizers as $name ) {
            $name   = trim( (string) $name );
            $tokens = array_unique( SFAF_Chat_Core::tokenize( $name ) );
            if ( '' === $name || array() === $tokens ) {
                continue;
            }
            $slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( remove_accents( $name ) ) ), '-' );

            $best  = null;
            $score = null;
            foreach ( $pool as list( $r, $curated ) ) {
                $url = (string) ( $r['url'] ?? '' );
                if ( ! preg_match( '#^https://#i', $url ) || ! isset( $r['id'] ) ) {
                    continue;
                }
                $title = (string) ( $r['title'] ?? '' );
                $words = SFAF_Chat_Core::tokenize( $title );
                if ( array() !== array_diff( $tokens, $words ) ) {
                    continue;
                }
                $last = basename( trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
                $s    = ( $last === $slug ? 4 : 0 )
                    + ( 0 === stripos( $title, $name ) ? 2 : 0 )
                    + $curated
                    - count( $words ) / 100;
                if ( null === $score || $s > $score ) {
                    $best  = $r;
                    $score = $s;
                }
            }
            if ( null !== $best ) {
                return $best;
            }
        }
        return null;
    }

    private static function linkable( array $record_map, $id ) {
        return isset( $record_map[ $id ] )
            && preg_match( '#^https://#i', (string) ( $record_map[ $id ]['url'] ?? '' ) );
    }

    /* ============================ LINKS + CARDS =========================== */

    /** Plain link button: {label, url}. A blog post says so in its label. */
    private static function link_for( array $r ) {
        $label = SFAF_Chat_Core::no_em_dash( $r['title'] ?? $r['id'] );
        if ( 'post' === ( $r['type'] ?? '' ) ) {
            $label = self::POST_KIND . ': ' . $label;
        }
        return array(
            'label' => $label,
            'url'   => (string) $r['url'],
        );
    }

    /**
     * Card for card-worthy records (events and articles) from STORED fields only.
     * Returns null for ordinary pages, which get a plain link. A blog post is an
     * "article" card whose kind line reads "Blog post", so it is never mistaken for
     * an official page.
     */
    private static function card_for( array $r ) {
        $type = $r['type'] ?? 'page';
        if ( 'event' !== $type && 'post' !== $type ) {
            return null;
        }

        $og    = is_array( $r['og'] ?? null ) ? $r['og'] : array();
        $image = $og['image'] ?? ( $r['image'] ?? null );

        if ( 'event' === $type ) {
            $place    = (string) ( ( $r['location_short'] ?? '' ) ?: ( $r['location'] ?? '' ) );
            $subtitle = trim( implode( ' · ', array_filter( array( (string) ( $r['date'] ?? '' ), $place ) ) ) );
        } else {
            $subtitle = (string) ( $og['description'] ?? '' );
            if ( mb_strlen( $subtitle ) > 140 ) {
                $subtitle = rtrim( mb_substr( $subtitle, 0, 137 ) ) . '…';
            }
        }

        $subtitle = SFAF_Chat_Core::no_em_dash( $subtitle );

        return array(
            'type'      => 'event' === $type ? 'event' : 'article',
            'title'     => SFAF_Chat_Core::no_em_dash( ( $og['title'] ?? null ) ?: ( $r['title'] ?? '' ) ),
            'subtitle'  => '' !== $subtitle ? $subtitle : null,
            // SFAF domains only (sfaf.org, *.sfaf.org, tweaker.org), else no image.
            'image_url' => SFAF_Chat_Core::is_sfaf_url( $image ) ? $image : null,
            'url'       => (string) $r['url'],
            'cta'       => 'event' === $type ? 'Event details' : 'Read the blog post',
            'kind'      => 'event' === $type ? 'Event' : self::POST_KIND,
        );
    }
}
