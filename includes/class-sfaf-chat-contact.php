<?php
/**
 * "Contact our team": a visitor leaves a name and an email, and the chat
 * transcript goes by email to the team for the page they were on.
 *
 * WHEN IT IS OFFERED (1.15.0, interim rule; tune it in OFFER_RULE). The model
 * judges each question and returns two answers with its reply: "wants_person"
 * (the message suggests they want to reach someone) and "fully_answered". The
 * button shows only when the reply gave no direct contact (a phone number or an
 * email address, found in the answer the visitor sees) AND the person may want
 * someone or the question was not fully answered. On top of that, as before: a
 * question about a service, a program or getting help (SERVICE_TOPICS), never on
 * a crisis reply, which points to a person by phone, and never off-topic. Never
 * unless the form is switched on, a Postmark token is saved and a default
 * recipient is set.
 *
 * WHERE IT GOES. The page the question was asked on is matched against the
 * routes (same patterns as Suggested Questions: a path, /x/* or a full URL), first
 * match wins; otherwise the default. Each route names a team ("Programa Latino
 * team") and one or more addresses.
 *
 * SENT THROUGH POSTMARK, from websites@sfaf.org, with a chat-specific server
 * token saved on the Contact screen (SFAF_Chat_Credentials 'postmark_token',
 * write-only, not autoloaded). Reply-To is the visitor's address unless staff set
 * a fixed one. The transcript is attached as a text file.
 *
 * NOTHING IS KEPT. No copy of a submission, no name, no email, no IP: the message
 * exists in Postmark and in the recipients' inboxes only. The one thing stored is
 * a site-wide count per ten minutes (RATE_MAX), which identifies no one.
 *
 * ABUSE. A message with a threat or targeted abuse is refused gently and not sent
 * (ABUSE_PATTERNS). It is a short, deliberate list: sexual-health words are not
 * abuse here and must never be on it. A filled-in honeypot field is a bot: it is
 * told "sent" and nothing is sent.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Contact {

    const OPTION    = 'sfaf_chat_contact';
    const FROM      = 'websites@sfaf.org';
    const FROM_NAME = 'SFAF Website Chat';
    const API       = 'https://api.postmarkapp.com/email';

    const MAX_NAME       = 100;
    const MAX_EMAIL      = 200;
    const MAX_MESSAGES   = 40;
    const MAX_MSG_CHARS  = 2000;
    const MAX_ROUTES     = 30;
    const MAX_RECIPIENTS = 10;
    const RATE_MAX       = 20;   // sends per ten minutes, site-wide
    const RATE_KEY       = 'sfaf_chat_contact_rate';

    /** Topics where talking to a person is a sensible next step. */
    const SERVICE_TOPICS = array(
        'testing', 'prep-pep', 'hiv-care', 'sexual-health', 'appointments', 'pharmacy', 'hepatitis-c',
        'trans-health', 'substance-use', 'harm-reduction', 'overdose', 'mental-health', 'support-groups',
        'aging', 'black-health', 'latine-health', 'housing-benefits', 'insurance-billing', 'contact',
    );

    /*
     * When the button shows, beyond the gates in offer(). The threshold lives here:
     *   skip_if_contact_given     no button when the answer already has a phone or email
     *   skip_if_booking_link      no button when the answer has the booking link, which
     *                             is already a way through to a person
     *   when_wants_person         show it when the model says they want to reach someone
     *   when_not_fully_answered   show it when the model says the answer fell short, or
     *                             flagged the question "unanswered"
     * Both "when_" off means it never shows; both on is today's rule.
     */
    const OFFER_RULE = array(
        'skip_if_contact_given'   => true,
        'skip_if_booking_link'    => true,
        'when_wants_person'       => true,
        'when_not_fully_answered' => true,
    );

    /** A phone number (415-487-3000, (415) 487-3000, 415.487.3000) or an email address. */
    const DIRECT_CONTACT = '/(?:\(\d{3}\)\s*|\b\d{3}[\s.\-])\d{3}[\s.\-]\d{4}\b|[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i';

    /** Threats and targeted abuse. Kept short and plain on purpose. */
    const ABUSE_PATTERNS = array(
        '/\b(i\s*(\'?ll|will|am going to|\'?m going to|gonna))\s+(kill|hurt|shoot|stab|beat|find)\s+(you|u|y\'?all|them|your|everyone)\b/i',
        '/\b(kill|shoot|stab|bomb)\s+(you|u|y\'?all|everyone|the\s+(staff|clinic|office|building))\b/i',
        '/\b(bomb|shoot\s*up)\s+(the\s+)?(clinic|office|building|strut|sfaf)\b/i',
        '/\byou\s+(should|deserve\s+to|will|are\s+going\s+to)\s+die\b/i',
        '/\bf+u+c+k+\s*(you|u|off)\b/i',
        '/\b(go\s+)?kill\s+yourself\b/i',
    );

    public static function defaults() {
        return array(
            'enabled'        => false,
            'reply_to'       => 'visitor',  // or 'fixed'
            'reply_to_email' => '',
            'default_team'   => 'team',
            'default_emails' => array(),
            'routes'         => array(),    // [{team, patterns[], emails[]}]
        );
    }

    public static function config() {
        $stored = get_option( self::OPTION, array() );
        return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
    }

    /** Ready to offer: on, a token, and somewhere to send by default. */
    public static function ready() {
        $c = self::config();
        return ! empty( $c['enabled'] ) && SFAF_Chat_Credentials::has( 'postmark_token' ) && array() !== (array) $c['default_emails'];
    }

    /** "Contact our Programa Latino team". */
    public static function label( $team ) {
        $team = trim( (string) $team );
        return 'Contact our ' . ( '' !== $team ? $team : 'team' );
    }

    /**
     * Where a page's requests go: [team, emails]. First matching route, else the
     * default.
     */
    public static function route_for( $page ) {
        $c = self::config();
        foreach ( (array) $c['routes'] as $r ) {
            foreach ( (array) ( $r['patterns'] ?? array() ) as $pattern ) {
                if ( '' !== (string) $page && SFAF_Chat_Starters::page_matches( (string) $pattern, (string) $page ) ) {
                    return array( (string) ( $r['team'] ?? '' ) ?: (string) $c['default_team'], (array) ( $r['emails'] ?? array() ) );
                }
            }
        }
        return array( (string) $c['default_team'], (array) $c['default_emails'] );
    }

    /**
     * The button for a reply, or null. Same team text the form will name.
     * $answer is the text the visitor is shown (after any pinned answer);
     * $wants_person and $fully_answered are the model's judgment of the question.
     *
     * @return array{label:string}|null
     */
    public static function offer( $topic, $flag, $page, $answer, $wants_person, $fully_answered ) {
        if ( ! self::ready() || 'crisis' === $flag || 'out_of_scope' === $flag ) {
            return null;
        }
        if ( ! in_array( (string) $topic, self::SERVICE_TOPICS, true ) ) {
            return null;
        }
        if ( ! self::wanted( (string) $answer, (bool) $wants_person, (bool) $fully_answered && 'unanswered' !== $flag ) ) {
            return null;
        }
        list( $team ) = self::route_for( $page );
        return array( 'label' => self::label( $team ) );
    }

    /** OFFER_RULE applied to one reply. */
    public static function wanted( $answer, $wants_person, $fully_answered ) {
        $rule = self::OFFER_RULE;
        if ( $rule['skip_if_contact_given'] && preg_match( self::DIRECT_CONTACT, (string) $answer ) ) {
            return false;
        }
        if ( $rule['skip_if_booking_link'] && false !== strpos( (string) $answer, '](#sfaf-appointments)' ) ) {
            return false;
        }
        return ( $rule['when_wants_person'] && $wants_person )
            || ( $rule['when_not_fully_answered'] && ! $fully_answered );
    }

    /** Is any of this text a threat or targeted abuse? */
    public static function abusive( array $texts ) {
        foreach ( $texts as $t ) {
            foreach ( self::ABUSE_PATTERNS as $re ) {
                if ( preg_match( $re, (string) $t ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Several addresses from a box: commas, semicolons or lines. Valid ones only. */
    public static function parse_emails( $text, &$bad = null ) {
        $bad = array();
        $out = array();
        foreach ( preg_split( '/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $e ) {
            $e = strtolower( trim( $e ) );
            if ( is_email( $e ) ) {
                $out[] = $e;
            } else {
                $bad[] = $e;
            }
        }
        return array_slice( array_values( array_unique( $out ) ), 0, self::MAX_RECIPIENTS );
    }

    /**
     * Validate and send one request.
     *
     * @param array $in name, email, page, thread [{role, text}], website (honeypot)
     * @return array{ok:bool, status:int, message?:string, error?:string}
     */
    public static function submit( array $in ) {
        // A bot filled in the field people never see: say "sent", send nothing.
        if ( '' !== trim( (string) ( $in['website'] ?? '' ) ) ) {
            return array( 'ok' => true, 'status' => 200, 'message' => self::thanks( '' ) );
        }

        $name  = trim( mb_substr( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) ( $in['name'] ?? '' ) ) ), 0, self::MAX_NAME ) );
        $email = strtolower( trim( (string) ( $in['email'] ?? '' ) ) );
        if ( '' === $name ) {
            return self::refuse( 'Please add your name.', 400 );
        }
        if ( strlen( $email ) > self::MAX_EMAIL || ! is_email( $email ) ) {
            return self::refuse( 'Please check your email address.', 400 );
        }

        $page   = SFAF_Chat_Core::clean_page_url( $in['page'] ?? '' );
        $thread = array();
        foreach ( array_slice( (array) ( $in['thread'] ?? array() ), -self::MAX_MESSAGES ) as $m ) {
            if ( ! is_array( $m ) ) {
                continue;
            }
            $role = 'assistant' === ( $m['role'] ?? '' ) ? 'Assistant' : 'Visitor';
            $text = trim( mb_substr( wp_strip_all_tags( (string) ( $m['text'] ?? '' ) ), 0, self::MAX_MSG_CHARS ) );
            if ( '' !== $text ) {
                $thread[] = array( $role, $text );
            }
        }

        // What the visitor wrote, and their name, are screened. The assistant's replies are not.
        $visitor_text = array( $name );
        foreach ( $thread as $m ) {
            if ( 'Visitor' === $m[0] ) {
                $visitor_text[] = $m[1];
            }
        }
        if ( self::abusive( $visitor_text ) ) {
            return self::refuse( "We can't send this message. If you need help, please call our Main Office at 415-487-3000.", 422 );
        }

        if ( ! self::ready() ) {
            return self::refuse( 'This form is not available right now. Please call our Main Office at 415-487-3000.', 503 );
        }

        // Site-wide throttle. Counts sends only; it knows nothing about who.
        $rate = get_transient( self::RATE_KEY );
        $rate = is_array( $rate ) ? $rate : array( 'n' => 0 );
        if ( (int) $rate['n'] >= self::RATE_MAX ) {
            return self::refuse( 'We are getting a lot of requests right now. Please try again in a few minutes, or call 415-487-3000.', 429 );
        }

        list( $team, $to ) = self::route_for( $page );
        if ( array() === $to ) {
            $to = (array) self::config()['default_emails'];
        }
        $sent = self::send( $name, $email, $team, $to, $page, $thread );
        if ( true !== $sent ) {
            return self::refuse( "Sorry, we couldn't send that just now. Please try again, or call our Main Office at 415-487-3000.", 502 );
        }

        set_transient( self::RATE_KEY, array( 'n' => (int) $rate['n'] + 1 ), 10 * MINUTE_IN_SECONDS );
        return array( 'ok' => true, 'status' => 200, 'message' => self::thanks( $team ) );
    }

    private static function thanks( $team ) {
        $team = trim( (string) $team );
        return 'Thanks. We sent your message and this chat to our ' . ( '' !== $team ? $team : 'team' )
            . '. Someone will reach out within 1 to 2 business days.';
    }

    private static function refuse( $error, $status ) {
        return array( 'ok' => false, 'status' => (int) $status, 'error' => $error );
    }

    /** The transcript file. */
    public static function transcript( $page, array $thread ) {
        $when  = wp_date( 'l, F j, Y, g:i a', null, SFAF_Chat_Core::timezone() ) . ' Pacific';
        $lines = array( 'SFAF website chat', 'Sent: ' . $when, 'Page: ' . ( '' !== $page ? $page : 'not given' ), '' );
        foreach ( $thread as $m ) {
            $lines[] = $m[0] . ': ' . $m[1];
            $lines[] = '';
        }
        return implode( "\n", $lines );
    }

    /** One email through Postmark. True, or a short reason (never the token). */
    public static function send( $name, $email, $team, array $to, $page, array $thread, $is_test = false ) {
        $token = SFAF_Chat_Credentials::get( 'postmark_token' );
        if ( '' === $token || array() === $to ) {
            return 'not configured';
        }
        $c        = self::config();
        $reply_to = ( 'fixed' === $c['reply_to'] && is_email( (string) $c['reply_to_email'] ) ) ? (string) $c['reply_to_email'] : $email;
        $where    = '' !== $page ? $page : 'a page that was not recorded';
        $subject  = $is_test ? 'SFAF Chat: test of the contact form' : 'Chat contact request for our ' . $team . ': ' . $name;

        $body = $is_test
            ? "This is a test from the SFAF Chat Contact screen. If it arrived, the contact form is set up.\n"
            : implode( "\n", array(
                'Someone asked to be contacted through the chat on the SFAF website.',
                '',
                'Name: ' . $name,
                'Email: ' . $email,
                'For: our ' . $team,
                'Page: ' . $where,
                '',
                'The chat transcript is attached. They were told someone will reach out within 1 to 2 business days.',
                $reply_to === $email ? 'Reply to this email to answer them directly.' : 'Their address is above; replies to this email go to ' . $reply_to . '.',
            ) ) . "\n";

        $payload = array(
            'From'          => self::FROM_NAME . ' <' . self::FROM . '>',
            'To'            => implode( ', ', $to ),
            'ReplyTo'       => $reply_to,
            'Subject'       => $subject,
            'TextBody'      => $body,
            'MessageStream' => 'outbound',
            'Tag'           => 'sfaf-chat-contact',
            'TrackOpens'    => false,
        );
        if ( ! $is_test ) {
            $payload['Attachments'] = array( array(
                'Name'        => 'chat-transcript.txt',
                'Content'     => base64_encode( self::transcript( $page, $thread ) ),
                'ContentType' => 'text/plain; charset=utf-8',
            ) );
        }

        $reply = wp_remote_post( self::API, array(
            'timeout' => 15,
            'headers' => array(
                'Accept'                  => 'application/json',
                'Content-Type'            => 'application/json',
                'X-Postmark-Server-Token' => $token,
            ),
            'body'    => wp_json_encode( $payload ),
        ) );
        if ( is_wp_error( $reply ) ) {
            return 'could not reach Postmark';
        }
        $code = (int) wp_remote_retrieve_response_code( $reply );
        $data = json_decode( (string) wp_remote_retrieve_body( $reply ), true );
        if ( 200 === $code && is_array( $data ) && 0 === (int) ( $data['ErrorCode'] ?? -1 ) ) {
            return true;
        }
        $why = is_array( $data ) && isset( $data['Message'] ) ? (string) $data['Message'] : 'HTTP ' . $code;
        return 'Postmark said: ' . mb_substr( wp_strip_all_tags( $why ), 0, 200 );
    }

    /** Store a validated config from the Contact screen. */
    public static function save( array $config ) {
        update_option( self::OPTION, array_merge( self::defaults(), $config ), false );
    }
}
