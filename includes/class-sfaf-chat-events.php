<?php
/**
 * LIVE EVENTS, read straight from the SFAF Calendar plugin on this same site.
 *
 * No feed and no crawl: the calendar's `uc_event` posts are in this database, so
 * they are queried at ask time and are as current as the calendar itself. A
 * cancellation or an unpublish reaches the assistant on the next question.
 *
 * WHAT THE CALENDAR STORES (read from its code, 3.137/3.138):
 *   post type     uc_event, one post per occurrence (a recurring event is many
 *                 posts in one uc_series term)
 *   _uc_event_date  Y-m-d, site timezone. _uc_start_time / _uc_end_time H:i.
 *                 _uc_end_date for a multi-day event.
 *   _uc_cancelled   '1' when cancelled (SFAF_Cancellation::META)
 *   _uc_private     '1' when unlisted (SFAF_Privacy::META)
 *   taxonomies    uc_organizer, uc_series, uc_event_category, uc_venue, and any
 *                 later ones. Read with get_object_taxonomies(), never listed here.
 * Display details (place, cost, contact, description, image) come through the
 * calendar's own sfaf_event_*() helpers, so they read exactly as the event page
 * does. The raw meta fallbacks only run if a helper is missing.
 *
 * DIVISION OF LABOUR. Everything about dates happens here: what is upcoming, what
 * has ended, what is "this weekend", which date is next in a series, and the
 * "Today"/"Tomorrow" label. The model receives a finished list and is told not to
 * compare dates.
 *
 * MATCHING, THEN A SHORT LIST. Keywords first, on stems with the question's synonyms
 * (SFAF_Chat_Core::terms() and SYNONYMS, as pages are ranked, so "Spanish" meets
 * "Programa Latino"), across each event's title, calendar labels, location and
 * description (1.30.0). Every event that matches is kept: title and label matches
 * first, then the rest, soonest first in each. When a message is about events, names a
 * topic, and still nothing matches, the model gets a short, separate list of the
 * next upcoming events in the window (titles and calendar labels only) as
 * CANDIDATES, and decides itself whether one is clearly what was asked. Keyword
 * overlap can't see that connection; the model can. The candidates are marked as
 * unmatched, and the prompt holds it to offering one only when it clearly fits.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Events {

    const POST_TYPE = 'uc_event';

    // The record id for the calendar page. Never change it once live.
    const CALENDAR_ID = 'events-calendar';

    const MAX_SCAN     = 300;   // upcoming occurrences considered per question, soonest first
    // Matched events handed to the model, one per series (1.30.0, was 6). Ten full
    // records is about the size of RELATED PAGES, and more than a phone-sized list a
    // visitor will read; past it the model is told how many there are and points to
    // the calendar.
    const MAX_SENT     = 10;
    const GENERAL_SENT = 5;     // "what's coming up?" with no topic
    const ALSO_ON      = 3;     // later dates of a series, listed on its next one
    const DESC_WORDS   = 120;
    const CANDIDATES   = 20;    // unmatched short list: series, next date each
    const WINDOW_SENT  = 12;    // matched events in a window: each date listed, soonest first

    // Words that make a message about events. Compared after SFAF_Chat_Core::tokenize(),
    // which folds a trailing s, so both spellings are listed where that matters.
    const EVENT_WORDS = array(
        'event', 'calendar', 'happening', 'upcoming', 'workshop', 'meetup', 'gathering',
        'class', 'classe', 'session', 'meeting', 'activity', 'activitie', 'going', 'scheduled',
        'evento', 'calendario', 'taller', 'tallere', 'actividad', 'actividade', 'reunion', 'reunión',
    );

    // Time words, which choose a window and are never a topic.
    const TIME_WORDS = array(
        'today', 'tonight', 'tomorrow', 'week', 'weekend', 'month', 'next', 'soon', 'coming',
        'later', 'now', 'date', 'time', 'schedule', 'hoy', 'mañana', 'manana', 'semana', 'fin',
        'mes', 'próximo', 'proximo', 'próxima', 'proxima', 'pronto', 'noche',
    );

    // Words too common in event and program names to identify one by themselves.
    // "health" is the reason: "health events" must not mean "Black Health".
    const GENERIC_WORDS = array(
        'health', 'service', 'program', 'programa', 'community', 'group', 'support', 'night',
        'day', 'free', 'center', 'centre', 'online', 'person', 'virtual', 'monthly', 'weekly',
        'series', 'new', 'open', 'social', 'any', 'there', 'other', 'salud', 'grupo', 'servicio',
        'where', 'start', 'much', 'cost', 'register', 'sign',
    );

    /** Is the calendar on this site? */
    public static function available() {
        return post_type_exists( self::POST_TYPE );
    }

    /**
     * What to show the model for this question.
     *
     * @param string   $question   The live message.
     * @param string[] $user_turns Earlier visitor messages, oldest first.
     * @return array{events:array,calendar:array|null,note:string}
     *   events    records of type "event", ready for the record map
     *   calendar  the calendar page record, or null when events are irrelevant
     *   candidates  short records (title, date, labels), sent only when the message
     *               is about events, names a topic, and nothing matched. The model
     *               picks from these, or none.
     *   note      one line for the model saying how the list was chosen
     *   organizers  organizer names of the matched events, most relevant first, so
     *               the endpoint can find the program's own page
     */
    public static function for_question( $question, array $user_turns = array() ) {
        $none = array( 'events' => array(), 'candidates' => array(), 'calendar' => null, 'note' => '', 'organizers' => array() );
        if ( ! self::available() ) {
            return $none;
        }

        $tokens  = array_values( array_unique( SFAF_Chat_Core::tokenize( $question ) ) );
        $eventish = self::is_eventish( $tokens );
        $window   = self::window( $tokens );

        $all = self::upcoming();
        if ( array() === $all ) {
            return $eventish
                ? array( 'events' => array(), 'candidates' => array(), 'calendar' => self::calendar_record(), 'note' => 'There are no upcoming events on the calendar right now.' )
                : $none;
        }

        $match = self::match( $all, $tokens );

        // A follow-up ("what time does it start?") names nothing; the subject is
        // earlier in the thread. Only when this message is about events, or names no
        // subject of its own (1.30.0; was "two words or fewer"), so an unrelated new
        // question stays unrelated: with stems, "How do I donate?" after "Where can I
        // get tested?" would otherwise pull in the calendar's testing events.
        if ( array() === $match['events'] && ( $eventish || array() === self::meaningful( $tokens ) ) ) {
            $prior = array();
            foreach ( array_slice( $user_turns, -2 ) as $t ) {
                $prior = array_merge( $prior, SFAF_Chat_Core::tokenize( $t ) );
            }
            $prior = array_values( array_unique( $prior ) );
            if ( array() !== $prior ) {
                $match = self::match( $all, $prior );
                if ( array() !== $match['events'] ) {
                    $eventish = true;
                    if ( null === $window ) {
                        $window = self::window( $prior );
                    }
                }
            }
        }

        $general = false;
        if ( array() === $match['events'] ) {
            // A topic was named and nothing on the calendar is clearly it: do not
            // guess. Send the calendar page only.
            if ( ! $eventish ) {
                return $none;
            }
            if ( array() !== self::content_tokens( $tokens ) ) {
                return self::candidates( $all, $window );
            }
            // "What's coming up?" with no topic: the next events, in order.
            $general = true;
            $match   = array( 'events' => $all, 'why' => '' );
        }

        $picked = $match['events'];

        /*
         * A topic matched and no window was named (1.30.0): every matching upcoming
         * event, one record per series at its next date with the dates after it, up to
         * MAX_SENT. Before 1.30.0 this listed every date of the next two weeks, which
         * spent the list on one weekly series and left other matches out.
         */
        if ( ! $general && null === $window ) {
            $records = self::to_records( $picked, self::MAX_SENT, $all );
            $total   = self::distinct( $picked );
            return array(
                'events'     => $records,
                'candidates' => array(),
                'calendar'   => self::calendar_record(),
                'note'       => 'Matched to the question by ' . $match['why'] . '. '
                    . ( $total > count( $records )
                        ? 'There are ' . $total . ' matching upcoming events and only the first ' . count( $records ) . ' are listed: list these, then say there are more and point to the events calendar for the rest. '
                        : 'These are ALL the matching upcoming events. ' )
                    . 'A repeating event is listed once, at its next date, with the dates after it. ' . self::ORDER_NOTE,
                'organizers' => self::organizers( $picked, $match ),
            );
        }

        $window_note = '';
        if ( null !== $window ) {
            $picked = array_values( array_filter( $picked, function ( $e ) use ( $window ) {
                return $e['date'] >= $window['from'] && $e['date'] <= $window['to'];
            } ) );
            $window_note = ' The visitor asked about ' . $window['label'] . ', so only events in that window are listed.';
            if ( array() === $picked ) {
                $last    = end( $all );
                $covered = count( $all ) < self::MAX_SCAN || $last['date'] >= $window['to'];
                return array(
                    'events'     => array(),
                    'candidates' => array(),
                    'calendar'   => self::calendar_record(),
                    'note'       => $covered
                        ? 'There are no ' . ( $general ? '' : 'matching ' ) . 'events on the calendar for ' . $window['label'] . '. Say so, and point to the events calendar for other dates.'
                        : 'Point the visitor to the events calendar for ' . $window['label'] . '. Do not name or guess an event, date or time.',
                );
            }
        }

        $records = ( ! $general && null !== $window )
            ? self::to_occurrences( $picked, self::WINDOW_SENT )
            : self::to_records( $picked, $general ? self::GENERAL_SENT : self::MAX_SENT, $all );

        $note = $general
            ? 'Not matched to a topic: these are simply the next events on the calendar.'
            : 'Matched to the question by ' . $match['why'] . '. ' . self::ORDER_NOTE
                . ( count( $picked ) > self::WINDOW_SENT
                    ? ' There are ' . count( $picked ) . ' matching dates and only the first ' . self::WINDOW_SENT . ' are listed: list these, then say there are more and point to the events calendar for the rest.'
                    : '' );

        return array(
            'events'     => $records,
            'candidates' => array(),
            'calendar'   => self::calendar_record(),
            'note'       => $note . $window_note,
            'organizers' => $general ? array() : self::organizers( $picked, $match ),
        );
    }

    // How a matched list is ordered, for the model (1.30.0).
    const ORDER_NOTE = 'Order: events whose title or calendar label matched come first, then events that only mention it in their description or location; within each, the closest matches first, then the soonest. List every one, in this order.';

    /**
     * Which dates are one repeating event: the same series AND the same title
     * (1.30.0), or '' for an event on its own. The series alone is not enough: the
     * live calendar files different events under one series ("World AIDS Day" holds
     * the breakfast, the vigil and the performance), and those are separate events.
     */
    private static function repeat_key( array $e ) {
        return $e['series'] ? $e['series'] . '|' . mb_strtolower( trim( (string) $e['title'] ) ) : '';
    }

    /** How many different events: a repeating event counts once. */
    private static function distinct( array $events ) {
        $keys = array();
        foreach ( $events as $e ) {
            $k = self::repeat_key( $e );
            $keys[ '' !== $k ? 's' . $k : 'e' . $e['id'] ] = true;
        }
        return count( $keys );
    }

    /**
     * Organizer names of these events, most relevant first: organizers the question
     * named come first, then by how many of the events each runs. Used only to find
     * the program's own page; never shown as a claim.
     */
    private static function organizers( array $events, array $match ) {
        $count = array();
        foreach ( $events as $e ) {
            foreach ( (array) ( $e['terms']['uc_organizer'] ?? array() ) as $name ) {
                $count[ $name ] = ( $count[ $name ] ?? 0 ) + 1;
            }
        }
        arsort( $count );
        $named = array_values( array_intersect( array_keys( $count ), (array) ( $match['hit_names'] ?? array() ) ) );
        return array_values( array_unique( array_merge( $named, array_keys( $count ) ) ) );
    }

    /**
     * Every date of these events, soonest first, capped at $limit. The first date of
     * a series is a full record; its later dates are short (title, date, labels),
     * because the model already has the details once.
     */
    private static function to_occurrences( array $events, $limit ) {
        $seen = array();
        $out  = array();
        foreach ( $events as $e ) {
            $key    = self::repeat_key( $e );
            $repeat = '' !== $key && isset( $seen[ $key ] );
            $rec    = self::record( $e, array(), $repeat );
            if ( null !== $rec ) {
                $out[] = $rec;
                if ( '' !== $key ) {
                    $seen[ $key ] = true;
                }
            }
            if ( count( $out ) >= $limit ) {
                break;
            }
        }
        return $out;
    }

    /**
     * Nothing matched the topic the visitor named: the next upcoming events in the
     * window, one per series, as short candidates for the model to judge. When the
     * window holds nothing, the server says so, as before.
     */
    private static function candidates( array $all, $window ) {
        $pool = $all;
        $when = '';
        if ( null !== $window ) {
            $pool = array_values( array_filter( $all, function ( $e ) use ( $window ) {
                return $e['date'] >= $window['from'] && $e['date'] <= $window['to'];
            } ) );
            $when = ' ' . $window['label'];
        }

        $records = array() === $pool ? array() : self::to_records( $pool, self::CANDIDATES, $all, true );
        if ( array() === $records ) {
            $last    = end( $all );
            $covered = null === $window || count( $all ) < self::MAX_SCAN || $last['date'] >= $window['to'];
            return array(
                'events'     => array(),
                'candidates' => array(),
                'calendar'   => self::calendar_record(),
                'note'       => $covered && null !== $window
                    ? 'There are no events on the calendar for ' . $window['label'] . '. Say so, and point to the events calendar for other dates.'
                    : 'No upcoming event clearly matches what the visitor asked about. Do not name or guess an event, date or time. Point them to the events calendar.',
            );
        }

        return array(
            'events'     => array(),
            'candidates' => $records,
            'calendar'   => self::calendar_record(),
            'note'       => 'No event matched the visitor\'s words, so OTHER UPCOMING EVENTS lists the next events' . $when . ' for you to judge.',
        );
    }

    /* ============================== THE QUERY ============================= */

    /**
     * Every upcoming, listed occurrence, soonest first, as light arrays for matching.
     *
     * publish only (so not drafts, pending or scheduled), no password, not private,
     * not cancelled (whether the organizer kept it listed or not), dated today or
     * later, and not already over today.
     *
     * @return array[] id, date, start, title, text, series (term id), terms
     *                 (taxonomy => names), tax_labels
     */
    public static function upcoming() {
        $cancel_key  = class_exists( 'SFAF_Cancellation' ) ? SFAF_Cancellation::META : '_uc_cancelled';
        $private_key = class_exists( 'SFAF_Privacy' ) ? SFAF_Privacy::META : '_uc_private';

        // The calendar stores dates in the site timezone, so "today" is taken there,
        // exactly as its own listings do.
        $today = wp_date( 'Y-m-d', null, wp_timezone() );

        $absent_or_not = function ( $key ) {
            return array(
                'relation' => 'OR',
                array( 'key' => $key, 'compare' => 'NOT EXISTS' ),
                array( 'key' => $key, 'value' => array( '', '0' ), 'compare' => 'IN' ),
            );
        };

        // Same shape as the calendar's own builder: meta_key for the order, the
        // date window and the two exclusions as siblings under AND.
        $q = new WP_Query( array(
            'post_type'           => self::POST_TYPE,
            'post_status'         => 'publish',
            'has_password'        => false,
            'posts_per_page'      => self::MAX_SCAN,
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'meta_key'            => '_uc_event_date',
            'orderby'             => 'meta_value',
            'order'               => 'ASC',
            'meta_query'          => array(
                'relation' => 'AND',
                array( 'key' => '_uc_event_date', 'value' => $today, 'compare' => '>=', 'type' => 'DATE' ),
                $absent_or_not( $private_key ),
                $absent_or_not( $cancel_key ),
            ),
        ) );

        $taxonomies = self::taxonomies();
        $out        = array();

        foreach ( $q->posts as $post ) {
            $id = (int) $post->ID;

            // Asked again through the calendar's own answers, in case the query
            // missed a shape of stored value it doesn't know about.
            if ( self::is_cancelled( $id ) || self::is_private( $id ) || self::has_ended( $id ) ) {
                continue;
            }
            $date = (string) get_post_meta( $id, '_uc_event_date', true );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
                continue;
            }

            $terms  = array();
            $series = 0;
            foreach ( $taxonomies as $tax => $label ) {
                $got = get_the_terms( $id, $tax );
                if ( ! $got || is_wp_error( $got ) ) {
                    continue;
                }
                foreach ( $got as $t ) {
                    $terms[ $tax ][ (int) $t->term_id ] = self::plain( $t->name );
                }
                if ( 'uc_series' === $tax ) {
                    $series = (int) reset( $got )->term_id;
                }
            }

            $out[] = array(
                'id'     => $id,
                'date'   => $date,
                'start'  => (string) get_post_meta( $id, '_uc_start_time', true ),
                'title'  => self::plain( get_the_title( $id ) ),
                'text'   => self::description( $id ),
                'where'  => self::location( $id ),   // matched too (1.30.0)
                'series' => $series,
                'terms'  => $terms,
            );
        }

        // The query orders by date only; within a day, by start time.
        usort( $out, function ( $a, $b ) {
            return array( $a['date'], $a['start'] ?: '99' ) <=> array( $b['date'], $b['start'] ?: '99' );
        } );
        return $out;
    }

    /**
     * The public taxonomies on events, read now, so a new one is picked up as soon
     * as the calendar registers it. taxonomy => singular label.
     */
    public static function taxonomies() {
        $out = array();
        foreach ( get_object_taxonomies( self::POST_TYPE, 'objects' ) as $tax ) {
            if ( empty( $tax->public ) ) {
                continue;
            }
            $label = isset( $tax->labels->singular_name ) ? (string) $tax->labels->singular_name : (string) $tax->label;
            $out[ $tax->name ] = self::plain( $label );
        }
        return $out;
    }

    /* ============================== MATCHING ============================== */

    /**
     * Which upcoming events the tokens are about (1.30.0).
     *
     * Every question word that could identify something (not an event, time or
     * generic word) is a group: its stem, and the stems of its synonyms. An event is
     * looked at in four places, each on stems (SFAF_Chat_Core::terms()): its title,
     * its calendar labels (every taxonomy), its location and its description.
     *
     * An event matches when
     *   - one of its calendar labels matches: every meaningful word of the label is in
     *     the question, or the question has a word that belongs to that label alone
     *     among all live labels ("aging" for Aging Services). The labels come from
     *     the events themselves, so one with nothing upcoming is never offered; or
     *   - the four places together hold enough of the question: two of its groups, or
     *     its only one. So one incidental word in a long question is not a match.
     *
     * Every match is kept. A title or label match comes first, then the events that
     * only mention it in the description or location; within each, the events that
     * hold more of the question's words first, then the soonest.
     * Before 1.30.0 a label match returned label matches only, and the rest kept only
     * the top band of scores, so an event that said "World AIDS Day" in its
     * description was dropped when another said it in its title.
     *
     * @return array{events:array,why:string,hit_names:array}
     */
    private static function match( array $all, array $tokens ) {
        $none  = array( 'events' => array(), 'why' => '', 'hit_names' => array() );
        $words = array_values( array_unique( self::meaningful( $tokens ) ) );
        if ( array() === $words ) {
            return $none;
        }

        // One group per word: its stem and its synonyms' stems.
        $syns    = SFAF_Chat_Core::synonyms_of( $words );
        $groups  = array();
        $content = array();   // every stem of every group
        foreach ( $words as $w ) {
            $g = array( SFAF_Chat_Core::stem( $w ) => true );
            foreach ( self::meaningful( $syns[ $w ] ?? array() ) as $s ) {
                $g[ SFAF_Chat_Core::stem( $s ) ] = true;
            }
            $groups[] = $g;
            $content += $g;
        }
        $need = min( 2, count( $groups ) );

        /* ---- calendar labels ---- */
        $names = array();   // "tax|term_id" => name
        foreach ( $all as $e ) {
            foreach ( $e['terms'] as $tax => $list ) {
                foreach ( $list as $tid => $name ) {
                    $names[ $tax . '|' . $tid ] = $name;
                }
            }
        }
        $label_stems = function ( $name ) {
            return array_values( array_unique( array_map( array( 'SFAF_Chat_Core', 'stem' ), self::meaningful( SFAF_Chat_Core::tokenize( $name ) ) ) ) );
        };
        $df = array();   // stem => how many distinct label names use it
        foreach ( array_unique( $names ) as $name ) {
            foreach ( $label_stems( $name ) as $w ) {
                $df[ $w ] = ( $df[ $w ] ?? 0 ) + 1;
            }
        }
        $hit_terms = array();
        foreach ( $names as $key => $name ) {
            $stems = $label_stems( $name );
            if ( array() === $stems ) {
                continue;
            }
            $all_in      = array() === array_diff( $stems, array_keys( $content ) );
            $distinctive = false;
            foreach ( $stems as $w ) {
                if ( isset( $content[ $w ] ) && 1 === ( $df[ $w ] ?? 0 ) && mb_strlen( $w ) >= 4 ) {
                    $distinctive = true;
                    break;
                }
            }
            if ( $all_in || $distinctive ) {
                $hit_terms[ $key ] = true;
            }
        }

        /* ---- each event ---- */
        $hits = array();   // [tier, words covered, position, event]
        $by   = array();   // which places matched, for the note
        foreach ( $all as $pos => $e ) {
            $label_hit = false;
            $label_txt = array();
            foreach ( $e['terms'] as $tax => $list ) {
                foreach ( $list as $tid => $name ) {
                    $label_txt[] = $name;
                    if ( isset( $hit_terms[ $tax . '|' . $tid ] ) ) {
                        $label_hit = true;
                    }
                }
            }
            $places = array(
                'event title'    => array_flip( SFAF_Chat_Core::terms( $e['title'] ) ),
                'calendar label' => array_flip( SFAF_Chat_Core::terms( implode( ' ', $label_txt ) ) ),
                'location'       => array_flip( SFAF_Chat_Core::terms( (string) ( $e['where'] ?? '' ) ) ),
                'description'    => array_flip( SFAF_Chat_Core::terms( $e['text'] ) ),
            );
            $covered  = 0;
            $in_title = false;
            $where    = array();
            foreach ( $groups as $g ) {
                foreach ( $places as $place => $stems ) {
                    if ( array() !== array_intersect_key( $g, $stems ) ) {
                        $covered++;
                        $where[ $place ] = true;
                        $in_title        = $in_title || 'event title' === $place;
                        break;
                    }
                }
            }
            if ( ! $label_hit && $covered < $need ) {
                continue;
            }
            // Tier 0: the title or a calendar label matched. Tier 1: only the
            // description or the location.
            $hits[] = array( ( $label_hit || $in_title ) ? 0 : 1, $covered, $pos, $e );
            $by    += $where;
        }
        if ( array() === $hits ) {
            return $none;
        }
        // Within a tier, the events holding more of the question first, then soonest
        // ($all is soonest first, so its position is the date order).
        usort( $hits, function ( $a, $b ) {
            return array( $a[0], -$a[1], $a[2] ) <=> array( $b[0], -$b[1], $b[2] );
        } );

        $hit_names = array_values( array_unique( array_intersect_key( $names, $hit_terms ) ) );
        $why       = array();
        if ( array() !== $hit_names ) {
            $why[] = 'calendar label "' . implode( '", "', $hit_names ) . '"';
            unset( $by['calendar label'] );
        }
        foreach ( array( 'event title', 'calendar label', 'location', 'description' ) as $place ) {
            if ( isset( $by[ $place ] ) ) {
                $why[] = $place;
            }
        }
        return array(
            'events'    => array_column( $hits, 3 ),
            'why'       => implode( ', ', $why ),
            'hit_names' => $hit_names,
        );
    }

    private static function is_eventish( array $tokens ) {
        return array() !== array_intersect( $tokens, self::EVENT_WORDS );
    }

    /** Tokens that name a subject: not event words, not time words. */
    private static function content_tokens( array $tokens ) {
        return array_values( array_diff( $tokens, self::EVENT_WORDS, self::TIME_WORDS ) );
    }

    /** Words in a term name that could identify it. */
    private static function meaningful( array $tokens ) {
        return array_values( array_diff( $tokens, self::EVENT_WORDS, self::TIME_WORDS, self::GENERIC_WORDS ) );
    }

    /**
     * A date window named in the question, worked out here so the model never has
     * to: today, tonight, tomorrow, this week, the weekend, next week, this month,
     * next month. English and Spanish. null when none is named.
     *
     * @return array{from:string,to:string,label:string}|null
     */
    private static function window( array $tokens ) {
        $has = array_flip( $tokens );
        $tz  = wp_timezone();
        $now = new DateTimeImmutable( 'now', $tz );
        $d   = function ( DateTimeImmutable $x ) {
            return $x->format( 'Y-m-d' );
        };
        $span = function ( DateTimeImmutable $from, DateTimeImmutable $to, $label ) use ( $d ) {
            return array( 'from' => $d( $from ), 'to' => $d( $to ), 'label' => $label );
        };
        $next = isset( $has['next'] ) || isset( $has['próxima'] ) || isset( $has['proxima'] ) || isset( $has['próximo'] ) || isset( $has['proximo'] );

        if ( isset( $has['today'] ) || isset( $has['tonight'] ) || isset( $has['hoy'] ) ) {
            return $span( $now, $now, 'today' );
        }
        if ( isset( $has['tomorrow'] ) ) {
            $t = $now->modify( '+1 day' );
            return $span( $t, $t, 'tomorrow' );
        }
        if ( isset( $has['weekend'] ) || ( isset( $has['fin'] ) && isset( $has['semana'] ) ) ) {
            $dow = (int) $now->format( 'N' );   // 1 Mon .. 7 Sun
            $sat = $dow >= 6 ? $now : $now->modify( 'next saturday' );
            $sun = 7 === $dow ? $now : $sat->modify( '+1 day' );
            // "Next weekend" is ambiguous on a weekday; the coming one is taken.
            return $span( $sat, $sun, 'the weekend' );
        }
        if ( isset( $has['week'] ) || isset( $has['semana'] ) ) {
            $dow    = (int) $now->format( 'N' );
            $sunday = 7 === $dow ? $now : $now->modify( 'next sunday' );
            if ( $next ) {
                return $span( $sunday->modify( '+1 day' ), $sunday->modify( '+7 days' ), 'next week' );
            }
            return $span( $now, $sunday, 'this week' );
        }
        if ( isset( $has['month'] ) || isset( $has['mes'] ) ) {
            if ( $next ) {
                $first = $now->modify( 'first day of next month' );
                return $span( $first, $first->modify( 'last day of this month' ), 'next month' );
            }
            return $span( $now, $now->modify( 'last day of this month' ), 'this month' );
        }
        return null;
    }

    /* ============================== RECORDS =============================== */

    /**
     * Records for the model and the card, one per series (its next date, with the
     * dates after it listed), capped at $limit.
     */
    private static function to_records( array $events, $limit, array $all, $labels_only = false ) {
        // Later dates of every series, from the full upcoming list.
        $dates_by_series = array();
        foreach ( $all as $e ) {
            if ( '' !== self::repeat_key( $e ) ) {
                $dates_by_series[ self::repeat_key( $e ) ][] = $e;
            }
        }

        $seen = array();
        $out  = array();
        foreach ( $events as $e ) {
            $key = self::repeat_key( $e );
            if ( '' !== $key ) {
                if ( isset( $seen[ $key ] ) ) {
                    continue;
                }
                $seen[ $key ] = true;
            }
            $rec = self::record( $e, '' !== $key ? ( $dates_by_series[ $key ] ?? array() ) : array(), $labels_only );
            if ( null !== $rec ) {
                $out[] = $rec;
            }
            if ( count( $out ) >= $limit ) {
                break;
            }
        }
        return $out;
    }

    /**
     * One event as a record in the shared schema, or null without an https address.
     * $labels_only: for the unmatched candidate list, the model sees only the calendar
     * labels as content (the card still gets everything).
     */
    private static function record( array $e, array $series_dates, $labels_only = false ) {
        $id  = $e['id'];
        $url = (string) get_permalink( $id );
        if ( ! preg_match( '#^https://#i', $url ) ) {
            return null;
        }

        $location = self::location( $id );
        $short    = function_exists( 'sfaf_event_location_short' ) ? self::plain( sfaf_event_location_short( $id ) ) : $location;

        $facts = array();
        foreach ( self::taxonomies() as $tax => $label ) {
            if ( ! empty( $e['terms'][ $tax ] ) ) {
                $facts[] = $label . ': ' . implode( ', ', $e['terms'][ $tax ] ) . '.';
            }
        }
        $labels = implode( ' ', $facts );
        if ( function_exists( 'sfaf_event_format_line' ) ) {
            $format = self::plain( sfaf_event_format_line( $id ) );
            if ( '' !== $format ) {
                $facts[] = $format . '.';
            }
        }
        $cost = function_exists( 'sfaf_event_cost' ) ? self::plain( sfaf_event_cost( $id ) ) : self::plain( get_post_meta( $id, '_uc_cost', true ) );
        if ( '' !== $cost ) {
            $facts[] = 'Cost: ' . $cost . '.';
        }
        if ( function_exists( 'sfaf_event_age_restriction' ) ) {
            $age = self::plain( sfaf_event_age_restriction( $id ) );
            if ( '' !== $age ) {
                $facts[] = 'Ages: ' . $age . '.';
            }
        }
        $contact = function_exists( 'sfaf_event_public_contact' ) ? self::plain( sfaf_event_public_contact( $id ) ) : '';
        if ( '' !== $contact ) {
            $facts[] = 'Public contact: ' . $contact . '.';
        }
        $registers = ( function_exists( 'sfaf_event_takes_rsvps' ) && sfaf_event_takes_rsvps( $id ) )
            || ( function_exists( 'sfaf_event_rsvp_url' ) && '' !== sfaf_event_rsvp_url( $id ) );
        if ( $registers ) {
            $facts[] = 'Registration: through the event page.';
        }

        $later = array();
        foreach ( $series_dates as $s ) {
            if ( $s['id'] !== $id && array( $s['date'], $s['start'] ) > array( $e['date'], $e['start'] ) ) {
                $later[] = self::when( $s['id'], false );
                if ( count( $later ) >= self::ALSO_ON ) {
                    break;
                }
            }
        }
        if ( $later ) {
            $facts[] = 'Next dates after this one: ' . implode( '; ', $later ) . '.';
        }

        $desc  = $e['text'];
        $words = preg_split( '/\s+/u', $desc, -1, PREG_SPLIT_NO_EMPTY );
        if ( count( $words ) > self::DESC_WORDS ) {
            $desc = implode( ' ', array_slice( $words, 0, self::DESC_WORDS ) ) . ' …';
        }

        $image = function_exists( 'sfaf_event_image_url' ) ? (string) sfaf_event_image_url( $id ) : (string) get_the_post_thumbnail_url( $id, 'large' );
        $when  = self::when( $id, true );

        return array(
            'id'             => 'evt-' . $id,
            'title'          => $e['title'],
            'url'            => $url,
            'source'         => 'resources.sfaf.org',
            'type'           => 'event',
            'date'           => $when,
            'location'       => $location,
            'location_short' => $short,
            'content'        => $labels_only ? $labels : trim( implode( ' ', $facts ) . ( '' !== $desc ? ' ' . $desc : '' ) ),
            'og'             => array(
                'title'       => $e['title'],
                'description' => $desc,
                'image'       => '' !== $image ? $image : null,
            ),
        );
    }

    /** The calendar page, for "see all dates" and for every uncertain match. */
    public static function calendar_record() {
        $url = function_exists( 'sfaf_calendar_home_url' ) ? (string) sfaf_calendar_home_url() : '';
        if ( ! preg_match( '#^https://#i', $url ) ) {
            $url = (string) get_post_type_archive_link( self::POST_TYPE );
        }
        $url = (string) apply_filters( 'sfaf_chat_calendar_url', $url );
        if ( ! preg_match( '#^https://#i', $url ) ) {
            return null;
        }
        return array(
            'id'      => self::CALENDAR_ID,
            'title'   => 'SFAF events calendar',
            'url'     => $url,
            'source'  => 'resources.sfaf.org',
            'type'    => 'page',
            'content' => 'The full calendar of upcoming SFAF events, with dates, times, locations and registration.',
        );
    }

    /* ============================== READERS =============================== */

    private static function is_cancelled( $id ) {
        if ( class_exists( 'SFAF_Cancellation' ) && SFAF_Cancellation::is_cancelled( $id ) ) {
            return true;
        }
        // "Set" means set: any value but empty or 0.
        $v = (string) get_post_meta( $id, '_uc_cancelled', true );
        return '' !== $v && '0' !== $v;
    }

    private static function is_private( $id ) {
        if ( class_exists( 'SFAF_Privacy' ) ) {
            return SFAF_Privacy::is_private( $id );
        }
        return '1' === (string) get_post_meta( $id, '_uc_private', true );
    }

    /** Over already today? Only the calendar knows its own end rules. */
    private static function has_ended( $id ) {
        return function_exists( 'sfaf_event_has_ended' ) && sfaf_event_has_ended( $id );
    }

    private static function description( $id ) {
        if ( function_exists( 'sfaf_event_description_text' ) ) {
            return self::plain( sfaf_event_description_text( $id ) );
        }
        return self::plain( wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) ) );
    }

    private static function location( $id ) {
        if ( function_exists( 'sfaf_event_location' ) ) {
            return self::plain( sfaf_event_location( $id ) );
        }
        return self::plain( get_post_meta( $id, '_uc_location', true ) );
    }

    /**
     * "Today, Tuesday, November 10, 2026, 6–8 pm": the date as the calendar writes
     * it, with Today or Tomorrow in front when it is, so the model can answer
     * "anything tonight?" without counting days.
     */
    private static function when( $id, $relative ) {
        $date  = (string) get_post_meta( $id, '_uc_event_date', true );
        $last  = (string) get_post_meta( $id, '_uc_end_date', true );
        $start = (string) get_post_meta( $id, '_uc_start_time', true );
        $end   = (string) get_post_meta( $id, '_uc_end_time', true );

        $fmt_date = function ( $ymd ) {
            if ( function_exists( 'sfaf_ap_date' ) ) {
                $s = (string) sfaf_ap_date( $ymd, 'full' );
                if ( '' !== $s ) {
                    return $s;
                }
            }
            $ts = strtotime( $ymd . ' 12:00:00' );
            return $ts ? wp_date( 'l, F j, Y', $ts, wp_timezone() ) : $ymd;
        };

        $out = $fmt_date( $date );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $last ) && $last > $date ) {
            $out .= ' to ' . $fmt_date( $last );
        }

        if ( '' !== $start ) {
            $clock = function_exists( 'sfaf_ap_time_range' ) ? (string) sfaf_ap_time_range( $start, $end ) : '';
            if ( '' === $clock ) {
                $clock = $start . ( '' !== $end ? "\xE2\x80\x93" . $end : '' );
            }
            $out .= ', ' . $clock;
        }

        if ( $relative ) {
            $tz    = wp_timezone();
            $today = wp_date( 'Y-m-d', null, $tz );
            $tmrw  = ( new DateTimeImmutable( 'now', $tz ) )->modify( '+1 day' )->format( 'Y-m-d' );
            if ( $date === $today ) {
                $out = 'Today, ' . $out;
            } elseif ( $date === $tmrw ) {
                $out = 'Tomorrow, ' . $out;
            }
        }
        return self::plain( $out );
    }

    /** Entities decoded, whitespace collapsed, em dashes out. */
    private static function plain( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
        return SFAF_Chat_Core::no_em_dash( $text );
    }
}
