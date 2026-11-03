<?php
/**
 * Credentials, stored apart from the ordinary settings, on purpose.
 *
 * The same design as the SFAF Calendar's credential store: one dedicated option,
 * never rewritten wholesale. set() reads the current array, changes the one key
 * it was asked to change and writes it back, so a save that knows nothing about
 * a key cannot erase it.
 *
 * ONE DIFFERENCE FROM THE CALENDAR: NOT AUTOLOADED. WordPress loads autoloaded
 * options on every request, front end included. The chat key is needed only when
 * a question is asked, so it stays out of that set.
 *
 * WHAT SURVIVES WHAT
 *   - A settings save: only absorb() writes here, key by key.
 *   - A plugin update: nothing in the update path touches this option.
 *   - Deactivate/reactivate: untouched.
 *   - Deleting the plugin: removed by uninstall.php. That is the one exit.
 *
 * SECRETS. get() returns values byte-for-byte. Nothing here logs, echoes or
 * returns a credential to the browser, and 'secret' fields are never rendered
 * back into their form inputs. The key must never enter the plugin code or the
 * repository.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Credentials {

    const OPTION = 'sfaf_chat_credentials';

    const DEFAULT_MODEL = 'gemini-3.6-flash';

    /** indexer route refuses to run with a token shorter than this. */
    const MIN_TOKEN_LENGTH = 24;

    /**
     * Every credential, and how its submitted value is treated.
     *
     *   secret  write-only in the UI. Blank means "keep what is stored".
     *   text    an identifier that round-trips visibly. Blank clears it.
     *
     * @return array<string,string> Key => type.
     */
    public static function keys() {
        return array(
            'api_key'     => 'secret',
            'index_token' => 'secret',
            'model'       => 'text',
        );
    }

    public static function all() {
        $stored = get_option( self::OPTION, array() );
        return is_array( $stored ) ? $stored : array();
    }

    public static function get( $key ) {
        $all = self::all();
        return isset( $all[ $key ] ) && is_string( $all[ $key ] ) ? $all[ $key ] : '';
    }

    public static function has( $key ) {
        return '' !== self::get( $key );
    }

    /** The model string, or the default when none has been saved. */
    public static function model() {
        $model = trim( self::get( 'model' ) );
        return '' !== $model ? $model : self::DEFAULT_MODEL;
    }

    public static function set( $key, $value ) {
        if ( ! array_key_exists( $key, self::keys() ) ) {
            return false;
        }
        $all         = self::all();
        $all[ $key ] = (string) $value;

        // The third argument is what keeps it out of the autoloaded set.
        update_option( self::OPTION, $all, false );
        return true;
    }

    /**
     * Take the credential fields out of a form submission.
     *
     * @param array $submitted Slashes already removed.
     * @return string[] Problems to show the admin. A rejected field keeps its
     *                  stored value.
     */
    public static function absorb( array $submitted ) {
        $problems = array();

        foreach ( self::keys() as $key => $type ) {
            if ( ! isset( $submitted[ $key ] ) ) {
                continue;
            }
            $value = trim( (string) $submitted[ $key ] );

            if ( 'secret' === $type && '' === $value ) {
                continue; // Blank means keep.
            }
            if ( 'index_token' === $key && strlen( $value ) < self::MIN_TOKEN_LENGTH ) {
                $problems[] = sprintf( 'The index token was not saved. It needs at least %d characters.', self::MIN_TOKEN_LENGTH );
                continue;
            }
            if ( 'model' === $key && '' !== $value && ! preg_match( '/^[a-z0-9][a-z0-9.\-]*$/i', $value ) ) {
                $problems[] = 'The model string was not saved. Use letters, numbers, dots and hyphens only.';
                continue;
            }
            self::set( $key, $value );
        }
        return $problems;
    }
}
