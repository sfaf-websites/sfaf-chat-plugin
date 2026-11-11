<?php
/**
 * DAYS SFAF IS CLOSED, read from the SFAF Calendar on this site, and the
 * "current status" section that tells the model about them and about active
 * alerts (SFAF_Chat_Alerts).
 *
 * WHERE THE CALENDAR KEEPS CLOSURES (read from its code, 3.137/3.138): not as
 * events. One option, sfaf_closures, owned by the calendar's SFAF_Closures class:
 * id => {label, start, end (Y-m-d, site timezone, inclusive), note (free text up
 * to 200 characters, e.g. "The 6th Street Center is open as usual"), open (venues
 * that stay open, with hours)}. A multi-day closure is ONE entry spanning dates.
 * It is read here only through that class (spans(), text(), note(),
 * open_lines()), each behind method_exists(), so the calendar's own cleaning
 * applies and a missing calendar costs nothing.
 *
 * DIVISION OF LABOUR, as with events: every date is worked out here, in Pacific
 * time. The model is told which closure is today and which is tomorrow; it never
 * compares dates.
 *
 * WHEN THE SECTION IS SENT. Only when there is something to say: an active alert,
 * a closure in the next DAYS_AHEAD days, or a question about being open or closed
 * (so "are you closed for Christmas?" can be told there is none on the calendar).
 * An ordinary question with nothing current adds nothing to the request.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Closures {

    const DAYS_AHEAD = 60;
    const MAX        = 12;

    // Question words that make closures worth stating even when there are none.
    // Compared after SFAF_Chat_Core::tokenize(), which folds a trailing s.
    const OPEN_WORDS = array(
        'open', 'opened', 'closed', 'close', 'closing', 'closure', 'hour', 'holiday', 'today',
        'tomorrow', 'weekend', 'thanksgiving', 'christmas', 'year', 'july', 'labor', 'memorial',
        'juneteenth', 'veteran', 'mlk', 'king', 'independence', 'break', 'shut',
        'abierto', 'abierta', 'cerrado', 'cerrada', 'cierre', 'horario', 'hora', 'feriado', 'festivo',
        'hoy', 'mañana', 'manana', 'navidad',
    );

    /** Is the calendar's closure store here? */
    public static function available() {
        return class_exists( 'SFAF_Closures' ) && method_exists( 'SFAF_Closures', 'spans' );
    }

    /**
     * Closures overlapping today .. today + DAYS_AHEAD, soonest first, as plain
     * facts with their dates already written out.
     *
     * @return array[] name, when, today (bool), tomorrow (bool), note, open (string[])
     */
    public static function upcoming() {
        if ( ! self::available() ) {
            return array();
        }
        $tz       = SFAF_Chat_Core::timezone();
        $now      = new DateTimeImmutable( 'now', $tz );
        $today    = $now->format( 'Y-m-d' );
        $tomorrow = $now->modify( '+1 day' )->format( 'Y-m-d' );
        $until    = $now->modify( '+' . self::DAYS_AHEAD . ' days' )->format( 'Y-m-d' );

        try {
            $rows = (array) SFAF_Closures::spans( $today, $until );
        } catch ( Throwable $e ) {
            return array();
        }

        $out = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['start'] ) || empty( $row['end'] ) ) {
                continue;
            }
            $name = method_exists( 'SFAF_Closures', 'text' ) ? (string) SFAF_Closures::text( $row ) : 'Closed';
            $note = method_exists( 'SFAF_Closures', 'note' ) ? (string) SFAF_Closures::note( $row ) : '';
            $open = array();
            if ( method_exists( 'SFAF_Closures', 'open_lines' ) ) {
                try {
                    $open = array_map( 'strval', (array) SFAF_Closures::open_lines( $row ) );
                } catch ( Throwable $e ) {
                    $open = array();
                }
            }
            $out[] = array(
                'name'     => SFAF_Chat_Core::no_em_dash( $name ),
                'when'     => self::span_text( (string) $row['start'], (string) $row['end'], $tz ),
                'today'    => $row['start'] <= $today && $today <= $row['end'],
                'tomorrow' => $row['start'] <= $tomorrow && $tomorrow <= $row['end'],
                'note'     => SFAF_Chat_Core::no_em_dash( $note ),
                'open'     => array_map( array( 'SFAF_Chat_Core', 'no_em_dash' ), $open ),
            );
            if ( count( $out ) >= self::MAX ) {
                break;
            }
        }
        return $out;
    }

    /** "Thursday, November 26, 2026", or a range of two such dates. */
    private static function span_text( $start, $end, DateTimeZone $tz ) {
        $fmt = function ( $ymd ) use ( $tz ) {
            $d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, $tz );
            return $d ? $d->format( 'l, F j, Y' ) : $ymd;
        };
        return $start === $end ? $fmt( $start ) : $fmt( $start ) . ' through ' . $fmt( $end );
    }

    /**
     * The "current status" section for the system instruction, or '' when there is
     * nothing current and the question isn't about being open or closed.
     *
     * @param string  $question The live message.
     * @param array[] $alerts   SFAF_Chat_Alerts::active()
     */
    public static function status_section( $question, array $alerts ) {
        $closures  = self::upcoming();
        $asks_open = array() !== array_intersect( SFAF_Chat_Core::tokenize( $question ), self::OPEN_WORDS );

        if ( array() === $alerts && array() === $closures && ! ( $asks_open && self::available() ) ) {
            return '';
        }

        $tz    = SFAF_Chat_Core::timezone();
        $now   = new DateTimeImmutable( 'now', $tz );
        $until = $now->modify( '+' . self::DAYS_AHEAD . ' days' )->format( 'l, F j, Y' );
        $lines = array( "\n\n## CURRENT STATUS (from the server, authoritative)" );

        if ( array() !== $alerts ) {
            $lines[] = 'NOTICES that staff have posted for right now. They are shown to the visitor when the chat opens. '
                . 'Never contradict them, and mention one when it bears on the question:';
            foreach ( $alerts as $a ) {
                $lines[] = '- ' . SFAF_Chat_Core::no_em_dash( (string) $a['text'] );
            }
        }

        if ( self::available() ) {
            $today_rows    = array_values( array_filter( $closures, function ( $c ) { return $c['today']; } ) );
            $tomorrow_rows = array_values( array_filter( $closures, function ( $c ) { return $c['tomorrow']; } ) );

            $lines[] = 'TODAY: ' . ( $today_rows
                ? 'SFAF is CLOSED (' . $today_rows[0]['name'] . '). Do not give normal hours as if we were open today.'
                : 'no closure on the calendar. Normal hours apply unless a notice above says otherwise.' );
            $lines[] = 'TOMORROW: ' . ( $tomorrow_rows ? 'CLOSED (' . $tomorrow_rows[0]['name'] . ').' : 'no closure on the calendar.' );

            if ( array() === $closures ) {
                $lines[] = 'CLOSURES: none on the calendar from today through ' . $until . '.';
            } else {
                $lines[] = 'CLOSURES on the calendar from today through ' . $until . ', soonest first. On these days we are '
                    . 'closed; give the note and anything that stays open:';
                foreach ( $closures as $c ) {
                    $line = '- ' . $c['name'] . ': ' . $c['when'] . '.';
                    if ( '' !== $c['note'] ) {
                        $line .= ' Note: ' . $c['note'];
                    }
                    if ( array() !== $c['open'] ) {
                        $line .= ' Still open: ' . implode( '; ', $c['open'] ) . '.';
                    }
                    $lines[] = $line;
                }
            }
            $lines[] = 'For a date after ' . $until . ', say you can only see closures through that date.';
        }

        return implode( "\n", $lines );
    }
}
