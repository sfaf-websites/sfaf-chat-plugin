<?php
/**
 * Ordinary settings: the widget switch, the raw-questions toggle and the special
 * instructions. Credentials are NOT here; see SFAF_Chat_Credentials.
 *
 * THE SWITCH DEFAULTS TO OFF, and that is what makes activating the plugin safe.
 * "off" is enforced in three places, so no single path can leak past it: the
 * widget is not enqueued, the stable script URL serves an empty script, and the
 * ask route refuses. See mode_allows_ask() and SFAF_Chat_Widget.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Settings {

    const OPTION = 'sfaf_chat_settings';

    const MODE_OFF    = 'off';
    const MODE_ADMINS = 'admins';
    const MODE_PUBLIC = 'public';

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

    public static function mode() {
        $mode = self::get( 'mode' );
        return in_array( $mode, array( self::MODE_ADMINS, self::MODE_PUBLIC ), true ) ? $mode : self::MODE_OFF;
    }

    /** May the current request ask a question? */
    public static function mode_allows_ask() {
        $mode = self::mode();
        if ( self::MODE_PUBLIC === $mode ) {
            return true;
        }
        return self::MODE_ADMINS === $mode && current_user_can( 'manage_options' );
    }

    /**
     * Raw question text is for internal testing only, so the toggle has no effect
     * once the widget is public. This holds even if somebody forgets to turn the
     * toggle off before launch.
     */
    public static function log_raw_questions() {
        return (bool) self::get( 'raw_questions' ) && self::MODE_PUBLIC !== self::mode();
    }
}
