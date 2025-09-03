<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

class BYTENFT_TRANSAK_PAYMENT_GATEWAY_Admin_Notices
{
	private $notices = [];

	public function bnfttransak_add_notice($key, $type, $message)
	{
		// Sanitize the input before storing it
		$sanitized_key = sanitize_key($key);
		$sanitized_type = sanitize_text_field($type);
		$sanitized_message = sanitize_text_field($message);

		$this->notices[] = array('key' => $sanitized_key, 'type' => $sanitized_type, 'message' => $sanitized_message);
	}

	public function bnfttransak_remove_notice($key)
	{
		// Sanitize the key before using it
		$sanitized_key = sanitize_key($key);
		// Find and remove the notice by key
		foreach ($this->notices as $index => $notice) {
			if ($notice['key'] === $sanitized_key) {
				unset($this->notices[$index]);
				break;
			}
		}

		// Re-index the array to prevent gaps
		$this->notices = array_values($this->notices);
	}

	public function display_notices()
	{
		// Escape data just before outputting it
		foreach ($this->notices as $notice) {
			echo '<div class="' . esc_attr($notice['type']) . '"><p>' . esc_html($notice['message']) . '</p></div>';
		}
	}
}
