<?php
/**
 * Ordinary settings: the widget switch, the raw-questions toggle and the special
 * instructions. Credentials are NOT here; see SFAF_Chat_Credentials.
 *
 * THE SWITCH IS ON OR OFF, and defaults to off, which is what makes activating the
 * plugin safe. It is a master switch only: it never decides WHERE the widget
 * appears. Placement does that. The widget shows on a page only when someone has
 * put the script tag there by hand; the plugin injects it nowhere, not even here.
 *
 * "Off" is enforced in three places, so no single path leaks past it: the stable
 * script URL serves an empty script, and the ask and starters routes refuse.
 * See is_on() and SFAF_Chat_Widget.
 *
 * BEFORE 1.3.0 there were two "on" values. A stored "public" still reads as on.
 * A stored "admins" reads as OFF: it never let anyone but a logged-in admin on
 * this site use the widget, and turning it into "on for any page with the tag"
 * must be somebody's deliberate choice, not a side effect of an update.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Settings {

    const OPTION = 'sfaf_chat_settings';

    const MODE_OFF = 'off';
    const MODE_ON  = 'on';

    /** Stored by 1.2.0 and earlier; read as on. See the note at the top. */
    const LEGACY_PUBLIC = 'public';

    /** First-run text for the special instructions. Editable, and may be cleared. */
    const SEED_INSTRUCTIONS = 'Never use em dashes; use commas or periods instead';

    public static function defaults() {
        return array(
            'mode'                 => self::MODE_OFF,
            'raw_questions'        => false,
            'special_instructions' => '',
        );
    }

    /**
     * Create the option once. add_option() does nothing if it already exists, so
     * this runs safely after every update, and instructions that staff cleared
     * on purpose stay cleared.
     */
    public static function seed() {
        add_option(
            self::OPTION,
            array_merge( self::defaults(), array( 'special_instructions' => self::SEED_INSTRUCTIONS ) )
        );
    }

    public static function all() {
        $stored = get_option( self::OPTION, array() );
        return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
    }

    public static function get( $key ) {
        $all = self::all();
        return $all[ $key ] ?? null;
    }

    public static function update( array $changes ) {
        update_option( self::OPTION, array_merge( self::all(), $changes ) );
    }

    /** 'on' or 'off'. Nothing else is ever returned. */
    public static function mode() {
        $mode = self::get( 'mode' );
        return ( self::MODE_ON === $mode || self::LEGACY_PUBLIC === $mode ) ? self::MODE_ON : self::MODE_OFF;
    }

    /** The master switch. Gates the stable script, ask and starters alike. */
    public static function is_on() {
        return self::MODE_ON === self::mode();
    }

    /**
     * Raw question text, for internal testing. The plugin can no longer tell a
     * test page from a page real visitors see, because placement decides that, so
     * this follows the toggle alone. The settings screen says so next to it.
     */
    public static function log_raw_questions() {
        return (bool) self::get( 'raw_questions' ) && self::is_on();
    }
}
