<?php
/**
 * Pinned answers: exact, staff-approved wording for specific questions.
 *
 * VERBATIM BY CONSTRUCTION. The model never sees a pinned answer, so it cannot
 * paraphrase one. The endpoint replaces the reply text with the stored text after
 * the model has run.
 *
 * WHY THE MODEL STILL RUNS. A keyword match cannot tell "How much does PrEP
 * cost?" from a crisis message that happens to mention what PrEP costs. Every
 * message therefore goes through the normal guarded call first, and a pin is used
 * only if the model did NOT flag crisis, personal advice or out of scope. See
 * blocks_pin(). If the model can't be reached or its reply can't be read, nothing
 * has been checked, so the pin is not used.
 *
 * WHY MATCHING IS NOT THE MODEL'S JOB. Matching is plain PHP, so staff can predict
 * and test exactly what fires, and it reads only the visitor's current message,
 * never earlier turns.
 *
 * TRIGGER SYNTAX, one rule per line. Any one line can fire the pin.
 *
 *   prep, cost | price | how much        every comma group must appear;
 *                                        | lists alternatives within a group
 *   "how much does prep cost"            quoted: the whole question must equal this
 *
 * Whole words and phrases only ("prep" does not match "preparation"). Capitals,
 * punctuation and accents are ignored. A line needs at least two comma groups
 * unless it is quoted, because a single word would fire far too loosely.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Pinned {

    const OPTION = 'sfaf_chat_pinned';

    /** Flags that mean the model refused or escalated. A pin never overrides these. */
    const BLOCKING_FLAGS = array( 'crisis', 'personal_advice', 'out_of_scope' );

    /** @return array[] Every pin, in match order. */
    public static function all() {
        $pins = get_option( self::OPTION, array() );
        if ( ! is_array( $pins ) ) {
            return array();
        }
        $pins = array_values( array_filter( $pins, 'is_array' ) );
        usort( $pins, function ( $a, $b ) {
            return (int) ( $a['order'] ?? 0 ) <=> (int) ( $b['order'] ?? 0 );
        } );
        return $pins;
    }

    public static function get( $id ) {
        foreach ( self::all() as $pin ) {
            if ( ( $pin['id'] ?? '' ) === $id ) {
                return $pin;
            }
        }
        return null;
    }

    public static function save_all( array $pins ) {
        update_option( self::OPTION, array_values( $pins ), false );
    }

    /* ================================ MATCHING ============================ */

    /** Lowercase, no accents, no apostrophes, punctuation to spaces. */
    public static function normalize( $text ) {
        $text = remove_accents( (string) $text );
        $text = mb_strtolower( $text );
        $text = str_replace( array( "'", "\u{2019}" ), '', $text );
        $text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
        return trim( (string) $text );
    }

    /**
     * One trigger line to a rule.
     *
     * @return array|string The rule, or a sentence saying why the line is invalid.
     */
    public static function parse_line( $line ) {
        $line = trim( (string) $line );

        if ( preg_match( '/^["\x{201C}](.*)["\x{201D}]$/u', $line, $m ) ) {
            $exact = self::normalize( $m[1] );
            return '' === $exact
                ? 'A quoted line has no words in it.'
                : array( 'type' => 'exact', 'text' => $exact );
        }

        $groups = array();
        foreach ( explode( ',', $line ) as $raw_group ) {
            $alternatives = array();
            foreach ( explode( '|', $raw_group ) as $raw_term ) {
                $term = self::normalize( $raw_term );
                if ( '' !== $term ) {
                    $alternatives[] = $term;
                }
            }
            if ( array() !== $alternatives ) {
                $groups[] = $alternatives;
            }
        }

        if ( count( $groups ) < 2 ) {
            return 'Needs at least two comma-separated parts, or quotes for an exact question.';
        }
        return array( 'type' => 'all', 'groups' => $groups );
    }

    /** Is $phrase present in normalized $question as whole words? */
    private static function has_phrase( $question, $phrase ) {
        return false !== strpos( ' ' . $question . ' ', ' ' . $phrase . ' ' );
    }

    private static function rule_matches( array $rule, $question ) {
        if ( 'exact' === $rule['type'] ) {
            return $question === $rule['text'];
        }
        foreach ( $rule['groups'] as $alternatives ) {
            $found = false;
            foreach ( $alternatives as $term ) {
                if ( self::has_phrase( $question, $term ) ) {
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                return false;
            }
        }
        return true;
    }

    /** Does any exclude phrase (comma separated) appear in the question? */
    private static function excluded( array $pin, $question ) {
        foreach ( explode( ',', (string) ( $pin['exclude'] ?? '' ) ) as $raw ) {
            $phrase = self::normalize( $raw );
            if ( '' !== $phrase && self::has_phrase( $question, $phrase ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The first enabled pin this question matches, in list order, or null.
     *
     * @param string $question The visitor's current message only.
     * @return array|null The pin, with 'matched_line' added.
     */
    public static function match( $question ) {
        $normalized = self::normalize( $question );
        if ( '' === $normalized ) {
            return null;
        }

        foreach ( self::all() as $pin ) {
            if ( empty( $pin['enabled'] ) || self::excluded( $pin, $normalized ) ) {
                continue;
            }
            foreach ( preg_split( '/\R/u', (string) ( $pin['triggers'] ?? '' ) ) as $line ) {
                if ( '' === trim( $line ) ) {
                    continue;
                }
                $rule = self::parse_line( $line );
                if ( is_array( $rule ) && self::rule_matches( $rule, $normalized ) ) {
                    $pin['matched_line'] = trim( $line );
                    return $pin;
                }
            }
        }
        return null;
    }

    /**
     * Must the model's verdict stop this pin?
     *
     * "unanswered" does not block: it means the question was legitimate and the
     * knowledge base had nothing, which is exactly what a pin is for. Any other
     * refusal does, including in_scope false with no recognised flag.
     */
    public static function blocks_pin( $flag, $in_scope ) {
        if ( in_array( $flag, self::BLOCKING_FLAGS, true ) ) {
            return true;
        }
        return ! $in_scope && 'unanswered' !== $flag;
    }

    /* ================================= SAVING ============================= */

    /**
     * Check submitted fields and shape them into a pin.
     *
     * The answer is stored as typed. No tag stripping or text sanitizing, because
     * those change wording ("under <$20" loses text, "10%off" can too) and a pin
     * is verbatim. That is safe: only administrators can write it, every admin
     * screen escapes it, and the widget inserts it with textContent, never as HTML.
     *
     * @param array $input Slashes already removed.
     * @return array{pin:array,errors:string[]}
     */
    public static function from_input( array $input ) {
        $errors = array();

        $label    = trim( sanitize_text_field( (string) ( $input['label'] ?? '' ) ) );
        $triggers = trim( str_replace( "\r\n", "\n", (string) ( $input['triggers'] ?? '' ) ) );
        $answer   = trim( str_replace( "\r\n", "\n", (string) ( $input['answer'] ?? '' ) ) );
        $link_id  = sanitize_text_field( (string) ( $input['link_id'] ?? '' ) );

        if ( '' === $label ) {
            $errors[] = 'Give the pin a name, so staff can find it later.';
        }
        if ( '' === $answer ) {
            $errors[] = 'The approved answer is empty.';
        }

        $valid = 0;
        foreach ( explode( "\n", $triggers ) as $line ) {
            if ( '' === trim( $line ) ) {
                continue;
            }
            $rule = self::parse_line( $line );
            if ( is_array( $rule ) ) {
                $valid++;
            } else {
                $errors[] = sprintf( 'Trigger "%s": %s', trim( $line ), $rule );
            }
        }
        if ( 0 === $valid && array() === $errors ) {
            $errors[] = 'Add at least one trigger.';
        }

        if ( '' !== $link_id && ! in_array( $link_id, array_column( SFAF_Chat_Core::kb_entries(), 'id' ), true ) ) {
            $errors[] = 'That link is not in the knowledge base.';
            $link_id  = '';
        }

        return array(
            'pin'    => array(
                'id'       => sanitize_key( (string) ( $input['id'] ?? '' ) ),
                'label'    => $label,
                'triggers' => $triggers,
                'exclude'  => trim( sanitize_text_field( (string) ( $input['exclude'] ?? '' ) ) ),
                'answer'   => $answer,
                'link_id'  => $link_id,
                'order'    => (int) ( $input['order'] ?? 10 ),
                'enabled'  => ! empty( $input['enabled'] ),
            ),
            'errors' => $errors,
        );
    }

    /** Insert or replace by id. */
    public static function upsert( array $pin ) {
        $pins = self::all();

        if ( '' === $pin['id'] ) {
            $pin['id'] = 'pin-' . strtolower( wp_generate_password( 8, false, false ) );
            $pins[]    = $pin;
        } else {
            $replaced = false;
            foreach ( $pins as $i => $existing ) {
                if ( ( $existing['id'] ?? '' ) === $pin['id'] ) {
                    $pins[ $i ] = $pin;
                    $replaced   = true;
                }
            }
            if ( ! $replaced ) {
                $pins[] = $pin;
            }
        }
        self::save_all( $pins );
        return $pin['id'];
    }

    public static function delete( $id ) {
        self::save_all( array_filter( self::all(), function ( $pin ) use ( $id ) {
            return ( $pin['id'] ?? '' ) !== $id;
        } ) );
    }
}
