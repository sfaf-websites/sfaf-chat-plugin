<?php
/**
 * Getting the widget onto pages, under the switch.
 *
 * TWO ROUTES TO A PAGE:
 *
 *   1. This site's own pages: wp_enqueue_script, in public or admins-only mode.
 *   2. Other sites (sfaf.org): one script tag pointing at the STABLE URL,
 *      /wp-json/sfaf-chat/v1/widget.js. It has no version in it and never
 *      changes, so the tag on sfaf.org never needs touching.
 *
 * WHY THE STABLE URL IS A ROUTE AND NOT THE FILE. A plain .js file would keep
 * loading on sfaf.org with the switch off, because nothing on the server would
 * be asked. The route is what lets the switch reach a page this plugin doesn't
 * control: off and admins-only both serve an empty script.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Widget {

    /**
     * How long a browser may keep the stable script. This is how soon a switch
     * change reaches visitors already holding a copy. The ask route obeys the
     * switch at once, regardless.
     */
    const BROWSER_CACHE_SECONDS = 300;

    public function register() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    public static function stable_url() {
        return rest_url( SFAF_Chat_Endpoint::REST_NAMESPACE . '/widget.js' );
    }

    public static function ask_url() {
        return rest_url( SFAF_Chat_Endpoint::REST_NAMESPACE . '/ask' );
    }

    public function enqueue() {
        $mode = SFAF_Chat_Settings::mode();

        if ( SFAF_Chat_Settings::MODE_OFF === $mode ) {
            return;
        }
        if ( SFAF_Chat_Settings::MODE_ADMINS === $mode && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $config = self::config();

        /*
         * Only in admins-only mode, which is only ever rendered for a logged-in
         * administrator, on a page the cache does not store. In public mode the
         * page is cacheable, so no nonce goes in it.
         */
        if ( SFAF_Chat_Settings::MODE_ADMINS === $mode ) {
            $config['nonce'] = wp_create_nonce( 'wp_rest' );
        }

        wp_enqueue_script( 'sfaf-chat-widget', SFAF_CHAT_URL . 'public/js/widget.js', array(), SFAF_CHAT_VERSION, true );
        wp_add_inline_script( 'sfaf-chat-widget', 'window.sfafChatConfig=' . wp_json_encode( $config ) . ';', 'before' );
    }

    /** What the widget needs from the plugin, wherever it runs. */
    private static function config() {
        return array(
            'proxy' => self::ask_url(),
            'theme' => SFAF_Chat_Appearance::theme(),
        );
    }

    /** What the stable URL serves right now. */
    public static function script_body() {
        if ( SFAF_Chat_Settings::MODE_PUBLIC !== SFAF_Chat_Settings::mode() ) {
            return "/* SFAF Chat is switched off. */\n";
        }

        $widget = @file_get_contents( SFAF_CHAT_DIR . 'public/js/widget.js' );
        if ( false === $widget ) {
            return "/* SFAF Chat is unavailable. */\n";
        }
        return 'window.sfafChatConfig=' . wp_json_encode( self::config() ) . ";\n" . $widget;
    }
}
