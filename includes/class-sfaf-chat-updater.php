<?php
/**
 * UPDATES FROM INSIDE WORDPRESS, FROM GITHUB RELEASES.
 *
 * The same mechanism as the SFAF Calendar. WordPress checks for a newer version,
 * the Plugins screen shows the update notice, and Update now does the rest.
 *
 * WHAT IT ASKS FOR, AND WHY THAT ONE THING. The GitHub releases API for a PUBLIC
 * repository, unauthenticated. A private repository would mean storing a token on
 * the site to serve its release assets. That is the whole reason the public
 * repository exists.
 *
 * ONE ZIP, NOT TWO. The update downloads the release ASSET, the file
 * `.claude/build-zip.sh` produces. It is NOT GitHub's auto-generated source zip:
 * that one is named after the tag and unpacks to a folder WordPress would treat
 * as a different plugin. asset_url() refuses anything but the built asset, so a
 * release published without one reports no update rather than installing the
 * wrong thing.
 *
 * ONE VERSION, NOT TWO. The installed version is SFAF_CHAT_VERSION, and the
 * available version is the release tag with any leading "v" removed.
 *
 * A PUSH DOES NOTHING HERE. Only a published release with the built zip attached
 * produces an update.
 *
 * WHAT IT DOES NOT DO. It never downgrades. The result is cached for twelve hours
 * and on every failure, so a rate limit or an outage costs one slow admin page
 * rather than one per page load.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Updater {

    /** The public repository releases are served from. */
    const REPO = 'sfaf-websites/sfaf-chat-plugin';

    /** Where the answer is cached, and for how long. */
    const CACHE_KEY = 'sfaf_chat_updater_release';
    const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /** Cached for a shorter time after a failure, so an outage self-heals. */
    const CACHE_TTL_FAIL = 1 * HOUR_IN_SECONDS;

    /** The forced check: its admin-post action, and the nonce that guards it. */
    const CHECK_ACTION = 'sfaf_chat_check_updates';

    public function register() {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
        add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
        add_action( 'upgrader_process_complete', array( $this, 'forget' ), 10, 2 );

        add_filter( 'plugin_action_links_' . self::basename(), array( $this, 'action_link' ) );
        add_action( 'admin_post_' . self::CHECK_ACTION, array( $this, 'handle_check' ) );
        add_action( 'admin_notices', array( $this, 'check_notice' ) );
    }

    /** `sfaf-chat/sfaf-chat.php`, however the folder is actually named. */
    public static function basename() {
        return plugin_basename( SFAF_CHAT_FILE );
    }

    /**
     * The latest release, or null.
     *
     * @return array{version:string,zip:string,url:string,notes:string,date:string}|null
     */
    public static function latest( $force = false ) {
        if ( ! $force ) {
            $cached = get_site_transient( self::CACHE_KEY );
            if ( is_array( $cached ) ) {
                return empty( $cached['version'] ) ? null : $cached;
            }
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPO . '/releases/latest',
            array(
                'timeout' => 10,
                'headers' => array(
                    'Accept'     => 'application/vnd.github+json',
                    // GitHub refuses a request with no user agent.
                    'User-Agent' => 'SFAF-Chat/' . SFAF_CHAT_VERSION,
                ),
            )
        );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            set_site_transient( self::CACHE_KEY, array( 'version' => '' ), self::CACHE_TTL_FAIL );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['tag_name'] ) || ! empty( $body['draft'] ) ) {
            set_site_transient( self::CACHE_KEY, array( 'version' => '' ), self::CACHE_TTL_FAIL );
            return null;
        }

        $version = ltrim( (string) $body['tag_name'], 'vV' );
        $zip     = self::asset_url( $body );

        // A release with no built asset is not an update. See the file header.
        if ( '' === $zip || ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
            set_site_transient( self::CACHE_KEY, array( 'version' => '' ), self::CACHE_TTL_FAIL );
            return null;
        }

        $release = array(
            'version' => $version,
            'zip'     => $zip,
            'url'     => isset( $body['html_url'] ) ? (string) $body['html_url'] : '',
            'notes'   => isset( $body['body'] ) ? (string) $body['body'] : '',
            'date'    => isset( $body['published_at'] ) ? substr( (string) $body['published_at'], 0, 10 ) : '',
        );
        set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
        return $release;
    }

    /**
     * The built zip attached to a release, and nothing else. `sfaf-chat-1.0.0.zip`,
     * matching what build-zip.sh names. GitHub's own `zipball_url` is deliberately
     * not accepted: it is the repository, not the plugin.
     */
    private static function asset_url( $body ) {
        if ( empty( $body['assets'] ) || ! is_array( $body['assets'] ) ) {
            return '';
        }
        foreach ( $body['assets'] as $asset ) {
            $name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
            if ( preg_match( '/^sfaf-chat-\d+\.\d+\.\d+\.zip$/', $name )
                && ! empty( $asset['browser_download_url'] ) ) {
                return (string) $asset['browser_download_url'];
            }
        }
        return '';
    }

    /**
     * Tell WordPress an update is available.
     *
     * THE GUARD IS version_compare AND NOTHING ELSE, so a release older than what
     * is installed is silently not an update rather than a downgrade offered to
     * somebody who would accept it.
     */
    public function inject( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }
        $release = self::latest();
        if ( ! $release ) {
            return $transient;
        }

        $file = self::basename();
        if ( version_compare( $release['version'], SFAF_CHAT_VERSION, '<=' ) ) {
            // Up to date. Recorded so the Plugins screen does not keep asking.
            if ( isset( $transient->response[ $file ] ) ) {
                unset( $transient->response[ $file ] );
            }
            $transient->no_update[ $file ] = self::payload( $release );
            return $transient;
        }

        $transient->response[ $file ] = self::payload( $release );
        return $transient;
    }

    /** The object both response and no_update want. */
    private static function payload( $release ) {
        $file = self::basename();
        return (object) array(
            'id'           => self::REPO,
            'slug'         => dirname( $file ),
            'plugin'       => $file,
            'new_version'  => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['zip'],
            'tested'       => get_bloginfo( 'version' ),
            'requires_php' => '7.4',
            'icons'        => array(),
        );
    }

    /**
     * The "View details" panel. Without this WordPress offers a link to
     * wordpress.org, where this plugin does not exist.
     */
    public function details( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }
        if ( ! isset( $args->slug ) || dirname( self::basename() ) !== $args->slug ) {
            return $result;
        }
        $release = self::latest();
        if ( ! $release ) {
            return $result;
        }
        return (object) array(
            'name'          => 'SFAF Chat',
            'slug'          => $args->slug,
            'version'       => $release['version'],
            'author'        => 'San Francisco AIDS Foundation',
            'homepage'      => 'https://github.com/' . self::REPO,
            'download_link' => $release['zip'],
            'last_updated'  => $release['date'],
            'requires_php'  => '7.4',
            'sections'      => array(
                'description' => 'The San Francisco AIDS Foundation website assistant.',
                'changelog'   => '' !== $release['notes']
                    ? wpautop( wp_kses_post( $release['notes'] ) )
                    : '<p>See the release on GitHub.</p>',
            ),
        );
    }

    /**
     * Forget the cached answer after an update, so the Plugins screen does not
     * keep offering a version that has just been installed. Installing counts as
     * well as updating: uploading a zip on the Plugins screen is `install`.
     */
    public function forget( $upgrader, $options ) {
        if ( ! isset( $options['type'] ) || 'plugin' !== $options['type'] ) {
            return;
        }
        $action = isset( $options['action'] ) ? (string) $options['action'] : '';
        if ( 'update' !== $action && 'install' !== $action ) {
            return;
        }
        delete_site_transient( self::CACHE_KEY );
    }

    /* =====================================================================
     * The forced check. WordPress's own "Check again" clears update_plugins
     * but not this class's twelve-hour cache, so it would be answered from
     * memory. This link is what actually asks GitHub.
     * ================================================================== */

    /**
     * "Check for updates", beside Deactivate on the Plugins screen.
     *
     * @param string[] $links
     * @return string[]
     */
    public function action_link( $links ) {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return $links;
        }
        $url = wp_nonce_url(
            add_query_arg( 'action', self::CHECK_ACTION, admin_url( 'admin-post.php' ) ),
            self::CHECK_ACTION
        );
        array_unshift(
            $links,
            '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'sfaf-chat' ) . '</a>'
        );
        return $links;
    }

    /** Ask GitHub now, whatever the cache says. */
    public function handle_check() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( esc_html__( 'You do not have permission to check for updates.', 'sfaf-chat' ) );
        }
        check_admin_referer( self::CHECK_ACTION );

        $release = self::run_check();

        // The version rather than yes or no: "no update" and "the check failed"
        // are different facts, and the person who pressed this needs to tell.
        $args = array( 'sfaf_chat_checked' => '1' );
        if ( is_array( $release ) && ! empty( $release['version'] ) ) {
            $args['sfaf_chat_found'] = $release['version'];
        }

        wp_safe_redirect( add_query_arg( $args, self_admin_url( 'plugins.php' ) ) );
        exit;
    }

    /**
     * The steps, with no capability check, no nonce and no redirect, so a test can
     * run them. latest( true ) writes the fresh answer to the cache, dropping
     * update_plugins stops WordPress short-circuiting on last_checked, and
     * wp_update_plugins() re-runs the check, which fires inject().
     *
     * @return array|null Whatever latest() found.
     */
    public static function run_check() {
        $release = self::latest( true );
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();
        return $release;
    }

    /** Say what the forced check found, on the screen it returns to. */
    public function check_notice() {
        if ( empty( $_GET['sfaf_chat_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
            return;
        }

        $found = isset( $_GET['sfaf_chat_found'] ) ? sanitize_text_field( wp_unslash( $_GET['sfaf_chat_found'] ) ) : '';
        if ( '' !== $found && ! preg_match( '/^\d+\.\d+\.\d+$/', $found ) ) {
            $found = '';
        }

        if ( '' === $found ) {
            $class = 'notice-warning';
            $text  = sprintf(
                /* translators: %s: the installed version. */
                __( 'SFAF Chat: no release was found on GitHub. You are running %s. Nothing has changed.', 'sfaf-chat' ),
                SFAF_CHAT_VERSION
            );
        } elseif ( version_compare( $found, SFAF_CHAT_VERSION, '>' ) ) {
            $class = 'notice-success';
            $text  = sprintf(
                /* translators: 1: the released version, 2: the installed version. */
                __( 'SFAF Chat: %1$s is available. You are running %2$s. Update now is in the row below.', 'sfaf-chat' ),
                $found,
                SFAF_CHAT_VERSION
            );
        } else {
            $class = 'notice-info';
            $text  = sprintf(
                /* translators: %s: the installed version. */
                __( 'SFAF Chat: checked just now, and %s is the latest release. Nothing to install.', 'sfaf-chat' ),
                SFAF_CHAT_VERSION
            );
        }

        printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $text ) );
    }
}
