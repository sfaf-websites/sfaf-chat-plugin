<?php
/**
 * Plugin Name: SFAF Chat
 * Plugin URI: https://sfaf.org
 * Description: The San Francisco AIDS Foundation website assistant. Answers questions about SFAF services from curated, staff-reviewed knowledge, with guardrails, de-identified analytics, and staff-approved pinned answers.
 * Version: 1.1.1
 * Author: San Francisco AIDS Foundation
 * Author URI: https://sfaf.org
 * License: GPL v2 or later
 * Text Domain: sfaf-chat
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SFAF_CHAT_VERSION', '1.1.1' );
define( 'SFAF_CHAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SFAF_CHAT_URL', plugin_dir_url( __FILE__ ) );
define( 'SFAF_CHAT_FILE', __FILE__ );

require_once SFAF_CHAT_DIR . 'includes/class-sfaf-chat-core.php';
require_once SFAF_CHAT_DIR . 'includes/class-sfaf-chat-credentials.php';
require_once SFAF_CHAT_DIR . 'includes/class-sfaf-chat-models.php';
require_once SFAF_CHAT_DIR . 'includes/class-sfaf-chat-settings.php';
require_once SFAF_CHAT_DIR . 'includes/class-sfaf-chat-log.php';
require_once SFAF_CHAT_DIR . 'includes/class-sfaf-chat-pinned.php';
require_once SFAF_CHAT_DIR . 'includes/class-sfaf-chat-starters.php';
require_once SFAF_CHAT_DIR . 'includes/class-sfaf-chat-appearance.php';
require_once SFAF_CHAT_DIR . 'includes/class-sfaf-chat-endpoint.php';
require_once SFAF_CHAT_DIR . 'includes/class-sfaf-chat-indexer.php';
require_once SFAF_CHAT_DIR . 'includes/class-sfaf-chat-widget.php';
require_once SFAF_CHAT_DIR . 'includes/class-sfaf-chat-install.php';
require_once SFAF_CHAT_DIR . 'includes/class-sfaf-chat-updater.php';

register_activation_hook( __FILE__, array( 'SFAF_Chat_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SFAF_Chat_Install', 'deactivate' ) );

/** Wire everything up. Each class registers its own hooks. */
function sfaf_chat_boot() {
    SFAF_Chat_Install::maybe_upgrade();

    add_action( SFAF_Chat_Log::CRON_HOOK, array( 'SFAF_Chat_Log', 'prune' ) );

    ( new SFAF_Chat_Endpoint() )->register();
    ( new SFAF_Chat_Widget() )->register();

    if ( is_admin() ) {
        require_once SFAF_CHAT_DIR . 'admin/class-sfaf-chat-admin.php';
        require_once SFAF_CHAT_DIR . 'admin/class-sfaf-chat-admin-appearance.php';
        require_once SFAF_CHAT_DIR . 'admin/class-sfaf-chat-admin-questions.php';
        require_once SFAF_CHAT_DIR . 'admin/class-sfaf-chat-dashboard.php';
        ( new SFAF_Chat_Admin() )->register();
    }

    /*
     * Not behind is_admin(). WordPress also runs its update check from cron,
     * and an updater that only loads on admin screens misses those checks.
     */
    ( new SFAF_Chat_Updater() )->register();
}
add_action( 'plugins_loaded', 'sfaf_chat_boot' );
