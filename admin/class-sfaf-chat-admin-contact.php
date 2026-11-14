<?php
/**
 * The Contact screen: the "Contact our team" form's switch, its Postmark token,
 * Reply-To, the default recipients and the teams (name, what they handle, their
 * addresses, optional pages). One form; a save with any problem stores nothing
 * (the token included) and says what to fix.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Admin_Contact {

    const PAGE        = 'sfaf-chat-contact';
    const SAVE_ACTION = 'sfaf_chat_save_contact';
    const TEST_ACTION = 'sfaf_chat_test_contact';
    const BLANK_ROWS  = 3;

    private $admin;

    public function __construct( SFAF_Chat_Admin $admin ) {
        $this->admin = $admin;
    }

    public function register() {
        add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
        add_action( 'admin_post_' . self::TEST_ACTION, array( $this, 'test' ) );
    }

    private function back() {
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }

    public function save() {
        $this->admin->gate();
        check_admin_referer( self::SAVE_ACTION );
        $post   = wp_unslash( $_POST );
        $errors = array();

        $default_emails = SFAF_Chat_Contact::parse_emails( $post['default_emails'] ?? '', $bad );
        foreach ( $bad as $b ) {
            $errors[] = SFAF_Chat_Contact::FROM === $b
                ? 'Default recipients: ' . $b . ' is the address the mail is sent from, so it can\'t also receive it. Use the address of the people who should answer.'
                : 'Default recipients: "' . $b . '" is not an email address.';
        }
        $enabled = ! empty( $post['enabled'] );
        if ( $enabled && array() === $default_emails ) {
            $errors[] = 'Add at least one default recipient before switching the form on.';
        }

        $reply_to       = 'fixed' === ( $post['reply_to'] ?? '' ) ? 'fixed' : 'visitor';
        $reply_to_email = strtolower( trim( (string) ( $post['reply_to_email'] ?? '' ) ) );
        if ( 'fixed' === $reply_to && ! is_email( $reply_to_email ) ) {
            $errors[] = 'Reply-To: give an email address, or choose the visitor\'s address.';
        }

        $routes = array();
        foreach ( (array) ( $post['routes'] ?? array() ) as $i => $row ) {
            $team     = trim( sanitize_text_field( (string) ( $row['team'] ?? '' ) ) );
            $about    = trim( sanitize_text_field( (string) ( $row['about'] ?? '' ) ) );
            $patterns = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) ( $row['patterns'] ?? '' ) ) ) ) );
            $emails   = SFAF_Chat_Contact::parse_emails( $row['emails'] ?? '', $bad_row );
            if ( '' === $team && '' === $about && array() === $patterns && array() === $emails && array() === $bad_row ) {
                continue; // an empty row
            }
            $n = count( $routes ) + 1;
            if ( '' === $team ) {
                $errors[] = 'Team ' . $n . ': add the team\'s name.';
            }
            foreach ( $bad_row as $b ) {
                $errors[] = SFAF_Chat_Contact::FROM === $b
                    ? 'Team ' . $n . ': ' . $b . ' is the address the mail is sent from, so it can\'t also receive it.'
                    : 'Team ' . $n . ': "' . $b . '" is not an email address.';
            }
            foreach ( $patterns as $p ) {
                $problem = SFAF_Chat_Starters::pattern_problem( $p );
                if ( null !== $problem ) {
                    $errors[] = 'Team ' . $n . ': "' . $p . '" ' . $problem . '.';
                }
            }
            if ( array() === $emails ) {
                $errors[] = 'Team ' . $n . ': add at least one recipient.';
            }
            $routes[] = array( 'team' => mb_substr( $team, 0, 60 ), 'about' => mb_substr( $about, 0, SFAF_Chat_Contact::MAX_ABOUT ), 'patterns' => array_slice( $patterns, 0, 20 ), 'emails' => $emails );
        }
        if ( count( $routes ) > SFAF_Chat_Contact::MAX_ROUTES ) {
            $errors[] = 'At most ' . SFAF_Chat_Contact::MAX_ROUTES . ' teams.';
        }

        $token = trim( (string) ( $post['postmark_token'] ?? '' ) );
        if ( '' !== $token && ! preg_match( '/^[A-Za-z0-9-]{20,80}$/', $token ) ) {
            $errors[] = 'That doesn\'t look like a Postmark server token (letters, numbers and dashes).';
        }
        if ( $enabled && '' === $token && ! SFAF_Chat_Credentials::has( 'postmark_token' ) ) {
            $errors[] = 'Save a Postmark server token before switching the form on.';
        }

        if ( array() !== $errors ) {
            array_unshift( $errors, 'Nothing was saved. Please fix these:' );
            $this->admin->stash_notice( $errors, 'error' );
            $this->back();
        }

        if ( '' !== $token ) {
            SFAF_Chat_Credentials::set( 'postmark_token', $token );
        }
        SFAF_Chat_Contact::save( array(
            'enabled'        => $enabled,
            'reply_to'       => $reply_to,
            'reply_to_email' => 'fixed' === $reply_to ? $reply_to_email : '',
            'default_team'   => mb_substr( trim( sanitize_text_field( (string) ( $post['default_team'] ?? '' ) ) ), 0, 60 ) ?: 'team',
            'default_emails' => $default_emails,
            'routes'         => $routes,
        ) );
        $this->admin->stash_notice( array( 'Contact settings saved.' ), 'success' );
        $this->back();
    }

    public function test() {
        $this->admin->gate();
        check_admin_referer( self::TEST_ACTION );
        $c    = SFAF_Chat_Contact::config();
        $sent = SFAF_Chat_Contact::send_test();
        true === $sent
            ? $this->admin->stash_notice( array( 'Test email sent to ' . implode( ', ', SFAF_Chat_Contact::recipients( (array) $c['default_emails'] ) ) . '. Check that inbox, and Postmark\'s Activity for where it was delivered.' ), 'success' )
            : $this->admin->stash_notice( array( 'The test email was not sent: ' . $sent ), 'error' );
        $this->back();
    }

    private function route_row( $i, array $r ) {
        $base = 'routes[' . (int) $i . ']';
        ?>
        <tr>
            <td><input type="text" name="<?php echo esc_attr( $base ); ?>[team]" class="regular-text" placeholder="Stonewall Project" value="<?php echo esc_attr( (string) ( $r['team'] ?? '' ) ); ?>" aria-label="Team name"></td>
            <td><textarea name="<?php echo esc_attr( $base ); ?>[about]" rows="2" class="large-text" placeholder="Substance use counseling for queer men and trans people" aria-label="What they handle"><?php echo esc_textarea( (string) ( $r['about'] ?? '' ) ); ?></textarea></td>
            <td><textarea name="<?php echo esc_attr( $base ); ?>[emails]" rows="2" class="large-text" spellcheck="false" aria-label="Recipients"><?php echo esc_textarea( implode( "\n", (array) ( $r['emails'] ?? array() ) ) ); ?></textarea></td>
            <td><textarea name="<?php echo esc_attr( $base ); ?>[patterns]" rows="2" class="large-text code" spellcheck="false" placeholder="/support-services/the-stonewall-project/*" aria-label="Pages, one per line (optional)"><?php echo esc_textarea( implode( "\n", (array) ( $r['patterns'] ?? array() ) ) ); ?></textarea></td>
        </tr>
        <?php
    }

    public function render() {
        $this->admin->gate();
        $c         = SFAF_Chat_Contact::config();
        $has_token = SFAF_Chat_Credentials::has( 'postmark_token' );
        ?>
        <div class="wrap sfaf-chat-admin">
            <h1>Contact</h1>
            <?php $this->admin->tabs( self::PAGE ); ?>
            <?php $this->admin->show_notice(); ?>

            <p>When someone wants to reach a person, the chat offers a <strong>Contact our team</strong> button, beside any phone number it gives. The visitor gives a name, an email and, if they like, a phone number and a note, and is told someone will reach out within 1 to 2 business days. The request, with the chat, is emailed to the team that handles the subject of their question, and the visitor gets a short confirmation email. No copy of a request is kept here.</p>
            <p>Status: <strong><?php echo SFAF_Chat_Contact::ready() ? 'Offered in the chat' : 'Not offered yet'; ?></strong><?php echo SFAF_Chat_Contact::ready() ? '' : ' (it needs to be switched on, a Postmark token and a default recipient).'; ?></p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
                <?php wp_nonce_field( self::SAVE_ACTION ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Contact form</th>
                        <td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $c['enabled'] ) ); ?>> Offer the contact form in the chat</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-chat-postmark">Postmark server token</label></th>
                        <td><input type="password" id="sfaf-chat-postmark" name="postmark_token" class="regular-text" value="" autocomplete="new-password">
                            <p class="description"><?php echo $has_token ? 'A token is saved. Leave this blank to keep it, or paste a new one to replace it.' : 'No token saved yet.'; ?> Use a server token made for the chat in Postmark (Servers, then the chat server, then API Tokens). It is never shown here again. Mail is sent from <code><?php echo esc_html( SFAF_Chat_Contact::FROM ); ?></code>, which must be a confirmed sender in that Postmark account.</p></td>
                    </tr>
                    <tr>
                        <th scope="row">Reply-To</th>
                        <td><fieldset>
                            <label><input type="radio" name="reply_to" value="visitor" <?php checked( 'fixed' !== $c['reply_to'] ); ?>> The visitor's email address (replying answers them directly)</label><br>
                            <label><input type="radio" name="reply_to" value="fixed" <?php checked( 'fixed' === $c['reply_to'] ); ?>> This address:</label>
                            <input type="email" name="reply_to_email" class="regular-text" value="<?php echo esc_attr( (string) $c['reply_to_email'] ); ?>" aria-label="Fixed Reply-To address">
                        </fieldset></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-chat-default-team">Default team</label></th>
                        <td><input type="text" id="sfaf-chat-default-team" name="default_team" class="regular-text" value="<?php echo esc_attr( (string) $c['default_team'] ); ?>">
                            <p class="description">The button reads "Contact our" and then this, e.g. "team" or "client services team".</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sfaf-chat-default-emails">Default recipients</label></th>
                        <td><textarea id="sfaf-chat-default-emails" name="default_emails" rows="2" class="large-text" spellcheck="false"><?php echo esc_textarea( implode( "\n", (array) $c['default_emails'] ) ); ?></textarea>
                            <p class="description">For any request no team below fits. One or more addresses, one per line or separated by commas. Not <code><?php echo esc_html( SFAF_Chat_Contact::FROM ); ?></code>, which sends the mail.</p></td>
                    </tr>
                </table>

                <h2>Teams</h2>
                <p>The chat picks the team from what the question is about, not the page it was asked on: someone on the home page asking about the Stonewall Project goes to that team. It reads each team's name and <strong>What they handle</strong>, so say plainly what they cover. <strong>Pages</strong> are optional, a fallback when the chat names no team; write them as on the Suggested Questions screen (<code>/strut</code>, <code>/support-services/latine-health/*</code>). Anything else goes to the default recipients. The button reads "Contact our" and the team's name, with "team" added if it isn't there. Clear every box of a row to remove it.</p>
                <table class="widefat sfaf-chat-routes">
                    <thead><tr><th scope="col">Team name</th><th scope="col">What they handle</th><th scope="col">Recipients</th><th scope="col">Pages (optional)</th></tr></thead>
                    <tbody>
                        <?php
                        $i = 0;
                        foreach ( (array) $c['routes'] as $r ) {
                            $this->route_row( $i++, $r );
                        }
                        for ( $k = 0; $k < self::BLANK_ROWS; $k++ ) {
                            $this->route_row( $i++, array() );
                        }
                        ?>
                    </tbody>
                </table>
                <?php submit_button( 'Save contact settings' ); ?>
            </form>

            <?php if ( $has_token && array() !== (array) $c['default_emails'] ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::TEST_ACTION ); ?>">
                    <?php wp_nonce_field( self::TEST_ACTION ); ?>
                    <p><button type="submit" class="button">Send a test email to the default recipients</button></p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
