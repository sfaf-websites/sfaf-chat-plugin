<?php
/**
 * The Appearance screen: colors, shape, font and placement, with a live preview.
 * Administrators only, on the same terms as every other screen in SFAF_Chat_Admin.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Admin_Appearance {

    const PAGE         = 'sfaf-chat-appearance';
    const SAVE_ACTION  = 'sfaf_chat_save_appearance';
    const RESET_ACTION = 'sfaf_chat_reset_appearance';

    private $admin;

    public function __construct( SFAF_Chat_Admin $admin ) {
        $this->admin = $admin;
    }

    public function register() {
        add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
        add_action( 'admin_post_' . self::RESET_ACTION, array( $this, 'reset' ) );
    }

    private function back() {
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }

    public function save() {
        $this->admin->gate();
        check_admin_referer( self::SAVE_ACTION );

        $result = SFAF_Chat_Appearance::from_input( wp_unslash( $_POST ) );
        SFAF_Chat_Appearance::save( $result['values'] );

        // Saved either way. A low contrast pair is a warning, never a silent success.
        $warnings = array_merge( $result['errors'], SFAF_Chat_Appearance::problems( $result['values'] ) );
        if ( array() === $warnings ) {
            $this->admin->stash_notice( array( 'Appearance saved.' ), 'success' );
        } else {
            $this->admin->stash_notice( array_merge( array( 'Appearance saved, with these problems:' ), $warnings ), 'warning' );
        }
        $this->back();
    }

    public function reset() {
        $this->admin->gate();
        check_admin_referer( self::RESET_ACTION );

        SFAF_Chat_Appearance::reset();
        $this->admin->stash_notice( array( 'Appearance reset to the SFAF brand defaults.' ), 'success' );
        $this->back();
    }

    private function color_field( $key, array $color, $value ) {
        $id = 'sfaf-color-' . $key;
        ?>
        <div class="sfaf-chat-color">
            <label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $color[0] ); ?></label>
            <div class="sfaf-chat-color-row">
                <input type="color" value="<?php echo esc_attr( $value ); ?>" data-swatch-for="<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( 'Pick ' . $color[0] ); ?>">
                <input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" class="code" value="<?php echo esc_attr( $value ); ?>" data-color="<?php echo esc_attr( $key ); ?>" maxlength="7" spellcheck="false">
            </div>
            <?php if ( '' !== $color[3] ) : ?>
                <p class="description"><?php echo esc_html( $color[3] ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function preview( array $values ) {
        $style = '';
        foreach ( SFAF_Chat_Appearance::css_vars( $values ) as $name => $value ) {
            $style .= '--p-' . $name . ':' . $value . ';';
        }
        ?>
        <div class="sfaf-chat-preview" id="sfaf-chat-preview" style="<?php echo esc_attr( $style ); ?>" aria-hidden="true">
            <div class="p-panel">
                <div class="p-header"><span>Ask SFAF</span><span class="p-read">Read aloud</span></div>
                <div class="p-log">
                    <div class="p-bubble p-bot">Hi! What are you looking for?</div>
                    <div class="p-chip">How do I get PrEP?</div>
                    <div class="p-bubble p-user">Where can I get tested?</div>
                    <div class="p-bubble p-bot">You can book a visit at Strut.</div>
                    <div class="p-link">HIV and STI Testing</div>
                </div>
                <div class="p-form"><span class="p-input">Type your question</span><span class="p-send">&rarr;</span></div>
                <div class="p-foot">Automated assistant, not medical advice</div>
            </div>
            <div class="p-dock"><span class="p-launcher">Ask SFAF</span><span class="p-weglot">English</span></div>
        </div>
        <?php
    }

    public function render() {
        $this->admin->gate();

        $v      = SFAF_Chat_Appearance::all();
        $groups = array();
        foreach ( SFAF_Chat_Appearance::COLORS as $key => $color ) {
            $groups[ $color[2] ][ $key ] = $color;
        }
        $problems = SFAF_Chat_Appearance::problems( $v );
        ?>
        <div class="wrap sfaf-chat-admin">
            <h1>Appearance</h1>
            <?php $this->admin->tabs( self::PAGE ); ?>
            <?php $this->admin->show_notice(); ?>

            <div class="sfaf-chat-split">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sfaf-chat-appearance" autocomplete="off">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
                    <?php wp_nonce_field( self::SAVE_ACTION ); ?>

                    <h2>Colors</h2>
                    <?php foreach ( $groups as $name => $colors ) : ?>
                        <fieldset class="sfaf-chat-group">
                            <legend><?php echo esc_html( $name ); ?></legend>
                            <div class="sfaf-chat-colors">
                                <?php foreach ( $colors as $key => $color ) {
                                    $this->color_field( $key, $color, (string) $v[ $key ] );
                                } ?>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>
                    <p class="description">Text on the accent color isn't a setting. It is chosen automatically so it stays readable, which keeps text on yellow dark.</p>

                    <h2>Shape and font</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="sfaf-radius">Corner radius</label></th>
                            <td><input type="number" id="sfaf-radius" name="radius" class="small-text" min="0" max="<?php echo (int) SFAF_Chat_Appearance::RADIUS_MAX; ?>" value="<?php echo (int) $v['radius']; ?>"> px
                                <p class="description">0 is square, which is the SFAF style.</p></td>
                        </tr>
                        <tr>
                            <th scope="row">Font</th>
                            <td>
                                <fieldset>
                                    <legend class="screen-reader-text">Font</legend>
                                    <label class="sfaf-chat-stack"><input type="radio" name="font_mode" value="site" <?php checked( $v['font_mode'], 'site' ); ?>> Use the font of whatever site it appears on</label>
                                    <label class="sfaf-chat-stack"><input type="radio" name="font_mode" value="custom" <?php checked( $v['font_mode'], 'custom' ); ?>> Use this font family:</label>
                                </fieldset>
                                <label class="screen-reader-text" for="sfaf-font">Font family</label>
                                <input type="text" id="sfaf-font" name="font" class="large-text code" maxlength="200" value="<?php echo esc_attr( (string) $v['font'] ); ?>">
                                <p class="description">The widget never loads a font. A family works only where the page already has it, and the next name in the list is used otherwise.</p>
                            </td>
                        </tr>
                    </table>

                    <h2>Placement</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="sfaf-corner">Corner</label></th>
                            <td><select id="sfaf-corner" name="corner">
                                    <option value="right" <?php selected( $v['corner'], 'right' ); ?>>Bottom right</option>
                                    <option value="left" <?php selected( $v['corner'], 'left' ); ?>>Bottom left</option>
                                </select></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sfaf-offset-x">Distance from the side</label></th>
                            <td><input type="number" id="sfaf-offset-x" name="offset_x" class="small-text" min="0" max="<?php echo (int) SFAF_Chat_Appearance::OFFSET_MAX; ?>" value="<?php echo (int) $v['offset_x']; ?>"> px
                                <p class="description">Used when the launcher is not beside Weglot. Check that nothing else sits in that spot.</p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sfaf-offset-y">Distance from the bottom</label></th>
                            <td><input type="number" id="sfaf-offset-y" name="offset_y" class="small-text" min="0" max="<?php echo (int) SFAF_Chat_Appearance::OFFSET_MAX; ?>" value="<?php echo (int) $v['offset_y']; ?>"> px
                                <p class="description">Beside Weglot, this is measured from the bottom of the Weglot selector.</p></td>
                        </tr>
                        <tr>
                            <th scope="row">Weglot</th>
                            <td><label><input type="checkbox" name="weglot" value="1" <?php checked( ! empty( $v['weglot'] ) ); ?>> Sit beside the Weglot language selector when it is showing</label>
                                <p class="description">Takes its height and sits to its left. Applies to the bottom right corner only.</p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="sfaf-gap">Gap beside Weglot</label></th>
                            <td><input type="number" id="sfaf-gap" name="gap" class="small-text" min="0" max="<?php echo (int) SFAF_Chat_Appearance::GAP_MAX; ?>" value="<?php echo (int) $v['gap']; ?>"> px</td>
                        </tr>
                    </table>

                    <?php submit_button( 'Save appearance' ); ?>
                </form>

                <div class="sfaf-chat-side">
                    <h2>Preview</h2>
                    <?php $this->preview( $v ); ?>
                    <div id="sfaf-chat-contrast" class="sfaf-chat-contrast<?php echo array() === $problems ? ' is-ok' : ''; ?>" role="status">
                        <?php if ( array() === $problems ) : ?>
                            <p>All text meets the readable contrast standard (WCAG AA).</p>
                        <?php else : ?>
                            <p><strong>Some text is hard to read:</strong></p>
                            <ul><?php foreach ( $problems as $problem ) { echo '<li>' . esc_html( $problem ) . '</li>'; } ?></ul>
                        <?php endif; ?>
                    </div>
                    <p class="description">A saved change reaches every page with the script tag within 5 minutes.</p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Reset every appearance setting to the SFAF brand defaults?');">
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::RESET_ACTION ); ?>">
                        <?php wp_nonce_field( self::RESET_ACTION ); ?>
                        <?php submit_button( 'Reset to brand defaults', 'secondary', 'reset', false ); ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
}
