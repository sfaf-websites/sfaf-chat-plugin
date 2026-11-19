<?php
/**
 * "Contact our team": a visitor leaves a name, an email and, if they like, a phone
 * number and a few words, and the request goes by email to the right team with the
 * chat in the body. The visitor gets a short confirmation email.
 *
 * WHEN IT IS OFFERED (1.17.0; tune it in OFFER_RULE). The model judges each
 * question and returns "wants_person" (they want to reach a person: who can I
 * contact, can I talk to someone, how do I get in touch with X) and
 * "fully_answered". Contact intent offers the form whatever the answer says, even
 * when it also gives a phone number, which the prompt has the model offer beside
 * it. Without that intent, a service question (SERVICE_TOPICS) that was not fully
 * answered offers it, unless the answer already gave a phone number or email. And
 * (1.20.0) a warm lead on giving or signing up ("warm_lead": interested, with an
 * open question) offers it on CTA_TOPICS, while a direct "how do I donate?" gets
 * only the link or the Donate button. Never
 * on a crisis reply, which points to a person by phone, never out of scope, never
 * beside a booking link. Never unless the form is switched on, a Postmark token is
 * saved and a default recipient is set.
 *
 * WHERE IT GOES (1.17.0): by the subject, not the page. Staff list teams (a name,
 * what they handle, their addresses); the model picks the team that fits the
 * question ("contact_team", only a name from that list counts). A team's pages are
 * a weak fallback when the model names none. Otherwise the default recipients. The
 * sending address is never a recipient: refused on save and dropped at send.
 *
 * SENT THROUGH POSTMARK, from websites@sfaf.org, with a chat-specific server
 * token saved on the Contact screen (SFAF_Chat_Credentials 'postmark_token',
 * write-only, not autoloaded). Reply-To is the visitor's address unless staff set
 * a fixed one. Everything is in the body (HTML and plain text), no attachment, and
 * link tracking is off so the page address stays a plain address.
 *
 * NOTHING IS KEPT. No copy of a submission, no name, no email, no phone, no IP:
 * the message exists in Postmark and in the inboxes only. The one thing stored is
 * a site-wide count per ten minutes (RATE_MAX), which identifies no one.
 *
 * ABUSE. A message with a threat or targeted abuse is refused gently and not sent
 * (ABUSE_PATTERNS). It is a short, deliberate list: sexual-health words are not
 * abuse here and must never be on it. A filled-in honeypot field is a bot: it is
 * told "sent" and nothing is sent. The confirmation goes to whatever address was
 * typed, so it says nothing personal and promises no time: a wrong address gets a
 * harmless note.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class SFAF_Chat_Contact {

    const OPTION    = 'sfaf_chat_contact';
    const FROM      = 'websites@sfaf.org';
    const FROM_NAME = 'SFAF Website Chat';
    const ORG_NAME  = 'San Francisco AIDS Foundation';
    const API       = 'https://api.postmarkapp.com/email';
    const LOGO      = 'https://www.sfaf.org/wp-content/uploads/sites/64/2026/03/sfaf-logo.png';
    const MAIN_LINE = '415-487-3000';

    const MAX_NAME       = 100;
    const MAX_EMAIL      = 200;
    const MAX_PHONE      = 30;
    const MAX_DETAILS    = 1000;
    const MAX_MESSAGES   = 40;
    const MAX_MSG_CHARS  = 2000;
    const MAX_ROUTES     = 50;
    const MAX_ABOUT      = 200;
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
     *   on_contact_intent         show it when the model says they want to reach a
     *                             person, on any topic, even if the answer also gives a
     *                             phone number or email
     *   when_not_fully_answered   show it when the model says the answer fell short, or
     *                             flagged the question "unanswered" (service topics only)
     *   skip_if_contact_given     ...but not that second case when the answer already
     *                             has a phone number or email
     *   skip_if_booking_link      never beside the booking link, its own way through
     *   on_warm_lead              (1.20.0) show it when the model says the visitor is
     *                             interested in giving or signing up (CTA_TOPICS) AND
     *                             has an open question, even if the answer gives a phone
     *                             number or email. A direct, ready-to-act ask ("how do I
     *                             donate?") is not a warm lead: it gets the link or the
     *                             Donate button and no box.
     */
    const OFFER_RULE = array(
        'on_contact_intent'       => true,
        'when_not_fully_answered' => true,
        'skip_if_contact_given'   => true,
        'skip_if_booking_link'    => true,
        'on_warm_lead'            => true,
    );

    /** Giving, sign-up and volunteering topics, where a warm lead is offered a person (1.20.0). */
    const CTA_TOPICS = array( 'donate', 'events', 'volunteer' );

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
            'routes'         => array(),    // teams: [{team, about, patterns[], emails[]}]
        );
    }

    public static function config() {
        $stored = get_option( self::OPTION, array() );
        return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
    }

    /** Ready to offer: on, a token, and somewhere to send by default. */
    public static function ready() {
        $c = self::config();
        return ! empty( $c['enabled'] ) && SFAF_Chat_Credentials::has( 'postmark_token' ) && array() !== self::recipients( (array) $c['default_emails'] );
    }

    /** "Stonewall Project" and "Stonewall Project team" both read "Stonewall Project team". */
    public static function team_phrase( $team ) {
        $team = trim( (string) $team );
        if ( '' === $team ) {
            return '';
        }
        return preg_match( '/\bteam$/i', $team ) ? $team : $team . ' team';
    }

    /** "Contact our Programa Latino team". */
    public static function label( $team ) {
        $phrase = self::team_phrase( $team );
        return 'Contact our ' . ( '' !== $phrase ? $phrase : 'team' );
    }

    /**
     * The teams the model may choose from: [{team, handles}]. Since 1.22.0 a team
     * with no recipients of its own is listed too; its requests go to the default.
     */
    public static function teams_for_model() {
        $out = array();
        foreach ( (array) self::config()['routes'] as $r ) {
            $team = trim( (string) ( $r['team'] ?? '' ) );
            if ( '' !== $team ) {
                $out[] = array( 'team' => $team, 'handles' => (string) ( $r['about'] ?? '' ) );
            }
        }
        return $out;
    }

    /**
     * Who gets a request: [team name, addresses, matched]. The team the model chose
     * (a name from the list, any case), else a team whose pages match, else the
     * default. A team with no usable address of its own (1.22.0: recipients are
     * optional) keeps its name, so the button and the email still say which team,
     * and its request goes to the default recipients.
     */
    public static function resolve( $model_team, $page ) {
        $c        = self::config();
        $routes   = (array) $c['routes'];
        $fallback = self::recipients( (array) $c['default_emails'] );
        $want     = strtolower( trim( (string) $model_team ) );
        if ( '' !== $want ) {
            foreach ( $routes as $r ) {
                if ( strtolower( trim( (string) ( $r['team'] ?? '' ) ) ) === $want ) {
                    $to = self::recipients( (array) ( $r['emails'] ?? array() ) );
                    return array( (string) $r['team'], array() !== $to ? $to : $fallback, true );
                }
            }
        }
        if ( '' !== (string) $page ) {
            foreach ( $routes as $r ) {
                foreach ( (array) ( $r['patterns'] ?? array() ) as $pattern ) {
                    if ( SFAF_Chat_Starters::page_matches( (string) $pattern, (string) $page ) ) {
                        $to = self::recipients( (array) ( $r['emails'] ?? array() ) );
                        return array( (string) ( $r['team'] ?? '' ), array() !== $to ? $to : $fallback, '' !== trim( (string) ( $r['team'] ?? '' ) ) );
                    }
                }
            }
        }
        return array( (string) $c['default_team'], $fallback, false );
    }

    /** Usable recipients: valid, lower case, no repeats, and never the sending address. */
    public static function recipients( array $emails ) {
        $out = array();
        foreach ( $emails as $e ) {
            $e = strtolower( trim( (string) $e ) );
            if ( is_email( $e ) && self::FROM !== $e ) {
                $out[] = $e;
            }
        }
        return array_slice( array_values( array_unique( $out ) ), 0, self::MAX_RECIPIENTS );
    }

    /**
     * The button for a reply, or null: {label, team}. "team" is the chosen team's
     * name, or "" for the default; the form sends it back. $answer is the text the
     * visitor is shown (after any pinned answer); $wants_person, $fully_answered and
     * $model_team are the model's judgment of the question.
     *
     * @return array{label:string, team:string}|null
     */
    public static function offer( $topic, $flag, $page, $answer, $wants_person, $fully_answered, $model_team = null, $warm_lead = false ) {
        if ( ! self::ready() || 'crisis' === $flag || 'out_of_scope' === $flag ) {
            return null;
        }
        if ( ! self::wanted( (string) $answer, (bool) $wants_person, (bool) $fully_answered && 'unanswered' !== $flag, (string) $topic, (bool) $warm_lead ) ) {
            return null;
        }
        list( $team, , $matched ) = self::resolve( $model_team, $page );
        return array( 'label' => self::label( $team ), 'team' => $matched ? $team : '' );
    }

    /** OFFER_RULE applied to one reply. */
    public static function wanted( $answer, $wants_person, $fully_answered, $topic = '', $warm_lead = false ) {
        $rule = self::OFFER_RULE;
        if ( $rule['skip_if_booking_link'] && false !== strpos( (string) $answer, '](#sfaf-appointments)' ) ) {
            return false;
        }
        if ( $rule['on_contact_intent'] && $wants_person ) {
            return true;
        }
        if ( $rule['on_warm_lead'] && $warm_lead && in_array( (string) $topic, self::CTA_TOPICS, true ) ) {
            return true;
        }
        if ( ! $rule['when_not_fully_answered'] || $fully_answered || ! in_array( (string) $topic, self::SERVICE_TOPICS, true ) ) {
            return false;
        }
        return ! ( $rule['skip_if_contact_given'] && preg_match( self::DIRECT_CONTACT, (string) $answer ) );
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

    /**
     * Several addresses from a box: commas, semicolons or lines. Valid ones only;
     * anything else, the sending address included, comes back in $bad.
     */
    public static function parse_emails( $text, &$bad = null ) {
        $bad = array();
        $out = array();
        foreach ( preg_split( '/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $e ) {
            $e = strtolower( trim( $e ) );
            if ( is_email( $e ) && self::FROM !== $e ) {
                $out[] = $e;
            } else {
                $bad[] = $e;
            }
        }
        return array_slice( array_values( array_unique( $out ) ), 0, self::MAX_RECIPIENTS );
    }

    /**
     * Validate and send one request, then the visitor's confirmation.
     *
     * @param array $in name, email, phone, details, team, page, thread [{role, text}], website (honeypot)
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
        $phone = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) ( $in['phone'] ?? '' ) ) ) );
        if ( '' !== $phone && ( strlen( $phone ) > self::MAX_PHONE
            || ! preg_match( '/^\+?[0-9().\-\s]+(?:\s*(?:ext\.?|x)\s*\d{1,6})?$/i', $phone )
            || strlen( (string) preg_replace( '/\D/', '', $phone ) ) < 7 ) ) {
            return self::refuse( 'Please check your phone number, or leave it blank.', 400 );
        }
        $details = trim( mb_substr( str_replace( array( "\r\n", "\r" ), "\n", wp_strip_all_tags( (string) ( $in['details'] ?? '' ) ) ), 0, self::MAX_DETAILS ) );

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
        $visitor_text = array( $name, $details );
        foreach ( $thread as $m ) {
            if ( 'Visitor' === $m[0] ) {
                $visitor_text[] = $m[1];
            }
        }
        if ( self::abusive( $visitor_text ) ) {
            return self::refuse( "We can't send this message. If you need help, please call our Main Office at " . self::MAIN_LINE . '.', 422 );
        }

        if ( ! self::ready() ) {
            return self::refuse( 'This form is not available right now. Please call our Main Office at ' . self::MAIN_LINE . '.', 503 );
        }

        // Site-wide throttle. Counts sends only; it knows nothing about who.
        $rate = get_transient( self::RATE_KEY );
        $rate = is_array( $rate ) ? $rate : array( 'n' => 0 );
        if ( (int) $rate['n'] >= self::RATE_MAX ) {
            return self::refuse( 'We are getting a lot of requests right now. Please try again in a few minutes, or call ' . self::MAIN_LINE . '.', 429 );
        }

        list( $team, $to, $matched ) = self::resolve( $in['team'] ?? '', $page );
        $sent = self::send( array(
            'name'    => $name,
            'email'   => $email,
            'phone'   => $phone,
            'details' => $details,
            'team'    => $matched ? $team : '',
            'page'    => $page,
            'thread'  => $thread,
        ), $to );
        if ( true !== $sent ) {
            return self::refuse( "Sorry, we couldn't send that just now. Please try again, or call our Main Office at " . self::MAIN_LINE . '.', 502 );
        }
        set_transient( self::RATE_KEY, array( 'n' => (int) $rate['n'] + 1 ), 10 * MINUTE_IN_SECONDS );

        // The insights report (1.23.0): one sent form, credited to the conversation's
        // category. Nothing the visitor typed goes with it.
        SFAF_Chat_Stats::record_action( 'contact', $in['cat_team'] ?? '', $in['cat_sub'] ?? '', true === ( $in['cat_first'] ?? null ) );

        // The team has it; the visitor's note is a courtesy, so its failure is not theirs.
        self::send_confirmation( $email );

        return array( 'ok' => true, 'status' => 200, 'message' => self::thanks( $matched ? $team : self::config()['default_team'] ) );
    }

    private static function thanks( $team ) {
        $phrase = self::team_phrase( $team );
        return 'Thanks. We sent your message and this chat to our ' . ( '' !== $phrase ? $phrase : 'team' )
            . '. Someone will reach out within 1 to 2 business days.';
    }

    private static function refuse( $error, $status ) {
        return array( 'ok' => false, 'status' => (int) $status, 'error' => $error );
    }

    /** "Stonewall Project team", or "someone from the SFAF team" when no team matched. */
    private static function team_line( $team ) {
        $phrase = self::team_phrase( $team );
        return '' !== $phrase ? $phrase : 'someone from the SFAF team';
    }

    /**
     * The team's email: everything in the body, as HTML and as plain text.
     * $r: name, email, phone, details, team ("" when none matched), page, thread.
     */
    public static function send( array $r, array $to ) {
        $to = self::recipients( $to );
        if ( array() === $to ) {
            $to = self::recipients( (array) self::config()['default_emails'] );
        }
        if ( array() === $to ) {
            return 'not configured';
        }
        $c        = self::config();
        $reply_to = ( 'fixed' === $c['reply_to'] && is_email( (string) $c['reply_to_email'] ) ) ? (string) $c['reply_to_email'] : (string) $r['email'];
        $when     = wp_date( 'l, F j, Y, g:i a', null, SFAF_Chat_Core::timezone() ) . ' Pacific';
        $page     = '' !== (string) $r['page'] ? (string) $r['page'] : 'not recorded';
        $team     = self::team_line( $r['team'] );
        $replying = $reply_to === $r['email']
            ? 'Reply to this email to answer them directly.'
            : 'Replies to this email go to ' . $reply_to . '.';

        $rows = array( array( 'Name', $r['name'] ), array( 'Email', $r['email'] ) );
        if ( '' !== (string) $r['phone'] ) {
            $rows[] = array( 'Phone', $r['phone'] );
        }
        if ( '' !== (string) $r['details'] ) {
            $rows[] = array( 'Additional details', $r['details'] );
        }
        $rows[] = array( 'Team', $team );
        $rows[] = array( 'Page', $page );

        // Plain text.
        $text = array( 'Someone asked to be contacted through the chat on the SFAF website.', '' );
        foreach ( $rows as $row ) {
            $text[] = $row[0] . ': ' . $row[1];
        }
        $text[] = '';
        $text[] = 'They were told someone will reach out within 1 to 2 business days. ' . $replying;
        $text[] = '';
        $text[] = 'Chat transcript';
        $text[] = '';
        foreach ( (array) $r['thread'] as $m ) {
            $text[] = $m[0] . ': ' . $m[1];
            $text[] = '';
        }
        $text[] = 'Sent ' . $when . '.';

        // HTML.
        $cell  = 'padding:8px 0;border-bottom:1px solid #e6e6e6;vertical-align:top;font-size:15px;line-height:1.5;color:#373433;';
        $table = '';
        foreach ( $rows as $row ) {
            $table .= '<tr><td style="' . $cell . 'width:150px;font-weight:bold;">' . esc_html( $row[0] ) . '</td>'
                . '<td style="' . $cell . '">' . self::html_text( $row[1] ) . '</td></tr>';
        }
        $chat = '';
        foreach ( (array) $r['thread'] as $m ) {
            $chat .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.5;color:#373433;"><strong>' . esc_html( $m[0] ) . '</strong><br>'
                . self::html_text( $m[1] ) . '</p>';
        }
        if ( '' === $chat ) {
            $chat = '<p style="margin:0;font-size:15px;color:#373433;">No messages were sent with this request.</p>';
        }
        $inner = '<h1 style="margin:0 0 6px;font-size:22px;line-height:1.3;color:#373433;">Chat contact request</h1>'
            . '<p style="margin:0 0 18px;font-size:15px;line-height:1.5;color:#373433;">Someone asked to be contacted through the chat on the SFAF website.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;margin:0 0 18px;">' . $table . '</table>'
            . '<p style="margin:0 0 24px;padding:12px 14px;background:#fff8cc;border-left:4px solid #FFD900;font-size:15px;line-height:1.5;color:#373433;">'
            . 'They were told someone will reach out within 1 to 2 business days. ' . esc_html( $replying ) . '</p>'
            . '<h2 style="margin:0 0 12px;padding-top:6px;border-top:2px solid #373433;font-size:17px;color:#373433;">Chat transcript</h2>'
            . $chat
            . '<p style="margin:18px 0 0;font-size:13px;color:#6b6766;">Sent ' . esc_html( $when ) . '.</p>';

        $subject = 'Chat contact request' . ( '' !== (string) $r['team'] ? ' for our ' . self::team_phrase( $r['team'] ) : '' ) . ': ' . $r['name'];
        return self::postmark( array(
            'From'     => self::FROM_NAME . ' <' . self::FROM . '>',
            'To'       => implode( ', ', $to ),
            'ReplyTo'  => $reply_to,
            'Subject'  => $subject,
            'HtmlBody' => self::html_email( 'Chat contact request', $inner ),
            'TextBody' => implode( "\n", $text ) . "\n",
            'Tag'      => 'sfaf-chat-contact',
        ) );
    }

    /** The visitor's note: who we are, that it arrived, the main line. Nothing they wrote. */
    public static function send_confirmation( $email ) {
        $lines = array(
            'Thanks for reaching out. We received your message submitted through the San Francisco AIDS Foundation website, and a member of our team will follow up with you.',
            'If you need immediate assistance, please call our main office at ' . self::MAIN_LINE . '.',
            'If you did not submit this, you can safely ignore this email.',
        );
        $inner = '';
        foreach ( $lines as $line ) {
            $inner .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;color:#373433;">' . self::html_text( $line ) . '</p>';
        }
        return self::postmark( array(
            'From'     => self::ORG_NAME . ' <' . self::FROM . '>',
            'To'       => (string) $email,
            'Subject'  => 'We received your message',
            'HtmlBody' => self::html_email( 'We received your message', $inner ),
            'TextBody' => implode( "\n\n", $lines ) . "\n",
            'Tag'      => 'sfaf-chat-confirmation',
        ) );
    }

    /** The Contact screen's test: to the default recipients, says where it went. */
    public static function send_test() {
        $to = self::recipients( (array) self::config()['default_emails'] );
        if ( array() === $to ) {
            return 'no default recipient is saved';
        }
        $inner = '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;color:#373433;">This is a test from the SFAF Chat Contact screen. If it arrived, the contact form is set up and requests with no matching team come to: '
            . esc_html( implode( ', ', $to ) ) . '.</p>';
        return self::postmark( array(
            'From'     => self::FROM_NAME . ' <' . self::FROM . '>',
            'To'       => implode( ', ', $to ),
            'Subject'  => 'SFAF Chat: test of the contact form',
            'HtmlBody' => self::html_email( 'Test of the contact form', $inner ),
            'TextBody' => 'This is a test from the SFAF Chat Contact screen. If it arrived, the contact form is set up and requests with no matching team come to: ' . implode( ', ', $to ) . ".\n",
            'Tag'      => 'sfaf-chat-contact',
        ) );
    }

    /** Escaped text as HTML, line breaks kept, phone numbers never split across lines. */
    private static function html_text( $text ) {
        $html = nl2br( esc_html( (string) $text ) );
        return (string) preg_replace( '/(?:\(\d{3}\)\s*|\b\d{3}[\s.\-])\d{3}[\s.\-]\d{4}\b/', '<span style="white-space:nowrap;">$0</span>', $html );
    }

    /** Our email layout: the logo, a yellow rule, Arial, our dark text. Inline styles only. */
    private static function html_email( $title, $inner ) {
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc_html( $title ) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;color:#373433;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f4f4f4;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:100%;max-width:600px;background:#ffffff;border-top:6px solid #FFD900;">'
            . '<tr><td style="padding:24px 28px 8px;"><img src="' . esc_url( self::LOGO ) . '" alt="' . esc_attr( self::ORG_NAME ) . '" width="180" style="display:block;border:0;width:180px;max-width:100%;height:auto;"></td></tr>'
            . '<tr><td style="padding:16px 28px 28px;font-family:Arial,Helvetica,sans-serif;color:#373433;">' . $inner . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** One email through Postmark, opens and links untracked. True, or a short reason (never the token). */
    private static function postmark( array $payload ) {
        $token = SFAF_Chat_Credentials::get( 'postmark_token' );
        if ( '' === $token ) {
            return 'not configured';
        }
        $payload += array(
            'MessageStream' => 'outbound',
            'TrackOpens'    => false,
            'TrackLinks'    => 'None',
        );
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
