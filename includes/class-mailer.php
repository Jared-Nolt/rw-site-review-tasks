<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outbound notification email for the plugin.
 *
 * Deliverability notes — why this class exists rather than bare wp_mail() calls:
 *
 * - WordPress defaults the From address to wordpress@<site domain>. That mailbox
 *   usually doesn't exist, so bounces go nowhere and receiving servers treat the
 *   message as unverifiable. A real, monitored address on the site's own domain
 *   is the single biggest in-plugin improvement.
 * - The From domain must match the domain whose DNS carries the SPF/DKIM records
 *   the mail is signed with, or DMARC alignment fails. is_from_aligned() flags a
 *   mismatch on the settings screen.
 * - A bare one-line body with a naked URL scores badly. Messages here are sent as
 *   HTML with a real plain-text alternative (AltBody), which is what filters
 *   expect from legitimate transactional mail.
 * - Return-Path is aligned with From (PHPMailer's Sender) so SPF is checked
 *   against the same domain the recipient sees.
 *
 * None of this substitutes for authenticated SMTP: mail sent with PHP's mail()
 * from a shared web host IP is the most common reason these land in spam.
 */
class SRT_Mailer {

	const FROM_NAME_OPT = 'srt_mail_from_name';
	const FROM_ADDR_OPT = 'srt_mail_from_address';
	const REPLY_TO_OPT  = 'srt_mail_reply_to';

	/**
	 * Plain-text alternative for the message currently being sent. Applied by the
	 * phpmailer_init hook and cleared immediately after, so it can never leak
	 * onto another plugin's mail.
	 */
	private static $alt_body = '';

	/** Collected wp_mail_failed error message for the current send. */
	private static $last_error = '';

	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'apply_alt_body' ) );
	}

	/**
	 * From address: the configured mailbox, or WordPress's own default when blank
	 * (so behaviour is unchanged until an address is actually set).
	 */
	public static function from_address() {
		$address = trim( (string) get_option( self::FROM_ADDR_OPT, '' ) );

		return is_email( $address ) ? $address : self::default_from_address();
	}

	/**
	 * Mirrors the wordpress@<domain> fallback in wp_mail().
	 */
	public static function default_from_address() {
		return 'wordpress@' . self::site_domain();
	}

	public static function from_name() {
		$name = trim( (string) get_option( self::FROM_NAME_OPT, '' ) );

		return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	public static function reply_to() {
		$address = trim( (string) get_option( self::REPLY_TO_OPT, '' ) );

		return is_email( $address ) ? $address : '';
	}

	/**
	 * Site host without a leading www., as used for the From fallback and the
	 * DMARC-alignment check.
	 */
	public static function site_domain() {
		$host = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );

		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return $host ? $host : 'localhost';
	}

	/**
	 * Whether the From address sits on the site's own domain. A mismatch (e.g.
	 * a @gmail.com From on a rosewood.us.com site) is what DMARC rejects.
	 */
	public static function is_from_aligned() {
		$address = self::from_address();
		$at      = strrpos( $address, '@' );

		if ( false === $at ) {
			return false;
		}

		$domain = strtolower( substr( $address, $at + 1 ) );
		$site   = self::site_domain();

		// An exact match or a subdomain of the site domain both align under DMARC's
		// relaxed alignment (the default).
		return $domain === $site || substr( $domain, - ( strlen( $site ) + 1 ) ) === '.' . $site;
	}

	/**
	 * Sends one notification.
	 *
	 * @param string   $to         Recipient email address.
	 * @param string   $subject    Subject line, already translated.
	 * @param string   $heading    Short headline shown above the body.
	 * @param string[] $paragraphs Body paragraphs, plain text (escaped here).
	 * @param string   $cta_url    Optional link to feature as a button.
	 * @param string   $cta_label  Label for that link.
	 * @return bool Whether wp_mail() accepted the message.
	 */
	public static function send( $to, $subject, $heading, $paragraphs, $cta_url = '', $cta_label = '' ) {
		if ( ! is_email( $to ) ) {
			return false;
		}

		$headers = array(
			sprintf( 'From: %s <%s>', self::encode_display_name( self::from_name() ), self::from_address() ),
			'Content-Type: text/html; charset=UTF-8',
		);

		$reply_to = self::reply_to();

		if ( $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		self::$alt_body   = self::text_body( $heading, $paragraphs, $cta_url );
		self::$last_error = '';

		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_error' ) );

		$sent = wp_mail( $to, $subject, self::html_body( $heading, $paragraphs, $cta_url, $cta_label ), $headers );

		remove_action( 'wp_mail_failed', array( __CLASS__, 'capture_error' ) );

		self::$alt_body = '';

		return (bool) $sent;
	}

	/**
	 * RFC 2047-encodes the From display name when it contains non-ASCII, and
	 * strips characters that would break the header otherwise.
	 */
	private static function encode_display_name( $name ) {
		$name = str_replace( array( '"', "\r", "\n" ), '', $name );

		if ( preg_match( '/[^\x20-\x7E]/', $name ) ) {
			return '=?UTF-8?B?' . base64_encode( $name ) . '?=';
		}

		return '"' . $name . '"';
	}

	/**
	 * Sets the plain-text part and aligns the envelope sender with From. Both are
	 * only reachable through send(), which clears $alt_body afterwards.
	 */
	public static function apply_alt_body( $phpmailer ) {
		if ( '' === self::$alt_body ) {
			return;
		}

		$phpmailer->AltBody = self::$alt_body;

		// Only fill this in when nothing else has: an SMTP plugin may have set an
		// envelope sender deliberately for its own provider.
		if ( empty( $phpmailer->Sender ) ) {
			$phpmailer->Sender = self::from_address();
		}
	}

	public static function capture_error( $error ) {
		if ( is_wp_error( $error ) ) {
			self::$last_error = $error->get_error_message();
		}
	}

	public static function last_error() {
		return self::$last_error;
	}

	private static function html_body( $heading, $paragraphs, $cta_url, $cta_label ) {
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$html  = '<!DOCTYPE html><html><head><meta charset="utf-8" />';
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1" /></head>';
		$html .= '<body style="margin:0;padding:24px;background:#f2ede3;font-family:Arial,Helvetica,sans-serif;color:#291f1c;">';
		$html .= '<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:6px;padding:28px 32px;">';
		$html .= '<h1 style="margin:0 0 16px;font-size:19px;line-height:1.3;color:#a82e3d;">' . esc_html( $heading ) . '</h1>';

		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">' . esc_html( $paragraph ) . '</p>';
		}

		if ( $cta_url ) {
			$label = $cta_label ? $cta_label : __( 'Open', 'rw-site-review-tasks' );

			$html .= '<p style="margin:22px 0;">';
			$html .= '<a href="' . esc_url( $cta_url ) . '" style="display:inline-block;padding:11px 22px;background:#a82e3d;color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none;border-radius:5px;">';
			$html .= esc_html( $label ) . '</a></p>';
			$html .= '<p style="margin:0 0 14px;font-size:12px;line-height:1.5;color:#6f6660;word-break:break-all;">';
			$html .= esc_html__( 'If the button does not work, copy this address into your browser:', 'rw-site-review-tasks' );
			$html .= '<br />' . esc_html( $cta_url ) . '</p>';
		}

		$html .= '<p style="margin:24px 0 0;padding-top:16px;border-top:1px solid #e8ddcd;font-size:12px;line-height:1.5;color:#6f6660;">';
		$html .= esc_html(
			sprintf(
				/* translators: %s: site name */
				__( 'Sent automatically by the website review scheduler at %s.', 'rw-site-review-tasks' ),
				$site_name
			)
		);
		$html .= '</p></div></body></html>';

		return $html;
	}

	private static function text_body( $heading, $paragraphs, $cta_url ) {
		$lines = array( $heading, '' );

		foreach ( $paragraphs as $paragraph ) {
			$lines[] = $paragraph;
			$lines[] = '';
		}

		if ( $cta_url ) {
			$lines[] = $cta_url;
			$lines[] = '';
		}

		$lines[] = sprintf(
			/* translators: %s: site name */
			__( 'Sent automatically by the website review scheduler at %s.', 'rw-site-review-tasks' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		return implode( "\n", $lines );
	}
}

SRT_Mailer::init();
