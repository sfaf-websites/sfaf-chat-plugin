<?php
/**
 * The Report screen (1.23.0): a monthly insights report for the team meeting, from
 * SFAF_Chat_Stats. All teams or one, for a date range: what people asked about
 * (demand), where the chat struggled (gaps), and what they did next (actions).
 * Print / Save as PDF prints the report alone (admin.css, @media print).
 *
 * Below the report, for staff only and never printed: the backfill that sorts
 * questions logged before 1.23.0, and Reset, which empties the report before launch.
 *
 * Counts only. Nothing here shows a question, a name or any contact detail.
 * Administrators only, checked in the menu and again in every handler.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Admin_Report {

    const PAGE            = 'sfaf-chat-report';
    const PURGE_ACTION    = 'sfaf_chat_purge_stats';
    const BACKFILL_ACTION = 'sfaf_chat_backfill';
    const BACKFILL_BUDGET = 25;   // seconds of batches per press without JavaScript

    private $admin;

    public function __construct( SFAF_Chat_Admin $admin ) {
        $this->admin = $admin;
    }

    public function register() {
        add_action( 'admin_post_' . self::PURGE_ACTION, array( $this, 'purge' ) );
        add_action( 'admin_post_' . self::BACKFILL_ACTION, array( $this, 'backfill' ) );
        add_action( 'wp_ajax_' . self::BACKFILL_ACTION, array( $this, 'backfill_ajax' ) );
    }

    private function back( array $args = array() ) {
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) ) );
        exit;
    }

    /* ============================== HANDLERS =========================== */

    public function purge() {
        $this->admin->gate();
        check_admin_referer( self::PURGE_ACTION );
        if ( empty( $_POST['confirm_reset'] ) ) {
            $this->admin->stash_notice( array( 'Nothing was deleted. Tick the box to confirm, then press Reset report data.' ), 'error' );
            $this->back();
        }
        SFAF_Chat_Stats::purge();
        $this->admin->stash_notice( array( 'Report data deleted. The report starts again from now; questions logged before now will not be sorted into it again. The question log itself was not changed.' ), 'success' );
        $this->back();
    }

    /** Without JavaScript: batches for up to BACKFILL_BUDGET seconds, then back. */
    public function backfill() {
        $this->admin->gate();
        check_admin_referer( self::BACKFILL_ACTION );
        $start = microtime( true );
        do {
            $r = SFAF_Chat_Stats::backfill_batch();
        } while ( '' === $r['error'] && ! $r['done'] && microtime( true ) - $start < self::BACKFILL_BUDGET );

        if ( '' !== $r['error'] ) {
            $this->admin->stash_notice( array( 'Sorting stopped: ' . $r['error'] ), 'error' );
        } elseif ( $r['done'] ) {
            $this->admin->stash_notice( array( 'Every logged question with text is now in the report.' ), 'success' );
        } else {
            $this->admin->stash_notice( array( $r['left'] . ' questions still to sort. Press the button again to continue.' ), 'warning' );
        }
        $this->back();
    }

    /** With JavaScript: one batch per call, so the screen can show progress. */
    public function backfill_ajax() {
        if ( ! current_user_can( SFAF_Chat_Admin::CAP ) ) {
            wp_send_json( array( 'ok' => false, 'error' => 'Not allowed.' ), 403 );
        }
        check_ajax_referer( self::BACKFILL_ACTION );
        $r = SFAF_Chat_Stats::backfill_batch();
        wp_send_json( array( 'ok' => '' === $r['error'], 'done' => $r['done'], 'left' => $r['left'], 'error' => $r['error'] ) );
    }

    /* ============================== RENDER ============================= */

    /** A Y-m-d from the address bar, or the default. */
    private static function day( $key, $default ) {
        $v = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
        $d = DateTimeImmutable::createFromFormat( '!Y-m-d', $v, SFAF_Chat_Core::timezone() );
        return ( $d && $d->format( 'Y-m-d' ) === $v ) ? $v : $default;
    }

    private static function pretty( $ymd ) {
        return wp_date( 'M j, Y', ( new DateTimeImmutable( $ymd . ' 12:00:00', SFAF_Chat_Core::timezone() ) )->getTimestamp(), SFAF_Chat_Core::timezone() );
    }

    private static function pct( $n, $of ) {
        return $of > 0 ? round( 100 * $n / $of ) . '%' : '0%';
    }

    /**
     * Horizontal bars, drawn in HTML so they print exactly. $data: label => count.
     * $tone: demand (yellow), gap (red) or action (green).
     */
    private static function bars( array $data, $tone, $empty, $limit = 15 ) {
        if ( array() === $data || 0 === array_sum( $data ) ) {
            echo '<p class="sfaf-report-empty">' . esc_html( $empty ) . '</p>';
            return;
        }
        $data = array_slice( $data, 0, $limit, true );
        $max  = max( $data );
        echo '<ol class="sfaf-bars sfaf-bars-' . esc_attr( $tone ) . '">';
        foreach ( $data as $label => $n ) {
            $w = $max > 0 ? max( 1.5, round( 100 * $n / $max, 1 ) ) : 0;
            printf(
                '<li><span class="sfaf-bar-label">%s</span><span class="sfaf-bar-track"><span class="sfaf-bar" style="width:%s%%"></span></span><span class="sfaf-bar-n">%s</span></li>',
                esc_html( (string) $label ),
                esc_attr( (string) $w ),
                esc_html( number_format_i18n( (int) $n ) )
            );
        }
        echo '</ol>';
    }

    public function render() {
        $this->admin->gate();

        $tz    = SFAF_Chat_Core::timezone();
        $today = wp_date( 'Y-m-d', null, $tz );
        $now   = new DateTimeImmutable( $today, $tz );
        $from  = self::day( 'from', $now->modify( '-29 days' )->format( 'Y-m-d' ) );
        $to    = self::day( 'to', $today );
        if ( $from > $to ) {
            list( $from, $to ) = array( $to, $from );
        }
        $teams = SFAF_Chat_Stats::report_teams();
        $team  = isset( $_GET['team'] ) ? sanitize_text_field( wp_unslash( $_GET['team'] ) ) : '';
        if ( ! in_array( $team, $teams, true ) ) {
            $team = '';
        }

        $r       = SFAF_Chat_Stats::report( $from, $to, $team );
        $acted   = array_sum( $r['actions'] );
        $by      = '' === $team ? 'team' : 'subject';
        $scope   = '' === $team ? 'All teams' : $team;

        $presets = array(
            'Last 30 days' => array( $now->modify( '-29 days' )->format( 'Y-m-d' ), $today ),
            'Last month'   => array( $now->modify( 'first day of last month' )->format( 'Y-m-d' ), $now->modify( 'last day of last month' )->format( 'Y-m-d' ) ),
            'This month'   => array( $now->modify( 'first day of this month' )->format( 'Y-m-d' ), $today ),
        );
        list( $pending, $no_text ) = SFAF_Chat_Stats::pending_counts();
        ?>
        <div class="wrap sfaf-chat-admin sfaf-report-wrap">
            <h1 class="sfaf-report-screen-title">Report</h1>
            <?php $this->admin->tabs( self::PAGE ); ?>
            <?php $this->admin->show_notice(); ?>

            <form class="sfaf-report-controls" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
                <label>Team
                    <select name="team" id="sfaf-report-team">
                        <option value="">All teams</option>
                        <?php foreach ( $teams as $t ) : ?>
                            <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $team, $t ); ?>><?php echo esc_html( $t ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>From <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" max="<?php echo esc_attr( $today ); ?>"></label>
                <label>To <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" max="<?php echo esc_attr( $today ); ?>"></label>
                <button type="submit" class="button">Show</button>
                <span class="sfaf-report-presets">
                    <?php foreach ( $presets as $label => $range ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'team' => $team, 'from' => $range[0], 'to' => $range[1] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
                    <?php endforeach; ?>
                </span>
                <button type="button" class="button button-primary sfaf-report-print" data-print>Print / Save as PDF</button>
            </form>

            <article class="sfaf-report" id="sfaf-report">
                <header class="sfaf-report-head">
                    <p class="sfaf-report-kicker">SFAF website chat</p>
                    <h2>Chat insights: <?php echo esc_html( $scope ); ?></h2>
                    <p class="sfaf-report-range"><?php echo esc_html( self::pretty( $from ) . ' to ' . self::pretty( $to ) ); ?></p>
                </header>

                <div class="sfaf-tiles">
                    <div class="sfaf-tile"><span class="sfaf-tile-n"><?php echo esc_html( number_format_i18n( $r['questions'] ) ); ?></span><span class="sfaf-tile-l">Questions asked</span></div>
                    <div class="sfaf-tile"><span class="sfaf-tile-n"><?php echo esc_html( self::pct( $r['questions'] - $r['struggled'], $r['questions'] ) ); ?></span><span class="sfaf-tile-l">Answered well</span></div>
                    <div class="sfaf-tile sfaf-tile-gap"><span class="sfaf-tile-n"><?php echo esc_html( number_format_i18n( $r['struggled'] ) ); ?></span><span class="sfaf-tile-l">The chat struggled</span></div>
                    <div class="sfaf-tile sfaf-tile-action"><span class="sfaf-tile-n"><?php echo esc_html( number_format_i18n( $acted ) ); ?></span><span class="sfaf-tile-l">Actions taken</span></div>
                </div>

                <section class="sfaf-report-section">
                    <h3>What people asked about</h3>
                    <p class="sfaf-report-lead">Questions by <?php echo esc_html( $by ); ?>, whether or not they led to an action.</p>
                    <?php self::bars( $r['demand'], 'demand', 'No questions in this range.' ); ?>
                    <?php if ( '' === $team && array() !== $r['top'] ) : ?>
                        <h4>Top subjects across teams</h4>
                        <?php self::bars( $r['top'], 'demand', '', 12 ); ?>
                    <?php endif; ?>
                </section>

                <section class="sfaf-report-section">
                    <h3>Where the chat struggled</h3>
                    <p class="sfaf-report-lead"><?php echo esc_html( sprintf( '%s of %s questions (%s): the chat did not have the information, answered only part, or declined.', number_format_i18n( $r['struggled'] ), number_format_i18n( $r['questions'] ), self::pct( $r['struggled'], $r['questions'] ) ) ); ?></p>
                    <?php self::bars( $r['gaps'], 'gap', 'None in this range.' ); ?>
                    <?php if ( array() !== $r['gap_reasons'] ) : ?>
                        <h4>Why</h4>
                        <?php
                        $why = array();
                        foreach ( $r['gap_reasons'] as $k => $n ) {
                            $why[ SFAF_Chat_Stats::GAPS[ $k ] ?? $k ] = $n;
                        }
                        self::bars( $why, 'gap', '' );
                        ?>
                    <?php endif; ?>
                </section>

                <section class="sfaf-report-section">
                    <h3>What people did next</h3>
                    <p class="sfaf-report-lead">Actions taken in the chat<?php echo '' === $team ? '' : ', in conversations about this team\'s work'; ?>.</p>
                    <?php
                    $acts = array();
                    foreach ( SFAF_Chat_Stats::ACTIONS as $k => $label ) {
                        $acts[ $label ] = $r['actions'][ $k ];
                    }
                    if ( 0 === $acted ) {
                        echo '<p class="sfaf-report-empty">No actions in this range.</p>';
                    } else {
                        arsort( $acts );
                        self::bars( $acts, 'action', '' );
                    }
                    ?>
                    <p class="sfaf-report-note">Booking itself happens on healow and is not tracked here: "Clicked toward booking" counts visit types chosen in the appointment chooser.</p>

                    <?php if ( array() !== $r['conversion'] ) : ?>
                        <h4>From question to action, by <?php echo esc_html( $by ); ?></h4>
                        <table class="sfaf-conv">
                            <thead><tr><th scope="col"><?php echo esc_html( ucfirst( $by ) ); ?></th><th scope="col">Conversations</th><th scope="col">Took an action</th><th scope="col">Rate</th></tr></thead>
                            <tbody>
                            <?php foreach ( array_slice( $r['conversion'], 0, 15, true ) as $k => $c ) : ?>
                                <tr>
                                    <th scope="row"><?php echo esc_html( (string) $k ); ?></th>
                                    <td><?php echo esc_html( number_format_i18n( $c['conversations'] ) ); ?></td>
                                    <td><?php echo esc_html( number_format_i18n( $c['acted'] ) ); ?></td>
                                    <td><span class="sfaf-rate"><span style="width:<?php echo esc_attr( (string) ( $c['conversations'] ? round( 100 * $c['acted'] / $c['conversations'] ) : 0 ) ); ?>%"></span></span><?php echo esc_html( self::pct( $c['acted'], $c['conversations'] ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="sfaf-report-note">A conversation counts once per <?php echo esc_html( $by ); ?> it asked about, and once if it then took any action there. Questions sorted from earlier logs count toward demand and gaps only.</p>
                    <?php endif; ?>
                </section>

                <footer class="sfaf-report-foot">
                    Counts only. This report holds no names, contact details or messages, and nothing in it can be traced to a person.
                    Prepared <?php echo esc_html( wp_date( 'M j, Y', null, $tz ) ); ?>.
                </footer>
            </article>

            <div class="sfaf-report-admin">
                <h2>Sort earlier questions</h2>
                <?php if ( $pending > 0 ) : ?>
                    <p><?php echo esc_html( sprintf( '%s logged questions are not in the report yet. Sorting asks the model about %d at a time, so it uses your Gemini key: about one call per %d questions.', number_format_i18n( $pending ), SFAF_Chat_Stats::BATCH, SFAF_Chat_Stats::BATCH ) ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sfaf-backfill" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::BACKFILL_ACTION ) ); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::BACKFILL_ACTION ); ?>">
                        <?php wp_nonce_field( self::BACKFILL_ACTION ); ?>
                        <p><button type="submit" class="button">Sort <?php echo esc_html( number_format_i18n( $pending ) ); ?> questions into the report</button> <span id="sfaf-backfill-status" role="status"></span></p>
                    </form>
                <?php else : ?>
                    <p>Every logged question with text is in the report.</p>
                <?php endif; ?>
                <?php if ( $no_text > 0 ) : ?>
                    <p class="description"><?php echo esc_html( sprintf( '%s logged questions have no text (raw logging was off when they were asked), so they cannot be sorted.', number_format_i18n( $no_text ) ) ); ?></p>
                <?php endif; ?>

                <h2>Reset test data before launch</h2>
                <p>Deletes every number in this report: categories, struggled questions and tracked actions. Use it once, just before the chat goes live, so real figures start clean. It cannot be undone. Questions logged before the reset are not sorted into the report again. The question log on the Dashboard is not changed.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="sfaf-report-reset" data-confirm="Delete all report data? This cannot be undone.">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::PURGE_ACTION ); ?>">
                    <?php wp_nonce_field( self::PURGE_ACTION ); ?>
                    <p><label><input type="checkbox" name="confirm_reset" value="1" required> I understand this permanently deletes all report data.</label></p>
                    <p><button type="submit" class="button button-link-delete sfaf-reset-btn">Reset report data</button></p>
                </form>
            </div>
        </div>
        <?php
    }
}
