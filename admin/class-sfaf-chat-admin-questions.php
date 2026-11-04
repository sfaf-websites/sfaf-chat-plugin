<?php
/**
 * The Suggested Questions screen: the default set and the sets for specific pages.
 *
 * The whole screen is one form. Sets can be added, removed and reordered in the
 * browser (admin/js/admin.js), and nothing changes until Save. A rejected save puts
 * what was typed back on the screen and stores nothing.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Admin_Questions {

    const PAGE        = 'sfaf-chat-questions';
    const SAVE_ACTION = 'sfaf_chat_save_starters';
    const DRAFT       = 'sfaf_chat_starters_draft_';
    const TEST_NONCE  = 'sfaf_chat_test_starters';

    private $admin;

    public function __construct( SFAF_Chat_Admin $admin ) {
        $this->admin = $admin;
    }

    public function register() {
        add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
    }

    public function save() {
        $this->admin->gate();
        check_admin_referer( self::SAVE_ACTION );

        $result = SFAF_Chat_Starters::from_input( wp_unslash( $_POST ) );

        if ( array() !== $result['errors'] ) {
            set_transient( self::DRAFT . get_current_user_id(), $result['config'], 5 * MINUTE_IN_SECONDS );
            $this->admin->stash_notice( array_merge( array( 'Nothing was saved. Please fix these:' ), $result['errors'] ), 'error' );
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&draft=1' ) );
            exit;
        }

        SFAF_Chat_Starters::save( $result['config'] );
        $this->admin->stash_notice( array( 'Suggested questions saved.' ), 'success' );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }

    /** Three question boxes, each movable. $name is the input name without the []. */
    private function question_rows( $name, array $questions ) {
        ?>
        <ol class="sfaf-chat-questions" data-questions>
            <?php for ( $i = 0; $i < SFAF_Chat_Starters::MAX_SHOWN; $i++ ) : ?>
                <li>
                    <input type="text" name="<?php echo esc_attr( $name ); ?>[]" class="large-text" aria-label="Question" maxlength="<?php echo (int) SFAF_Chat_Starters::MAX_QUESTION_LEN; ?>" value="<?php echo esc_attr( (string) ( $questions[ $i ] ?? '' ) ); ?>">
                    <button type="button" class="button" data-move="up" aria-label="Move this question up">&uarr;</button>
                    <button type="button" class="button" data-move="down" aria-label="Move this question down">&darr;</button>
                </li>
            <?php endfor; ?>
        </ol>
        <?php
    }

    /** One set. $i is a form index, or __i__ inside the blank template. */
    private function set_block( $i, array $set ) {
        $base = 'sets[' . $i . ']';
        ?>
        <div class="sfaf-chat-set" data-set>
            <div class="sfaf-chat-set-head">
                <label>Name <span class="description">(for staff only)</span>
                    <input type="text" name="<?php echo esc_attr( $base ); ?>[label]" class="regular-text" value="<?php echo esc_attr( (string) ( $set['label'] ?? '' ) ); ?>">
                </label>
                <span class="sfaf-chat-set-actions">
                    <button type="button" class="button" data-set-move="up">Move up</button>
                    <button type="button" class="button" data-set-move="down">Move down</button>
                    <button type="button" class="button-link button-link-delete" data-set-remove>Remove</button>
                </span>
            </div>
            <label class="sfaf-chat-stack">Pages, one per line
                <textarea name="<?php echo esc_attr( $base ); ?>[patterns]" rows="2" class="large-text code" spellcheck="false"><?php echo esc_textarea( implode( "\n", (array) ( $set['patterns'] ?? array() ) ) ); ?></textarea>
            </label>
            <p class="sfaf-chat-stack-label">Questions</p>
            <?php $this->question_rows( $base . '[questions]', (array) ( $set['questions'] ?? array() ) ); ?>
        </div>
        <?php
    }

    public function render() {
        $this->admin->gate();

        $config = SFAF_Chat_Starters::config();
        if ( ! empty( $_GET['draft'] ) ) {
            $draft = get_transient( self::DRAFT . get_current_user_id() );
            delete_transient( self::DRAFT . get_current_user_id() );
            if ( is_array( $draft ) ) {
                $config = $draft;
            }
        }

        $tested = null;
        if ( isset( $_POST['test_url'] ) ) {
            check_admin_referer( self::TEST_NONCE );
            $tested = (string) wp_unslash( $_POST['test_url'] );
        }
        ?>
        <div class="wrap sfaf-chat-admin">
            <h1>Suggested Questions</h1>
            <?php $this->admin->tabs( self::PAGE ); ?>
            <?php $this->admin->show_notice(); ?>

            <div class="sfaf-chat-explain">
                <h2>How this works</h2>
                <p>When someone opens the chat, they see two or three questions to tap. A tapped question goes through the same guardrails as a typed one.</p>
                <ul>
                    <li><strong>The default set</strong> shows everywhere that no other set matches.</li>
                    <li><strong>Page sets</strong> replace the default on the pages they name. The first match going down the list wins, so put specific sets above general ones.</li>
                    <li><code>/get-involved/volunteer</code> matches that one page, on any SFAF site.</li>
                    <li><code>/get-involved/*</code> matches that page and everything under it.</li>
                    <li><code>https://www.sfaf.org/about/*</code> does the same on that site only.</li>
                    <li>Capitals and a final slash don't matter, and <code>/es/</code> pages match their English address.</li>
                    <li><strong>Pick questions the assistant can answer.</strong> Try each one in the chat after you add it.</li>
                </ul>
            </div>

            <h2>Test a page address</h2>
            <form method="post" class="sfaf-chat-test">
                <?php wp_nonce_field( self::TEST_NONCE ); ?>
                <label class="screen-reader-text" for="sfaf-chat-test-url">Page address to test</label>
                <input type="url" id="sfaf-chat-test-url" name="test_url" class="large-text" placeholder="https://www.sfaf.org/health-services/" value="<?php echo esc_attr( (string) $tested ); ?>">
                <?php submit_button( 'Test', 'secondary', 'submit', false ); ?>
            </form>
            <?php
            if ( null !== $tested ) {
                $match = SFAF_Chat_Starters::for_url( $tested );
                printf(
                    '<div class="notice notice-info inline"><p>Uses the set <strong>%s</strong>: %s</p></div>',
                    esc_html( '' !== $match['label'] ? $match['label'] : $match['set'] ),
                    esc_html( implode( ' / ', $match['questions'] ) )
                );
            }
            ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sfaf-chat-starters" autocomplete="off">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
                <?php wp_nonce_field( self::SAVE_ACTION ); ?>

                <h2>Default set</h2>
                <div class="sfaf-chat-set">
                    <p class="sfaf-chat-stack-label">Questions</p>
                    <?php $this->question_rows( 'default', (array) ( $config['default'] ?? array() ) ); ?>
                </div>

                <h2>Page sets</h2>
                <div id="sfaf-chat-sets">
                    <?php foreach ( array_values( (array) ( $config['sets'] ?? array() ) ) as $i => $set ) {
                        $this->set_block( $i, (array) $set );
                    } ?>
                </div>
                <template id="sfaf-chat-set-template"><?php $this->set_block( '__i__', array() ); ?></template>
                <p><button type="button" class="button" id="sfaf-chat-add-set">Add a page set</button></p>
                <p class="screen-reader-text" id="sfaf-chat-moved" role="status"></p>

                <?php submit_button( 'Save suggested questions' ); ?>
            </form>
        </div>
        <?php
    }
}
