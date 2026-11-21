<?php
/**
 * Insights (1.28.0): the one screen for what the chat is doing. It joins the Report
 * (1.23.0, restyled 1.24.0) and the Dashboard (1.0), which overlapped, and keeps
 * everything either showed, once.
 *
 * Two sources, one date range and one team filter:
 *   - The insights report, from SFAF_Chat_Stats (kept 400 days): demand, gaps, actions
 *     and conversion, by team or subject. Counts only.
 *   - The question log, SFAF_Chat_Log (kept 60 days): pinned answers, crisis handoffs,
 *     system errors, topics, hours, pages. With a team chosen, only the questions the
 *     report sorted to it (through log_id).
 *
 * What prints (Print / Save as PDF, admin.css @media print) is #sfaf-report: counts
 * only, nothing that can be traced to a person. Below it, on screen only, for staff:
 * the unanswered queue and recent exchanges (question text, while raw logging is on),
 * then Sort earlier questions and Reset.
 *
 * WHAT WAS A DUPLICATE AND WENT: the Dashboard's Questions and Answered tiles (the
 * report's Questions asked and Answered well), and its Unanswered tile and the personal
 * advice and off-topic declines (the report's "Why" under Where the chat struggled).
 * Kept from the Dashboard because the report had no equivalent: pinned answers, crisis
 * handoffs, system errors, topics, hours, pages, the queue and the exchanges, and its
 * 7 and 60 day ranges (now presets).
 *
 * Administrators only, checked in the menu and again in every handler.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Admin_Insights {

    const PAGE            = 'sfaf-chat-insights';
    const PURGE_ACTION    = 'sfaf_chat_purge_stats';
    const BACKFILL_ACTION = 'sfaf_chat_backfill';
    const BACKFILL_BUDGET = 25;    // seconds of batches per press without JavaScript
    const LIST_MAX        = 200;   // rows in the queue and the exchanges

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

    /* =============================== HELPERS =========================== */

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

    private static function hour_label( $h ) {
        return ( 0 === $h % 12 ? 12 : $h % 12 ) . ( $h < 12 ? ' am' : ' pm' );
    }

    /** "www.sfaf.org/strut/": the host and path, without the scheme. */
    private static function page_label( $url ) {
        return (string) preg_replace( '#^https?://#i', '', $url );
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
            // A zero draws no bar at all, just its 0 (1.24.0). Anything above zero keeps
            // a visible minimum, so a 1 beside a 500 still shows.
            $w = ( $max > 0 && $n > 0 ) ? max( 1.5, round( 100 * $n / $max, 1 ) ) : 0;
            printf(
                '<li><span class="sfaf-bar-label">%s</span><span class="sfaf-bar-track"><span class="sfaf-bar" style="width:%s%%"></span></span><span class="sfaf-bar-n">%s</span></li>',
                esc_html( (string) $label ),
                esc_attr( (string) $w ),
                esc_html( number_format_i18n( (int) $n ) )
            );
        }
        echo '</ol>';
    }

    /**
     * The question log for the range, counted here, in PHP. API and parse errors are
     * system failures, not visitor questions, so they are counted apart.
     */
    private static function log_counts( array $rows ) {
        $tz  = SFAF_Chat_Core::timezone();
        $utc = new DateTimeZone( 'UTC' );
        $c   = array(
            'rows' => count( $rows ), 'total' => 0, 'errors' => 0, 'pinned' => 0, 'crisis' => 0,
            'topics' => array(), 'hours' => array_fill( 0, 24, 0 ), 'pages' => array(),
            'queue' => array(), 'exchanges' => array(), 'raw' => false,
        );
        foreach ( $rows as $r ) {
            $flag       = $r['flag'] ?? null;
            $r['_when'] = ( new DateTimeImmutable( (string) $r['ts'], $utc ) )->setTimezone( $tz );
            if ( null !== ( $r['question'] ?? null ) ) {
                $c['raw']         = true;
                $c['exchanges'][] = $r;
            }
            if ( 'api_error' === $flag || 'parse_error' === $flag ) {
                $c['errors']++;
                continue;
            }
            $c['total']++;
            if ( ! empty( $r['page'] ) ) {
                $c['pages'][ self::page_label( (string) $r['page'] ) ] = ( $c['pages'][ self::page_label( (string) $r['page'] ) ] ?? 0 ) + 1;
            }
            $topic = is_string( $r['topic'] ?? null ) && '' !== $r['topic'] ? $r['topic'] : 'other';
            $c['topics'][ $topic ] = ( $c['topics'][ $topic ] ?? 0 ) + 1;
            $c['hours'][ (int) $r['_when']->format( 'G' ) ]++;
            if ( ! empty( $r['pin'] ) ) {
                $c['pinned']++;
            }
            if ( 'crisis' === $flag ) {
                $c['crisis']++;
            }
            if ( 'unanswered' === $flag ) {
                $c['queue'][] = $r;
            }
        }
        arsort( $c['topics'] );
        arsort( $c['pages'] );
        $c['queue']     = array_slice( array_reverse( $c['queue'] ), 0, self::LIST_MAX );
        $c['exchanges'] = array_slice( array_reverse( $c['exchanges'] ), 0, self::LIST_MAX );
        return $c;
    }

    /** Questions by hour of day: 24 square columns, each with its count above it. */
    private static function hours( array $hours ) {
        $max = max( $hours );
        echo '<div class="sfaf-hours" role="img" aria-label="' . esc_attr( 'Questions by hour of day, Pacific time. The table below has every hour.' ) . '">';
        foreach ( $hours as $hr => $n ) {
            printf(
                '<div class="sfaf-hour" title="%s"><span class="sfaf-hour-n">%s</span><span class="sfaf-hour-bar" style="height:%s%%"></span></div>',
                esc_attr( self::hour_label( $hr ) . ' to ' . self::hour_label( ( $hr + 1 ) % 24 ) . ': ' . $n ),
                $n > 0 ? esc_html( number_format_i18n( $n ) ) : '',
                esc_attr( (string) ( $max > 0 ? round( 100 * $n / $max, 1 ) : 0 ) )
            );
        }
        echo '</div><div class="sfaf-hours-axis" aria-hidden="true">';
        for ( $hr = 0; $hr < 24; $hr++ ) {
            echo '<span>' . ( 0 === $hr % 3 ? esc_html( str_replace( ' ', '', self::hour_label( $hr ) ) ) : '' ) . '</span>';
        }
        echo '</div>';
        echo '<details class="sfaf-hours-table"><summary>Show as table</summary><div>';
        foreach ( $hours as $hr => $n ) {
            echo '<div><span>' . esc_html( self::hour_label( $hr ) ) . '</span><span>' . (int) $n . '</span></div>';
        }
        echo '</div></details>';
    }

    /* ============================== RENDER ============================= */

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

        $r     = SFAF_Chat_Stats::report( $from, $to, $team );
        $log   = self::log_counts( SFAF_Chat_Log::between( $from, $to, $team ) );
        $acted = array_sum( $r['actions'] );
        $by    = '' === $team ? 'team' : 'subject';
        $scope = '' === $team ? 'All teams' : $team;

        $log_from = $now->modify( '-' . ( SFAF_Chat_Log::RETENTION_DAYS - 1 ) . ' days' )->format( 'Y-m-d' );
        $presets  = array(
            'Last 7 days'  => array( $now->modify( '-6 days' )->format( 'Y-m-d' ), $today ),
            'Last 30 days' => array( $now->modify( '-29 days' )->format( 'Y-m-d' ), $today ),
            'Last 60 days' => array( $now->modify( '-59 days' )->format( 'Y-m-d' ), $today ),
            'Last month'   => array( $now->modify( 'first day of last month' )->format( 'Y-m-d' ), $now->modify( 'last day of last month' )->format( 'Y-m-d' ) ),
            'This month'   => array( $now->modify( 'first day of this month' )->format( 'Y-m-d' ), $today ),
        );
        list( $pending, $no_text ) = SFAF_Chat_Stats::pending_counts();

        // Lines under the headline numbers, all from the same report data.
        $answered = $r['questions'] - $r['struggled'];
        $n_groups = count( $r['demand'] );
        $top_act  = '';
        if ( $acted > 0 ) {
            $by_kind = $r['actions'];
            arsort( $by_kind );
            $top_act = SFAF_Chat_Stats::ACTIONS[ (string) key( $by_kind ) ] ?? '';
        }
        $log_scope = '' === $team ? '' : ' Only questions sorted to this team, so system errors are not counted here.';
        ?>
        <div class="wrap sfaf-chat-admin sfaf-report-wrap">
            <h1 class="sfaf-report-screen-title">Insights</h1>
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
                        <a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'team' => $team, 'from' => $range[0], 'to' => $range[1] ), admin_url( 'admin.php' ) ) ); ?>"<?php echo ( $range[0] === $from && $range[1] === $to ) ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a>
                    <?php endforeach; ?>
                </span>
                <button type="button" class="button button-primary sfaf-report-print" data-print>Print / Save as PDF</button>
            </form>

            <?php if ( $log['raw'] ) : ?>
                <p class="sfaf-insights-banner" role="note"><strong>Raw question and answer text is in these logs.</strong>
                    That's expected during internal testing only. Turn it off on the General screen before the script tag goes on a page real visitors can reach. Log rows are removed after <?php echo (int) SFAF_Chat_Log::RETENTION_DAYS; ?> days. Question text never prints.</p>
            <?php endif; ?>

            <article class="sfaf-report" id="sfaf-report">
                <header class="sfaf-report-head">
                    <p class="sfaf-report-kicker"><span>SFAF website chat</span></p>
                    <h2>Chat insights: <?php echo esc_html( $scope ); ?></h2>
                    <p class="sfaf-report-range"><?php echo esc_html( self::pretty( $from ) . ' to ' . self::pretty( $to ) ); ?></p>
                </header>

                <?php /* The lead number, then three smaller ones. .sfaf-tile-n marks every number, in reading order. */ ?>
                <div class="sfaf-r-hero">
                    <div class="sfaf-r-primary">
                        <span class="sfaf-tile-n sfaf-r-primary-n"><?php echo esc_html( number_format_i18n( $r['questions'] ) ); ?></span>
                        <span class="sfaf-r-primary-l">Questions asked</span>
                        <span class="sfaf-r-primary-sub"><?php echo esc_html( $n_groups > 0 ? sprintf( 'Across %d %s', $n_groups, 1 === $n_groups ? $by : ( 'team' === $by ? 'teams' : 'subjects' ) ) : 'None in this range' ); ?></span>
                    </div>
                    <ul class="sfaf-r-kpis">
                        <li class="sfaf-r-kpi sfaf-r-kpi-good">
                            <span class="sfaf-tile-n"><?php echo esc_html( self::pct( $answered, $r['questions'] ) ); ?></span>
                            <span class="sfaf-r-kpi-l">Answered well</span>
                            <span class="sfaf-r-kpi-sub"><?php echo esc_html( sprintf( '%s of %s questions', number_format_i18n( $answered ), number_format_i18n( $r['questions'] ) ) ); ?></span>
                        </li>
                        <li class="sfaf-r-kpi sfaf-r-kpi-gap">
                            <span class="sfaf-tile-n"><?php echo esc_html( number_format_i18n( $r['struggled'] ) ); ?></span>
                            <span class="sfaf-r-kpi-l">The chat struggled</span>
                            <span class="sfaf-r-kpi-sub"><?php echo esc_html( self::pct( $r['struggled'], $r['questions'] ) . ' of questions' ); ?></span>
                        </li>
                        <li class="sfaf-r-kpi sfaf-r-kpi-action">
                            <span class="sfaf-tile-n"><?php echo esc_html( number_format_i18n( $acted ) ); ?></span>
                            <span class="sfaf-r-kpi-l">Actions taken</span>
                            <span class="sfaf-r-kpi-sub"><?php echo esc_html( '' !== $top_act ? 'Most often: ' . $top_act : 'None in this range' ); ?></span>
                        </li>
                    </ul>
                </div>

                <section class="sfaf-report-section sfaf-r-demand">
                    <h3>What people asked about</h3>
                    <p class="sfaf-report-lead">Questions by <?php echo esc_html( $by ); ?>, whether or not they led to an action.</p>
                    <?php self::bars( $r['demand'], 'demand', 'No questions in this range.' ); ?>
                    <?php if ( '' === $team && array() !== $r['top'] ) : ?>
                        <h4>Top subjects across teams</h4>
                        <?php self::bars( $r['top'], 'demand', '', 12 ); ?>
                    <?php endif; ?>
                </section>

                <div class="sfaf-r-pair">
                <section class="sfaf-report-section sfaf-r-gaps">
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

                <section class="sfaf-report-section sfaf-r-actions">
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
                </section>
                </div>

                <?php if ( array() !== $r['conversion'] ) : ?>
                <section class="sfaf-report-section sfaf-r-conversion">
                        <h3>From question to action, by <?php echo esc_html( $by ); ?></h3>
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
                </section>
                <?php endif; ?>

                <?php /* From here, the question log (60 days). Its figures are .sfaf-r-fig, never .sfaf-tile-n. */ ?>
                <section class="sfaf-report-section sfaf-r-log">
                    <h3>How the chat answered</h3>
                    <p class="sfaf-report-lead"><?php echo esc_html( sprintf( 'From the question log: %s questions in this range.', number_format_i18n( $log['total'] ) ) . $log_scope ); ?></p>
                    <ul class="sfaf-r-figs">
                        <li class="sfaf-r-fig"><span class="sfaf-r-fig-n"><?php echo esc_html( number_format_i18n( $log['pinned'] ) ); ?></span><span class="sfaf-r-fig-l">Used a pinned answer</span><span class="sfaf-r-fig-sub"><?php echo esc_html( self::pct( $log['pinned'], $log['total'] ) . ' of questions, in approved wording' ); ?></span></li>
                        <li class="sfaf-r-fig sfaf-r-fig-gap"><span class="sfaf-r-fig-n"><?php echo esc_html( number_format_i18n( $log['crisis'] ) ); ?></span><span class="sfaf-r-fig-l">Crisis handoffs</span><span class="sfaf-r-fig-sub">Pointed to 988 and 911. Never counted as a gap.</span></li>
                        <?php if ( '' === $team ) : ?>
                        <li class="sfaf-r-fig sfaf-r-fig-gap"><span class="sfaf-r-fig-n"><?php echo esc_html( number_format_i18n( $log['errors'] ) ); ?></span><span class="sfaf-r-fig-l">System errors</span><span class="sfaf-r-fig-sub">Gemini unreachable or unreadable. Not questions.</span></li>
                        <?php endif; ?>
                    </ul>
                </section>

                <div class="sfaf-r-pair">
                <section class="sfaf-report-section sfaf-r-topics">
                    <h3>Questions by topic</h3>
                    <p class="sfaf-report-lead">The topic the chat gave each question.</p>
                    <?php self::bars( $log['topics'], 'demand', 'No questions in this range.', 20 ); ?>
                </section>
                <section class="sfaf-report-section sfaf-r-pages">
                    <h3>Questions by page</h3>
                    <p class="sfaf-report-lead">Where people asked: the page the chat was open on, no query strings. Top 15.</p>
                    <?php self::bars( $log['pages'], 'demand', 'No page recorded in this range.' ); ?>
                </section>
                </div>

                <section class="sfaf-report-section sfaf-r-hours">
                    <h3>Questions by hour of day</h3>
                    <p class="sfaf-report-lead">Pacific time.</p>
                    <?php
                    if ( 0 === $log['total'] ) {
                        echo '<p class="sfaf-report-empty">No questions in this range.</p>';
                    } else {
                        self::hours( $log['hours'] );
                    }
                    ?>
                </section>

                <footer class="sfaf-report-foot">
                    Counts only. This report holds no names, contact details or messages, and nothing in it can be traced to a person.
                    The report's categories are kept <?php echo (int) SFAF_Chat_Stats::RETENTION_DAYS; ?> days, the question log <?php echo (int) SFAF_Chat_Log::RETENTION_DAYS; ?><?php echo $from < $log_from ? ', so the log sections cover ' . esc_html( self::pretty( $log_from ) ) . ' onward' : ''; ?>.
                    Prepared <?php echo esc_html( wp_date( 'M j, Y', null, $tz ) ); ?>.
                </footer>
            </article>

            <?php /* Staff only, never printed: question text. */ ?>
            <div class="sfaf-insights-log">
                <section class="sfaf-insights-block" aria-labelledby="sfaf-queue">
                    <h2 id="sfaf-queue">Unanswered queue</h2>
                    <p class="sfaf-report-lead">Questions the knowledge didn't cover: candidates for new or better content. Newest first<?php echo self::LIST_MAX === count( $log['queue'] ) ? ' (latest ' . (int) self::LIST_MAX . ')' : ''; ?>. Not printed.</p>
                    <?php if ( ! $log['queue'] ) : ?>
                        <p class="sfaf-report-empty">Nothing unanswered in this range.</p>
                    <?php else : ?>
                        <table class="sfaf-conv sfaf-log-table">
                            <thead><tr><th scope="col">When (Pacific)</th><th scope="col">Topic</th><th scope="col">Question</th></tr></thead>
                            <tbody>
                            <?php foreach ( $log['queue'] as $row ) : ?>
                                <tr>
                                    <td class="sfaf-when"><?php echo esc_html( $row['_when']->format( 'M j, g:i a' ) ); ?></td>
                                    <td><?php echo esc_html( (string) ( $row['topic'] ?? 'other' ) ); ?></td>
                                    <td><?php echo null !== ( $row['question'] ?? null ) ? esc_html( (string) $row['question'] ) : '<span class="sfaf-nq">not logged (topic-only mode)</span>'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>

                <section class="sfaf-insights-block" aria-labelledby="sfaf-exchanges">
                    <h2 id="sfaf-exchanges">Recent exchanges</h2>
                    <p class="sfaf-report-lead">Each question with the answer the visitor was shown, newest first<?php echo self::LIST_MAX === count( $log['exchanges'] ) ? ' (latest ' . (int) self::LIST_MAX . ')' : ''; ?>. Only recorded while raw logging is on (General screen). Each row stands alone: rows are never linked into conversations. Not printed.</p>
                    <?php if ( ! $log['exchanges'] ) : ?>
                        <p class="sfaf-report-empty">No exchanges recorded in this range. Turn on raw logging on the General screen to review them.</p>
                    <?php else : ?>
                        <table class="sfaf-conv sfaf-log-table sfaf-exchanges">
                            <thead><tr><th scope="col">When (Pacific)</th><th scope="col">Question</th><th scope="col">Answer</th><th scope="col">Page</th><th scope="col">Details</th></tr></thead>
                            <tbody>
                            <?php foreach ( $log['exchanges'] as $row ) : ?>
                                <tr>
                                    <td class="sfaf-when"><?php echo esc_html( $row['_when']->format( 'M j, g:i a' ) ); ?></td>
                                    <td><?php echo esc_html( (string) $row['question'] ); ?></td>
                                    <td class="sfaf-answer"><?php
                                    if ( null !== ( $row['answer'] ?? null ) ) {
                                        echo esc_html( (string) $row['answer'] );
                                    } elseif ( 'api_error' === ( $row['flag'] ?? null ) ) {
                                        echo '<span class="sfaf-nq">no answer: the model could not be reached</span>';
                                    } else {
                                        echo '<span class="sfaf-nq">not recorded</span>';
                                    }
                                    ?></td>
                                    <td class="sfaf-page"><?php echo ! empty( $row['page'] ) ? esc_html( self::page_label( (string) $row['page'] ) ) : '<span class="sfaf-nq">not recorded</span>'; ?></td>
                                    <td class="sfaf-meta"><?php
                                    $bits = array( (string) ( $row['topic'] ?? 'other' ) );
                                    if ( ! empty( $row['flag'] ) ) {
                                        $bits[] = 'flag: ' . $row['flag'];
                                    }
                                    if ( ! empty( $row['link'] ) ) {
                                        $bits[] = 'link: ' . $row['link'];
                                    }
                                    if ( ! empty( $row['pin'] ) ) {
                                        $bits[] = 'pinned answer';
                                    }
                                    echo esc_html( implode( ' · ', $bits ) );
                                    ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>
                <p class="sfaf-report-note"><?php echo esc_html( sprintf( 'Source: %s log rows in this range. Log rows older than %d days are deleted automatically.', number_format_i18n( $log['rows'] ), SFAF_Chat_Log::RETENTION_DAYS ) ); ?></p>
            </div>

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
                <p>Deletes every number in the report: categories, struggled questions and tracked actions. Use it once, just before the chat goes live, so real figures start clean. It cannot be undone. Questions logged before the reset are not sorted into the report again. The question log (the queue, the exchanges, and the sections from the log) is not changed.</p>
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
