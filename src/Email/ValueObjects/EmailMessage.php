<?php
/**
 * Immutable snapshot of a single outbound email’s content parts.
 *
 * @package WPDualCheck
 */

namespace WPDualCheck\Email\ValueObjects;

/**
 * Subject plus plain and/or HTML body for Mailer dispatch.
 */
final class EmailMessage {

	/**
	 * @param string      $subject   Final subject line (already filtered/stripped).
	 * @param string      $text      Plain-text body (may be empty when HTML-only).
	 * @param string|null $html      HTML body or null when text-only.
	 * @param bool        $multipart True when both text and HTML should be sent as alternatives.
	 */
	public function __construct(
		public readonly string $subject,
		public readonly string $text,
		public readonly ?string $html,
		public readonly bool $multipart,
	) {
	}
}
