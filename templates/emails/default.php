<?php
/**
 * HTML email shell for login code (variables: $accent, $bg, $max, $hw, $fw, $header_text, $footer_text, $header_img, $footer_img, $inner_html).
 *
 * @package WPDualCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /></head>';
echo '<body style="margin:0;padding:0;background:' . esc_attr( $bg ) . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">';
echo '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:' . esc_attr( $bg ) . ';padding:24px 12px;"><tr><td align="center">';
echo '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="' . esc_attr( (string) $max ) . '" style="max-width:100%;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);">';

if ( '' !== $header_img ) {
	echo '<tr><td align="center" style="padding:0;line-height:0;">';
	echo '<img src="' . esc_url( $header_img ) . '" alt="" width="' . esc_attr( (string) min( $hw, $max ) ) . '" style="display:block;max-width:100%;height:auto;width:' . esc_attr( (string) min( $hw, $max ) ) . 'px;" />';
	echo '</td></tr>';
}

if ( '' !== $header_text ) {
	echo '<tr><td style="padding:16px 24px;background:' . esc_attr( $accent ) . ';color:#ffffff;font-size:18px;font-weight:600;">';
	echo $header_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post in manager
	echo '</td></tr>';
}

echo '<tr><td style="padding:28px 24px;">';
echo $inner_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '</td></tr>';

if ( '' !== $footer_img ) {
	echo '<tr><td align="center" style="padding:0 0 16px;line-height:0;">';
	echo '<img src="' . esc_url( $footer_img ) . '" alt="" width="' . esc_attr( (string) min( $fw, $max ) ) . '" style="display:block;max-width:100%;height:auto;width:' . esc_attr( (string) min( $fw, $max ) ) . 'px;" />';
	echo '</td></tr>';
}

if ( '' !== $footer_text ) {
	echo '<tr><td style="padding:16px 24px;border-top:1px solid #e0e0e0;font-size:12px;line-height:1.5;color:#646970;text-align:center;">';
	echo $footer_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</td></tr>';
}

echo '</table></td></tr></table></body></html>';
