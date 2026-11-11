<?php
/**
 * Analytics: topic frequency, questions by hour (Pacific), and the unanswered
 * queue. Every number here is counted in PHP. No model is involved.
 *
 * Administrators only. This is a wp-admin page, so it has no public URL, and
 * render() checks the capability itself as well as the menu doing so.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Dashboard {

    const RANGES = array( 7 => 'Last 7 days', 30 => 'Last 30 days', 60 => 'Last 60 days' );

    private static function pct( $n, $of ) {
        return $of > 0 ? round( 100 * $n / $of ) . '%' : 'n/a';
    }

    private static function hour_label( $h ) {
        return ( 0 === $h % 12 ? 12 : $h % 12 ) . ( $h < 12 ? ' am' : ' pm' );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to view this page.', 403 );
        }

        $days = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30;
        if ( ! isset( self::RANGES[ $days ] ) ) {
            $days = 30;
        }
        $range = self::RANGES[ $days ];

        $tz   = SFAF_Chat_Core::timezone();
        $utc  = new DateTimeZone( 'UTC' );
        $rows = SFAF_Chat_Log::since_days( $days );

        /* ------------------------------ COUNTS ---------------------------- */
        // API/parse errors are system failures, not visitor questions.

        $total = $answered = $unanswered = $declined = $errors = $pinned = 0;
        $topics   = array();
        $hours    = array_fill( 0, 24, 0 );
        $flags    = array( 'personal_advice' => 0, 'out_of_scope' => 0, 'crisis' => 0 );
        $queue    = array();
        $raw_seen = false;

        foreach ( $rows as $r ) {
            $flag = $r['flag'] ?? null;
            if ( 'api_error' === $flag || 'parse_error' === $flag ) {
                $errors++;
                continue;
            }
            $total++;

            $when = ( new DateTimeImmutable( (string) $r['ts'], $utc ) )->setTimezone( $tz );

            $topic = is_string( $r['topic'] ?? null ) && '' !== $r['topic'] ? $r['topic'] : 'other';
            $topics[ $topic ] = ( $topics[ $topic ] ?? 0 ) + 1;
            $hours[ (int) $when->format( 'G' ) ]++;

            if ( ! empty( $r['pin'] ) ) {
                $pinned++;
            }
            if ( 'unanswered' === $flag ) {
                $unanswered++;
                $r['_when'] = $when;
                $queue[]    = $r;
            } elseif ( isset( $flags[ $flag ] ) ) {
                $flags[ $flag ]++;
                $declined++;
            } else {
                $answered++;
            }
            if ( null !== ( $r['question'] ?? null ) ) {
                $raw_seen = true;
            }
        }

        arsort( $topics );
        $queue = array_slice( array_reverse( $queue ), 0, 200 );

        $max_topic = $topics ? max( $topics ) : 0;
        $max_hour  = max( $hours ) ?: 0;
        $base_url  = admin_url( 'admin.php?page=' . SFAF_Chat_Admin::DASH_PAGE );
        ?>
        <div class="wrap sfaf-chat-dash">

        <div class="dash-head">
            <h1>SFAF Chat Dashboard</h1>
            <nav class="ranges" aria-label="Date range">
                <?php foreach ( self::RANGES as $d => $label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'days', $d, $base_url ) ); ?>"<?php echo $d === $days ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>
        </div>

        <?php if ( $raw_seen ) : ?>
            <div class="banner" role="note"><strong>Raw question text is in these logs.</strong>
                That's expected during internal testing only. Turn it off in Settings before the script tag goes on a page real visitors can reach. Old rows are removed after 60 days.</div>
        <?php endif; ?>

        <section class="tiles" aria-label="Summary">
            <div class="tile"><div class="n"><?php echo (int) $total; ?></div><div class="l">Questions</div></div>
            <div class="tile"><div class="n"><?php echo (int) $answered; ?></div><div class="l">Answered (<?php echo esc_html( self::pct( $answered, $total ) ); ?>)</div></div>
            <div class="tile"><div class="n"><?php echo (int) $pinned; ?></div><div class="l">Used a pinned answer</div></div>
            <div class="tile"><div class="n"><?php echo (int) $unanswered; ?></div><div class="l">Unanswered (<?php echo esc_html( self::pct( $unanswered, $total ) ); ?>)</div></div>
            <div class="tile"><div class="n"><?php echo (int) $declined; ?></div><div class="l">Declined: <?php echo (int) $flags['personal_advice']; ?> personal advice · <?php echo (int) $flags['out_of_scope']; ?> off-topic · <?php echo (int) $flags['crisis']; ?> crisis</div></div>
            <div class="tile"><div class="n"><?php echo (int) $errors; ?></div><div class="l">System errors (API / parse)</div></div>
        </section>

        <section class="card" aria-labelledby="t-topics">
            <h2 id="t-topics">Questions by topic</h2>
            <p class="sub"><?php echo esc_html( $range ); ?> · topic assigned per question; counted on the server</p>
            <?php if ( ! $topics ) : ?>
                <p class="empty">No questions in this range yet.</p>
            <?php else : ?>
                <div class="hbars" role="table" aria-label="Questions by topic">
                    <?php foreach ( $topics as $t => $n ) : ?>
                        <div class="lab" role="rowheader"><?php echo esc_html( (string) $t ); ?></div>
                        <div class="track" role="cell" title="<?php echo esc_attr( $t . ': ' . $n . ' (' . self::pct( $n, $total ) . ')' ); ?>">
                            <div class="bar" style="width:<?php echo esc_attr( (string) ( $max_topic ? round( 85 * $n / $max_topic, 1 ) : 0 ) ); ?>%"></div>
                            <span class="val"><?php echo (int) $n; ?> · <?php echo esc_html( self::pct( $n, $total ) ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="card" aria-labelledby="t-hours">
            <h2 id="t-hours">Questions by hour of day</h2>
            <p class="sub"><?php echo esc_html( $range ); ?> · Pacific time · hover a bar for its count</p>
            <?php if ( 0 === $total ) : ?>
                <p class="empty">No questions in this range yet.</p>
            <?php else :
                // Gridlines at nice round steps.
                $step = max( 1, (int) ceil( $max_hour / 4 ) );
                $top  = $step * 4;
                ?>
                <div class="vchart">
                    <div class="plot" role="img" aria-label="Bar chart of questions by hour; table below.">
                        <?php for ( $g = 1; $g <= 4; $g++ ) : ?>
                            <div class="gridline" style="bottom:<?php echo esc_attr( (string) ( 100 * $g / 4 ) ); ?>%"><span><?php echo (int) ( $step * $g ); ?></span></div>
                        <?php endfor; ?>
                        <?php foreach ( $hours as $hr => $n ) : ?>
                            <div class="col" tabindex="0" aria-label="<?php echo esc_attr( self::hour_label( $hr ) . ': ' . $n ); ?>">
                                <div class="bar" style="height:<?php echo esc_attr( (string) ( $top ? round( 100 * $n / $top, 1 ) : 0 ) ); ?>%"></div>
                                <div class="tip"><?php echo esc_html( self::hour_label( $hr ) ); ?> to <?php echo esc_html( self::hour_label( ( $hr + 1 ) % 24 ) ); ?>: <strong><?php echo (int) $n; ?></strong></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="xaxis" aria-hidden="true">
                        <?php for ( $hr = 0; $hr < 24; $hr++ ) : ?>
                            <span><?php echo 0 === $hr % 3 ? esc_html( str_replace( ' ', '', self::hour_label( $hr ) ) ) : ''; ?></span>
                        <?php endfor; ?>
                    </div>
                </div>
                <details>
                    <summary>Show as table</summary>
                    <div class="hourtable">
                        <?php foreach ( $hours as $hr => $n ) : ?>
                            <div><span><?php echo esc_html( self::hour_label( $hr ) ); ?></span><span><?php echo (int) $n; ?></span></div>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endif; ?>
        </section>

        <section class="card" aria-labelledby="t-queue">
            <h2 id="t-queue">Unanswered queue</h2>
            <p class="sub">Legitimate questions the knowledge base didn't cover. These are candidates for new or better content. Newest first<?php echo 200 === count( $queue ) ? ' (latest 200)' : ''; ?>.</p>
            <?php if ( ! $queue ) : ?>
                <p class="empty">Nothing unanswered in this range.</p>
            <?php else : ?>
                <table>
                    <thead><tr><th>When (Pacific)</th><th>Topic</th><th>Question</th></tr></thead>
                    <tbody>
                        <?php foreach ( $queue as $r ) : ?>
                            <tr>
                                <td class="when"><?php echo esc_html( $r['_when']->format( 'M j, g:i a' ) ); ?></td>
                                <td><?php echo esc_html( (string) ( $r['topic'] ?? 'other' ) ); ?></td>
                                <td><?php
                                    if ( null !== ( $r['question'] ?? null ) ) {
                                        echo '<span class="q">' . esc_html( (string) $r['question'] ) . '</span>';
                                    } else {
                                        echo '<span class="nq">not logged (topic-only mode)</span>';
                                    }
                                ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <p class="sub">Source: <?php echo count( $rows ); ?> log rows in <?php echo esc_html( strtolower( $range ) ); ?>. Rows older than 60 days are deleted automatically.</p>
        </div>
        <?php
    }
}
