<?php
/**
 * The widget's look: colors, corner radius, font and placement.
 *
 * Nothing here is secret, and the option is not autoloaded. It is written only by the
 * Appearance screen, so a plugin update never touches it. With nothing saved, the
 * defaults are the SFAF brand.
 *
 * EVERY VALUE IS VALIDATED HERE, BEFORE IT IS STORED. Colors must be six digit hex,
 * numbers are clamped, and a font can hold only name characters. These values travel
 * to every page the widget runs on, so nothing free-form may reach them.
 *
 * READABLE TEXT. Below-AA pairs are reported, not blocked: staff may have a reason,
 * but it can never happen silently. See problems(). The exception is text on the
 * accent color, which is not a setting at all. It is always worked out from the accent
 * by on_accent(), so dark text on yellow is enforced rather than requested.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Appearance {

    const OPTION = 'sfaf_chat_appearance';

    /** WCAG AA for body text. */
    const AA = 4.5;

    const RADIUS_MAX = 24;
    const OFFSET_MAX = 400;
    const GAP_MAX    = 100;

    const DEFAULT_FONT = 'Geologica,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif';

    /** Every editable color: key => label, brand default, screen group, help. */
    const COLORS = array(
        'dark'         => array( 'Dark base', '#373433', 'Brand', 'Header, launcher, and dark buttons.' ),
        'on_dark'      => array( 'Text on dark', '#ffffff', 'Brand', 'Text and icons on the dark base.' ),
        'accent'       => array( 'Accent', '#ffd900', 'Brand', 'Header rule, send button, link button, launcher edge.' ),
        'surface'      => array( 'Panel surface', '#ffffff', 'Surfaces', 'Input row, cards and footer.' ),
        'surface_warm' => array( 'Conversation background', '#f4f3f0', 'Surfaces', 'Behind the messages.' ),
        'border'       => array( 'Borders', '#d1d3d4', 'Surfaces', 'Lines around bubbles and fields.' ),
        'text'         => array( 'Text', '#373433', 'Text', 'Main text outside the bubbles.' ),
        'muted'        => array( 'Secondary text', '#5f5b58', 'Text', 'Footer, hints and placeholder.' ),
        'bot_bg'       => array( 'Assistant bubble', '#ffffff', 'Bubbles', '' ),
        'bot_text'     => array( 'Assistant bubble text', '#373433', 'Bubbles', '' ),
        'user_bg'      => array( 'Visitor bubble', '#373433', 'Bubbles', '' ),
        'user_text'    => array( 'Visitor bubble text', '#ffffff', 'Bubbles', '' ),
    );

    /** The text and background pairs that must stay readable: text, background, name. */
    const PAIRS = array(
        array( 'text', 'surface', 'Text on the panel surface' ),
        array( 'text', 'surface_warm', 'Text on the conversation background' ),
        array( 'muted', 'surface', 'Secondary text on the panel surface' ),
        array( 'muted', 'surface_warm', 'Secondary text on the conversation background' ),
        array( 'on_dark', 'dark', 'Text on the dark base' ),
        array( 'bot_text', 'bot_bg', 'Assistant bubble text' ),
        array( 'user_text', 'user_bg', 'Visitor bubble text' ),
    );

    public static function defaults() {
        $out = array();
        foreach ( self::COLORS as $key => $color ) {
            $out[ $key ] = $color[1];
        }
        return array_merge( $out, array(
            'radius'    => 0,
            'font_mode' => 'custom',
            'font'      => self::DEFAULT_FONT,
            'corner'    => 'right',
            'offset_x'  => 40,
            'offset_y'  => 0,
            'weglot'    => true,
            'gap'       => 8,
        ) );
    }

    public static function all() {
        $stored = get_option( self::OPTION, array() );
        return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
    }

    public static function save( array $values ) {
        update_option( self::OPTION, $values, false );
    }

    public static function reset() {
        delete_option( self::OPTION );
    }

    /* ============================== VALIDATION ============================ */

    /** Lowercase six digit hex, or null. Three digit shorthand is expanded. */
    public static function hex( $value ) {
        $value = strtolower( trim( (string) $value ) );
        if ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m ) ) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        return preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : null;
    }

    private static function clamp( $value, $max ) {
        return max( 0, min( $max, (int) $value ) );
    }

    /** A form post to clean values: array( 'values' => ..., 'errors' => list ). */
    public static function from_input( array $input ) {
        $current = self::all();
        $values  = $current;
        $errors  = array();

        foreach ( self::COLORS as $key => $color ) {
            $hex = self::hex( $input[ $key ] ?? '' );
            if ( null === $hex ) {
                $errors[] = $color[0] . ' is not a six digit color such as #373433, so it was left as it was.';
            } else {
                $values[ $key ] = $hex;
            }
        }

        $font = trim( (string) preg_replace( '/[^\p{L}\p{N} ,"\'_-]/u', '', (string) ( $input['font'] ?? '' ) ) );
        if ( '' === $font ) {
            $font = self::DEFAULT_FONT;
        }

        return array(
            'values' => array_merge( $values, array(
                'radius'    => self::clamp( $input['radius'] ?? 0, self::RADIUS_MAX ),
                'font_mode' => 'site' === ( $input['font_mode'] ?? '' ) ? 'site' : 'custom',
                'font'      => mb_substr( $font, 0, 200 ),
                'corner'    => 'left' === ( $input['corner'] ?? '' ) ? 'left' : 'right',
                'offset_x'  => self::clamp( $input['offset_x'] ?? 0, self::OFFSET_MAX ),
                'offset_y'  => self::clamp( $input['offset_y'] ?? 0, self::OFFSET_MAX ),
                'weglot'    => ! empty( $input['weglot'] ),
                'gap'       => self::clamp( $input['gap'] ?? 0, self::GAP_MAX ),
            ) ),
            'errors' => $errors,
        );
    }

    /* =============================== CONTRAST ============================= */

    private static function rgb( $hex ) {
        return array_map( 'hexdec', str_split( substr( $hex, 1 ), 2 ) );
    }

    private static function luminance( $hex ) {
        $c = array_map( function ( $v ) {
            $v /= 255;
            return $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
        }, self::rgb( $hex ) );
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    public static function contrast( $a, $b ) {
        $x = self::luminance( $a );
        $y = self::luminance( $b );
        return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
    }

    /**
     * Text color for the accent. The dark base when that is readable, otherwise the
     * first that is. Black or white always passes, so this can't come back unreadable.
     * admin/js/admin.js repeats this for the live preview.
     */
    public static function on_accent( array $v ) {
        foreach ( array( $v['dark'], '#373433', '#000000', '#ffffff' ) as $candidate ) {
            if ( self::contrast( $candidate, $v['accent'] ) >= self::AA ) {
                return $candidate;
            }
        }
        return '#000000';
    }

    /** One sentence per pair below AA. Rounded down, so 4.49 can't read as 4.5. */
    public static function problems( array $v ) {
        $out = array();
        foreach ( self::PAIRS as $pair ) {
            $ratio = self::contrast( $v[ $pair[0] ], $v[ $pair[1] ] );
            if ( $ratio < self::AA ) {
                $out[] = sprintf( '%s is %s to 1. Readable text needs at least 4.5 to 1.', $pair[2], number_format( floor( $ratio * 100 ) / 100, 2 ) );
            }
        }
        return $out;
    }

    /* ============================= FOR THE WIDGET ========================= */

    private static function mix( $from, $to, $amount ) {
        $a = self::rgb( $from );
        $b = self::rgb( $to );
        $out = '#';
        foreach ( array( 0, 1, 2 ) as $i ) {
            $out .= sprintf( '%02x', (int) round( $a[ $i ] + ( $b[ $i ] - $a[ $i ] ) * $amount ) );
        }
        return $out;
    }

    /** The CSS variables, without their --sfaf- prefix. */
    public static function css_vars( array $v ) {
        $vars = array();
        foreach ( array_keys( self::COLORS ) as $key ) {
            $vars[ str_replace( '_', '-', $key ) ] = $v[ $key ];
        }
        return array_merge( $vars, array(
            'dark-hover'    => self::mix( $v['dark'], $v['on_dark'], 0.1 ),
            'accent-hover'  => self::mix( $v['accent'], '#ffffff', 0.3 ),
            'on-accent'     => self::on_accent( $v ),
            'focus'         => $v['text'],
            'focus-on-dark' => self::contrast( $v['accent'], $v['dark'] ) >= 3 ? $v['accent'] : $v['on_dark'],
            'radius'        => (int) $v['radius'] . 'px',
            'font'          => 'site' === $v['font_mode'] ? 'inherit' : $v['font'],
        ) );
    }

    /** What widget.js receives in sfafChatConfig.theme. */
    public static function theme() {
        $v = self::all();
        return array(
            'vars'      => self::css_vars( $v ),
            'placement' => array(
                'corner' => $v['corner'],
                'x'      => (int) $v['offset_x'],
                'y'      => (int) $v['offset_y'],
                'weglot' => (bool) $v['weglot'],
                'gap'    => (int) $v['gap'],
            ),
        );
    }
}
