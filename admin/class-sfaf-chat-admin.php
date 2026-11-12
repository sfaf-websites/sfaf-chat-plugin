<?php
/**
 * The wp-admin area: the tabs, and the General, Special Instructions and Pinned
 * Answers screens. Appearance and Suggested Questions have their own classes, and use
 * gate(), the notices and tabs() from here. Administrators only.
 *
 * Every screen asks for manage_options twice: once in the menu registration, and
 * again in the render or save handler, because a handler can be reached directly.
 *
 * Interface copy follows SFAF's editorial guide: no em dashes.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Admin {

    const CAP          = 'manage_options';
    const MENU         = 'sfaf-chat';
    const PINS_PAGE    = 'sfaf-chat-pins';
    const DASH_PAGE    = 'sfaf-chat-dashboard';
    const SPECIAL_PAGE = 'sfaf-chat-instructions';
    const SPECIAL_ACTION = 'sfaf_chat_save_special';
    const MODELS_ACTION  = 'sfaf_chat_check_models';
    const SAVE_ACTION  = 'sfaf_chat_save_settings';
    const PIN_ACTION   = 'sfaf_chat_save_pin';
    const UNPIN_ACTION = 'sfaf_chat_delete_pin';

    /** One-screen messages, carried across the redirect after a save. */
    const NOTICE_TRANSIENT = 'sfaf_chat_notice_';

    private $appearance;
    private $questions;
    private $messages;
    private $indexing;
    private $contact;

    public function register() {
        $this->appearance = new SFAF_Chat_Admin_Appearance( $this );
        $this->questions  = new SFAF_Chat_Admin_Questions( $this );
        $this->messages   = new SFAF_Chat_Admin_Messages( $this );
        $this->indexing   = new SFAF_Chat_Admin_Index( $this );
        $this->indexing->register();
        $this->contact    = new SFAF_Chat_Admin_Contact( $this );
        $this->contact->register();
        $this->appearance->register();
        $this->questions->register();
        $this->messages->register();

        add_action( 'admin_post_' . self::SPECIAL_ACTION, array( $this, 'save_special' ) );
        add_action( 'admin_post_' . self::MODELS_ACTION, array( $this, 'check_models' ) );
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save_settings' ) );
        add_action( 'admin_post_' . self::PIN_ACTION, array( $this, 'save_pin' ) );
        add_action( 'admin_post_' . self::UNPIN_ACTION, array( $this, 'delete_pin' ) );
        add_filter( 'plugin_action_links_' . SFAF_Chat_Updater::basename(), array( $this, 'settings_link' ) );
    }

    public function menu() {
        add_menu_page( 'SFAF Chat', 'SFAF Chat', self::CAP, self::MENU, array( $this, 'render_settings' ), 'dashicons-format-chat', 58 );
        add_submenu_page( self::MENU, 'SFAF Chat Settings', 'General', self::CAP, self::MENU, array( $this, 'render_settings' ) );
        add_submenu_page( self::MENU, 'Appearance', 'Appearance', self::CAP, SFAF_Chat_Admin_Appearance::PAGE, array( $this->appearance, 'render' ) );
        add_submenu_page( self::MENU, 'Suggested Questions', 'Suggested Questions', self::CAP, SFAF_Chat_Admin_Questions::PAGE, array( $this->questions, 'render' ) );
        add_submenu_page( self::MENU, 'Welcome & Alerts', 'Welcome &amp; Alerts', self::CAP, SFAF_Chat_Admin_Messages::PAGE, array( $this->messages, 'render' ) );
        add_submenu_page( self::MENU, 'Indexing', 'Indexing', self::CAP, SFAF_Chat_Admin_Index::PAGE, array( $this->indexing, 'render' ) );
        add_submenu_page( self::MENU, 'Contact', 'Contact', self::CAP, SFAF_Chat_Admin_Contact::PAGE, array( $this->contact, 'render' ) );
        add_submenu_page( self::MENU, 'Special Instructions', 'Special Instructions', self::CAP, self::SPECIAL_PAGE, array( $this, 'render_special' ) );
        add_submenu_page( self::MENU, 'Pinned Answers', 'Pinned Answers', self::CAP, self::PINS_PAGE, array( $this, 'render_pins' ) );
        add_submenu_page( self::MENU, 'SFAF Chat Dashboard', 'Dashboard', self::CAP, self::DASH_PAGE, array( 'SFAF_Chat_Dashboard', 'render' ) );
    }

    public function assets( $hook ) {
        if ( false === strpos( (string) $hook, 'sfaf-chat' ) ) {
            return;
        }
        wp_enqueue_style( 'sfaf-chat-admin', SFAF_CHAT_URL . 'admin/css/admin.css', array(), SFAF_CHAT_VERSION );

        $is_appearance = false !== strpos( (string) $hook, SFAF_Chat_Admin_Appearance::PAGE );
        if ( $is_appearance || false !== strpos( (string) $hook, SFAF_Chat_Admin_Questions::PAGE ) ) {
            wp_enqueue_script( 'sfaf-chat-admin', SFAF_CHAT_URL . 'admin/js/admin.js', array(), SFAF_CHAT_VERSION, true );
            wp_add_inline_script( 'sfaf-chat-admin', 'window.sfafChatAdmin=' . wp_json_encode( array( 'pairs' => SFAF_Chat_Appearance::PAIRS ) ) . ';', 'before' );
        }
    }

    /** The same row of tabs on every screen. */
    public function tabs( $current ) {
        $tabs = array(
            self::MENU                       => 'General',
            SFAF_Chat_Admin_Appearance::PAGE => 'Appearance',
            SFAF_Chat_Admin_Questions::PAGE  => 'Suggested Questions',
            SFAF_Chat_Admin_Messages::PAGE   => 'Welcome & Alerts',
            SFAF_Chat_Admin_Index::PAGE      => 'Indexing',
            SFAF_Chat_Admin_Contact::PAGE    => 'Contact',
            self::SPECIAL_PAGE               => 'Special Instructions',
            self::PINS_PAGE                  => 'Pinned Answers',
            self::DASH_PAGE                  => 'Dashboard',
        );
        echo '<nav class="nav-tab-wrapper sfaf-chat-tabs" aria-label="SFAF Chat sections">';
        foreach ( $tabs as $slug => $label ) {
            printf(
                '<a class="nav-tab%s" href="%s"%s>%s</a>',
                $slug === $current ? ' nav-tab-active' : '',
                esc_url( admin_url( 'admin.php?page=' . $slug ) ),
                $slug === $current ? ' aria-current="page"' : '',
                esc_html( $label )
            );
        }
        echo '</nav>';
    }

    public function settings_link( $links ) {
        array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU ) ) . '">Settings</a>' );
        return $links;
    }

    public function gate() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'You do not have permission to do this.', 403 );
        }
    }

    /* ================================ NOTICES ============================= */

    public function stash_notice( array $messages, $type ) {
        set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), array( 'type' => $type, 'messages' => $messages ), MINUTE_IN_SECONDS );
    }

    public function show_notice() {
        $key    = self::NOTICE_TRANSIENT . get_current_user_id();
        $notice = get_transient( $key );
        if ( ! is_array( $notice ) ) {
            return;
        }
        delete_transient( $key );

        printf( '<div class="notice notice-%s is-dismissible">', esc_attr( $notice['type'] ) );
        foreach ( (array) $notice['messages'] as $message ) {
            echo '<p>' . esc_html( $message ) . '</p>';
        }
        echo '</div>';
    }

    /* ================================ SETTINGS ============================ */

    public function save_settings() {
        $this->gate();
        check_admin_referer( self::SAVE_ACTION );

        $post     = wp_unslash( $_POST );
        $problems = SFAF_Chat_Credentials::absorb( array(
            'api_key'     => $post['api_key'] ?? '',
            'index_token' => $post['index_token'] ?? '',
            'model'       => $post['model'] ?? '',
        ) );

        $mode = SFAF_Chat_Settings::MODE_ON === ( $post['mode'] ?? '' )
            ? SFAF_Chat_Settings::MODE_ON
            : SFAF_Chat_Settings::MODE_OFF;

        if ( SFAF_Chat_Settings::MODE_ON === $mode && ! SFAF_Chat_Credentials::has( 'api_key' ) ) {
            $problems[] = 'The widget stayed off because no Gemini API key is saved.';
            $mode       = SFAF_Chat_Settings::MODE_OFF;
        }

        SFAF_Chat_Settings::update( array(
            'mode'          => $mode,
            'raw_questions' => ! empty( $post['raw_questions'] ),
        ) );

        if ( array() === $problems ) {
            $this->stash_notice( array( 'Settings saved.' ), 'success' );
        } else {
            $this->stash_notice( $problems, 'warning' );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU ) );
        exit;
    }

    /** The only place that asks Google for the model list. */
    public function check_models() {
        $this->gate();
        check_admin_referer( self::MODELS_ACTION );

        $result = SFAF_Chat_Models::refresh();
        $this->stash_notice( array( $result['message'] ), $result['ok'] ? 'success' : 'warning' );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU ) );
        exit;
    }

    public function render_settings() {
        $this->gate();

        $settings = SFAF_Chat_Settings::all();
        $mode     = SFAF_Chat_Settings::mode();
        $has_key  = SFAF_Chat_Credentials::has( 'api_key' );
        $has_tok  = SFAF_Chat_Credentials::has( 'index_token' );
        ?>
        <div class="wrap sfaf-chat-admin">
            <h1>SFAF Chat Settings</h1>
            <?php $this->tabs( self::MENU ); ?>
            <?php $this->show_notice(); ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
                <?php wp_nonce_field( self::SAVE_ACTION ); ?>

                <h2>Widget switch</h2>
                <fieldset class="sfaf-chat-modes">
                    <legend class="screen-reader-text">Master switch</legend>
                    <label><input type="radio" name="mode" value="off" <?php checked( $mode, 'off' ); ?>>
                        <strong>Off.</strong> Off everywhere at once. The script tag loads nothing, and the endpoint refuses every question.</label>
                    <label><input type="radio" name="mode" value="on" <?php checked( $mode, 'on' ); ?>>
                        <strong>On.</strong> The widget appears on every page that carries the script tag, for anyone who visits it, and nowhere else.</label>
                </fieldset>
                <p class="description">This switch never decides where the widget shows. The script tag does: the plugin adds the widget to no page by itself, not even on this site. A change here reaches open pages within 5 minutes; the endpoint obeys it at once.</p>
                <p class="description">Script tag: <code>&lt;script src="<?php echo esc_html( SFAF_Chat_Widget::stable_url() ); ?>" defer&gt;&lt;/script&gt;</code></p>
                <p class="description">Before the tag goes on a page real visitors can reach: the Gemini key must be on a paid plan, the clinical knowledge reviewed, and SFAF privacy sign-off done.</p>

                <h2>Gemini</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sfaf-chat-key">API key</label></th>
                        <td>
                            <input type="password" id="sfaf-chat-key" name="api_key" class="regular-text" value="" autocomplete="new-password">
                            <p class="description"><?php echo $has_key ? 'A key is saved. Leave this blank to keep it, or type a new key to replace it.' : 'No key saved yet.'; ?> The saved key is never shown here.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-chat-model">Model</label></th>
                        <td>
                            <select id="sfaf-chat-model" name="model">
                                <?php
                                $saved_model = SFAF_Chat_Credentials::model();
                                $listed      = SFAF_Chat_Models::available();
                                foreach ( SFAF_Chat_Models::choices() as $choice ) {
                                    $note = SFAF_Chat_Credentials::DEFAULT_MODEL === $choice ? ' (default)' : '';
                                    $note = in_array( $choice, $listed, true ) ? $note : ' (saved, no longer listed)';
                                    printf( '<option value="%s"%s>%s</option>', esc_attr( $choice ), selected( $saved_model, $choice, false ), esc_html( $choice . $note ) );
                                }
                                ?>
                            </select>
                            <button type="submit" form="sfaf-chat-check-models" class="button">Check models</button>
                            <p class="description">Flash and Flash-Lite text models only. Check models asks Google what the saved key can use, so save any changes on this page first.
                                <?php $checked = SFAF_Chat_Models::checked_at(); echo $checked ? esc_html( 'Last checked ' . wp_date( 'M j, g:i a', $checked, SFAF_Chat_Core::timezone() ) . ' Pacific.' ) : 'Not checked yet, so this is the built-in list.'; ?></p>
                            <p class="description">Re-test the refusals after changing the model.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-chat-token">Index token</label></th>
                        <td>
                            <input type="password" id="sfaf-chat-token" name="index_token" class="regular-text" value="" autocomplete="new-password">
                            <p class="description"><?php echo $has_tok ? 'A token is saved. Leave this blank to keep it.' : 'No token saved yet. The indexer will not run without one.'; ?> At least 24 characters. The scheduler sends it to run the indexer.</p>
                        </td>
                    </tr>
                </table>

                <h2>Logging</h2>
                <p><label><input type="checkbox" name="raw_questions" value="1" <?php checked( ! empty( $settings['raw_questions'] ) ); ?>>
                    Log raw question and answer text, to review full exchanges on the Dashboard. For internal testing only. It records every question asked on any page with the script tag, and the assistant's answer, so turn it off before the tag goes on a page real visitors can reach. Still no IP address, user or session; rows are deleted after 60 days.</label></p>

                <?php submit_button( 'Save settings' ); ?>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sfaf-chat-check-models">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::MODELS_ACTION ); ?>">
                <?php wp_nonce_field( self::MODELS_ACTION ); ?>
            </form>

            <h2>Status</h2>
            <?php $this->render_status(); ?>
        </div>
        <?php
    }

    /** What the old health check reported, for administrators only. Never the key. */
    private function render_status() {
        $index     = SFAF_Chat_Core::read_json( SFAF_Chat_Core::index_path() );
        $next_cron = wp_next_scheduled( SFAF_Chat_Log::CRON_HOOK );
        $yes       = function ( $v ) { return $v ? 'Yes' : 'No'; };

        $rows = array(
            'Version'             => SFAF_CHAT_VERSION,
            'API key saved'       => $yes( SFAF_Chat_Credentials::has( 'api_key' ) ),
            'Model'               => SFAF_Chat_Credentials::model(),
            'System prompt found' => $yes( is_readable( SFAF_Chat_Core::prompt_path() ) ),
            'Knowledge entries'   => (string) count( SFAF_Chat_Core::kb_entries() ),
            'Pinned answers'      => (string) count( SFAF_Chat_Pinned::all() ),
            'Page question sets'  => (string) count( (array) ( SFAF_Chat_Starters::config()['sets'] ?? array() ) ),
            'Colors below AA'     => (string) count( SFAF_Chat_Appearance::problems( SFAF_Chat_Appearance::all() ) ),
            'Indexed pages'       => isset( $index['records'] ) ? (string) count( $index['records'] ) : '0',
            'Index last written'  => (string) ( $index['generated'] ?? 'Never' ),
            'Log table ready'     => $yes( SFAF_Chat_Log::table_exists() ),
            'Raw questions logged now' => $yes( SFAF_Chat_Settings::log_raw_questions() ),
            'Next log cleanup'    => $next_cron ? wp_date( 'M j, g:i a', $next_cron, SFAF_Chat_Core::timezone() ) . ' Pacific' : 'Not scheduled',
            'Stable script URL'   => SFAF_Chat_Widget::stable_url(),
        );

        echo '<table class="widefat striped sfaf-chat-status"><tbody>';
        foreach ( $rows as $label => $value ) {
            printf( '<tr><th scope="row">%s</th><td><code>%s</code></td></tr>', esc_html( $label ), esc_html( $value ) );
        }
        echo '</tbody></table>';
    }

    /* ========================== SPECIAL INSTRUCTIONS ====================== */

    public function save_special() {
        $this->gate();
        check_admin_referer( self::SPECIAL_ACTION );

        $text = wp_unslash( $_POST['special_instructions'] ?? '' );
        SFAF_Chat_Settings::update( array(
            'special_instructions' => trim( str_replace( "\r\n", "\n", is_string( $text ) ? $text : '' ) ),
        ) );

        $this->stash_notice( array( 'Special instructions saved.' ), 'success' );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::SPECIAL_PAGE ) );
        exit;
    }

    public function render_special() {
        $this->gate();
        ?>
        <div class="wrap sfaf-chat-admin">
            <h1>Special Instructions</h1>
            <?php $this->tabs( self::SPECIAL_PAGE ); ?>
            <?php $this->show_notice(); ?>

            <div class="sfaf-chat-explain">
                <h2>How this works</h2>
                <p>This text is added to the assistant's rules on every question, so staff can add rules without code.</p>
                <ul>
                    <li><strong>It adds to the guardrails and can't relax them.</strong> The assistant is told that the scope, safety and crisis rules always win.</li>
                    <li><strong>It takes effect on the next question.</strong> Try a few questions after any change, including ones the assistant should refuse.</li>
                    <li><strong>Pinned answers aren't affected,</strong> because the assistant never writes them.</li>
                    <li>If you clear this box, it stays clear. Updates don't put the first rule back.</li>
                </ul>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::SPECIAL_ACTION ); ?>">
                <?php wp_nonce_field( self::SPECIAL_ACTION ); ?>
                <h2><label for="sfaf-chat-special">Instructions</label></h2>
                <textarea id="sfaf-chat-special" name="special_instructions" rows="8" class="large-text"><?php echo esc_textarea( (string) SFAF_Chat_Settings::get( 'special_instructions' ) ); ?></textarea>
                <?php submit_button( 'Save instructions' ); ?>
            </form>
        </div>
        <?php
    }

    /* ============================= PINNED ANSWERS ========================= */

    public function save_pin() {
        $this->gate();
        check_admin_referer( self::PIN_ACTION );

        $result = SFAF_Chat_Pinned::from_input( wp_unslash( $_POST ) );

        if ( array() !== $result['errors'] ) {
            // Keep what was typed, so a rejected trigger doesn't cost the answer.
            set_transient( 'sfaf_chat_pin_draft_' . get_current_user_id(), $result['pin'], 5 * MINUTE_IN_SECONDS );
            $this->stash_notice( $result['errors'], 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PINS_PAGE . '&draft=1' ) );
            exit;
        }

        SFAF_Chat_Pinned::upsert( $result['pin'] );
        $this->stash_notice( array( 'Pinned answer saved.' ), 'success' );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PINS_PAGE ) );
        exit;
    }

    public function delete_pin() {
        $this->gate();
        check_admin_referer( self::UNPIN_ACTION );

        SFAF_Chat_Pinned::delete( sanitize_key( wp_unslash( $_REQUEST['id'] ?? '' ) ) );
        $this->stash_notice( array( 'Pinned answer deleted.' ), 'success' );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PINS_PAGE ) );
        exit;
    }

    public function render_pins() {
        $this->gate();

        $pins    = SFAF_Chat_Pinned::all();
        $editing = null;
        if ( ! empty( $_GET['draft'] ) ) {
            $editing = get_transient( 'sfaf_chat_pin_draft_' . get_current_user_id() );
            delete_transient( 'sfaf_chat_pin_draft_' . get_current_user_id() );
        } elseif ( ! empty( $_GET['edit'] ) ) {
            $editing = SFAF_Chat_Pinned::get( sanitize_key( wp_unslash( $_GET['edit'] ) ) );
        }
        $pin = is_array( $editing ) ? $editing : array( 'id' => '', 'label' => '', 'triggers' => '', 'exclude' => '', 'answer' => '', 'link_id' => '', 'order' => 10, 'enabled' => true );

        $tested = null;
        if ( isset( $_POST['test_question'] ) ) {
            check_admin_referer( 'sfaf_chat_test_pin' );
            $tested = (string) wp_unslash( $_POST['test_question'] );
        }
        ?>
        <div class="wrap sfaf-chat-admin">
            <h1>Pinned Answers</h1>
            <?php $this->tabs( self::PINS_PAGE ); ?>
            <?php $this->show_notice(); ?>

            <div class="sfaf-chat-explain">
                <h2>How matching works</h2>
                <p>A pinned answer is exact, approved wording. When it applies, visitors get your text word for word. The AI never rewrites it.</p>
                <ul>
                    <li><strong>Only the current message counts.</strong> Earlier messages in the conversation are ignored, so "how much is that?" will not match a pin about PrEP cost. Those follow-ups get a normal answer.</li>
                    <li><strong>Each line is one rule, and any one line can fire.</strong> Commas mean "and", and <code>|</code> means "or". <code>prep, cost | price | how much</code> needs "prep" and at least one of the other three.</li>
                    <li><strong>Quotes mean the whole question.</strong> <code>"how much does prep cost"</code> fires only if that is the entire question.</li>
                    <li><strong>Whole words only.</strong> "prep" does not match "preparation". List other forms yourself, such as <code>cost | costs</code>. Capitals, punctuation and accents are ignored.</li>
                    <li><strong>A line needs at least two parts,</strong> unless it is quoted. A single word would fire on far too many questions.</li>
                    <li><strong>Exclude words stop a pin.</strong> If any of them appear, the pin does not fire.</li>
                    <li><strong>The first match wins,</strong> going down the list by order number. Put specific pins before general ones.</li>
                    <li><strong>Spanish needs its own pin,</strong> with Spanish triggers and a Spanish answer.</li>
                </ul>
                <p><strong>Safety always comes first.</strong> Every message still goes through the assistant's guardrails. If a message is a crisis, a personal health or advice question, or off topic, the guarded reply is used and the pin does not fire, even when the words match. If Gemini is unavailable, the pin does not fire either, because nothing has checked the message.</p>
            </div>

            <h2>Test a question</h2>
            <p>Shows which pin the words match. This checks matching only. It doesn't call Gemini, so it can't show a pin being stopped by the guardrails.</p>
            <form method="post" class="sfaf-chat-test">
                <?php wp_nonce_field( 'sfaf_chat_test_pin' ); ?>
                <label class="screen-reader-text" for="sfaf-chat-test-q">Question to test</label>
                <input type="text" id="sfaf-chat-test-q" name="test_question" class="large-text" value="<?php echo esc_attr( (string) $tested ); ?>">
                <?php submit_button( 'Test', 'secondary', 'submit', false ); ?>
            </form>
            <?php
            if ( null !== $tested ) {
                $match = SFAF_Chat_Pinned::match( $tested );
                if ( $match ) {
                    printf( '<div class="notice notice-info inline"><p>Matches <strong>%s</strong>, through the rule <code>%s</code>.</p></div>', esc_html( (string) $match['label'] ), esc_html( (string) $match['matched_line'] ) );
                } else {
                    echo '<div class="notice notice-info inline"><p>No pin matches. The assistant would answer normally.</p></div>';
                }
            }
            ?>

            <h2>Pins</h2>
            <?php if ( array() === $pins ) : ?>
                <p>No pinned answers yet.</p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th>Order</th><th>Name</th><th>Triggers</th><th>Status</th><th><span class="screen-reader-text">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ( $pins as $row ) :
                        $delete = wp_nonce_url(
                            add_query_arg( array( 'action' => self::UNPIN_ACTION, 'id' => $row['id'] ), admin_url( 'admin-post.php' ) ),
                            self::UNPIN_ACTION
                        ); ?>
                        <tr>
                            <td><?php echo (int) ( $row['order'] ?? 0 ); ?></td>
                            <td><?php echo esc_html( (string) $row['label'] ); ?></td>
                            <td><code class="sfaf-chat-triggers"><?php echo esc_html( (string) $row['triggers'] ); ?></code></td>
                            <td><?php echo ! empty( $row['enabled'] ) ? 'On' : 'Off'; ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PINS_PAGE . '&edit=' . rawurlencode( (string) $row['id'] ) ) ); ?>">Edit</a> |
                                <a href="<?php echo esc_url( $delete ); ?>" onclick="return confirm('Delete this pinned answer?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php echo '' !== (string) $pin['id'] ? 'Edit pin' : 'Add a pin'; ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::PIN_ACTION ); ?>">
                <input type="hidden" name="id" value="<?php echo esc_attr( (string) $pin['id'] ); ?>">
                <?php wp_nonce_field( self::PIN_ACTION ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sfaf-pin-label">Name</label></th>
                        <td><input type="text" id="sfaf-pin-label" name="label" class="regular-text" value="<?php echo esc_attr( (string) $pin['label'] ); ?>">
                            <p class="description">For staff only, such as "PrEP cost".</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-pin-triggers">Triggers</label></th>
                        <td><textarea id="sfaf-pin-triggers" name="triggers" rows="4" class="large-text code"><?php echo esc_textarea( (string) $pin['triggers'] ); ?></textarea>
                            <p class="description">One rule per line. For example: <code>prep, cost | costs | price | how much</code></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-pin-exclude">Exclude words</label></th>
                        <td><input type="text" id="sfaf-pin-exclude" name="exclude" class="large-text" value="<?php echo esc_attr( (string) $pin['exclude'] ); ?>">
                            <p class="description">Optional, separated by commas. For example: <code>pep, doxypep</code></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-pin-answer">Approved answer</label></th>
                        <td><textarea id="sfaf-pin-answer" name="answer" rows="5" class="large-text"><?php echo esc_textarea( (string) $pin['answer'] ); ?></textarea>
                            <p class="description">Shown to visitors exactly as typed, as plain text.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-pin-link">Link</label></th>
                        <td><select id="sfaf-pin-link" name="link_id">
                                <option value="">No link</option>
                                <?php foreach ( SFAF_Chat_Core::kb_entries() as $entry ) : ?>
                                    <option value="<?php echo esc_attr( (string) $entry['id'] ); ?>" <?php selected( (string) $pin['link_id'], (string) $entry['id'] ); ?>><?php echo esc_html( (string) ( $entry['title'] ?? $entry['id'] ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Optional. Links come from the knowledge base, so a mistyped address can't reach a visitor.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-pin-order">Order</label></th>
                        <td><input type="number" id="sfaf-pin-order" name="order" class="small-text" value="<?php echo (int) $pin['order']; ?>">
                            <p class="description">Lower numbers are checked first.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Status</th>
                        <td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $pin['enabled'] ) ); ?>> On</label></td>
                    </tr>
                </table>
                <?php submit_button( '' !== (string) $pin['id'] ? 'Save pin' : 'Add pin' ); ?>
            </form>
        </div>
        <?php
    }
}
