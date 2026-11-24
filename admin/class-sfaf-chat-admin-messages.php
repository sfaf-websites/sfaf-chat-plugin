<?php
/**
 * The Welcome & Alerts screen: the chat's opening message, scheduled alerts, and a
 * read-only view of the closures the assistant can see from the SFAF Calendar.
 *
 * Alerts are added, edited (in place, 1.31.0) and deleted one at a time; there is
 * nothing to reorder. Each form posts to admin-post.php with its own nonce, and every handler
 * goes through SFAF_Chat_Admin::gate() (manage_options).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Admin_Messages {

    const PAGE           = 'sfaf-chat-messages';
    const WELCOME_ACTION = 'sfaf_chat_save_welcome';
    const ALERT_ACTION   = 'sfaf_chat_save_alert';
    const DELETE_ACTION  = 'sfaf_chat_delete_alert';
    const MIGRATE_ACTION = 'sfaf_chat_migrate_alerts';

    private $admin;

    public function __construct( SFAF_Chat_Admin $admin ) {
        $this->admin = $admin;
    }

    public function register() {
        add_action( 'admin_post_' . self::WELCOME_ACTION, array( $this, 'save_welcome' ) );
        add_action( 'admin_post_' . self::ALERT_ACTION, array( $this, 'save_alert' ) );
        add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'delete_alert' ) );
        add_action( 'admin_post_' . self::MIGRATE_ACTION, array( $this, 'migrate_alerts' ) );
    }

    /** Back to the screen, or to the edit form for $edit. */
    private function back( $edit = '' ) {
        $args = array( 'page' => self::PAGE );
        if ( '' !== $edit ) {
            $args['edit'] = $edit;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . ( '' !== $edit ? '#sfaf-chat-alert-form' : '' ) );
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

        // The text exactly as typed (only WordPress's slashes undone), never cleaned.
        $post = wp_unslash( $_POST );
        $id   = sanitize_text_field( (string) ( $post['id'] ?? '' ) );
        $edit = '' !== $id;
        $result = SFAF_Chat_Alerts::save( $id, (string) ( $post['text'] ?? '' ), $post['start'] ?? '', $post['end'] ?? '', $post['url'] ?? '', $post['link_text'] ?? '', $edit );

        if ( is_wp_error( $result ) ) {
            $this->admin->stash_notice( array( ( $edit ? 'The alert was not changed: ' : 'The alert was not saved: ' ) . $result->get_error_message() ), 'error' );
            $this->back( $edit && 'sfaf_chat_alert_gone' !== $result->get_error_code() ? $id : '' );
        }
        $this->admin->stash_notice( array( $edit ? 'Alert updated.' : 'Alert saved.' ), 'success' );
        $this->back();
    }

    /** Older alerts with no end date, to the one-day rule. Deletes nothing. */
    public function migrate_alerts() {
        $this->admin->gate();
        check_admin_referer( self::MIGRATE_ACTION );
        $n = SFAF_Chat_Alerts::migrate();
        $this->admin->stash_notice( array( 0 === $n ? 'There were no older alerts to change.' : sprintf( '%d older %s now one day only. None was deleted.', $n, 1 === $n ? 'alert is' : 'alerts are' ) ), 'success' );
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

    public function render() {
        $this->admin->gate();

        $saved   = trim( (string) SFAF_Chat_Settings::get( 'welcome' ) );
        $alerts  = SFAF_Chat_Alerts::all();
        $today   = SFAF_Chat_Alerts::today();
        $labels  = array( 'active' => 'Showing now', 'scheduled' => 'Scheduled', 'ended' => 'Ended' );
        $legacy  = SFAF_Chat_Alerts::legacy();

        // Edit (1.31.0): the alert named in ?edit=, every field filled in as stored.
        $edit_id = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
        $editing = null;
        foreach ( $alerts as $a ) {
            if ( '' !== $edit_id && $a['id'] === $edit_id ) {
                $editing = $a;
            }
        }
        $form = $editing ?: array( 'id' => '', 'text' => '', 'start' => '', 'end' => '', 'url' => '', 'link_text' => '' );
        $page_url = admin_url( 'admin.php?page=' . self::PAGE );
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
            <p>Short notices such as “Closed today due to weather” or “Strut hours changed this week”. Each one shows at the top of the chat on its dates only, Pacific time, and the assistant is told about it so its answers agree. Without a last day, an alert shows on its first day only, and ends at midnight. Ended alerts are removed automatically after <?php echo (int) SFAF_Chat_Alerts::KEEP_ENDED_DAYS; ?> days.</p>
            <p class="description">Calendar closures (holidays and the like) come from the SFAF Calendar and are listed below; you don't need an alert for those.</p>

            <?php if ( $legacy ) : ?>
                <div class="notice notice-warning inline sfaf-chat-legacy-alerts">
                    <p><strong><?php echo esc_html( sprintf( '%d %s saved before version 1.31.0 without a last day.', count( $legacy ), 1 === count( $legacy ) ? 'alert was' : 'alerts were' ) ); ?></strong>
                        They still show until deleted. Under the new rule, each would show on its first day only:</p>
                    <ul>
                        <?php foreach ( $legacy as $a ) : $gone = $a['start'] < $today; ?>
                            <li>“<?php echo esc_html( $a['text'] ); ?>”: now <?php echo esc_html( SFAF_Chat_Alerts::dates_label( $a ) ); ?>; would be <?php echo esc_html( SFAF_Chat_Alerts::day( $a['start'] ) ); ?> only<?php echo $gone ? ', so it would stop showing now' : ''; ?>.</li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::MIGRATE_ACTION ); ?>">
                        <?php wp_nonce_field( self::MIGRATE_ACTION ); ?>
                        <p><button type="submit" class="button">Apply the one-day rule to these</button>
                            <span class="description">Nothing is deleted, now or by the automatic cleanup. You can edit or delete each one yourself.</span></p>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ( $alerts ) : ?>
                <table class="widefat striped sfaf-chat-alerts">
                    <thead><tr><th scope="col">Alert</th><th scope="col">Dates</th><th scope="col">Status</th><th scope="col"><span class="screen-reader-text">Actions</span></th></tr></thead>
                    <tbody>
                        <?php foreach ( $alerts as $a ) : $status = SFAF_Chat_Alerts::status( $a, $today ); ?>
                            <tr>
                                <td><?php echo esc_html( $a['text'] ); ?>
                                    <?php $link = SFAF_Chat_Alerts::link( $a ); if ( $link ) : ?>
                                        <br><span class="description">Link: <a href="<?php echo esc_url( $link['url'], array( 'http', 'https', 'mailto', 'tel' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $link['label'] ); ?></a><?php echo $link['label'] !== $link['url'] ? ' (' . esc_html( $link['url'] ) . ')' : ''; ?></span>
                                    <?php endif; ?></td>
                                <td class="sfaf-chat-alert-dates"><?php echo esc_html( SFAF_Chat_Alerts::dates_label( $a ) ); ?></td>
                                <td><span class="sfaf-chat-status sfaf-chat-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $labels[ $status ] ); ?></span></td>
                                <td>
                                    <a class="button-link sfaf-chat-alert-edit" href="<?php echo esc_url( add_query_arg( 'edit', $a['id'], $page_url ) . '#sfaf-chat-alert-form' ); ?>">Edit<span class="screen-reader-text"> this alert</span></a>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:12px">
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

            <?php if ( '' !== $edit_id && null === $editing ) : ?>
                <div class="notice notice-warning inline"><p>That alert no longer exists. You can add a new one below.</p></div>
            <?php endif; ?>
            <h3 id="sfaf-chat-alert-form"><?php echo $editing ? 'Edit alert' : 'Add an alert'; ?></h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sfaf-chat-alert-form">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ALERT_ACTION ); ?>">
                <input type="hidden" name="id" value="<?php echo esc_attr( $form['id'] ); ?>">
                <?php wp_nonce_field( self::ALERT_ACTION ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sfaf-chat-alert-text">Alert</label></th>
                        <td><textarea id="sfaf-chat-alert-text" name="text" rows="2" class="large-text" maxlength="<?php echo (int) SFAF_Chat_Alerts::MAX_TEXT; ?>" required><?php echo esc_textarea( $form['text'] ); ?></textarea>
                            <p class="description">Plain text, up to <?php echo (int) SFAF_Chat_Alerts::MAX_TEXT; ?> characters. Saved and shown to visitors exactly as written.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-chat-alert-start">First day</label></th>
                        <td><input type="date" id="sfaf-chat-alert-start" name="start" value="<?php echo esc_attr( $form['start'] ); ?>" data-today="<?php echo esc_attr( $today ); ?>">
                            <p class="description">Optional. Leave blank to start the day you save it.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-chat-alert-end">Last day</label></th>
                        <td><input type="date" id="sfaf-chat-alert-end" name="end" value="<?php echo esc_attr( $form['end'] ); ?>">
                            <p class="description">Optional. Leave blank for the first day only.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-chat-alert-url">Link address</label></th>
                        <td><input type="url" id="sfaf-chat-alert-url" name="url" class="large-text" maxlength="<?php echo (int) SFAF_Chat_Alerts::MAX_URL; ?>" placeholder="https://www.sfaf.org/" value="<?php echo esc_attr( $form['url'] ); ?>">
                            <p class="description">Optional. One link, shown under the alert. A full address starting with https:// (or http://, mailto: or tel:). It is shown exactly as you enter it, so check it.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-chat-alert-link-text">Link text</label></th>
                        <td><input type="text" id="sfaf-chat-alert-link-text" name="link_text" class="regular-text" maxlength="<?php echo (int) SFAF_Chat_Alerts::MAX_LINK_TEXT; ?>" value="<?php echo esc_attr( $form['link_text'] ); ?>">
                            <p class="description">Optional. Without it, the address itself is the link.</p></td>
                    </tr>
                </table>
                <p class="submit">
                    <?php submit_button( $editing ? 'Save changes' : 'Add alert', 'primary', 'submit', false ); ?>
                    <?php if ( $editing ) : ?>
                        <a class="button sfaf-chat-alert-cancel" href="<?php echo esc_url( $page_url ); ?>">Cancel</a>
                    <?php endif; ?>
                </p>
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
