<?php
/**
 * Page indexer (knowledge tier 2).
 *
 * An external scheduler calls, repeatedly, on a schedule:
 *     GET /wp-json/sfaf-chat/v1/index        with header  X-Index-Token: <token>
 *  or GET /wp-json/sfaf-chat/v1/index?token=<token>
 *
 * What it does:
 *   1. Reads each site's sitemap. A sitemap index is followed into every child
 *      sitemap it lists, for every site. A sitemap that fails, is not a sitemap,
 *      or lists nothing is recorded as that site's fetch problem.
 *   2. Queues only pages that are new, or whose sitemap <lastmod> changed since
 *      they were last indexed. Pages gone from the sitemap are dropped. Each site
 *      has its own lane and the queue takes them in turn, so a big site (sfaf.org)
 *      can never hold the others back until it is finished.
 *   3. Each call processes ONE BATCH (BATCH_SIZE pages, or TIME_BUDGET seconds from
 *      the start of the call, sitemap reading included, whichever comes first) and
 *      reports {done, remaining}. The scheduler simply calls it repeatedly; once
 *      the run is done, further calls are cheap no-ops until MIN_RUN_INTERVAL has
 *      passed and a new run starts. A change to the list of sites, or to how runs
 *      are planned (PLAN_VERSION), starts a new run at once.
 *   4. For each page: fetch, strip nav/header/footer/scripts to clean text, pull
 *      og:title / og:description / og:image, write one record.
 *
 * WHERE THINGS LIVE, and why. A plugin update replaces the plugin folder, so:
 *   - the index is uploads/sfaf-chat/page-index.json (public page text only)
 *   - run state is the sfaf_chat_index_state option
 *   - the lock is uploads/sfaf-chat/indexer.lock
 *
 * Records use the same schema as data/knowledge-base.json:
 *   id, title, url, source, type, keywords, og{title,description,image}, text, lastmod, indexed, cap
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Indexer {

    const STATE_OPTION = 'sfaf_chat_index_state';

    const BATCH_SIZE       = 20;          // pages per web call
    const TIME_BUDGET      = 20;          // seconds per web call (stay under host limits)
    const MIN_RUN_INTERVAL = 12 * 3600;   // after a run completes, wait this long before the next
    const FETCH_TIMEOUT    = 15;
    const MAX_PAGE_BYTES   = 3 * 1024 * 1024;
    const MAX_TEXT_WORDS   = 2000;        // per page
    const MAX_POST_WORDS   = 900;         // per post: enough to match on and point to, never the whole post
    const POLITE_DELAY_MS  = 250;         // pause between page fetches
    const USER_AGENT       = 'SFAF-Chat-Indexer/1.0 (+https://resources.sfaf.org/chat/)';
    const THIN_PAGE_WORDS  = 60;          // below this, a page's JSON-LD supplies its text
    const PLAN_VERSION     = 2;           // bump when planning changes, so the next call plans afresh

    /**
     * Sites to index. Each record is tagged with 'source' so the endpoint can
     * scope answers per site. The sfaf_chat_index_sites filter may replace this.
     *
     *   source   tag stored on every record (and used by the `scope` param)
     *   sitemap  sitemap or sitemap index URL
     *   exclude  URL regexes to skip (test/sample/junk pages)
     *   types    URL regex => record type. Default 'page'. 'post' records are
     *            stored truncated and are NOT used for answers yet.
     */
    private static function sites() {
        $sites = array(
            array(
                'source'  => 'sfaf.org',
                'name'    => 'SFAF',
                'sitemap' => 'https://www.sfaf.org/sitemap_index.xml',
                'exclude' => array(
                    '#/sample-page/#',
                    '#/test-#',
                    '#/1080-2/#',
                    '#/marcom/#',
                    '#/dev-board-of-directors/#',
                    '#/resources/resource-title-goes-here/#',
                ),
                'types'   => array(
                    '#^https://www\.sfaf\.org/news/.+#' => 'post',
                ),
            ),
            /*
             * OTHER SITES. Their pages are answered from as another site's, never in
             * SFAF's voice: see SFAF_Chat_Endpoint, OTHER SITES. Probed 2026-11-11.
             *
             * tweaker.org (checked 2026-11-13): Yoast's sitemap_index.xml, an index
             * whose one child, page-sitemap.xml, lists the pages. wp-sitemap.xml now
             * only redirects there, and sitemap.xml is a stale static file listing
             * the home page alone, so neither is read.
             */
            array(
                'source'   => 'tweaker.org',
                'name'     => 'Tweaker',
                'sitemap'  => 'https://tweaker.org/sitemap_index.xml',
                // Subjects this site is dedicated to: see subject_sources().
                'subjects' => array( 'tweaker', 'meth', 'crystal meth', 'methamphetamine', 'chemsex', 'party and play', 'pnp' ),
            ),
            array(
                'source'   => 'cycletozero.org',
                'name'     => 'Cycle to Zero',
                'sitemap'  => 'https://2027.cycletozero.org/sitemap_index.xml',
                'subjects' => array( 'cycle to zero', 'cycletozero', 'ctz' ),
                'exclude' => array(
                    '#/home-3/#',                    // a duplicate of the home page
                    '#/title-of-upcoming-ride/#',    // a placeholder event
                ),
            ),
        );
        return (array) apply_filters( 'sfaf_chat_index_sites', $sites );
    }

    /** Is this source SFAF's own (sfaf.org or a subdomain, such as donate.sfaf.org)? */
    public static function is_own( $source ) {
        $source = strtolower( (string) $source );
        return 'sfaf.org' === $source || '.sfaf.org' === substr( $source, -9 );
    }

    /** A source's display name: "Cycle to Zero", or the host when it has none. */
    public static function site_name( $source ) {
        foreach ( self::sites() as $site ) {
            if ( ( $site['source'] ?? '' ) === $source && '' !== (string) ( $site['name'] ?? '' ) ) {
                return (string) $site['name'];
            }
        }
        return self::is_own( $source ) ? 'SFAF' : (string) $source;
    }

    /**
     * Sites whose subject this text names, in config order: a site's 'subjects'
     * are whole words or phrases ("cycle to zero", "meth"), matched without case or
     * punctuation, so "meth" never matches "method" and "tweaker.org" matches
     * "tweaker". Only sites other than SFAF's own carry subjects.
     *
     * @return string[] sources
     */
    public static function subject_sources( $text ) {
        $norm = ' ' . trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( (string) $text ) ) ) . ' ';
        $out  = array();
        foreach ( self::sites() as $site ) {
            foreach ( (array) ( $site['subjects'] ?? array() ) as $phrase ) {
                $p = trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( (string) $phrase ) ) );
                if ( '' !== $p && false !== strpos( $norm, ' ' . $p . ' ' ) ) {
                    $out[] = (string) $site['source'];
                    break;
                }
            }
        }
        return $out;
    }

    /** Every configured site, for the Indexing screen. */
    public static function configured_sites() {
        $out = array();
        foreach ( self::sites() as $site ) {
            $out[] = array(
                'source'   => (string) ( $site['source'] ?? '' ),
                'name'     => self::site_name( (string) ( $site['source'] ?? '' ) ),
                'sitemaps' => self::site_sitemaps( $site ),
            );
        }
        return $out;
    }

    /** REST handler. Same checks and same JSON as the standalone indexer. */
    public static function handle( WP_REST_Request $request ) {
        $expected = SFAF_Chat_Credentials::get( 'index_token' );
        if ( strlen( $expected ) < SFAF_Chat_Credentials::MIN_TOKEN_LENGTH ) {
            return self::out( array( 'ok' => false, 'error' => 'The index token is not configured (needs 24+ characters).' ), 503 );
        }

        $given = (string) ( $request->get_header( 'x-index-token' ) ?: $request->get_param( 'token' ) );
        if ( ! hash_equals( $expected, $given ) ) {
            return self::out( array( 'ok' => false, 'error' => 'Forbidden' ), 403 );
        }

        if ( ! class_exists( 'DOMDocument' ) || ! function_exists( 'curl_init' ) ) {
            return self::out( array( 'ok' => false, 'error' => 'PHP needs the dom and curl extensions.' ), 500 );
        }

        $lock = @fopen( SFAF_Chat_Core::data_dir() . '/indexer.lock', 'c' );
        if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
            return self::out( array( 'ok' => true, 'busy' => true, 'note' => 'Another indexer run is in progress.' ) );
        }

        $result = self::run( null !== $request->get_param( 'force' ) );

        flock( $lock, LOCK_UN );
        fclose( $lock );
        return self::out( $result );
    }

    /**
     * Fetch every live manual URL now, for the Indexing screen's button, so a page
     * added by hand can be answered from without waiting for the scheduler.
     * Same lock as a run. Returns [fetched, ok, messages].
     */
    public static function index_extra_now() {
        if ( ! class_exists( 'DOMDocument' ) || ! function_exists( 'curl_init' ) ) {
            return array( 0, 0, array( 'PHP needs the dom and curl extensions.' ) );
        }
        $lock = @fopen( SFAF_Chat_Core::data_dir() . '/indexer.lock', 'c' );
        if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
            return array( 0, 0, array( 'The indexer is running right now. Try again in a minute.' ) );
        }

        $index   = SFAF_Chat_Core::read_json( SFAF_Chat_Core::index_path() ) ?? array();
        $records = array();
        foreach ( (array) ( $index['records'] ?? array() ) as $r ) {
            if ( isset( $r['url'] ) ) {
                $records[ $r['url'] ] = $r;
            }
        }

        $done = 0;
        $good = 0;
        $msgs = array();
        foreach ( array_slice( SFAF_Chat_Extra_Urls::live(), 0, 20 ) as $url ) {
            $item   = array( 'url' => $url, 'lastmod' => null, 'source' => SFAF_Chat_Extra_Urls::source_for( $url ), 'type' => 'page', 'extra' => true );
            $rec    = self::index_page( $item );
            $status = SFAF_Chat_Extra_Urls::report( $url, is_array( $rec ) ? '' : (string) $rec, is_array( $rec ) ? $rec['title'] : '' );
            $done++;
            if ( is_array( $rec ) ) {
                $records[ $url ] = $rec;
                $good++;
            } else {
                $msgs[] = $url . ': ' . $rec;
                if ( 'dead' === $status ) {
                    unset( $records[ $url ] );
                }
            }
        }

        SFAF_Chat_Core::write_json_atomic( SFAF_Chat_Core::index_path(), array(
            'generated' => gmdate( 'c' ),
            'sources'   => $index['sources'] ?? array(),
            'count'     => count( $records ),
            'records'   => array_values( $records ),
        ) );
        flock( $lock, LOCK_UN );
        fclose( $lock );
        return array( $done, $good, $msgs );
    }

    /** Last run's state, for the Indexing screen. */
    public static function state() {
        $state = get_option( self::STATE_OPTION, array() );
        return is_array( $state ) ? $state : array();
    }

    private static function out( array $payload, $status = 200 ) {
        return new WP_REST_Response( $payload, $status );
    }

    /** One batch. */
    private static function run( $force ) {
        $call_start = microtime( true );
        $sites      = self::sites();
        $signature  = self::plan_signature( $sites );

        $state = get_option( self::STATE_OPTION, array() );
        $state = is_array( $state ) ? $state : array();
        $index = SFAF_Chat_Core::read_json( SFAF_Chat_Core::index_path() ) ?? array();

        // Records keyed by URL internally; written out as a list.
        $records = array();
        foreach ( (array) ( $index['records'] ?? array() ) as $r ) {
            if ( isset( $r['url'] ) ) {
                $records[ $r['url'] ] = $r;
            }
        }

        $queue       = (array) ( $state['queue'] ?? array() );
        $started     = false;
        $plan_errors = array();

        // The sites, or the way a run is planned, changed since the last plan: the
        // queue in hand was built without them, so plan again now rather than
        // finishing it and then waiting MIN_RUN_INTERVAL. Nothing is lost: what was
        // still queued is still new or changed, so the new plan queues it again.
        $replan = ( $state['plan_sig'] ?? '' ) !== $signature;

        $run_due = $force
            || empty( $state['run_completed'] )
            || ( time() - (int) $state['run_completed'] ) >= self::MIN_RUN_INTERVAL;

        if ( $replan || ( array() === $queue && $run_due && empty( $state['run_started_open'] ) ) ) {
            list( $queue, $records, $plan_errors, $summary ) = self::plan_run( $sites, $records );
            $state = array(
                'run_started'      => time(),
                'run_started_open' => true,
                'run_completed'    => $state['run_completed'] ?? null,
                'plan_sig'         => $signature,
                'queued'           => count( $queue ),
                'queue'            => $queue,
                'sites'            => $summary,
                // Kept apart from page errors, so a run's many page errors can never
                // push a site's sitemap problem off the list.
                'sitemap_errors'   => $plan_errors,
                'errors'           => array(),
            );
            $started = true;
        }

        $processed = 0;
        $errors    = array();
        // Counted from the start of the call, so reading every sitemap and then a
        // full batch can't add up to more than one request should take.
        $deadline  = $call_start + self::TIME_BUDGET;

        while ( array() !== $queue ) {
            if ( $processed >= self::BATCH_SIZE || microtime( true ) >= $deadline ) {
                break;
            }
            $item = array_shift( $queue );

            $rec = self::index_page( $item );
            if ( is_array( $rec ) ) {
                $records[ $item['url'] ] = $rec;
            } else {
                // Keep any previous version of the page rather than losing it to a
                // transient failure; it'll be retried on the next run.
                $errors[] = array( 'url' => $item['url'], 'error' => $rec, 'source' => (string) ( $item['source'] ?? '' ) );
            }
            if ( ! empty( $item['extra'] ) ) {
                $status = SFAF_Chat_Extra_Urls::report( $item['url'], is_array( $rec ) ? '' : (string) $rec, is_array( $rec ) ? $rec['title'] : '' );
                if ( 'dead' === $status ) {
                    unset( $records[ $item['url'] ] );
                }
            }
            $processed++;

            if ( array() !== $queue ) {
                usleep( self::POLITE_DELAY_MS * 1000 );
            }
        }

        $done = ( array() === $queue );
        if ( $done && ! empty( $state['run_started_open'] ) ) {
            $state['run_completed']    = time();
            $state['run_started_open'] = false;
        }
        $state['queue']  = $queue;
        $state['errors'] = array_slice( array_merge( (array) ( $state['errors'] ?? array() ), $errors ), -50 );

        $saved = true;
        if ( $processed > 0 || $started ) {
            $saved = SFAF_Chat_Core::write_json_atomic( SFAF_Chat_Core::index_path(), array(
                'generated' => gmdate( 'c' ),
                'sources'   => array_values( array_unique( array_column( $sites, 'source' ) ) ),
                'count'     => count( $records ),
                'records'   => array_values( $records ),
            ) );
        }
        update_option( self::STATE_OPTION, $state, false );

        return array(
            'ok'             => $saved,
            'started_run'    => $started,
            'processed'      => $processed,
            'remaining'      => count( $queue ),
            'done'           => $done,
            'records'        => count( $records ),
            'errors'         => $errors,
            'sitemap_errors' => $plan_errors,
        ) + ( $saved ? array() : array( 'error' => 'Could not write page-index.json (check folder permissions).' ) );
    }

    /**
     * What a plan depends on: the sites and their sitemaps, exclusions and types,
     * and PLAN_VERSION. When it changes, the next call plans a new run at once.
     */
    private static function plan_signature(array $sites): string
    {
        $parts = [];
        foreach ($sites as $site) {
            $parts[] = [
                (string)($site['source'] ?? ''),
                self::site_sitemaps($site),
                (array)($site['exclude'] ?? []),
                (array)($site['types'] ?? []),
            ];
        }
        return md5(self::PLAN_VERSION . '|' . serialize($parts));
    }

    /** A site's sitemap URLs, from 'sitemaps' or 'sitemap'. */
    private static function site_sitemaps(array $site): array
    {
        return array_values(array_filter(array_map('strval', (array)($site['sitemaps'] ?? [$site['sitemap'] ?? '']))));
    }

    /**
     * Read every site's sitemap(s) and decide what to (re)index.
     * Returns [queue, records-with-removals-applied, errors, per-site summary].
     *
     * Each error carries its site's source, so the Indexing screen shows it on that
     * site's row. The summary says, per site, how many sitemaps were read, how many
     * pages they listed and how many were queued, so a 0 can always be explained.
     */
    private static function plan_run(array $sites, array $records): array
    {
        $lanes   = [];
        $errors  = [];
        $seen    = [];
        $summary = [];

        foreach ($sites as $site) {
            $source   = (string)($site['source'] ?? '');
            $sitemaps = self::site_sitemaps($site);
            $urls     = [];
            $errs     = [];
            $read     = 0;
            $ok       = $sitemaps !== [];
            foreach ($sitemaps as $sitemap) {
                $ok = self::read_sitemap($sitemap, $urls, $errs, 0, $read) && $ok;
            }
            // Sitemaps that load but list no page at all are never taken at face
            // value: that is reported, and none of the site's pages are dropped.
            if ($urls === []) {
                $ok = false;
                if ($errs === []) {
                    $errs[] = ['url' => $sitemaps[0] ?? '', 'error' => 'the sitemaps list no pages'];
                }
            }
            foreach ($errs as $e) {
                $errors[] = $e + ['source' => $source];
            }

            $lane = [];
            foreach ($urls as $url => $lastmod) {
                if (self::matches_any($url, (array)($site['exclude'] ?? []))) {
                    continue;
                }
                $seen[$url] = true;

                $type = 'page';
                foreach ((array)($site['types'] ?? []) as $re => $t) {
                    if (@preg_match($re, $url)) {
                        $type = $t;
                        break;
                    }
                }

                $prev = $records[$url] ?? null;
                $changed = $prev === null
                    || ($prev['lastmod'] ?? null) !== $lastmod
                    || ($prev['source'] ?? null) !== $source
                    || ($prev['type'] ?? null) !== $type
                    // stored under a different word cap (the cap changed): fetch again once
                    || ($prev['cap'] ?? null) !== self::word_cap($type)
                    // no lastmod in the sitemap: refresh weekly
                    || ($lastmod === null && (time() - strtotime((string)($prev['indexed'] ?? '1970-01-01'))) > 7 * 86400);

                if ($changed) {
                    $lane[] = ['url' => $url, 'lastmod' => $lastmod, 'source' => $source, 'type' => $type];
                }
            }
            $lanes[] = $lane;

            $summary[$source] = [
                'sitemaps_read' => $read,
                'listed'        => count($urls),
                'queued'        => count($lane),
                'complete'      => $ok,
            ];

            // Drop pages that left this site's sitemap, but only if every sitemap for
            // the site was read successfully, so a transient error can't wipe the index.
            if ($ok) {
                foreach ($records as $url => $r) {
                    // A page added by hand is never a sitemap's to drop.
                    if (($r['source'] ?? null) === $source && !isset($seen[$url]) && empty($r['extra'])) {
                        unset($records[$url]);
                    }
                }
            }
        }

        // Pages added by hand (SFAF_Chat_Extra_Urls): fetched every run, so daily.
        // One taken off the list, or dead, leaves the index. They get a lane too.
        $live = class_exists('SFAF_Chat_Extra_Urls') ? SFAF_Chat_Extra_Urls::live() : [];
        foreach ($records as $url => $r) {
            if (!empty($r['extra']) && !in_array($url, $live, true)) {
                unset($records[$url]);
            }
        }
        $lane = [];
        foreach ($live as $url) {
            if (!isset($seen[$url])) {
                $lane[] = ['url' => $url, 'lastmod' => null, 'source' => SFAF_Chat_Extra_Urls::source_for($url), 'type' => 'page', 'extra' => true];
            }
        }
        $lanes[] = $lane;

        return [self::interleave($lanes), $records, $errors, $summary];
    }

    /**
     * One queue from the sites' lanes, one page from each in turn:
     * [a1, a2, a3], [b1], [c1, c2] gives a1, b1, c1, a2, c2, a3.
     */
    private static function interleave(array $lanes): array
    {
        $queue = [];
        $lanes = array_values(array_filter($lanes));
        for ($i = 0; $lanes !== []; $i++) {
            foreach ($lanes as $k => $lane) {
                if (isset($lane[$i])) {
                    $queue[] = $lane[$i];
                } else {
                    unset($lanes[$k]);
                }
            }
        }
        return $queue;
    }

    /**
     * Collect url => lastmod from a sitemap, following a sitemap index into every
     * child sitemap it lists (depth-limited). $read counts the sitemaps that loaded.
     * Returns false if any sitemap in the tree failed to load or was not a sitemap.
     */
    private static function read_sitemap(string $sitemapUrl, array &$urls, array &$errors, int $depth = 0, int &$read = 0): bool
    {
        if ($sitemapUrl === '' || $depth > 2) {
            return false;
        }

        [$body, $err] = self::http_get($sitemapUrl, 'xml');
        if ($body === null) {
            $errors[] = ['url' => $sitemapUrl, 'error' => $err];
            return false;
        }

        // A bot check or an error page can answer 200 with HTML, which may even
        // parse as XML. Say so plainly either way.
        $html = 'not a sitemap: got an HTML page (a bot check?)';
        $doc  = new DOMDocument();
        if (!@$doc->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA)) {
            $errors[] = ['url' => $sitemapUrl, 'error' => preg_match('#^\s*(<!doctype\s+html|<html)#i', $body) ? $html : 'invalid sitemap XML'];
            return false;
        }
        $root = $doc->documentElement ? strtolower((string)$doc->documentElement->localName) : '';
        if ($root === 'html') {
            $errors[] = ['url' => $sitemapUrl, 'error' => $html];
            return false;
        }
        if ($root !== 'sitemapindex' && $root !== 'urlset') {
            $errors[] = ['url' => $sitemapUrl, 'error' => 'not a sitemap (its root element is <' . ($root ?: 'none') . '>)'];
            return false;
        }
        $read++;

        $ok = true;

        // Sitemap index: follow each child sitemap.
        $children = 0;
        foreach ($doc->getElementsByTagNameNS('*', 'sitemap') as $sm) {
            $loc = trim((string)($sm->getElementsByTagNameNS('*', 'loc')->item(0)->textContent ?? ''));
            if ($loc === '') {
                continue;
            }
            // A child written relative to its index is resolved against it.
            $loc = self::absolutize($loc, $sitemapUrl);
            // Yoast writes child sitemap locs as http:// even on an https site;
            // keep them on https when the parent was fetched over https.
            if (stripos($sitemapUrl, 'https://') === 0) {
                $loc = preg_replace('#^http://#i', 'https://', $loc);
            }
            $children++;
            $ok = self::read_sitemap($loc, $urls, $errors, $depth + 1, $read) && $ok;
        }

        // Plain sitemap: url entries.
        $listed = 0;
        foreach ($doc->getElementsByTagNameNS('*', 'url') as $u) {
            $loc = trim((string)($u->getElementsByTagNameNS('*', 'loc')->item(0)->textContent ?? ''));
            if ($loc === '' || !preg_match('#^https?://#i', $loc)) {
                continue;
            }
            $lm = $u->getElementsByTagNameNS('*', 'lastmod')->item(0);
            $urls[$loc] = $lm ? trim($lm->textContent) : null;
            $listed++;
        }

        // An index with no children, or a sitemap with no pages, is reported. On its
        // own it doesn't fail the site; a site whose sitemaps list nothing at all
        // does (see plan_run).
        if ($children === 0 && $listed === 0) {
            $errors[] = ['url' => $sitemapUrl, 'error' => $root === 'sitemapindex' ? 'sitemap index lists no child sitemaps' : 'sitemap lists no pages'];
        }

        return $ok;
    }

    /**
     * GET a URL. Returns [body, null] on success or [null, reason] on failure.
     * $want: 'html' or 'xml', checked against Content-Type loosely.
     */
    private static function http_get(string $url, string $want): array
    {
        // Tests answer from fixtures here, with no network. Nothing else hooks it.
        $pre = apply_filters('sfaf_chat_index_pre_fetch', null, $url, $want);
        if (is_array($pre) && count($pre) === 2) {
            return array_values($pre);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_TIMEOUT        => self::FETCH_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXFILESIZE    => self::MAX_PAGE_BYTES,
        ]);
        $body  = curl_exec($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        $cerr  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return [null, 'fetch failed: ' . $cerr];
        }
        if ($code !== 200) {
            // A 403 here usually means a site's bot protection is blocking the indexer.
            // Have this user agent allowlisted. Never disguise it as a browser.
            return [null, 'HTTP ' . $code];
        }
        if (strlen($body) > self::MAX_PAGE_BYTES) {
            return [null, 'too large'];
        }
        if ($want === 'html' && $ctype !== '' && strpos($ctype, 'html') === false) {
            return [null, 'not html (' . $ctype . ')'];
        }
        return [$body, null];
    }

    private static function matches_any(string $url, array $patterns): bool
    {
        foreach ($patterns as $re) {
            if (@preg_match($re, $url)) {
                return true;
            }
        }
        return false;
    }

    /** Fetch + parse one page into a record, or return an error string. */
    private static function index_page(array $item)
    {
        [$html, $err] = self::http_get($item['url'], 'html');
        if ($html === null) {
            return $err;
        }

        $parsed = self::parse_page($html, $item['url']);
        if ($parsed['title'] === '' && $parsed['text'] === '') {
            return 'no content extracted';
        }

        $maxWords = self::word_cap($item['type']);

        return [
            'id'       => self::record_id($item['source'], $item['url']),
            'title'    => $parsed['title'],
            'url'      => $item['url'],
            'source'   => $item['source'],
            'type'     => $item['type'],
            'keywords' => $parsed['keywords'],
            'og'       => $parsed['og'],
            'text'     => self::truncate_words($parsed['text'], $maxWords),
            'lastmod'  => $item['lastmod'],
            'indexed'  => gmdate('c'),
            'cap'      => $maxWords,
        ] + (!empty($item['extra']) ? ['extra' => true] : []);
    }

    /**
     * Words kept per record of this type. Stored on each record as "cap", so that
     * changing a cap re-indexes the affected records on the next run by itself.
     */
    private static function word_cap(string $type): int
    {
        return $type === 'post' ? self::MAX_POST_WORDS : self::MAX_TEXT_WORDS;
    }

    /**
     * Stable, readable id from source + URL path, e.g.
     *   sfaf.org + /health-services/prep-pep/ → "idx-sfaf-health-services-prep-pep"
     * Stable across runs because it depends only on the URL. Long paths get a hash
     * suffix to stay unique.
     */
    private static function record_id(string $source, string $url): string
    {
        $src  = preg_replace('/[^a-z0-9]+/', '-', strtolower(preg_replace('/\.(org|com|net)$/', '', $source)));
        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($path)), '-');
        $id   = 'idx-' . trim($src, '-') . ($slug !== '' ? '-' . $slug : '-home');

        if (strlen($id) > 80) {
            $id = substr($id, 0, 70) . '-' . substr(sha1($url), 0, 8);
        }
        return $id;
    }

    /**
     * Extract title, og fields, keywords and clean body text from HTML.
     */
    private static function parse_page(string $html, string $url): array
    {
        $doc = new DOMDocument();
        // Force UTF-8 interpretation; libxml otherwise assumes ISO-8859-1.
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        $xp = new DOMXPath($doc);

        $meta = function (string $prop) use ($xp): string {
            $n = $xp->query("//meta[@property='$prop' or @name='$prop']/@content")->item(0);
            return $n ? self::clean_ws(html_entity_decode($n->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        };

        $ogImage = $meta('og:image');
        if ($ogImage !== '') {
            $ogImage = self::absolutize($ogImage, $url);
        }

        $og = [
            'title'       => $meta('og:title') ?: null,
            'description' => ($meta('og:description') ?: $meta('description')) ?: null,
            'image'       => $ogImage ?: null,
        ];

        $h1 = $xp->query('//h1')->item(0);
        $titleNode = $xp->query('//title')->item(0);
        $title = $og['title']
            ?? ($h1 ? self::clean_ws($h1->textContent) : '')
            ?: ($titleNode ? self::clean_ws($titleNode->textContent) : '');
        // "Page - San Francisco AIDS Foundation" → "Page"
        $title = preg_replace('/\s+[-|–\x{2014}]\s+San Francisco AIDS Foundation$/u', '', (string)$title);
        // And "Page - Tweaker" → "Page", by the page's own og:site_name.
        $siteName = $meta('og:site_name');
        if ($siteName !== '' && mb_strlen($siteName) < 60) {
            $title = preg_replace('/\s+[-|–\x{2014}]\s+' . preg_quote($siteName, '/') . '$/u', '', (string)$title);
        }

        // Structured data, read before scripts are stripped. Pages built in the browser
        // (Classy's donate.sfaf.org) carry their substance only here.
        $ld = self::jsonld_text($xp);
        if ($ld['name'] !== '' && trim((string)$title) === '') {
            $title = $ld['name'];
        }

        // Headings are good keywords, so collect before stripping.
        $headings = [];
        foreach ($xp->query('//h1|//h2|//h3') as $h) {
            $headings[] = self::clean_ws($h->textContent);
        }

        self::strip_chrome($xp);

        $root = $xp->query('//main')->item(0)
            ?? $xp->query("//*[@role='main']")->item(0)
            ?? $xp->query('//article')->item(0)
            ?? $xp->query('//body')->item(0);

        $text = '';
        if ($root) {
            // textContent glues adjacent elements together ("TitleParagraph"); add a
            // space at the end of every element first.
            foreach ($xp->query('.//*', $root) as $el) {
                $el->appendChild($doc->createTextNode(' '));
            }
            $text = self::clean_ws($root->textContent);
        }

        // Structured facts stand in only for a page with almost no text of its own,
        // which is how a page built in the browser (Classy) arrives. A page with real
        // text keeps its own wording: structured data can disagree with it (seen on
        // 2027.cycletozero.org, whose event data gives times hours off the page).
        if ($ld['text'] !== '' && count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY)) < self::THIN_PAGE_WORDS) {
            $text = trim($ld['text'] . ' ' . $text);
        }
        if (($og['description'] ?? null) === null && $ld['description'] !== '') {
            $og['description'] = $ld['description'];
        }

        return [
            'title'    => (string)$title,
            'og'       => $og,
            'keywords' => self::derive_keywords($title . ' ' . implode(' ', $headings) . ' ' . ($og['description'] ?? ''), $text),
            'text'     => $text,
        ];
    }

    /**
     * Plain sentences from a page's JSON-LD: Event (name, when, where, registration),
     * FAQPage (each question and answer), and any description. Dates are written out
     * in Pacific time here, so nothing downstream does date work.
     *
     * @return array{name:string,description:string,text:string}
     */
    private static function jsonld_text(DOMXPath $xp): array
    {
        $out   = ['name' => '', 'description' => '', 'text' => ''];
        $lines = [];
        $nodes = [];
        foreach ($xp->query("//script[@type='application/ld+json']") as $script) {
            $data = json_decode(trim((string)$script->textContent), true);
            if (!is_array($data)) {
                continue;
            }
            $items = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : ((isset($data[0]) && !isset($data['@type'])) ? $data : [$data]);
            foreach ($items as $item) {
                if (is_array($item)) {
                    $nodes[] = $item;
                }
            }
        }

        $tz   = class_exists('SFAF_Chat_Core') ? SFAF_Chat_Core::timezone() : new DateTimeZone('America/Los_Angeles');
        $when = function ($iso) use ($tz) {
            try {
                // A time with no offset is taken as Pacific, never as the server's UTC.
                $iso = (string)$iso;
                return preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $iso)
                    ? (new DateTimeImmutable($iso))->setTimezone($tz)
                    : new DateTimeImmutable($iso, $tz);
            } catch (Exception $e) {
                return null;
            }
        };
        $plain = function ($s) {
            return self::clean_ws(html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        };

        foreach ($nodes as $n) {
            $types = (array)($n['@type'] ?? []);
            if (in_array('Event', $types, true)) {
                $name = $plain($n['name'] ?? '');
                if ($name !== '' && $out['name'] === '') {
                    $out['name'] = $name;
                }
                $line  = $name !== '' ? $name . '.' : 'Event.';
                $start = $when($n['startDate'] ?? '');
                $end   = $when($n['endDate'] ?? '');
                if ($start) {
                    $line .= ' When: ' . $start->format('l, F j, Y, g:i a');
                    if ($end) {
                        $line .= ' to ' . ($end->format('Y-m-d') === $start->format('Y-m-d') ? $end->format('g:i a') : $end->format('l, F j, Y, g:i a'));
                    }
                    $line .= ' (Pacific).';
                }
                $loc = is_array($n['location'] ?? null) ? $n['location'] : [];
                $place = $plain($loc['name'] ?? '');
                $city  = $plain($loc['address']['addressLocality'] ?? '');
                if ($place !== '' || $city !== '') {
                    $line .= ' Where: ' . trim($place . ($place !== '' && $city !== '' ? ', ' : '') . $city) . '.';
                }
                if (isset($n['eventStatus']) && false !== stripos((string)$n['eventStatus'], 'Cancelled')) {
                    $line .= ' Status: cancelled.';
                }
                $offers = [];
                foreach ((array)($n['offers'] ?? []) as $o) {
                    if (!is_array($o)) {
                        continue;
                    }
                    $label = $plain($o['name'] ?? 'Registration');
                    if (isset($o['price']) && is_numeric($o['price'])) {
                        $label .= ' $' . number_format((float)$o['price'], 2);
                    }
                    $avail = (string)($o['availability'] ?? '');
                    if (false !== stripos($avail, 'SoldOut')) {
                        $label .= ' (sold out)';
                    } elseif (false !== stripos($avail, 'InStock')) {
                        $label .= ' (available)';
                    }
                    $offers[] = $label;
                }
                if ($offers) {
                    $line .= ' Registration: ' . implode('; ', array_slice($offers, 0, 8)) . '.';
                }
                $desc = $plain($n['description'] ?? '');
                if ($desc !== '') {
                    $line .= ' ' . $desc;
                    $out['description'] = $out['description'] ?: $desc;
                }
                $lines[] = $line;
            } elseif (in_array('FAQPage', $types, true)) {
                foreach (array_slice((array)($n['mainEntity'] ?? []), 0, 30) as $q) {
                    if (!is_array($q)) {
                        continue;
                    }
                    $question = $plain($q['name'] ?? '');
                    $answer   = $plain($q['acceptedAnswer']['text'] ?? '');
                    if ($question !== '' && $answer !== '') {
                        $lines[] = 'Q: ' . $question . ' A: ' . $answer;
                    }
                }
            } elseif (isset($n['description']) && $out['description'] === '') {
                $out['description'] = $plain($n['description']);
            }
        }

        $out['text'] = implode(' ', $lines);
        return $out;
    }

    /** Remove navigation, header, footer, scripts and similar page chrome. */
    private static function strip_chrome(DOMXPath $xp): void
    {
        $kill = [];

        foreach ($xp->query('//script|//style|//noscript|//template|//svg|//iframe|//form|//nav|//header|//footer|//aside|//button|//select') as $n) {
            $kill[] = $n;
        }
        foreach ($xp->query("//*[@role='navigation' or @role='banner' or @role='contentinfo' or @role='search' or @role='complementary' or @aria-hidden='true']") as $n) {
            $kill[] = $n;
        }

        // Common theme class/id names for chrome. Word-boundary matches only, and
        // never anything that is or contains the main content.
        $chrome = '/(^|[\s_-])(nav|navbar|navigation|menu|footer|breadcrumbs?|cookie|cookies|skip-link|sidebar|share|social|modal|popup|newsletter-signup)([\s_-]|$)|(site|global|masthead|main)[-_]?header/i';
        foreach ($xp->query('//*[@class or @id]') as $n) {
            if (in_array($n->nodeName, ['html', 'body', 'main', 'article'], true)) {
                continue;
            }
            $attr = $n->getAttribute('class') . ' ' . $n->getAttribute('id');
            if (preg_match($chrome, $attr) && $xp->query('.//main|.//article', $n)->length === 0) {
                $kill[] = $n;
            }
        }

        foreach ($kill as $n) {
            if ($n->parentNode) {
                $n->parentNode->removeChild($n);
            }
        }
    }

    /** Keywords: words from title/headings/description + the most frequent body terms. */
    private static function derive_keywords(string $lead, string $text): array
    {
        $kw = array_values(array_unique(SFAF_Chat_Core::tokenize($lead)));

        $freq = array_count_values(SFAF_Chat_Core::tokenize($text));
        arsort($freq);
        foreach (array_keys($freq) as $w) {
            if (count($kw) >= 40) {
                break;
            }
            if (mb_strlen((string)$w) >= 4 && !in_array($w, $kw, true)) {
                $kw[] = (string)$w;
            }
        }
        return array_slice($kw, 0, 40);
    }

    private static function clean_ws(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private static function truncate_words(string $s, int $max): string
    {
        $words = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) <= $max) {
            return $s;
        }
        return implode(' ', array_slice($words, 0, $max)) . ' …';
    }

    /** Resolve a possibly-relative URL against the page URL. */
    private static function absolutize(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '')
            . (isset($b['port']) ? ':' . $b['port'] : '');
        if (strpos($href, '//') === 0) {
            return ($b['scheme'] ?? 'https') . ':' . $href;
        }
        if (strpos($href, '/') === 0) {
            return $origin . $href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
        return $origin . $dir . $href;
    }
}
