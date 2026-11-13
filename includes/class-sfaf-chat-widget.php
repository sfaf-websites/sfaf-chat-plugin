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
            'proxy'    => self::ask_url(),
            'theme'    => SFAF_Chat_Appearance::theme(),
            'greeting' => SFAF_Chat_Settings::welcome(),
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
        return 'window.sfafChatConfig=' . wp_json_encode( self::config() ) . ";\n" . self::appointments() . $widget;
    }

    /**
     * THE APPOINTMENT CHOOSER (1.15.0). public/appointments/modal.html is the block
     * as staff supplied it, kept byte for byte (healow links included), and is
     * never edited here: this takes its style, markup and script apart and serves
     * them with the widget, so every page with the chat has it, with no paste.
     *
     * - Added once: not when the page already has #sfaf-appt-modal or
     *   window.SFAFAppointments (say, pasted by hand or through GTM).
     * - One rule of our own, kept apart from the block: the chat panel sits above
     *   z-index 2147483000, so the chooser is lifted over it, or it would open
     *   behind the chat.
     * - It opens from the block's own document click listener, which also sees
     *   replies added later; widget.js turns [Make an appointment](#sfaf-appointments)
     *   into that link and loads the block's fonts when one is shown.
     * - The file missing or not in the expected shape: nothing is added.
     */
    private static function appointments() {
        $block = @file_get_contents( SFAF_CHAT_DIR . 'public/appointments/modal.html' );
        if ( false === $block
            || ! preg_match( '#<style>(.*?)</style>#s', $block, $css )
            || ! preg_match( '#(<div id="sfaf-appt-modal".*</div>)\s*<script>#s', $block, $html )
            || ! preg_match( '#<script>(.*?)</script>#s', $block, $js ) ) {
            return '';
        }
        $lift = '#sfaf-appt-modal{z-index:2147483646 !important}';
        return "/* SFAF appointment chooser, from public/appointments/modal.html */\n"
            . "(function(){\n"
            . 'var css=' . wp_json_encode( $css[1] ) . ',html=' . wp_json_encode( trim( $html[1] ) ) . ',lift=' . wp_json_encode( $lift ) . ";\n"
            . "function style(id,text){if(document.getElementById(id))return;var s=document.createElement('style');s.id=id;s.textContent=text;document.head.appendChild(s);}\n"
            . "function run(){\n"
            . "style('sfaf-appt-lift',lift);\n"
            . "if(document.getElementById('sfaf-appt-modal')||window.SFAFAppointments)return;\n"
            . "style('sfaf-appt-style',css);\n"
            . "var box=document.createElement('div');box.innerHTML=html;document.body.appendChild(box.firstElementChild);\n"
            . $js[1] . "\n"
            . "}\n"
            . "if(document.body)run();else document.addEventListener('DOMContentLoaded',run);\n"
            . "})();\n";
    }
}
