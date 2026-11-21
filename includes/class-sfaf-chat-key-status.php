<?php
/**
 * Whether each saved key works (1.28.0), without ever showing it.
 *
 * Three keys: Gemini (General), the Postmark server token (Contact) and Google Cloud
 * Text-to-Speech (General, or config.php). For each, one light, read-only call:
 *   - Gemini: the saved model's details (GET models/{model}). No text is generated.
 *   - Postmark: the server's details (GET /server). No email is sent.
 *   - Text-to-Speech: the voice list (GET voices), which also refreshes the dropdowns.
 *     No audio is made.
 *
 * WHEN. After a key is saved, and on "Test". A screen showing a key whose result is
 * not cached yet (a key saved before 1.28.0, or one read from config.php) checks it
 * once; after that the cached result is shown and no service is called on load.
 *
 * WHAT IS KEPT, in sfaf_chat_key_status (not autoloaded): per service, the state,
 * when, and a 16 character HMAC of the key, salted with this site's salt, so a
 * changed key (here or in config.php) is never shown another key's result. The key
 * itself is never stored here, echoed, logged or sent to the browser.
 *
 * States: ok, rejected (the service refused the key), unreachable (could not tell:
 * no answer, overload, billing), none (no key).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Key_Status {

    const OPTION = 'sfaf_chat_key_status';
    const ACTION = 'sfaf_chat_test_key';

    /** service => what the screen calls it. */
    const SERVICES = array(
        'gemini'   => 'Gemini',
        'postmark' => 'Postmark',
        'tts'      => 'Google Cloud Text-to-Speech',
    );

    const POSTMARK_SERVER = 'https://api.postmarkapp.com/server';
    const TIMEOUT         = 10;

    /** The key in use for a service, or ''. Never leaves the server. */
    private static function key( $service ) {
        switch ( $service ) {
            case 'gemini':
                return trim( SFAF_Chat_Credentials::get( 'api_key' ) );
            case 'postmark':
                return trim( SFAF_Chat_Credentials::get( 'postmark_token' ) );
            case 'tts':
                return SFAF_Chat_Voice::api_key();
        }
        return '';
    }

    private static function fingerprint( $key ) {
        return substr( hash_hmac( 'sha256', (string) $key, wp_salt( 'auth' ) . '|sfaf-chat-key-status' ), 0, 16 );
    }

    private static function stored() {
        $s = get_option( self::OPTION, array() );
        return is_array( $s ) ? $s : array();
    }

    /** The result for the key in use now: {state, checked}. state 'none' without a key. */
    public static function get( $service, $check_if_unknown = true ) {
        $key = self::key( $service );
        if ( '' === $key ) {
            return array( 'state' => 'none', 'checked' => 0 );
        }
        $row = self::stored()[ $service ] ?? null;
        if ( is_array( $row ) && ( $row['fp'] ?? '' ) === self::fingerprint( $key ) ) {
            return array( 'state' => (string) $row['state'], 'checked' => (int) $row['checked'] );
        }
        return $check_if_unknown ? self::check( $service ) : array( 'state' => 'unknown', 'checked' => 0 );
    }

    /** Ask the service now, and keep the answer. */
    public static function check( $service ) {
        $key = self::key( $service );
        if ( ! isset( self::SERVICES[ $service ] ) || '' === $key ) {
            return array( 'state' => 'none', 'checked' => 0 );
        }
        $http = self::probe( $service, $key );
        if ( 200 === $http || ( 'gemini' === $service && 404 === $http ) ) {
            // A 404 for Gemini is the model's name, not the key: it got past the key.
            $state = 'ok';
        } elseif ( in_array( $http, array( 400, 401, 403, 422 ), true ) ) {
            $state = 'rejected';
        } else {
            $state = 'unreachable';
        }
        $all             = self::stored();
        $all[ $service ] = array( 'state' => $state, 'checked' => time(), 'fp' => self::fingerprint( $key ) );
        update_option( self::OPTION, $all, false );
        return array( 'state' => $state, 'checked' => time() );
    }

    /** The HTTP status of the read-only call, 0 for no answer. */
    private static function probe( $service, $key ) {
        if ( 'tts' === $service ) {
            return (int) SFAF_Chat_Voice::voice_list( true )['http'];
        }
        if ( 'postmark' === $service ) {
            $reply = wp_remote_get( self::POSTMARK_SERVER, array(
                'timeout' => self::TIMEOUT,
                'headers' => array( 'Accept' => 'application/json', 'X-Postmark-Server-Token' => $key ),
            ) );
        } else {
            $base  = rtrim( (string) apply_filters( 'sfaf_chat_api_base', 'https://generativelanguage.googleapis.com/v1beta' ), '/' );
            $reply = wp_remote_get( $base . '/models/' . rawurlencode( SFAF_Chat_Credentials::model() ), array(
                'timeout' => self::TIMEOUT,
                'headers' => array( 'x-goog-api-key' => $key ),
            ) );
        }
        return is_wp_error( $reply ) ? 0 : (int) wp_remote_retrieve_response_code( $reply );
    }

    /** The Test link: back to the screen it was pressed on. */
    public static function test_url( $service ) {
        return wp_nonce_url( add_query_arg( array( 'action' => self::ACTION, 'service' => $service ), admin_url( 'admin-post.php' ) ), self::ACTION . '_' . $service );
    }

    /**
     * The status line under a key field: an icon and words, never color alone.
     * Green square check for working, red square cross for rejected, plain muted text
     * for no key and for a check that could not reach the service.
     */
    public static function render( $service ) {
        $name = self::SERVICES[ $service ] ?? $service;
        $st   = self::get( $service );
        if ( 'none' === $st['state'] ) {
            echo '<p class="description sfaf-key-status sfaf-key-none" data-key-status="none">No key saved</p>';
            return;
        }
        $icons = array(
            'ok'       => '<svg class="sfaf-key-icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><rect width="16" height="16" fill="#8CC745"/><path d="M3.5 8.4l3 3 6-6.6" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="square"/></svg>',
            'rejected' => '<svg class="sfaf-key-icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><rect width="16" height="16" fill="#F04937"/><path d="M4.5 4.5l7 7M11.5 4.5l-7 7" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="square"/></svg>',
        );
        $words = array(
            'ok'          => 'Key saved and working',
            'rejected'    => sprintf( 'Key saved, but %s rejected it', $name ),
            'unreachable' => sprintf( 'Key saved. %s could not be reached to check it', $name ),
        );
        $state = isset( $words[ $st['state'] ] ) ? $st['state'] : 'unreachable';
        printf(
            '<p class="sfaf-key-status sfaf-key-%1$s" data-key-status="%1$s" role="status">%2$s<span class="sfaf-key-words">%3$s</span>%4$s <a class="sfaf-key-test" href="%5$s">Test<span class="screen-reader-text"> the %6$s key again</span></a></p>',
            esc_attr( $state ),
            $icons[ $state ] ?? '', // phpcs:ignore -- fixed markup above.
            esc_html( $words[ $state ] ),
            $st['checked'] ? '<span class="sfaf-key-when">' . esc_html( 'Checked ' . wp_date( 'M j, g:i a', $st['checked'], SFAF_Chat_Core::timezone() ) . ' Pacific.' ) . '</span>' : '',
            esc_url( self::test_url( $service ) ),
            esc_html( $name )
        );
    }
}
