<?php
/**
 * The Indexing screen: what the indexer crawls, how the last run went per site,
 * and the pages added by hand (SFAF_Chat_Extra_Urls).
 *
 * The external scheduler still drives the crawl. The one button that fetches
 * here is "Fetch these pages now", for the hand-added pages only, so a new
 * campaign page can be answered from without waiting a day.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Admin_Index {

    const PAGE         = 'sfaf-chat-indexing';
    const SAVE_ACTION  = 'sfaf_chat_save_extra_urls';
    const FETCH_ACTION = 'sfaf_chat_fetch_extra_urls';
    const RETRY_ACTION = 'sfaf_chat_retry_extra_url';

    private $admin;

    public function __construct( SFAF_Chat_Admin $admin ) {
        $this->admin = $admin;
    }

    public function register() {
        add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
        add_action( 'admin_post_' . self::FETCH_ACTION, array( $this, 'fetch' ) );
        add_action( 'admin_post_' . self::RETRY_ACTION, array( $this, 'retry' ) );
    }

    private function back() {
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }

    public function save() {
        $this->admin->gate();
        check_admin_referer( self::SAVE_ACTION );
        $lines = preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $_POST['urls'] ?? '' ) );
        list( $count, $rejected ) = SFAF_Chat_Extra_Urls::set_list( (array) $lines );
        $msgs = array( sprintf( '%d page%s saved. New ones are fetched on the next indexer run, or now with Fetch these pages now.', $count, 1 === $count ? '' : 's' ) );
        foreach ( $rejected as $bad ) {
            $msgs[] = 'Not added (needs a full https:// address): ' . $bad;
        }
        $this->admin->stash_notice( $msgs, $rejected ? 'warning' : 'success' );
        $this->back();
    }

    public function fetch() {
        $this->admin->gate();
        check_admin_referer( self::FETCH_ACTION );
        list( $done, $good, $msgs ) = SFAF_Chat_Indexer::index_extra_now();
        array_unshift( $msgs, sprintf( 'Fetched %d page%s: %d indexed.', $done, 1 === $done ? '' : 's', $good ) );
        $this->admin->stash_notice( $msgs, $done === $good ? 'success' : 'warning' );
        $this->back();
    }

    public function retry() {
        $this->admin->gate();
        check_admin_referer( self::RETRY_ACTION );
        $url = SFAF_Chat_Extra_Urls::clean_url( (string) wp_unslash( $_POST['url'] ?? '' ) );
        SFAF_Chat_Extra_Urls::retry( $url )
            ? $this->admin->stash_notice( array( 'It will be fetched again on the next run, or now with Fetch these pages now.' ), 'success' )
            : $this->admin->stash_notice( array( 'That page is no longer on the list.' ), 'warning' );
        $this->back();
    }

    private static function when( $iso ) {
        if ( '' === (string) $iso ) {
            return 'Never';
        }
        $t = strtotime( (string) $iso );
        return $t ? wp_date( 'M j, g:i a', $t, SFAF_Chat_Core::timezone() ) . ' Pacific' : (string) $iso;
    }

    public function render() {
        $this->admin->gate();

        $index   = SFAF_Chat_Core::read_json( SFAF_Chat_Core::index_path() ) ?? array();
        $counts  = array();
        foreach ( (array) ( $index['records'] ?? array() ) as $r ) {
            $src            = (string) ( $r['source'] ?? '' );
            $counts[ $src ] = ( $counts[ $src ] ?? 0 ) + 1;
        }
        $state   = SFAF_Chat_Indexer::state();
        $errors  = (array) ( $state['errors'] ?? array() );
        $extra   = SFAF_Chat_Extra_Urls::all();
        $labels  = array( 'pending' => 'Waiting for the next fetch', 'ok' => 'Indexed', 'dead' => 'Dead: dropped from the index' );
        $form    = function ( $action, $label, $class = 'button', $hidden = array() ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sfaf-chat-inline-form">';
            echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
            foreach ( $hidden as $k => $v ) {
                echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
            }
            wp_nonce_field( $action );
            echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
        };
        ?>
        <div class="wrap sfaf-chat-admin">
            <h1>Indexing</h1>
            <?php $this->admin->tabs( self::PAGE ); ?>
            <?php $this->admin->show_notice(); ?>

            <h2>Sites</h2>
            <p>Each site is crawled from its sitemap on the indexer's schedule. Pages from sites other than sfaf.org are answered from as that site's, never in SFAF's voice.</p>
            <table class="widefat striped">
                <thead><tr><th scope="col">Site</th><th scope="col">Source tag</th><th scope="col">Sitemaps</th><th scope="col">Pages in the index</th><th scope="col">Last run's fetch problems</th></tr></thead>
                <tbody>
                    <?php foreach ( SFAF_Chat_Indexer::configured_sites() as $site ) :
                        $mine = array_values( array_filter( $errors, function ( $e ) use ( $site ) {
                            $host = (string) parse_url( (string) ( $e['url'] ?? '' ), PHP_URL_HOST );
                            return '' !== $host && false !== strpos( $host, $site['source'] );
                        } ) ); ?>
                        <tr>
                            <td><strong><?php echo esc_html( $site['name'] ); ?></strong></td>
                            <td><code><?php echo esc_html( $site['source'] ); ?></code></td>
                            <td><?php echo implode( '<br>', array_map( function ( $u ) { return '<code>' . esc_html( $u ) . '</code>'; }, $site['sitemaps'] ) ); ?></td>
                            <td><?php echo (int) ( $counts[ $site['source'] ] ?? 0 ); ?></td>
                            <td><?php
                                if ( ! $mine ) {
                                    echo 'None';
                                } else {
                                    echo '<ul class="sfaf-chat-errors">';
                                    foreach ( array_slice( $mine, 0, 5 ) as $e ) {
                                        echo '<li><code>' . esc_html( (string) $e['error'] ) . '</code> ' . esc_html( (string) $e['url'] ) . '</li>';
                                    }
                                    echo '</ul>';
                                    if ( count( $mine ) > 5 ) {
                                        echo '<span class="description">' . (int) ( count( $mine ) - 5 ) . ' more.</span>';
                                    }
                                }
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">A 403 means the site's bot protection is turning the indexer away: ask whoever runs that site to allowlist the user agent <code><?php echo esc_html( SFAF_Chat_Indexer::USER_AGENT ); ?></code>. Last run started: <?php echo esc_html( self::when( isset( $state['run_started'] ) ? gmdate( 'c', (int) $state['run_started'] ) : '' ) ); ?>. Pages still queued: <?php echo (int) count( (array) ( $state['queue'] ?? array() ) ); ?>.</p>

            <h2>Pages added by hand</h2>
            <p>For pages no sitemap lists, such as campaign and event pages on donate.sfaf.org. One full address per line. Each is fetched on every indexer run (so daily) and tagged with its site. A page that returns 404 or 410, or fails <?php echo (int) SFAF_Chat_Extra_Urls::FAIL_LIMIT; ?> times in a row, is dropped from the index and marked dead here.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
                <?php wp_nonce_field( self::SAVE_ACTION ); ?>
                <label for="sfaf-chat-extra" class="screen-reader-text">Pages to index, one per line</label>
                <textarea id="sfaf-chat-extra" name="urls" rows="6" class="large-text code" spellcheck="false"><?php echo esc_textarea( implode( "\n", array_keys( $extra ) ) ); ?></textarea>
                <?php submit_button( 'Save pages', 'primary', 'submit', false ); ?>
            </form>

            <?php if ( $extra ) : ?>
                <table class="widefat striped sfaf-chat-extra">
                    <thead><tr><th scope="col">Page</th><th scope="col">Source tag</th><th scope="col">Status</th><th scope="col">Last fetched</th><th scope="col"><span class="screen-reader-text">Actions</span></th></tr></thead>
                    <tbody>
                        <?php foreach ( $extra as $url => $m ) : ?>
                            <tr>
                                <td><?php echo '' !== $m['title'] ? '<strong>' . esc_html( $m['title'] ) . '</strong><br>' : ''; ?><code><?php echo esc_html( $url ); ?></code></td>
                                <td><code><?php echo esc_html( SFAF_Chat_Extra_Urls::source_for( $url ) ); ?></code></td>
                                <td><span class="sfaf-chat-status sfaf-chat-status-<?php echo esc_attr( 'ok' === $m['status'] ? 'active' : ( 'dead' === $m['status'] ? 'ended' : 'scheduled' ) ); ?>"><?php echo esc_html( $labels[ $m['status'] ] ?? $m['status'] ); ?></span>
                                    <?php if ( '' !== $m['error'] ) : ?><br><span class="description">Last error: <?php echo esc_html( $m['error'] ); ?></span><?php endif; ?></td>
                                <td><?php echo esc_html( self::when( $m['checked'] ) ); ?></td>
                                <td><?php if ( 'dead' === $m['status'] ) { $form( self::RETRY_ACTION, 'Re-check', 'button-link', array( 'url' => $url ) ); } ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><?php $form( self::FETCH_ACTION, 'Fetch these pages now', 'button button-secondary' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
