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
    /*
     * OTHER SITES (tweaker.org, Cycle to Zero): pages from sites other than SFAF's
     * own (sfaf.org and its subdomains), ranked apart from RELATED PAGES so they never
     * crowd out an SFAF page, and named by site so the model can point to them. Since
     * 1.16.0 the answer is in SFAF's own voice ("You can read more on our Tweaker.org
     * page."), never narrated as the site's. See SFAF_Chat_Indexer::is_own().
     */
    const OTHER_TOP_N         = 4;

    /*
     * SITE FOR THIS SUBJECT. When the question names a subject another site is
     * dedicated to (SFAF_Chat_Indexer 'subjects': Cycle to Zero, meth), up to this
     * many of that site's pages go in their own section ahead of RELATED PAGES.
     * The current question decides; a short follow-up ("how do I sign up?") also
     * looks at the previous one. See subject_sources().
     */
    const SUBJECT_TOP_N       = 5;
    const FOLLOW_UP_WORDS     = 8;

    /*
     * DONATE BUTTON. Only on a donation question that was answered (topic "donate",
     * in scope, no flag), never on a refusal or a crisis. It links to the donate page
     * with the chat's UTM tags; the chat never touches payment. The page is the
     * curated "donate" record's address, so it changes with the knowledge base.
     */
    const DONATE_FALLBACK = 'https://donate.sfaf.org/campaign/773029/donate';
    const DONATE_UTM      = array( 'utm_source' => 'chat', 'utm_medium' => 'assistant', 'utm_campaign' => 'sfaf_chat' );

    const POST_TOP_N          = 3;
    const POST_TITLES_N       = 7;
    const MAX_POST_WORDS_SENT = 120;
    const POST_KIND           = 'Blog post';

    // A reply the model declined (or marked crisis) never links a blog post.
    const POST_DECLINE_FLAGS = array( 'personal_advice', 'out_of_scope', 'crisis' );

    /*
     * PAGES FOR A KIND OF QUESTION (1.19.0). Leadership, board and job questions each
     * have one page that answers them, so when the live question is that kind, the page
     * goes first in RELATED PAGES whatever the ranking says. For leadership and board
     * ('no_posts'), blog posts are then left out altogether: an old post about an
     * appointment must never answer "who is the CEO". Whole words, any case, the live
     * question only. Paths are on www.sfaf.org. See topic_pages().
     * Deliberately absent: bare "president" (news about the US President) and the
     * Board of Supervisors, school board and the like, where blog posts matter.
     */
    const TOPIC_PAGES = array(
        'leadership' => array(
            'path'     => '/about/team/',
            'no_posts' => true,
            're'       => '/\b(ceo|cfo|coo|chief|vice president|director|directora|leadership|liderazgo|senior staff|management team|head of|in charge|who (leads|runs|heads|manages))\b/i',
        ),
        'board'      => array(
            'path'     => '/about/dev-board-of-directors/',
            'no_posts' => true,
            're'       => '/(?<!school )\b(board(?! of (supervisors|education|health))|directors|trustees?|junta directiva)\b/i',
        ),
        'careers'    => array(
            'path'     => '/about/careers/',
            'no_posts' => false,
            're'       => '/\b(jobs?|careers?|hiring|employment|open positions?|positions? (open|available)|vacanc(y|ies)|work (for|at) (sfaf|you|the foundation)|empleos?|vacantes?)\b/i',
        ),
    );

    /*
     * JOBS (1.19.0). Our Careers page loads its openings from Greenhouse with a script,
     * so the page as indexed has none. A Greenhouse board page for SFAF (added by hand
     * on the Indexing screen) has them. It is folded into the Careers page before the
     * model sees anything: the model reads the openings as part of our own page, and the
     * link is always the Careers page, never Greenhouse.
     */
    const CAREERS_URL    = 'https://www.sfaf.org/about/careers/';
    const JOBS_BOARD_RE  = '#^https?://(job-)?boards(\.eu)?\.greenhouse\.io/sfaf(?:[/?\#]|$)#i';
    const JOBS_BOARD_JUNK = array(
        '/Create a Job Alert.*?Create alert/isu',
        '/Search\s+Department\s+Select\.*\s*Office\s+Select\.*/iu',
        '/Powered by Greenhouse/iu',
        '/(Jobs|Current openings) at San Francisco AIDS Foundation/iu',
    );

    /*
     * HARM REDUCTION LABEL (1.19.0). When the model says its answer gives substance-use
     * dosing, amounts or safer-use how-to (the "harm_reduction" field), this line goes
     * first, like the "Blog post" label: added so the reader knows what the content is.
     * It is a label, not a gate. Nothing is ever taken out because of it, and it stays
     * whatever else the reply carries. The model never writes it; the line is taken back
     * out of earlier turns, so the model doesn't copy it into answers that aren't dosing.
     */
    const HARM_REDUCTION_LINE = array(
        'en' => 'This is general harm reduction information, not medical advice.',
        'es' => 'Esta es información general de reducción de daños, no consejo médico.',
    );

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
        register_rest_route( self::REST_NAMESPACE, '/contact', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'contact' ),
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

        // The routes the widget calls from another site. All get the CORS rules.
        $is_called = in_array( $route, array( '/' . self::REST_NAMESPACE . '/ask', '/' . self::REST_NAMESPACE . '/starters', '/' . self::REST_NAMESPACE . '/contact' ), true );
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

    /* ================================ CONTACT ============================= */

    /**
     * "Contact our team": the same switch and origin rules as ask. Sends one email
     * through Postmark and keeps nothing. See SFAF_Chat_Contact.
     */
    public function contact( WP_REST_Request $request ) {
        if ( ! SFAF_Chat_Settings::is_on() ) {
            return $this->fail( 'The assistant is not available.', 403 );
        }
        if ( ! self::origin_allowed( get_http_origin() ) ) {
            return $this->fail( 'Forbidden', 403 );
        }
        $body   = json_decode( (string) $request->get_body(), true );
        $result = SFAF_Chat_Contact::submit( is_array( $body ) ? $body : array() );
        $status = (int) $result['status'];
        unset( $result['status'] );
        return new WP_REST_Response( $result, $status );
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

        // The page this was asked on, as the widget reports it, cleaned. For routing a
        // contact request and for the Dashboard; never sent to the model.
        $page = SFAF_Chat_Core::clean_page_url( is_array( $body ) ? ( $body['page'] ?? '' ) : '' );
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
                $text = SFAF_Chat_Core::no_em_dash( self::without_harm_reduction_line( $text ) );
                if ( '' === $text ) {
                    continue;
                }
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
        $related       = array();
        $other_pages   = array();
        $subject_pages = array();
        $posts         = array();
        $post_titles = array();
        $query       = array();
        $index       = SFAF_Chat_Core::read_json( SFAF_Chat_Core::index_path() );
        $index_pages = array();
        if ( isset( $index['records'] ) && is_array( $index['records'] ) ) {
            $candidates       = array();
            $post_candidates  = array();
            $other_candidates = array();
            $jobs_boards      = array();
            foreach ( $index['records'] as $r ) {
                if ( ! is_array( $r ) || ! isset( $r['id'], $r['url'] ) || ! $in_scope_source( $r )
                    || isset( $core_urls[ $url_key( $r['url'] ) ] ) ) {
                    continue;
                }
                if ( preg_match( self::JOBS_BOARD_RE, (string) $r['url'] ) ) {
                    $jobs_boards[] = $r;   // folded into the Careers page below
                    continue;
                }
                if ( ! SFAF_Chat_Indexer::is_own( $r['source'] ?? '' ) ) {
                    if ( 'post' !== ( $r['type'] ?? 'page' ) ) {
                        $other_candidates[] = $r;
                    }
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

            // A question about a subject another site is dedicated to (Cycle to Zero,
            // meth and Tweaker): that site's pages go in their own section, ahead of
            // ours, so a page of ours that only mentions the subject can't bury them.
            $subject_sources = self::subject_sources( $question, $user_turns );
            $mine            = array();
            foreach ( $other_candidates as $k => $r ) {
                if ( in_array( (string) ( $r['source'] ?? '' ), $subject_sources, true ) ) {
                    $mine[] = $r;
                    unset( $other_candidates[ $k ] );
                }
            }
            $subject_pages = SFAF_Chat_Core::rank_records( $mine, $query, self::SUBJECT_TOP_N );
            if ( array() === $subject_pages && array() !== $mine ) {
                // Nothing scored ("what is it?"): the site's home page, its shortest address.
                usort( $mine, function ( $a, $b ) {
                    return strlen( (string) $a['url'] ) - strlen( (string) $b['url'] );
                } );
                $subject_pages = array( $mine[0] );
            }

            $candidates = self::fold_jobs_boards( $candidates, $jobs_boards );

            $related     = SFAF_Chat_Core::rank_records( $candidates, $query, self::PREFILTER_TOP_N );
            $other_pages = SFAF_Chat_Core::rank_records( array_values( $other_candidates ), $query, self::OTHER_TOP_N );
            $ranked      = SFAF_Chat_Core::rank_records( $post_candidates, $query, self::POST_TOP_N + self::POST_TITLES_N );
            $posts       = array_slice( $ranked, 0, self::POST_TOP_N );
            $post_titles = array_slice( $ranked, self::POST_TOP_N );

            // Leadership, board and job questions: their page first, whatever the ranking.
            list( $first, $no_posts ) = self::topic_pages( $question, $candidates );
            if ( array() !== $first ) {
                $ids     = array_column( $first, 'id' );
                $related = array_slice( array_merge( $first, array_values( array_filter( $related, function ( $r ) use ( $ids ) {
                    return ! in_array( $r['id'], $ids, true );
                } ) ) ), 0, self::PREFILTER_TOP_N );
            }
            if ( $no_posts ) {
                $posts       = array();
                $post_titles = array();
            }
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
        foreach ( array_merge( $core, $related, $subject_pages, $other_pages, $posts, $post_titles, $events, $candidates, null === $calendar ? array() : array( $calendar ), null === $program ? array() : array( $program ) ) as $r ) {
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

        if ( array() !== $subject_pages ) {
            $names = array_values( array_unique( array_map( function ( $r ) {
                return SFAF_Chat_Indexer::site_name( (string) ( $r['source'] ?? '' ) );
            }, $subject_pages ) ) );
            $system_instruction .= "\n\n## SITE FOR THIS SUBJECT\n"
                . 'This question is about a subject that ' . implode( ' and ', $names ) . ' is dedicated to. '
                . "Its pages below are usually the best source and the best link for it, better than a page of "
                . "our main site that only mentions the subject. When one of them answers the question, answer "
                . "in our own voice and link it, as the rules for our other sites say. Use a main-site page "
                . "instead only when it answers something these don't, such as one of our services.\n"
                . json_encode( array_map( function ( array $r ) use ( $for_model ) {
                    return $for_model( $r ) + array( 'site' => SFAF_Chat_Indexer::site_name( (string) ( $r['source'] ?? '' ) ) );
                }, $subject_pages ), $enc );
        }

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

        if ( array() !== $other_pages ) {
            $system_instruction .= "\n\n## OTHER SITES (our sites other than the main one, lower priority)\n"
                . "Pages from our other sites, each named in \"site\". Use one only when the question is about "
                . "that site's subject, or our main pages don't cover it. Answer in our own voice, then point to "
                . "the page and link it, for example \"You can read more on our Tweaker.org page.\" Never open "
                . "with the source (\"Tweaker's site says...\"), and don't copy the page's tone.\n"
                . json_encode( array_map( function ( array $r ) use ( $for_model ) {
                    return $for_model( $r ) + array( 'site' => SFAF_Chat_Indexer::site_name( (string) ( $r['source'] ?? '' ) ) );
                }, $other_pages ), $enc );
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
                . "{$calendar_hint} instead. When several are listed, list them all as a bulleted list, one "
                . "line each (title and date), in the order given, and {$list_link}. When only one is listed, link "
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
        $system_instruction .= self::contact_section();
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
                        // For the "Contact our team" button: see SFAF_Chat_Contact::OFFER_RULE.
                        'wants_person'   => array( 'type' => 'boolean' ),
                        'fully_answered' => array( 'type' => 'boolean' ),
                        // A team from CONTACT FORM, or null (1.17.0).
                        'contact_team'   => array( 'type' => 'string', 'nullable' => true ),
                        // Dosing or safer-use specifics: see HARM_REDUCTION_LINE (1.19.0).
                        'harm_reduction' => array( 'type' => 'boolean' ),
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
                'page'     => $page,
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
                'page'     => $page,
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

        // The model's judgment for the contact button. Missing or not a boolean reads
        // as "no reason to offer it", so a reply that skips them never adds a button.
        $wants_person   = true === ( $parsed['wants_person'] ?? null );
        $fully_answered = false !== ( $parsed['fully_answered'] ?? true );
        // Only a name from the configured list counts; SFAF_Chat_Contact::resolve() checks.
        $contact_team = is_string( $parsed['contact_team'] ?? null ) ? mb_substr( $parsed['contact_team'], 0, 100 ) : '';

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
            $fully_answered    = true;   // approved wording for exactly this question
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

        // The harm reduction label goes first, after any pin, and whatever the flag: it
        // says what the content is, it never decides whether the content is shown.
        if ( true === ( $parsed['harm_reduction'] ?? null ) ) {
            $answer = self::with_harm_reduction_line( $answer );
        }

        // Donate button: a donation question, answered. It replaces a plain link to
        // the same page, so the visitor sees one way to give, not two.
        $donate = null;
        if ( 'donate' === $topic && $answered_in_scope && null === $flag ) {
            $donate_url = (string) ( $record_map['donate']['url'] ?? self::DONATE_FALLBACK );
            if ( ! preg_match( '#^https://#i', $donate_url ) ) {
                $donate_url = self::DONATE_FALLBACK;
            }
            $donate = array( 'label' => 'Donate', 'url' => add_query_arg( self::DONATE_UTM, $donate_url ) );
            if ( null !== $link && strtok( (string) $link['url'], '?' ) === strtok( $donate_url, '?' ) ) {
                $link = null;
            }
        }

        SFAF_Chat_Log::write( array(
            'question' => $question,
            'answer'   => $answer,
            'page'     => $page,
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
            // "Contact our team": set up, and by OFFER_RULE; routed to the model's team.
            'contact'  => SFAF_Chat_Contact::offer( $topic, $flag, $page, $answer, $wants_person, $fully_answered, $contact_team ),
            'donate'   => $donate,
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
    /**
     * CONTACT FORM (1.17.0): only while the form can really be offered, so the model
     * never promises a button that won't appear. Says what the form does, so the
     * model offers it instead of saying it can't pass a message on, and lists the
     * teams it may route to. Absent otherwise, which keeps the request unchanged.
     */
    private static function contact_section() {
        if ( ! SFAF_Chat_Contact::ready() ) {
            return '';
        }
        $teams = SFAF_Chat_Contact::teams_for_model();
        $out   = "\n\n## CONTACT FORM\n"
            . "We can take a message for a team right here in the chat. When the system shows the contact "
            . "button under your reply, the visitor leaves a name, an email and, if they like, a phone number "
            . "and a note, and it is emailed to the right team with this chat. So never say you can't pass a "
            . "message on, can't connect them with our staff, or that there is no way to reach someone from "
            . "the chat.\n"
            . "- When they want to reach a person (\"who can I contact?\", \"can I talk to someone?\", \"how "
            . "do I get in touch with the Stonewall team?\", \"can I contact them here?\"), set \"wants_person\" "
            . "to true and say they can send a message to the team here in the chat, using the button below. "
            . "If the knowledge gives a phone number for that team or service, give it too, as the quickest "
            . "way to reach them; they can choose.\n"
            . "- Don't offer it on an ordinary question you fully answered, and don't set \"wants_person\" "
            . "just because your answer mentions a phone number.\n";
        if ( array() !== $teams ) {
            $out .= "- Set \"contact_team\" to the team below that handles what they want to reach us about, by "
                . "the subject of the question, not the page they are on. Write its name exactly as given. Use "
                . "null when none clearly fits; it then goes to our general team.\n"
                . wp_json_encode( array_map( function ( $t ) {
                    return array_map( array( 'SFAF_Chat_Core', 'no_em_dash' ), $t );
                }, $teams ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        } else {
            $out .= "- Set \"contact_team\" to null.\n";
        }
        return $out;
    }

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
     * Sites whose subject this question is about. The question itself decides; a
     * short one that names no subject ("how do I sign up?") is a follow-up, so the
     * previous question counts. A longer one has moved on, and the thread doesn't.
     */
    /**
     * The pages for this kind of question (TOPIC_PAGES), found among our indexed pages,
     * and whether blog posts stay out. A kind whose page isn't in the index adds nothing
     * and keeps posts, so the prompt's fallback (a post can name a leader) still works.
     *
     * @return array{0: array[], 1: bool}
     */
    private static function topic_pages( $question, array $pages ) {
        $first    = array();
        $no_posts = false;
        foreach ( self::TOPIC_PAGES as $kind ) {
            if ( ! preg_match( $kind['re'], (string) $question ) ) {
                continue;
            }
            foreach ( $pages as $r ) {
                if ( self::is_sfaf_path( (string) ( $r['url'] ?? '' ), $kind['path'] ) ) {
                    $first[]  = $r;
                    $no_posts = $no_posts || $kind['no_posts'];
                    break;
                }
            }
        }
        return array( $first, $no_posts );
    }

    /** Is this address www.sfaf.org (or sfaf.org) at exactly this path? */
    private static function is_sfaf_path( $url, $path ) {
        $parts = wp_parse_url( $url );
        $host  = strtolower( (string) ( $parts['host'] ?? '' ) );
        return in_array( $host, array( 'www.sfaf.org', 'sfaf.org' ), true )
            && rtrim( (string) ( $parts['path'] ?? '' ), '/' ) === rtrim( $path, '/' );
    }

    /**
     * JOBS: the Greenhouse board's openings go into our Careers page record, first,
     * so the model reads them as our own page and the only link is the Careers page.
     * With no Careers page indexed, the board stands in for it under the Careers
     * address, as our page. Greenhouse's own words (alerts, filters, "Powered by")
     * are taken out. Nothing changes when no board is indexed.
     */
    private static function fold_jobs_boards( array $candidates, array $boards ) {
        if ( array() === $boards ) {
            return $candidates;
        }
        $openings = array();
        foreach ( $boards as $b ) {
            $text = (string) ( $b['text'] ?? $b['content'] ?? '' );
            foreach ( self::JOBS_BOARD_JUNK as $re ) {
                $text = (string) preg_replace( $re, ' ', $text );
            }
            $text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
            if ( '' !== $text ) {
                $openings[] = $text;
            }
        }
        $lead = array() === $openings ? '' : 'Our current job openings: ' . implode( ' ', $openings ) . ' ';

        foreach ( $candidates as $i => $r ) {
            if ( self::is_sfaf_path( (string) $r['url'], wp_parse_url( self::CAREERS_URL, PHP_URL_PATH ) ) ) {
                $candidates[ $i ]['text'] = $lead . (string) ( $r['text'] ?? $r['content'] ?? '' );
                unset( $candidates[ $i ]['content'] );
                return $candidates;
            }
        }

        $b            = $boards[0];
        $candidates[] = array(
            'id'       => (string) $b['id'],
            'title'    => 'Careers',
            'url'      => self::CAREERS_URL,
            'source'   => 'sfaf.org',
            'type'     => 'page',
            'keywords' => array( 'careers', 'jobs', 'hiring', 'employment' ),
            'og'       => array(),
            'text'     => trim( $lead ),
        );
        return $candidates;
    }

    /**
     * The label, first, in the answer's language. Not added twice: an answer that
     * already starts with either wording keeps just that one.
     */
    private static function with_harm_reduction_line( $answer ) {
        $answer = (string) $answer;
        $bare   = self::without_harm_reduction_line( $answer );
        $line   = self::looks_spanish( $bare ) ? self::HARM_REDUCTION_LINE['es'] : self::HARM_REDUCTION_LINE['en'];
        return '' === $bare ? $line : $line . "\n\n" . $bare;
    }

    /** The answer with either wording of the label taken off its start. */
    private static function without_harm_reduction_line( $text ) {
        $text = ltrim( (string) $text );
        foreach ( self::HARM_REDUCTION_LINE as $line ) {
            if ( 0 === mb_stripos( $text, $line ) ) {
                return ltrim( mb_substr( $text, mb_strlen( $line ) ) );
            }
        }
        return $text;
    }

    /**
     * Rough, and only used to pick the label's language: more common Spanish words than
     * English ones, or Spanish punctuation. A tie reads as English.
     */
    private static function looks_spanish( $text ) {
        $t = ' ' . mb_strtolower( (string) $text ) . ' ';
        if ( preg_match( '/[¿¡ñ]/u', $t ) ) {
            return true;
        }
        $es = preg_match_all( '/\b(el|la|los|las|de|del|que|y|es|en|para|con|una?|por|puede|nuestra?|más|dosis)\b/u', $t );
        $en = preg_match_all( '/\b(the|and|is|of|to|for|with|you|your|it|can|our|more|dose|in)\b/u', $t );
        return $es > $en;
    }

    private static function subject_sources( $question, array $earlier_user_turns ) {
        $now = SFAF_Chat_Indexer::subject_sources( $question );
        if ( array() !== $now || array() === $earlier_user_turns ) {
            return $now;
        }
        $words = preg_split( '/\s+/u', trim( (string) $question ), -1, PREG_SPLIT_NO_EMPTY );
        if ( count( $words ) > self::FOLLOW_UP_WORDS ) {
            return array();
        }
        return SFAF_Chat_Indexer::subject_sources( (string) end( $earlier_user_turns ) );
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
        } elseif ( isset( $r['source'] ) && 'event' !== ( $r['type'] ?? '' ) && ! SFAF_Chat_Indexer::is_own( $r['source'] ) ) {
            // Another site says so on the button: "Cycle to Zero: Registration", unless
            // the title already starts with the site's name.
            $site = SFAF_Chat_Indexer::site_name( (string) $r['source'] );
            if ( 0 !== stripos( $label, $site ) ) {
                $label = $site . ': ' . $label;
            }
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
