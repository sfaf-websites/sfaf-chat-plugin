<?php
/**
 * Activation, upgrade and deactivation.
 *
 * run() is safe to repeat and runs again after every update, because WordPress
 * does not fire the activation hook on an update. It must never reset anything
 * staff have set: no credential, setting or pinned answer is written here except
 * through seed(), which only creates the option if it is missing.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Install {

    const VERSION_OPTION = 'sfaf_chat_installed_version';

    public static function activate() {
        self::run();
        SFAF_Chat_Log::prune();
    }

    public static function maybe_upgrade() {
        if ( get_option( self::VERSION_OPTION ) !== SFAF_CHAT_VERSION ) {
            self::run();
        }
    }

    private static function run() {
        SFAF_Chat_Log::create_table();
        SFAF_Chat_Settings::seed();
        SFAF_Chat_Core::data_dir();

        if ( ! wp_next_scheduled( SFAF_Chat_Log::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SFAF_Chat_Log::CRON_HOOK );
        }
        update_option( self::VERSION_OPTION, SFAF_CHAT_VERSION );
    }

    /**
     * Stops the daily job and nothing else. Credentials and pins survive, and
     * rows stay until reactivation prunes them or uninstall removes them.
     */
    public static function deactivate() {
        SFAF_Chat_Log::prune();
        wp_clear_scheduled_hook( SFAF_Chat_Log::CRON_HOOK );
    }
}
