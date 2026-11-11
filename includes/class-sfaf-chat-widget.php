<?php
/**
 * The widget's script, under the switch.
 *
 * ONE ROUTE TO A PAGE: a script tag that somebody places by hand, pointing at the
 * STABLE URL, /wp-json/sfaf-chat/v1/widget.js. The plugin enqueues nothing, on
 * this site or anywhere else, so the widget appears exactly where the tag is and
 * nowhere it isn't. The URL has no version in it and never changes, so a placed
 * tag never needs touching.
 *
 * WHY THE STABLE URL IS A ROUTE AND NOT THE FILE. A plain .js file would keep
 * loading with the switch off, because nothing on the server would be asked. The
 * route is what lets the switch reach pages this plugin doesn't control: off
 * serves an empty script.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Widget {

    /**
     * How long a browser may keep the stable script. This is how soon a switch
     * change reaches visitors already holding a copy. The ask route obeys the
     * switch at once, regardless.
     */
    const BROWSER_CACHE_SECONDS = 300;

    public static function stable_url() {
        return rest_url( SFAF_Chat_Endpoint::REST_NAMESPACE . '/widget.js' );
    }

    public static function ask_url() {
        return rest_url( SFAF_Chat_Endpoint::REST_NAMESPACE . '/ask' );
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
        if ( ! SFAF_Chat_Settings::is_on() ) {
            return "/* SFAF Chat is switched off. */\n";
        }

        $widget = @file_get_contents( SFAF_CHAT_DIR . 'public/js/widget.js' );
        if ( false === $widget ) {
            return "/* SFAF Chat is unavailable. */\n";
        }
        return 'window.sfafChatConfig=' . wp_json_encode( self::config() ) . ";\n" . $widget;
    }
}
