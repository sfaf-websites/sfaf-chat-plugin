<?php
/**
 * Which Gemini models can be chosen, so the Model setting can't hold a typo.
 *
 * The list is BUILT_IN until somebody presses Check models, which asks Google what
 * the saved key can use and stores the result. That is the only time Google is
 * asked: never on a page load. A failed check changes nothing.
 *
 * ONLY MODELS THAT SUIT A TEXT Q&A BOT. keep() takes plain Flash and Flash-Lite and
 * drops Pro, image, speech, previews and "-latest" aliases, because an alias can change
 * what answers health questions without anybody re-testing the refusals.
 *
 * REDIRECTED is the same idea: Google lists those names, but sends their requests to
 * a different model, or will soon. Picking one would hide which model really answers.
 *
 * A saved model that drops off the list stays saved and stays in the dropdown, marked.
 * Changing it silently would swap the model with nobody having tested the new one.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Models {

    const OPTION = 'sfaf_chat_models';

    /** From Google's list for the SFAF key on 2026-11-04. */
    const BUILT_IN = array(
        'gemini-3.8-flash',
        'gemini-3.6-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.1-flash-lite',
        'gemini-2.5-flash-lite',
    );

    const REDIRECTED = array( 'gemini-3.7-flash', 'gemini-3.5-flash', 'gemini-2.5-flash' );

    /** @return string[] The stored list, or the built-in one. */
    public static function available() {
        $stored = get_option( self::OPTION );
        if ( is_array( $stored ) && ! empty( $stored['models'] ) && is_array( $stored['models'] ) ) {
            return array_values( array_filter( $stored['models'], 'is_string' ) );
        }
        return self::BUILT_IN;
    }

    /** When the list was last refreshed, as a timestamp, or 0. */
    public static function checked_at() {
        $stored = get_option( self::OPTION );
        return is_array( $stored ) ? (int) ( $stored['checked'] ?? 0 ) : 0;
    }

    /** @return string[] What the dropdown offers: the list, plus the saved model. */
    public static function choices() {
        $list  = self::available();
        $saved = SFAF_Chat_Credentials::model();
        if ( ! in_array( $saved, $list, true ) ) {
            array_unshift( $list, $saved );
        }
        return $list;
    }

    public static function allowed( $model ) {
        return in_array( (string) $model, self::choices(), true );
    }

    /**
     * Google's list down to what suits this bot, newest first, Flash before Flash-Lite.
     *
     * @param array $models The `models` array from Google's reply.
     * @return string[]
     */
    public static function keep( array $models ) {
        $out = array();
        foreach ( $models as $m ) {
            if ( ! is_array( $m ) || ! in_array( 'generateContent', (array) ( $m['supportedGenerationMethods'] ?? array() ), true ) ) {
                continue;
            }
            $name = preg_replace( '#^models/#', '', (string) ( $m['name'] ?? '' ) );
            if ( 1 === preg_match( '/^gemini-\d+(\.\d+)?-flash(-lite)?$/', $name ) && ! in_array( $name, self::REDIRECTED, true ) ) {
                $out[] = $name;
            }
        }
        $out = array_values( array_unique( $out ) );
        usort( $out, function ( $a, $b ) {
            $va = explode( '-', $a )[1];
            $vb = explode( '-', $b )[1];
            return version_compare( $vb, $va ) ?: strlen( $a ) <=> strlen( $b );
        } );
        return $out;
    }

    /**
     * Ask Google, and store the answer only if it is usable.
     *
     * @return array{ok:bool,message:string} The message is for the admin. It never
     *               holds Google's reply, which can echo request details.
     */
    public static function refresh() {
        $kept = ' The existing list was kept.';

        $key = trim( SFAF_Chat_Credentials::get( 'api_key' ) );
        if ( '' === $key ) {
            return array( 'ok' => false, 'message' => 'Save an API key first, because the check uses the saved key.' . $kept );
        }

        $api_base = rtrim( (string) apply_filters( 'sfaf_chat_api_base', 'https://generativelanguage.googleapis.com/v1beta' ), '/' );
        $reply    = wp_remote_get( $api_base . '/models?pageSize=1000', array(
            'headers' => array( 'x-goog-api-key' => $key ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $reply ) ) {
            return array( 'ok' => false, 'message' => 'Google could not be reached.' . $kept );
        }
        $code = (int) wp_remote_retrieve_response_code( $reply );
        if ( in_array( $code, array( 400, 401, 403 ), true ) ) {
            return array( 'ok' => false, 'message' => "Google refused the saved API key (HTTP $code)." . $kept );
        }
        if ( 200 !== $code ) {
            return array( 'ok' => false, 'message' => "Google's model list is unavailable right now (HTTP $code)." . $kept );
        }

        $body   = json_decode( (string) wp_remote_retrieve_body( $reply ), true );
        $models = self::keep( is_array( $body['models'] ?? null ) ? $body['models'] : array() );
        if ( array() === $models ) {
            return array( 'ok' => false, 'message' => "Google's reply had no suitable models." . $kept );
        }

        update_option( self::OPTION, array( 'models' => $models, 'checked' => time() ), false );

        $message = sprintf( 'Model list updated: %d models for this key.', count( $models ) );
        $saved   = SFAF_Chat_Credentials::model();
        if ( ! in_array( $saved, $models, true ) ) {
            return array( 'ok' => false, 'message' => $message . " The saved model, $saved, is not one of them. It stays saved until you pick another." );
        }
        return array( 'ok' => true, 'message' => $message );
    }
}
