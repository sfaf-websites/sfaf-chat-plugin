<?php
/**
 * Page indexer (knowledge tier 2).
 *
 * An external scheduler calls, repeatedly, on a schedule:
 *     GET /wp-json/sfaf-chat/v1/index        with header  X-Index-Token: <token>
 *  or GET /wp-json/sfaf-chat/v1/index?token=<token>
 *
 * What it does:
 *   1. Reads each site's sitemap (sitemap indexes are followed).
 *   2. Queues only pages that are new, or whose sitemap <lastmod> changed since
 *      they were last indexed. Pages gone from the sitemap are dropped.
 *   3. Each call processes ONE BATCH (BATCH_SIZE pages or TIME_BUDGET seconds,
 *      whichever comes first) and reports {done, remaining}. The scheduler simply
 *      calls it repeatedly; once the run is done, further calls are cheap no-ops
 *      until MIN_RUN_INTERVAL has passed and a new run starts.
 *   4. For each page: fetch, strip nav/header/footer/scripts to clean text, pull
 *      og:title / og:description / og:image, write one record.
 *
 * WHERE THINGS LIVE, and why. A plugin update replaces the plugin folder, so:
 *   - the index is uploads/sfaf-chat/page-index.json (public page text only)
 *   - run state is the sfaf_chat_index_state option
 *   - the lock is uploads/sfaf-chat/indexer.lock
 *
 * Records use the same schema as data/knowledge-base.json:
 *   id, title, url, source, type, keywords, og{title,description,image}, text, lastmod
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
    const MAX_POST_WORDS   = 400;         // per post (pointer-only: enough to match on)
    const POLITE_DELAY_MS  = 250;         // pause between page fetches
    const USER_AGENT       = 'SFAF-Chat-Indexer/1.0 (+https://resources.sfaf.org/chat/)';

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
            // Other SFAF sites go here once their sitemap URLs are confirmed.
        );
        return (array) apply_filters( 'sfaf_chat_index_sites', $sites );
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

    private static function out( array $payload, $status = 200 ) {
        return new WP_REST_Response( $payload, $status );
    }

    /** One batch. */
    private static function run( $force ) {
        $sites = self::sites();

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

        $run_due = $force
            || empty( $state['run_completed'] )
            || ( time() - (int) $state['run_completed'] ) >= self::MIN_RUN_INTERVAL;

        if ( array() === $queue && $run_due && empty( $state['run_started_open'] ) ) {
            list( $queue, $records, $plan_errors ) = self::plan_run( $sites, $records );
            $state = array(
                'run_started'      => time(),
                'run_started_open' => true,
                'run_completed'    => $state['run_completed'] ?? null,
                'queued'           => count( $queue ),
                'queue'            => $queue,
                'errors'           => $plan_errors,
            );
            $started = true;
        }

        $processed = 0;
        $errors    = array();
        $deadline  = microtime( true ) + self::TIME_BUDGET;

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
                $errors[] = array( 'url' => $item['url'], 'error' => $rec );
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
     * Read every site's sitemap(s) and decide what to (re)index.
     * Returns [queue, records-with-removals-applied, errors].
     */
    private static function plan_run(array $sites, array $records): array
    {
        $queue  = [];
        $errors = [];
        $seen   = [];

        foreach ($sites as $site) {
            $source = (string)($site['source'] ?? '');
            $urls   = [];
            $ok     = self::read_sitemap((string)($site['sitemap'] ?? ''), $urls, $errors);

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
                    // no lastmod in the sitemap: refresh weekly
                    || ($lastmod === null && (time() - strtotime((string)($prev['indexed'] ?? '1970-01-01'))) > 7 * 86400);

                if ($changed) {
                    $queue[] = ['url' => $url, 'lastmod' => $lastmod, 'source' => $source, 'type' => $type];
                }
            }

            // Drop pages that left this site's sitemap, but only if every sitemap for
            // the site was read successfully, so a transient error can't wipe the index.
            if ($ok) {
                foreach ($records as $url => $r) {
                    if (($r['source'] ?? null) === $source && !isset($seen[$url])) {
                        unset($records[$url]);
                    }
                }
            }
        }

        return [$queue, $records, $errors];
    }

    /**
     * Collect url => lastmod from a sitemap, following sitemap indexes (depth-limited).
     * Returns false if any sitemap in the tree failed to load.
     */
    private static function read_sitemap(string $sitemapUrl, array &$urls, array &$errors, int $depth = 0): bool
    {
        if ($sitemapUrl === '' || $depth > 2) {
            return false;
        }

        [$body, $err] = self::http_get($sitemapUrl, 'xml');
        if ($body === null) {
            $errors[] = ['url' => $sitemapUrl, 'error' => $err];
            return false;
        }

        $doc = new DOMDocument();
        if (!@$doc->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA)) {
            $errors[] = ['url' => $sitemapUrl, 'error' => 'invalid sitemap XML'];
            return false;
        }

        $ok = true;

        // Sitemap index → recurse into each child sitemap.
        foreach ($doc->getElementsByTagNameNS('*', 'sitemap') as $sm) {
            $loc = trim((string)($sm->getElementsByTagNameNS('*', 'loc')->item(0)->textContent ?? ''));
            if ($loc !== '') {
                // Yoast writes child sitemap locs as http:// even on an https site;
                // keep them on https when the parent was fetched over https.
                if (stripos($sitemapUrl, 'https://') === 0) {
                    $loc = preg_replace('#^http://#i', 'https://', $loc);
                }
                $ok = self::read_sitemap($loc, $urls, $errors, $depth + 1) && $ok;
            }
        }

        // Plain sitemap → url entries.
        foreach ($doc->getElementsByTagNameNS('*', 'url') as $u) {
            $loc = trim((string)($u->getElementsByTagNameNS('*', 'loc')->item(0)->textContent ?? ''));
            if ($loc === '' || !preg_match('#^https?://#i', $loc)) {
                continue;
            }
            $lm = $u->getElementsByTagNameNS('*', 'lastmod')->item(0);
            $urls[$loc] = $lm ? trim($lm->textContent) : null;
        }

        return $ok;
    }

    /**
     * GET a URL. Returns [body, null] on success or [null, reason] on failure.
     * $want: 'html' or 'xml', checked against Content-Type loosely.
     */
    private static function http_get(string $url, string $want): array
    {
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

        $maxWords = ($item['type'] === 'post') ? self::MAX_POST_WORDS : self::MAX_TEXT_WORDS;

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
        ];
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

        return [
            'title'    => (string)$title,
            'og'       => $og,
            'keywords' => self::derive_keywords($title . ' ' . implode(' ', $headings) . ' ' . ($og['description'] ?? ''), $text),
            'text'     => $text,
        ];
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
