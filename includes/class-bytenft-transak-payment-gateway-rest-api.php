<?php
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

class BYTENFT_TRANSAK_PAYMENT_GATEWAY_REST_API
{
	private $logger;
	private static $instance = null;

	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct()
	{
		// Initialize the logger
		$this->logger = wc_get_logger();
	}

	public function bnfttransak_register_routes()
	{
		// Log incoming request with sanitized parameters
		add_action('rest_api_init', function () {
			register_rest_route('bnfttransak/v1', '/data', array(
				'methods' => 'POST',
				'callback' => array($this, 'bnfttransak_handle_api_request'),
				'permission_callback' => '__return_true',
			));
		});
	}

	private function bnfttransak_verify_api_key($api_key)
	{
		// Sanitize the API key parameter early
		$api_key = sanitize_text_field($api_key);

		// Get ByteNFT Transak settingss
		$bnfttransak_settings = get_option('woocommerce_bnfttransak_payment_gateway_accounts');
		$bytenft_settings = get_option('woocommerce_bnfttransak_settings');

		if (!$bnfttransak_settings || empty($bnfttransak_settings)) {
			return false; // No accounts available
		}

		$accounts = $bnfttransak_settings;

		$sandbox = isset($bytenft_settings['sandbox']) && $bytenft_settings['sandbox'] === 'yes';

		$this->logger->info('Accounts to evaluate inside bnfttransak_verify_api_key fnc :', [
		    'source' => 'bytenft-transak-payment-gateway',
		    'context' => $accounts,
			'sandbox' => $sandbox,
			'$bytenft_settings'=>$bytenft_settings
		]);

		foreach ($accounts as $account) {
			$public_key = $sandbox ? sanitize_text_field($account['sandbox_public_key']) : sanitize_text_field($account['live_public_key']);

			$this->logger->info(' check public_key ::  '.$public_key, array('source' => 'bytenft-transak-payment-gateway'));

			// Use a secure hash comparison
			if (!empty($public_key) && hash_equals($public_key, $api_key)) {
				$this->logger->info('keys are matched ', array('source' => 'bytenft-transak-payment-gateway'));
				
				return true;
			}
		}

		return false;
	}
	/**
	 * Handles incoming ByteNFT Transak API requests to update order status.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 * @return WP_REST_Response The response object.
	 */
	public function bnfttransak_handle_api_request(WP_REST_Request $request)
	{
		$parameters = $request->get_json_params();

		// Sanitize incoming data to prevent security vulnerabilities.
		$api_key = isset($parameters['nonce']) ? sanitize_text_field($parameters['nonce']) : '';
		$order_id = isset($parameters['order_id']) ? intval($parameters['order_id']) : 0;
		$api_order_status = isset($parameters['order_status']) ? sanitize_text_field($parameters['order_status']) : '';
		$pay_id = isset($parameters['pay_id']) ? sanitize_text_field($parameters['pay_id']) : '';

		$this->logger->info('ByteNFT Transak API Request Received.', [
		    'source'  => 'bytenft-transak-payment-gateway',
		    'context' => [
		        'response_payload' => $parameters
		    ],
		]);

		// Validate API key first to secure the endpoint.
		if (!$this->bnfttransak_verify_api_key(base64_decode($api_key))) {
			$this->logger->error('Unauthorized access attempt due to invalid API key.', array('source' => 'bytenft-transak-payment-gateway'));
			return new WP_REST_Response(['error' => 'Unauthorized'], 401);
		}

		// Validate order ID.
		if ($order_id <= 0) {
			$this->logger->error('Invalid order ID received: ' . $order_id, array('source' => 'bytenft-transak-payment-gateway'));
			return new WP_REST_Response(['error' => 'Invalid data (Order ID missing or invalid)'], 400);
		}

		// Retrieve the order object.
		$order = wc_get_order($order_id);
		if (!$order) {
			$this->logger->error('Order not found for ID: ' . $order_id, array('source' => 'bytenft-transak-payment-gateway'));
			return new WP_REST_Response(['error' => 'Order not found'], 404);
		}

		// Retrieve the stored payment token (pay_id) from the order meta.
		$stored_payment_token = $order->get_meta('_bnfttransak_pay_id');

		// Crucial check: Ensure the received pay_id matches the one stored with the order.
		// This prevents unauthorized updates to orders by supplying a valid order_id but a different pay_id.
		if (!empty($stored_payment_token) && $stored_payment_token !== $pay_id) {
			$this->logger->error('Pay ID mismatch for order ' . $order_id . '. Received: ' . $pay_id . ', Stored: ' . $stored_payment_token, array('source' => 'bytenft-transak-payment-gateway'));
			return new WP_REST_Response(['error' => 'Pay ID mismatch'], 400);
		}

		// --- Idempotency and Order Status Update Logic ---
		$current_order_status = $order->get_status();
		$target_order_status = $current_order_status; // Initialize with current status

		if ($api_order_status === 'completed') {
			// Check if the current order status allows for a transition to 'completed' or 'processing'.
			if (in_array($current_order_status, ['pending', 'failed', 'cancelled'])) {
				// Get the configured order status from the payment gateway settings for successful payments.
				$gateway_id = 'bnfttransak';
				$payment_gateways = WC()->payment_gateways->payment_gateways();

				if (isset($payment_gateways[$gateway_id])) {
					$gateway = $payment_gateways[$gateway_id];
					// Default to 'processing' if not explicitly set in gateway options.
					$target_order_status = sanitize_text_field($gateway->get_option('order_status', 'processing'));
				} else {
					$this->logger->error('ByteNFT Transak payment gateway settings not found.', array('source' => 'bytenft-transak-payment-gateway'));
					return new WP_REST_Response(['error' => 'Payment gateway configuration error'], 500);
				}

				// Validate that the configured target status is a recognized WooCommerce status.
				$allowed_statuses = wc_get_order_statuses();
				if (!array_key_exists('wc-' . $target_order_status, $allowed_statuses)) {
					$this->logger->error('Invalid order status configured in ByteNFT Transak gateway settings: ' . $target_order_status, array('source' => 'bytenft-transak-payment-gateway'));
					return new WP_REST_Response(['error' => 'Invalid configured order status'], 400);
				}
			} else {
				// If the order is already in a completed or processing state (not pending/failed),
				// it means this is a duplicate 'completed' webhook. Log and respond successfully.
				$this->logger->info('Order ' . esc_html($order_id) . ' is already in "' . esc_html($current_order_status) . '". No status change performed for duplicate "completed" webhook.', array('source' => 'bytenft-transak-payment-gateway'));

				// Still empty the cart if it's a successful payment, even if status hasn't changed.
				if (WC()->cart) {
					WC()->cart->empty_cart();
				}
				$payment_return_url = $order->get_checkout_order_received_url();
				return new WP_REST_Response(['success' => true, 'message' => 'Order status already updated or no change required', 'payment_return_url' => $payment_return_url], 200);
			}
		}elseif ($api_order_status === 'failed') {
		    // Only allow cancelling if current order is not already cancelled or completed
		    if (!in_array($current_order_status, ['completed'])) {
		        $target_order_status = 'failed';
		    } else {
		        $this->logger->info("Order {$order_id} is already in '{$current_order_status}' status. No change for duplicate 'failed' status.", ['source' => 'bytenft-transak-payment-gateway']);
		        return new WP_REST_Response(['success' => true, 'message' => 'Order already failed or completed', 'payment_return_url' => $order->get_checkout_order_received_url()], 200);
		    }
		}elseif ($api_order_status === 'cancelled') {
		    // Only allow cancelling if current order is not already cancelled or completed
		    if (!in_array($current_order_status, ['cancelled', 'completed'])) {
		        $target_order_status = 'cancelled';
		    } else {
		        $this->logger->info("Order {$order_id} is already in '{$current_order_status}' status. No change for duplicate 'cancelled' status.", ['source' => 'bytenft-transak-payment-gateway']);
		        return new WP_REST_Response(['success' => true, 'message' => 'Order already cancelled or completed', 'payment_return_url' => $order->get_checkout_order_received_url()], 200);
		    }
		} else {
			// If the API status is not 'completed', or if your system needs to handle other statuses
			// (e.g., 'failed', 'refunded'), you would add logic here.
			// For now, if it's not a 'completed' status, we might not want to change the status,
			// or we might set $target_order_status based on $api_order_status if those are mapped.
			// This example assumes 'completed' is the primary status to act upon.
			$this->logger->info('ByteNFT Transak API requested status "' . esc_html($api_order_status) . '" for order ' . esc_html($order_id) . '. Current status is "' . esc_html($current_order_status) . '". No specific action for this API status defined.', array('source' => 'bytenft-transak-payment-gateway'));

			// If no action is needed for this specific API status, we still return success to acknowledge receipt.
			$payment_return_url = $order->get_checkout_order_received_url();
			return new WP_REST_Response(['success' => true, 'message' => 'Request received, no status change performed based on API status', 'payment_return_url' => $payment_return_url], 200);
		}

		// Only attempt to update the order status if the target status is different from the current status.
		// WooCommerce's internal `update_status` also has idempotency, but this explicit check is clearer.
		if ('wc-' . $target_order_status !== $current_order_status) {
			$updated = $order->update_status(
				$target_order_status,
				sprintf(
					// translators: %1$s: current order status, %2$s: target order status
					__('Order status updated via ByteNFT Transak API from %1$s to %2$s', 'bytenft-transak-payment-gateway'),
					$current_order_status, // This will map to %1$s
					$target_order_status   // This will map to %2$s
				)
			);

			if ($updated) {
				$this->logger->info('Order status updated successfully for order ' . esc_html($order_id) . ' from "' . esc_html($current_order_status) . '" to "' . esc_html($target_order_status) . '".', array('source' => 'bytenft-transak-payment-gateway'));
			} else {
				$this->logger->error('Failed to update order status for order ' . esc_html($order_id) . ' from "' . esc_html($current_order_status) . '" to "' . esc_html($target_order_status) . '".', array('source' => 'bytenft-transak-payment-gateway'));
				return new WP_REST_Response(['error' => 'Failed to update order status'], 500);
			}
		} else {
			$this->logger->info('Order ' . esc_html($order_id) . ' is already in the target status "' . esc_html($target_order_status) . '". No update performed.', array('source' => 'bytenft-transak-payment-gateway'));
		}

		// Always empty the cart after a successful payment webhook has been processed.
		if (WC()->cart) {
			WC()->cart->empty_cart();
		}

		// Return a successful response to ByteNFT Transak API.
		$payment_return_url = $order->get_checkout_order_received_url();
		return new WP_REST_Response(['success' => true, 'message' => 'Order status processed successfully', 'payment_return_url' => $payment_return_url], 200);
	}
}
