<?php
/**
 * The Welcome & Alerts screen: the chat's opening message, scheduled alerts, and a
 * read-only view of the closures the assistant can see from the SFAF Calendar.
 *
 * Alerts are added one at a time and deleted one at a time; there is nothing to
 * reorder. Each form posts to admin-post.php with its own nonce, and every handler
 * goes through SFAF_Chat_Admin::gate() (manage_options).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Admin_Messages {

    const PAGE           = 'sfaf-chat-messages';
    const WELCOME_ACTION = 'sfaf_chat_save_welcome';
    const ALERT_ACTION   = 'sfaf_chat_save_alert';
    const DELETE_ACTION  = 'sfaf_chat_delete_alert';

    private $admin;

    public function __construct( SFAF_Chat_Admin $admin ) {
        $this->admin = $admin;
    }

    public function register() {
        add_action( 'admin_post_' . self::WELCOME_ACTION, array( $this, 'save_welcome' ) );
        add_action( 'admin_post_' . self::ALERT_ACTION, array( $this, 'save_alert' ) );
        add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'delete_alert' ) );
    }

    private function back() {
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }

    public function save_welcome() {
        $this->admin->gate();
        check_admin_referer( self::WELCOME_ACTION );

        $post = wp_unslash( $_POST );
        $text = ! empty( $post['reset'] ) ? '' : SFAF_Chat_Settings::clean_welcome( $post['welcome'] ?? '' );
        SFAF_Chat_Settings::update( array( 'welcome' => $text ) );

        $this->admin->stash_notice( array( '' === $text ? 'Welcome message set to the default.' : 'Welcome message saved. Pages with the chat show it within 5 minutes.' ), 'success' );
        $this->back();
    }

    public function save_alert() {
        $this->admin->gate();
        check_admin_referer( self::ALERT_ACTION );

        $post   = wp_unslash( $_POST );
        $result = SFAF_Chat_Alerts::save( '', $post['text'] ?? '', $post['start'] ?? '', $post['end'] ?? '' );

        if ( is_wp_error( $result ) ) {
            $this->admin->stash_notice( array( 'The alert was not saved: ' . $result->get_error_message() ), 'error' );
        } else {
            $this->admin->stash_notice( array( 'Alert saved.' ), 'success' );
        }
        $this->back();
    }

    public function delete_alert() {
        $this->admin->gate();
        check_admin_referer( self::DELETE_ACTION );

        $id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
        SFAF_Chat_Alerts::delete( $id )
            ? $this->admin->stash_notice( array( 'Alert deleted.' ), 'success' )
            : $this->admin->stash_notice( array( 'That alert no longer exists.' ), 'warning' );
        $this->back();
    }

    /** "Thu, Nov 26, 2026" for a Y-m-d date. */
    private static function day( $ymd ) {
        $d = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd, SFAF_Chat_Core::timezone() );
        return $d ? $d->format( 'D, M j, Y' ) : (string) $ymd;
    }

    public function render() {
        $this->admin->gate();

        $saved   = trim( (string) SFAF_Chat_Settings::get( 'welcome' ) );
        $alerts  = SFAF_Chat_Alerts::all();
        $today   = SFAF_Chat_Alerts::today();
        $labels  = array( 'active' => 'Showing now', 'scheduled' => 'Scheduled', 'ended' => 'Ended' );
        ?>
        <div class="wrap sfaf-chat-admin">
            <h1>Welcome &amp; Alerts</h1>
            <?php $this->admin->tabs( self::PAGE ); ?>
            <?php $this->admin->show_notice(); ?>

            <h2>Welcome message</h2>
            <p>The first thing the chat says when someone opens it. Plain text. Leave it short and open-ended.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::WELCOME_ACTION ); ?>">
                <?php wp_nonce_field( self::WELCOME_ACTION ); ?>
                <textarea id="sfaf-chat-welcome" name="welcome" rows="3" class="large-text" maxlength="<?php echo (int) SFAF_Chat_Settings::MAX_WELCOME; ?>" aria-describedby="sfaf-chat-welcome-help"><?php echo esc_textarea( '' !== $saved ? $saved : SFAF_Chat_Settings::DEFAULT_WELCOME ); ?></textarea>
                <p class="description" id="sfaf-chat-welcome-help">Default: “<?php echo esc_html( SFAF_Chat_Settings::DEFAULT_WELCOME ); ?>” Pages with the chat show a change within 5 minutes.</p>
                <p>
                    <?php submit_button( 'Save welcome message', 'primary', 'submit', false ); ?>
                    <?php if ( '' !== $saved ) : ?>
                        <button type="submit" name="reset" value="1" class="button">Use the default</button>
                    <?php endif; ?>
                </p>
            </form>

            <h2>Alerts</h2>
            <p>Short notices such as “Closed today due to weather” or “Strut hours changed this week”. Each one shows at the top of the chat on its dates only, Pacific time, and the assistant is told about it so its answers agree. Without an end date, an alert shows until you delete it. Ended alerts are removed automatically after <?php echo (int) SFAF_Chat_Alerts::KEEP_ENDED_DAYS; ?> days.</p>
            <p class="description">Calendar closures (holidays and the like) come from the SFAF Calendar and are listed below; you don't need an alert for those.</p>

            <?php if ( $alerts ) : ?>
                <table class="widefat striped sfaf-chat-alerts">
                    <thead><tr><th scope="col">Alert</th><th scope="col">From</th><th scope="col">Through</th><th scope="col">Status</th><th scope="col"><span class="screen-reader-text">Actions</span></th></tr></thead>
                    <tbody>
                        <?php foreach ( $alerts as $a ) : $status = SFAF_Chat_Alerts::status( $a, $today ); ?>
                            <tr>
                                <td><?php echo esc_html( $a['text'] ); ?></td>
                                <td><?php echo esc_html( self::day( $a['start'] ) ); ?></td>
                                <td><?php echo esc_html( '' === $a['end'] ? 'Until deleted' : self::day( $a['end'] ) ); ?></td>
                                <td><span class="sfaf-chat-status sfaf-chat-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $labels[ $status ] ); ?></span></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_ACTION ); ?>">
                                        <input type="hidden" name="id" value="<?php echo esc_attr( $a['id'] ); ?>">
                                        <?php wp_nonce_field( self::DELETE_ACTION ); ?>
                                        <button type="submit" class="button-link button-link-delete">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><em>No alerts yet.</em></p>
            <?php endif; ?>

            <h3>Add an alert</h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ALERT_ACTION ); ?>">
                <?php wp_nonce_field( self::ALERT_ACTION ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sfaf-chat-alert-text">Alert</label></th>
                        <td><textarea id="sfaf-chat-alert-text" name="text" rows="2" class="large-text" maxlength="<?php echo (int) SFAF_Chat_Alerts::MAX_TEXT; ?>" required></textarea>
                            <p class="description">Plain text, up to <?php echo (int) SFAF_Chat_Alerts::MAX_TEXT; ?> characters. Shown to visitors exactly as written.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-chat-alert-start">First day</label></th>
                        <td><input type="date" id="sfaf-chat-alert-start" name="start" value="<?php echo esc_attr( $today ); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-chat-alert-end">Last day</label></th>
                        <td><input type="date" id="sfaf-chat-alert-end" name="end">
                            <p class="description">Optional. Leave blank to show it until you delete it.</p></td>
                    </tr>
                </table>
                <?php submit_button( 'Add alert' ); ?>
            </form>

            <h2>Closures from the SFAF Calendar</h2>
            <?php if ( ! SFAF_Chat_Closures::available() ) : ?>
                <p>The SFAF Calendar isn't active on this site, so the assistant can't see closures.</p>
            <?php else : $closures = SFAF_Chat_Closures::upcoming(); ?>
                <p>What the assistant knows about the next <?php echo (int) SFAF_Chat_Closures::DAYS_AHEAD; ?> days. Closures are managed in the SFAF Calendar, not here.</p>
                <?php if ( ! $closures ) : ?>
                    <p><em>No closures on the calendar in the next <?php echo (int) SFAF_Chat_Closures::DAYS_AHEAD; ?> days.</em></p>
                <?php else : ?>
                    <ul class="sfaf-chat-closures">
                        <?php foreach ( $closures as $c ) : ?>
                            <li><strong><?php echo esc_html( $c['name'] ); ?></strong>: <?php echo esc_html( $c['when'] ); ?><?php echo $c['today'] ? ' <em>(today)</em>' : ''; ?>
                                <?php if ( '' !== $c['note'] ) : ?><br><span class="description"><?php echo esc_html( $c['note'] ); ?></span><?php endif; ?>
                                <?php if ( $c['open'] ) : ?><br><span class="description">Still open: <?php echo esc_html( implode( '; ', $c['open'] ) ); ?></span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
