<?php
if (!defined('ABSPATH')) {
	exit(); // Exit if accessed directly.
}
// Include the configuration file
require_once plugin_dir_path(__FILE__) . 'config.php';

/**
 * Main WooCommerce ByteNFT transak Payment Gateway class.
 */
class BYTENFT_TRANSAK_PAYMENT_GATEWAY extends WC_Payment_Gateway_CC
{
	const ID = 'bnfttransak';

	protected $sandbox;
	private $base_url;
	private $public_key;
	private $secret_key;
	private $sandbox_secret_key;
	private $sandbox_public_key;

	private $admin_notices;
	private $accounts = [];
	private $current_account_index = 0;
	private $used_accounts = [];

	private static $log_once_flags = [];
	
	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
		// Check if WooCommerce is active
		if (!class_exists('WC_Payment_Gateway_CC')) {
			add_action('admin_notices', [$this, 'woocommerce_not_active_notice']);
			return;
		}

		// Instantiate the notices class
		$this->admin_notices = new BYTENFT_TRANSAK_PAYMENT_GATEWAY_Admin_Notices();

		$this->base_url = BYTENFT_TRANSAK_BASE_URL;
		
		// Define user set variables
		$this->id = self::ID;
		$this->icon = ''; // Define an icon URL if needed.
		$this->method_title = __('ByteNFT transak Payment Gateway', 'bytenft-transak-payment-gateway');
		$this->method_description = __('This plugin allows you to accept payments in USD through a secure payment gateway integration. Customers can complete their payment process with ease and security.', 'bytenft-transak-payment-gateway');

		// Load the settings
		$this->bnfttransak_init_form_fields();
		$this->init_settings();
		$this->load_gateway_settings();

		 // Register hooks
    	$this->register_hooks();
	}
	
	/**
	 * Load gateway settings.
	 */
	private function load_gateway_settings() {
	    $this->title = sanitize_text_field($this->get_option('title'));
	    $this->description = !empty($this->get_option('description'))
	        ? sanitize_textarea_field($this->get_option('description'))
	        : ($this->get_option('show_consent_checkbox') === 'yes' ? 1 : 0);
	    
	    $this->enabled = sanitize_text_field($this->get_option('enabled'));
	    $this->sandbox = 'yes' === sanitize_text_field($this->get_option('sandbox'));
	    $this->public_key = sanitize_text_field($this->get_option($this->sandbox ? 'sandbox_public_key' : 'public_key'));
	    $this->secret_key = sanitize_text_field($this->get_option($this->sandbox ? 'sandbox_secret_key' : 'secret_key'));
	    $this->current_account_index = 0;
	}

	/**
	 * Register hooks for the gateway.
	 */
	private function register_hooks() {
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'bnfttransak_process_admin_options']);
		add_action('wp_enqueue_scripts', [$this, 'bnfttransak_enqueue_styles_and_scripts']);
		add_action('admin_enqueue_scripts', [$this, 'bnfttransak_admin_scripts']);

		add_action('woocommerce_admin_order_data_after_order_details', [$this, 'bnfttransak_display_test_order_tag']);
		add_filter('woocommerce_admin_order_preview_line_items', [$this, 'bnfttransak_add_custom_label_to_order_row'], 10, 2);
		add_filter('woocommerce_available_payment_gateways', [$this, 'bnft_transak_hide_custom_payment_gateway_conditionally']);
	}

	/**
	 * Get the API URL for the given endpoint.
	 *
	 * @param string $endpoint The API endpoint.
	 * @return string
	 */
	private function get_api_url($endpoint) {
		return $this->base_url . $endpoint;
	}
	
	protected function log_info($message, $context = [])
	{
	    $logger = wc_get_logger();
	    $data = ['source' => 'bytenft-transak-payment-gateway'];

	    if (!empty($context)) {
	        $data['context'] = $context;
	    }

	    $logger->info($message, $data);
	}
		
	public function bnfttransak_process_admin_options() {
	    parent::process_admin_options();

	    if (!isset($_POST['bnfttransak_accounts_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bnfttransak_accounts_nonce'])), 'bnfttransak_accounts_nonce_action')) {
	        $this->log_info('CSRF check failed during admin options update.');
	        wp_die(esc_html__('Security check failed!', 'bytenft-transak-payment-gateway'));
	    }

	    $errors = [];
	    $valid_accounts = [];
	    $unique_live_keys = [];
	    $unique_sandbox_keys = [];
	    $normalized_index = 0;

	    $raw_accounts = [];

		if ( isset( $_POST['accounts'] ) && is_array( $_POST['accounts'] ) ) {
		    // Step 1: Unslash the whole array first.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$unslashed_accounts = wp_unslash( $_POST['accounts'] );
			
		    // Step 2: Sanitize each value.
		    $raw_accounts = array_map(
		        static function ( $account ) {
		            return is_array( $account )
		                ? array_map( 'sanitize_text_field', $account )
		                : sanitize_text_field( $account );
		        },
		        $unslashed_accounts
		    );
		}

	    if (!is_array($raw_accounts) || empty($raw_accounts)) {
	        $errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'bytenft-transak-payment-gateway');
	        $this->log_info('No accounts submitted in admin options.');
	    }

	    foreach ((array) $raw_accounts as $account) {
	        if (!is_array($account)) {
	            continue;
	        }

	        $account = array_map('sanitize_text_field', $account);

	        $account_title       = $account['title'] ?? '';
	        $priority            = intval($account['priority'] ?? 1);
	        $live_public_key     = $account['live_public_key'] ?? '';
	        $live_secret_key     = $account['live_secret_key'] ?? '';
	        $sandbox_public_key  = $account['sandbox_public_key'] ?? '';
	        $sandbox_secret_key  = $account['sandbox_secret_key'] ?? '';
	        $has_sandbox         = isset($account['has_sandbox']);
	        $live_status         = $account['live_status'] ?? 'Active';
	        $sandbox_status      = $has_sandbox ? ($account['sandbox_status'] ?? 'Active') : '';

	        if (empty($account_title) && empty($live_public_key) && empty($live_secret_key) && empty($sandbox_public_key) && empty($sandbox_secret_key)) {
	            continue;
	        }

	        if (empty($account_title) || empty($live_public_key) || empty($live_secret_key)) {
				// translators: %s: The account title entered by the user.
			    $errors[] = sprintf(__( 'Account "%s": Title, Live Public Key, and Live Secret Key are required.', 'bytenft-transak-payment-gateway' ),$account_title);
			    $this->log_info("Validation failed: missing required fields for account '{$account_title}'");
			    continue;
			}


	        $live_combined = $live_public_key . '|' . $live_secret_key;
	        if (in_array($live_combined, $unique_live_keys, true)) {
				// translators: %s: Live Public Key.
	            $errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be unique.', 'bytenft-transak-payment-gateway'), $account_title);
	            $this->log_info("Validation failed: duplicate live keys for account '{$account_title}'");
	            continue;
	        }

	        if ($live_public_key === $live_secret_key) {
				// translators: %s: Live Public Key.
	            $errors[] = sprintf(__('Account "%s": Live Public Key and Live Secret Key must be different.', 'bytenft-transak-payment-gateway'), $account_title);
	            $this->log_info("Validation warning: live keys are identical for account '{$account_title}'");
	        }

	        $unique_live_keys[] = $live_combined;

	        if ($has_sandbox && !empty($sandbox_public_key) && !empty($sandbox_secret_key)) {
	            $sandbox_combined = $sandbox_public_key . '|' . $sandbox_secret_key;

	            if (in_array($sandbox_combined, $unique_sandbox_keys, true)) {
					// translators: %s: Sandbox Public Key and Sandbox Secret Key.
	                $errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be unique.', 'bytenft-transak-payment-gateway'), $account_title);
	                $this->log_info("Validation failed: duplicate sandbox keys for account '{$account_title}'");
	                continue;
	            }

	            if ($sandbox_public_key === $sandbox_secret_key) {
					// translators: %s: Sandbox Public Key and Sandbox Secret Key.
	                $errors[] = sprintf(__('Account "%s": Sandbox Public Key and Sandbox Secret Key must be different.', 'bytenft-transak-payment-gateway'), $account_title);
	                $this->log_info("Validation warning: sandbox keys are identical for account '{$account_title}'");
	            }

	            $unique_sandbox_keys[] = $sandbox_combined;
	        }

	        $valid_accounts[$normalized_index++] = [
	            'title'              => $account_title,
	            'priority'           => $priority,
	            'live_public_key'    => $live_public_key,
	            'live_secret_key'    => $live_secret_key,
	            'sandbox_public_key' => $sandbox_public_key,
	            'sandbox_secret_key' => $sandbox_secret_key,
	            'has_sandbox'        => $has_sandbox ? 'on' : 'off',
	            'sandbox_status'     => $sandbox_status,
	            'live_status'        => $live_status,
	        ];

	        $this->log_info("Validated and added account '{$account_title}' to saved list.");
	    }

	    if (empty($valid_accounts) && empty($errors)) {
	        $errors[] = __('You cannot delete all accounts. At least one valid payment account must be configured.', 'bytenft-transak-payment-gateway');
	        $this->log_info('All submitted accounts failed validation. No accounts will be saved.');
	    }

	    if (empty($errors)) {
	        update_option('woocommerce_bnfttransak_payment_gateway_accounts', $valid_accounts);
	        $this->admin_notices->bnfttransak_add_notice('settings_success', 'notice notice-success', __('Settings saved successfully.', 'bytenft-transak-payment-gateway'));
	        $this->log_info('Account settings updated successfully.', ['count' => count($valid_accounts)]);

	        if (class_exists('BYTENFT_TRANSAK_PAYMENT_GATEWAY_Loader')) {
	            $loader = BYTENFT_TRANSAK_PAYMENT_GATEWAY_Loader::get_instance();
	            if (method_exists($loader, 'handle_cron_event')) {
	                $loader->handle_cron_event();
	                $this->log_info('Triggered BYTENFT_TRANSAK_PAYMENT_GATEWAY_Loader::handle_cron_event() after settings save.');
	            }
	        }
	    } else {
	        foreach ($errors as $error) {
	            $this->admin_notices->bnfttransak_add_notice('settings_error', 'notice notice-error', $error);
	            $this->log_info("Admin settings error: {$error}");
	        }
	    }

	    add_action('admin_notices', [$this->admin_notices, 'display_notices']);
	}

	/**
	 * Get the next available account for payment processing.
	 *
	 * @param array $used_accounts List of already used accounts.
	 * @return array|null
	 */
	public function get_updated_account() {
	    $accounts = get_option('woocommerce_bytenft_payment_gateway_accounts', []);
	    $valid_accounts = [];

	    foreach ($accounts as $index => $account) {
	        $useSandbox = $this->sandbox;
	        $secretKey = $useSandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];
	        $publicKey = $useSandbox ? $account['sandbox_public_key'] : $account['live_public_key'];

	        $this->log_info("Checking merchant status for account '{$account['title']}'", [
	            'useSandbox' => $useSandbox,
	            'publicKey' => $publicKey,
	        ]);

	        $checkStatusUrl = $this->get_api_url('/api/check-merchant-status', $useSandbox);
	        $response = wp_remote_post($checkStatusUrl, [
	            'headers' => [
	                'Authorization' => 'Bearer ' . $publicKey,
	                'Content-Type'  => 'application/json',
	            ],
	            'timeout' => 10,
	            'body' => wp_json_encode([
	                'api_secret_key' => $secretKey,
	                'is_sandbox'     => $useSandbox,
	            ]),
	        ]);

	        $body = json_decode(wp_remote_retrieve_body($response), true);
	        $isError = is_array($body) && strtolower($body['status'] ?? '') === 'error';

	        $valid_accounts[] = [
	            'title'              => $account['title'],
	            'priority'           => $account['priority'],
	            'live_public_key'    => $account['live_public_key'],
	            'live_secret_key'    => $account['live_secret_key'],
	            'sandbox_public_key' => $account['sandbox_public_key'],
	            'sandbox_secret_key' => $account['sandbox_secret_key'],
	            'has_sandbox'        => $account['has_sandbox'],
	            'sandbox_status'     => $isError ? 'Inactive' : 'Active',
	            'live_status'        => $isError ? 'Inactive' : 'Active',
	        ];

	        if ($isError) {
	            $this->log_info("Account '{$account['title']}' is inactive", ['response' => $body]);
	        } else {
	            $this->log_info("Account '{$account['title']}' is active");
	        }
	    }

	    if (!empty($valid_accounts)) {
	        update_option('woocommerce_bytenft_payment_gateway_accounts', $valid_accounts);
	        return true;
	    }

	    $this->log_info('No active account. Removing bytenft gateway.');
	    return false;
	}


	/**
	 * Initialize gateway settings form fields.
	 */
	public function bnfttransak_init_form_fields()
	{
		$this->form_fields = $this->bnfttransak_get_form_fields();
	}

	/**
	 * Get form fields.
	 */
	public function bnfttransak_get_form_fields() {
	    $dev_instructions_link = sprintf(
	        '<strong><a class="bnfttransak-instructions-url" href="%s" target="_blank">%s</a></strong><br>',
	        esc_url($this->base_url . '/developers'),
	        __('click here to access your developer account', 'bytenft-transak-payment-gateway')
	    );

	    return apply_filters('woocommerce_gateway_settings_fields_' . $this->id, [

	        'enabled' => [
	            'title'       => __('Enable/Disable', 'bytenft-transak-payment-gateway'),
	            'label'       => __('Enable ByteNFT transak Payment Gateway', 'bytenft-transak-payment-gateway'),
	            'type'        => 'checkbox',
	            'default'     => 'yes',
	        ],

	        'title' => [
	            'title'       => __('Title', 'bytenft-transak-payment-gateway'),
	            'type'        => 'text',
	            'description' => __('This controls the title which the user sees during checkout.', 'bytenft-transak-payment-gateway'),
	            'default'     => __('Pay with Debit Cards (Visa, Mastercard, or Apple Pay)', 'bytenft-transak-payment-gateway'),
	            'desc_tip'    => true,
	        ],

			'description' => [
			    'title'       => __('Description', 'bytenft-transak-payment-gateway'),
			    'type'        => 'textarea',
			    'description' => __('Provide a brief description of the payment option.', 'bytenft-transak-payment-gateway'),
			    'default'     => __(
			        '<p style="margin:0 0 8px; font-weight:bold; font-size:14px;">Important Information about Your Purchase</p>

			        <p style="margin:0 0 6px; font-weight:bold; font-size:13px;">NFT Information</p>
			        <p style="margin:0 0 10px; font-size:13px;">
			            You are completing your NFT purchase through <strong>ByteNFT</strong>.<br>
			            For your order, an NFT will be minted, purchased, and then burned as part of the process.<br>
			            Please note this is a <strong>digital purchase</strong> and is <strong>non-refundable</strong>.
			        </p>

			        <p style="margin:0 0 6px; font-weight:bold; font-size:13px;">Bank Statement Information</p>
			        <p style="margin:0; font-size:13px;">
			            On your bank or card statement, this transaction will appear as <em>“TRANSAK*BYTE”</em>.<br>
			            Make sure you recognize this description to avoid confusion when reviewing your statement.
			        </p>',
			        'bytenft-transak-payment-gateway'
			    ),
			    'desc_tip'    => true,
			],


	         'instructions' => [
	            'title'       => __('Instructions', 'bytenft-transak-payment-gateway'),
	            'type'        => 'title',
				// translators: 1: Opening HTML link tag for "Get your API keys" instructions, 2: Closing HTML link tag.
	            'description' => sprintf(__('To configure this gateway, %1$sGet your API keys from your merchant account: Developer Settings > API Keys.%2$s', 'bytenft-transak-payment-gateway'),$dev_instructions_link,''),
	            'desc_tip'    => true,
	        ],

	        'sandbox' => [
	            'title'       => __('Sandbox', 'bytenft-transak-payment-gateway'),
	            'label'       => __('Enable Sandbox Mode', 'bytenft-transak-payment-gateway'),
	            'type'        => 'checkbox',
	            'description' => __('Use sandbox API keys (real payments will not be taken).', 'bytenft-transak-payment-gateway'),
	            'default'     => 'no',
	        ],

	        'accounts' => [
	            'title'       => __('Payment Accounts', 'bytenft-transak-payment-gateway'),
	            'type'        => 'accounts_repeater',
	            'description' => __('Add multiple payment accounts dynamically.', 'bytenft-transak-payment-gateway'),
	        ],

	        'order_status' => [
	            'title'       => __('Order Status', 'bytenft-transak-payment-gateway'),
	            'type'        => 'select',
	            'description' => __('Order status after successful payment.', 'bytenft-transak-payment-gateway'),
	            'default'     => '',
	            'id'          => 'order_status_select',
	            'desc_tip'    => true,
	            'options'     => [
	                'processing' => __('Processing', 'bytenft-transak-payment-gateway'),
	                'completed'  => __('Completed', 'bytenft-transak-payment-gateway'),
	            ],
	        ],

	        'show_consent_checkbox' => [
	            'title'       => __('Show Consent Checkbox', 'bytenft-transak-payment-gateway'),
	            'label'       => __('Enable consent checkbox on checkout page', 'bytenft-transak-payment-gateway'),
	            'type'        => 'checkbox',
	            'description' => __('Show a checkbox for user consent during checkout.', 'bytenft-transak-payment-gateway'),
	            'default'     => 'no',
	        ],

	    ], $this);
	}


	public function generate_accounts_repeater_html($key, $data)
	{

		$option_value = get_option('woocommerce_bnfttransak_payment_gateway_accounts', []);
		$option_value = maybe_unserialize($option_value);
		$active_account = get_option('bnfttransak_active_account', 0); // Store active account ID
		$global_settings = get_option('woocommerce_bnfttransak_settings', []);
		$global_settings = maybe_unserialize($global_settings);
		$sandbox_enabled = !empty($global_settings['sandbox']) && $global_settings['sandbox'] === 'yes';

		ob_start();
?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html($data['title']); ?></label>
			</th>
			<td class="forminp">
				<div id="global-error" class="error-message" style="color: red; margin-bottom: 10px;"></div>
				<div class="bnfttransak-accounts-container">
					<?php if (!empty($option_value)): ?>
						<div class="bnfttransak-sync-account">
							<span id="bnfttransak-sync-status"></span>
							<button class="button" class="bnfttransak-sync-accounts" id="bnfttransak-sync-accounts"><span><i class="fa fa-refresh" aria-hidden="true"></i></span> <?php esc_html_e('Sync Accounts', 'bytenft-transak-payment-gateway'); ?></button>
						</div>
					<?php endif; ?>


					<?php if (empty($option_value)): ?>
						<div class="empty-account"><?php esc_html_e('No accounts available. Please add one to continue.', 'bytenft-transak-payment-gateway'); ?></div>
					<?php else: ?>
						<?php foreach (array_values($option_value) as $index => $account): ?>
							<?php
							$live_status = (!empty($account['live_status'])) ? $account['live_status'] : '';
							$sandbox_status = (!empty($account['sandbox_status'])) ? $account['sandbox_status'] : 'unknown';
							?>
							<div class="bnfttransak-account" data-index="<?php echo esc_attr($index); ?>">
								<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][live_status]"
									value="<?php echo esc_attr($account['live_status'] ?? ''); ?>">
								<input type="hidden" name="accounts[<?php echo esc_attr($index); ?>][sandbox_status]"
									value="<?php echo esc_attr($account['sandbox_status'] ?? ''); ?>">
								<div class="title-blog">

									<h4>
										<span class="account-name-display">
											<?php echo !empty($account['title']) ? esc_html($account['title']) : esc_html__('Untitled Account', 'bytenft-transak-payment-gateway'); ?>
										</span>
										&nbsp;<i class="fa fa-caret-down <?php echo esc_attr($this->id); ?>-toggle-btn" aria-hidden="true"></i>
									</h4>

									<div class="action-button">
										<div class="account-status-block" style="float: right;">
											<span class="account-status-label 
									    <?php echo esc_attr($sandbox_enabled ? 'sandbox-status' : 'live-status'); ?> 
									    <?php echo esc_attr(strtolower($sandbox_enabled ? ($sandbox_status ?? '') : ($live_status ?? ''))); ?>">
												<?php
												if ($sandbox_enabled) {
													echo esc_html__('Sandbox Account Status: ', 'bytenft-transak-payment-gateway') . esc_html(ucfirst($sandbox_status));
												} else {
													echo esc_html__('Live Account Status: ', 'bytenft-transak-payment-gateway') . esc_html(ucfirst($live_status));
												} ?>
											</span>
										</div>
										<button type="button" class="delete-account-btn">
											<i class="fa fa-trash" aria-hidden="true"></i>
										</button>
									</div>
								</div>
								
								<div class="<?php echo esc_attr($this->id); ?>-info">
									<div class="add-blog title-priority">
										<div class="account-input account-name">
											<label><?php esc_html_e('Account Name', 'bytenft-transak-payment-gateway'); ?></label>
											<input type="text" class="account-title"
												name="accounts[<?php echo esc_attr($index); ?>][title]"
												placeholder="<?php esc_attr_e('Account Title', 'bytenft-transak-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['title'] ?? ''); ?>">
										</div>
										<div class="account-input priority-name">
											<label><?php esc_html_e('Priority', 'bytenft-transak-payment-gateway'); ?></label>
											<input type="number" class="account-priority"
												name="accounts[<?php echo esc_attr($index); ?>][priority]"
												placeholder="<?php esc_attr_e('Priority', 'bytenft-transak-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['priority'] ?? '1'); ?>" min="1">
										</div>
									</div>

									<div class="add-blog">

										<div class="account-input">
											<label><?php esc_html_e('Live Keys', 'bytenft-transak-payment-gateway'); ?></label>
											<input type="text" class="live-public-key"
												name="accounts[<?php echo esc_attr($index); ?>][live_public_key]"
												placeholder="<?php esc_attr_e('Public Key', 'bytenft-transak-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['live_public_key'] ?? ''); ?>">
										</div>
										<div class="account-input">
											<input type="text" class="live-secret-key"
												name="accounts[<?php echo esc_attr($index); ?>][live_secret_key]"
												placeholder="<?php esc_attr_e('Secret Key', 'bytenft-transak-payment-gateway'); ?>"
												value="<?php echo esc_attr($account['live_secret_key'] ?? ''); ?>">
										</div>
									</div>

									<div class="account-checkbox">
										<?php
											$checkbox_id    = $this->id . '-sandbox-checkbox-' . $index;
											$checkbox_class = $this->id . '-sandbox-checkbox';
										?>
										<input type="checkbox"
											class="<?php echo esc_attr( $checkbox_class ); ?>"
											id="<?php echo esc_attr( $checkbox_id ); ?>"
											name="accounts[<?php echo esc_attr( $index ); ?>][has_sandbox]"
											<?php checked( $account['has_sandbox'] == 'on' ); ?>>
										<label for="<?php echo esc_attr( $checkbox_id ); ?>">
											<?php esc_html_e( 'Do you have the sandbox keys?', 'bytenft-transak-payment-gateway' ); ?>
										</label>
									</div>

									<?php
									$sandbox_container_id    = $this->id . '-sandbox-keys-' . $index;
									$sandbox_container_class = $this->id . '-sandbox-keys';
									$sandbox_display_style   = $account['has_sandbox'] == 'off' ? 'display: none;' : '';
									?>
									<div id="<?php echo esc_attr($sandbox_container_id); ?>"
									     class="<?php echo esc_attr($sandbox_container_class); ?>"
									     style="<?php echo esc_attr($sandbox_display_style); ?>">

									    <div class="add-blog">
									        <div class="account-input">
									            <label><?php esc_html_e('Sandbox Keys', 'bytenft-transak-payment-gateway'); ?></label>
									            <input type="text" class="sandbox-public-key"
									                   name="accounts[<?php echo esc_attr($index); ?>][sandbox_public_key]"
									                   placeholder="<?php esc_attr_e('Public Key', 'bytenft-transak-payment-gateway'); ?>"
									                   value="<?php echo esc_attr($account['sandbox_public_key'] ?? ''); ?>">
									        </div>
									        <div class="account-input">
									            <input type="text" class="sandbox-secret-key"
									                   name="accounts[<?php echo esc_attr($index); ?>][sandbox_secret_key]"
									                   placeholder="<?php esc_attr_e('Secret Key', 'bytenft-transak-payment-gateway'); ?>"
									                   value="<?php echo esc_attr($account['sandbox_secret_key'] ?? ''); ?>">
									        </div>
									    </div>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php wp_nonce_field('bnfttransak_accounts_nonce_action', 'bnfttransak_accounts_nonce'); ?>
					<div class="add-account-btn">
						<button type="button" class="button bnfttransak-add-account">
							<span>+</span> <?php esc_html_e('Add Account', 'bytenft-transak-payment-gateway'); ?>
						</button>
					</div>
				</div>
			</td>
		</tr>
<?php return ob_get_clean();
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment($order_id, $used_accounts = [])
	{
		global $wpdb;
		$logger_context = ['source' => 'bytenft-transak-payment-gateway'];

		// Retrieve client IP
		$ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
		if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
			$ip_address = 'invalid';
		}

		// **Rate Limiting**
		$window_size = 30; // 30 seconds
		$max_requests = 100;
		$timestamp_key = "rate_limit_{$ip_address}_timestamps";
		$request_timestamps = get_transient($timestamp_key) ?: [];

		// Remove old timestamps
		$timestamp = time();
		$request_timestamps = array_filter($request_timestamps, fn($ts) => $timestamp - $ts <= $window_size);

		if (count($request_timestamps) >= $max_requests) {
			wc_get_logger()->warning("Rate limit exceeded for IP: {$ip_address}", $logger_context);
			wc_add_notice(__('Too many requests. Please try again later.', 'bytenft-transak-payment-gateway'), 'error');
			return ['result' => 'fail'];
		}

		// Add the current timestamp
		$request_timestamps[] = $timestamp;
		set_transient($timestamp_key, $request_timestamps, $window_size);

		// **Retrieve Order**
		$order = wc_get_order($order_id);
		if (!$order) {
			wc_get_logger()->error("Invalid order ID: {$order_id}", $logger_context);
			wc_add_notice(__('Invalid order.', 'bytenft-transak-payment-gateway'), 'error');
			return ['result' => 'fail'];
		}

		// **Sandbox Mode Handling**
		if ($this->sandbox) {
			$test_note = __('This is a test order processed in sandbox mode.', 'bytenft-transak-payment-gateway');
			$existing_notes = get_comments(['post_id' => $order->get_id(), 'type' => 'order_note', 'approve' => 'approve']);

			if (!array_filter($existing_notes, fn($note) => trim($note->comment_content) === trim($test_note))) {
				$order->update_meta_data('_is_test_order', true);
				$order->add_order_note($test_note);
			}
			wc_get_logger()->info("Sandbox mode: test order flag set for Order ID: {$order_id}", $logger_context);
		}
		$last_failed_account = null; // Track the last account that reached the limit
		$previous_account = null;
		// **Start Payment Process**
		while (true) {
			$account = $this->get_next_available_account($used_accounts);

			if (!$account) {
				// **Ensure email is sent to the last failed account**
				if ($last_failed_account) {
					wc_get_logger()->info("Sending notification to account '{$last_failed_account['title']}' due to no available alternatives.", $logger_context);
					$this->send_account_switch_email($last_failed_account, $account);
				}
				wc_add_notice(__('No available payment accounts.', 'bytenft-transak-payment-gateway'), 'error');
				return ['result' => 'fail'];
			}

			/* ==========================================================
			   CHECK MERCHANT STATUS BEFORE USING ACCOUNT
			   ----------------------------------------------------------
			   Ensures the current account (merchant) is active and 
			   approved before proceeding. Skips account if inactive.
			   ========================================================== */

			$public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
			$secret_key= $this->sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];
			$accStatusApiUrl = $this->get_api_url('/api/check-merchant-status');
			$merchant_status_data = [
			    'is_sandbox'     => $this->sandbox,
			    'amount'         => $order->get_total(),
			    'api_public_key' => $public_key,
				'api_secret_key' => $secret_key,
			];

			// Use cache for status check
			$cache_key = 'merchant_status_' . md5($public_key);
			$merchant_status_response = $this->get_cached_api_response($accStatusApiUrl, $merchant_status_data, $cache_key);

			if (
			    !is_array($merchant_status_response) ||
			    !isset($merchant_status_response['status']) ||
			    $merchant_status_response['status'] !== 'success'
			) {
			    wc_get_logger()->warning("Account '{$account['title']}' failed merchant status check.", [
			        'source'  => 'bytenft-transak-payment-gateway',
			        'context' => [
			            'order_id'      => $order_id,
			            'account_title' => $account['title'] ?? 'unknown',
			            'response'      => $merchant_status_response,
			        ],
			    ]);

			    if (!empty($lock_key)) {
			        $this->release_lock($lock_key);
			    }

			    // 👇 THIS LINE PREVENTS INFINITE LOOP
				$used_accounts[] = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];

			    continue; // Try next account
			}

			/* ========================== END ========================== */

			$raw_lock_key = $account['lock_key'] ?? null;
			$lock_key = $raw_lock_key ? preg_replace('/\s+/', '_', trim($raw_lock_key)) : null;

			// Add order note mentioning account name
			$order->add_order_note(__('Processing Payment Via: ', 'bytenft-transak-payment-gateway') . $account['title']);

			// **Prepare API Data**
			$data = $this->bnfttransak_prepare_payment_data($order, $public_key, $secret_key);

			// **Check Transaction Limit**
			$transactionLimitApiUrl = $this->get_api_url('/api/dailylimit');
			$transaction_limit_response = wp_remote_post($transactionLimitApiUrl, [
				'method' => 'POST',
				'timeout' => 30,
				'body' => $data,
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Authorization' => 'Bearer ' . sanitize_text_field($data['api_public_key']),
				],
				'sslverify' => true,
			]);

			$transaction_limit_data = json_decode(wp_remote_retrieve_body($transaction_limit_response), true);

			// **Handle Account Limit Error**
			if (isset($transaction_limit_data['status']) && $transaction_limit_data['status'] === 'error') {
				$error_message = sanitize_text_field($transaction_limit_data['message']);
				wc_get_logger()->warning("['{$account['title']}'] exceeded daily transaction limit: $error_message", $logger_context);

				if (!empty($lock_key)) {
					$this->release_lock($lock_key);
				}

				$last_failed_account = $account;
				// Switch to next available account
				$used_accounts[] = $account['title'];
				$new_account = $this->get_next_available_account($used_accounts);

				// **Send Email Notification **
				if ($new_account) {
					wc_get_logger()->info("Switched to fallback account '{$new_account['title']}' after '{$account['title']}' limit reached.", $logger_context);

					// Send email only to the previously failed account
					if ($previous_account) {
						//$this->send_account_switch_email($previous_account, $account);
					}

					$previous_account = $account;
					continue; // Retry with the new account
				} else {
					// **No available accounts left, send email to the last failed account**
					if ($last_failed_account) {
						$this->send_account_switch_email($last_failed_account, $account);
					}
					wc_add_notice(__('All accounts have reached their transaction limit.', 'bytenft-transak-payment-gateway'), 'error');
					return ['result' => 'fail'];
				}
			}

			// **Proceed with Payment**
			wc_get_logger()->info("Sending payment request using account '{$account['title']}'", $logger_context);
			$apiPath = '/api/request-payment';
			$url = esc_url($this->base_url . $apiPath);

			$order->update_meta_data('_order_origin', 'bnfttransak_payment_gateway');
			$order->save();

			$response = wp_remote_post($url, [
				'method' => 'POST',
				'timeout' => 30,
				'body' => $data,
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Authorization' => 'Bearer ' . sanitize_text_field($data['api_public_key']),
				],
				'sslverify' => true,
			]);

			// **Handle Response**
			if (is_wp_error($response)) {
				wc_get_logger()->error("HTTP error during payment request: {$response->get_error_message()}", $logger_context);
				if (!empty($lock_key)) {
					$this->release_lock($lock_key);
				}
				wc_add_notice(__('Payment error: Unable to process.', 'bytenft-transak-payment-gateway'), 'error');
				return ['result' => 'fail'];
			}

			$response_data = json_decode(wp_remote_retrieve_body($response), true);

			wc_get_logger()->info(
			    'Payment API raw response',
			    [
			        'source'  => 'bytenft-transak-payment-gateway',
			        'context' => [
						'url'=>$url,
			            'order_id' => $order_id,
			            'response' => wp_remote_retrieve_body($response), // raw
			            'parsed'   => $response_data, // parsed array
			        ],
			    ]
			);


			if (!empty($response_data['status']) && $response_data['status'] === 'success' && !empty($response_data['data']['payment_link'])) {
				if ($last_failed_account) {
					wc_get_logger()->info("Sending email before returning success to: '{$last_failed_account['title']}'", ['source' => 'bytenft-transak-payment-gateway']);
					$this->send_account_switch_email($last_failed_account, $account);
				}
				//$last_successful_account = $account;
				// Save pay_id to order meta
				$pay_id = $response_data['data']['pay_id'] ?? '';
				if (!empty($pay_id)) {
					$order->update_meta_data('_bnfttransak_pay_id', $pay_id);
				}

				$table_name = $wpdb->prefix . 'order_payment_link';

				// Add simple cache to avoid hitting DB on every request
				$cache_key    = 'bnfttransak_table_exists_' . md5($table_name);
				$cache_group  = 'bnfttransak_payment_gateway';

				$table_exists = wp_cache_get($cache_key, $cache_group);

				if (false === $table_exists) {
				    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
				    $table_exists = $wpdb->get_var(
				        $wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
				    );

				    // Cache result for 1 hour
				    wp_cache_set($cache_key, $table_exists, $cache_group, HOUR_IN_SECONDS);
				}

				if ($table_exists !== $table_name) {
				    // Create the table if not exists
				    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

				    $charset_collate = $wpdb->get_charset_collate();

				    $create_sql = "CREATE TABLE $table_name (
				        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
				        order_id BIGINT UNSIGNED NOT NULL,
				        uuid VARCHAR(100) NOT NULL,
				        payment_link TEXT NOT NULL,
				        customer_email VARCHAR(191),
				        amount DECIMAL(18,2),
				        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
				    ) $charset_collate;";

				    dbDelta($create_sql);

				    wc_get_logger()->info("Created missing `$table_name` table.", [
				        'source' => 'bytenft-transak-payment-gateway',
				        'context' => ['table' => $table_name],
				    ]);
				}

				// Prepare amount
				$formatted_amount = number_format((float) ($response_data['data']['amount'] ?? 0), 2, '.', '');

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert is safely prepared with format specifiers
				$wpdb->insert(
				    $table_name,
				    [
				        'order_id'       => $order_id,
				        'uuid'           => sanitize_text_field($pay_id),
				        'payment_link'   => esc_url_raw($response_data['data']['payment_link'] ?? ''),
				        'customer_email' => sanitize_email($response_data['data']['customer_email'] ?? ''),
				        'amount'         => $formatted_amount,
				        'created_at'     => current_time('mysql', 1),
				    ],
				    ['%d', '%s', '%s', '%s', '%s', '%s']
				);

				wc_get_logger()->info('Stored order payment link to DB.', [
				    'source'  => 'bytenft-transak-payment-gateway',
				    'context' => [
				        'order_id' => $order_id,
				        'uuid'     => $pay_id,
				        'amount'   => $formatted_amount,
				    ],
				]);

				// **Update Order Status**
				$order->update_status('pending', __('Payment pending.', 'bytenft-transak-payment-gateway'));

				// **Add Order Note (If Not Exists)**
				// translators: %s represents the account title.
				$new_note = sprintf(
					/* translators: %s represents the account title. */
					esc_html__('Payment initiated via ByteNFT transak. Awaiting your completion ( %s )', 'bytenft-transak-payment-gateway'),
					esc_html($account['title'])
				);
				$existing_notes = $order->get_customer_order_notes();

				if (!array_filter($existing_notes, fn($note) => trim(wp_strip_all_tags($note->comment_content)) === trim($new_note))) {
					$order->add_order_note($new_note, false, true);
				}				

				$order_id   = $order->get_id();
				$uuid = sanitize_text_field($response_data['data']['pay_id']);

				$json_data = json_encode($response_data);
				wc_get_logger()->info(
				    'Received successful payment API response. Saving order payment link data.',
				    [
				        'source'  => 'bytenft-transak-payment-gateway',
				        'context' => [
				            'order_id'       => $order_id,
				            'uuid'           => $uuid,
				            'payment_link'   => $response_data['data']['payment_link'] ?? '',
				            'customer_email' => $response_data['data']['customer_email'] ?? '',
				            'amount'         => $response_data['data']['amount'] ?? '',
				        ],
				    ]
				);

				if (!empty($lock_key)) {
					$this->release_lock($lock_key);
				}
				return [
					'payment_link' => esc_url($response_data['data']['payment_link']),
					'result' => 'success',
				];
			}

			// **Handle Payment Failure**
			$error_message = isset($response_data['message']) ? sanitize_text_field($response_data['message']) : __('Payment failed.', 'bytenft-transak-payment-gateway');
			wc_get_logger()->error("Final payment failure using '{$account['title']}': $error_message", $logger_context);
			// **Add Order Note for Failed Payment**
			$order->add_order_note(
				sprintf(
					/* translators: 1: Account title, 2: Error message. */
					esc_html__('Payment failed using account: %1$s. Error: %2$s', 'bytenft-transak-payment-gateway'),
					esc_html($account['title']),
					esc_html($error_message)
				)
			);

			// Add WooCommerce error notice
			wc_add_notice(__('Payment error: ', 'bytenft-transak-payment-gateway') . $error_message, 'error');
			if (!empty($lock_key)) {
				$this->release_lock($lock_key);
			}
			return ['result' => 'fail'];
		}
	}

	// Display the "Test Order" tag in admin order details
	public function bnfttransak_display_test_order_tag($order)
	{
		if (get_post_meta($order->get_id(), '_is_test_order', true)) {
			echo '<p><strong>' . esc_html__('Test Order', 'bytenft-transak-payment-gateway') . '</strong></p>';
		}
	}

	private function bnfttransak_get_return_url_base()
	{
		return rest_url('/bnfttransak/v1/data');
	}

	private function bnfttransak_prepare_payment_data($order, $api_public_key, $api_secret)
	{
		$order_id = $order->get_id(); // Validate order ID
		// Check if sandbox mode is enabled
		$is_sandbox = $this->get_option('sandbox') === 'yes';

		// Sanitize and get the billing email or phone
		$request_for = sanitize_email($order->get_billing_email() ?: $order->get_billing_phone());
		// Get order details and sanitize
		$first_name = sanitize_text_field($order->get_billing_first_name());
		$last_name = sanitize_text_field($order->get_billing_last_name());
		$amount = number_format($order->get_total(), 2, '.', '');

		// Get billing address details
		// $email = sanitize_text_field($order->get_billing_email());
		$email = WC()->checkout()->get_value('billing_email');
		$phone = sanitize_text_field($order->get_billing_phone());

		// Get billing country (ISO code like "US", "IN", etc.)
		$country = $order->get_billing_country();

		// Convert to country calling code
		$country_code = WC()->countries->get_country_calling_code($country);

		$billing_address_1 = sanitize_text_field($order->get_billing_address_1());
		$billing_address_2 = sanitize_text_field($order->get_billing_address_2());
		$billing_city = sanitize_text_field($order->get_billing_city());
		$billing_postcode = sanitize_text_field($order->get_billing_postcode());
		$billing_country = sanitize_text_field($order->get_billing_country());
		$billing_state = sanitize_text_field($order->get_billing_state());

		$redirect_url = esc_url_raw(
			add_query_arg(
				[
					'order_id' => $order_id, // Include order ID or any other identifier
					'key' => $order->get_order_key(),
					'nonce' => wp_create_nonce('bnfttransak_payment_nonce'), // Create a nonce for verification
					'mode' => 'wp',
				],
				$this->bnfttransak_get_return_url_base() // Use the updated base URL method
			)
		);

		$ip_address = sanitize_text_field($this->bnfttransak_get_client_ip());

		if (empty($order_id)) {
			wc_get_logger()->error('Order ID is missing or invalid.', ['source' => 'bytenft-transak-payment-gateway']);
			return ['result' => 'fail'];
		}

		// Create the meta data array
		$meta_data_array = [
			'order_id' => $order_id,
			'amount' => $amount,
			'source' => 'woocommerce',
		];

		// Log errors but continue processing
		foreach ($meta_data_array as $key => $value) {
			$meta_data_array[$key] = sanitize_text_field($value); // Sanitize each field
			if (is_object($value) || is_resource($value)) {
				wc_get_logger()->error('Invalid value for key ' . $key . ': ' . wp_json_encode($value), ['source' => 'bytenft-transak-payment-gateway']);
			}
		}

		return [
			'api_secret' => $api_secret, // Use sandbox or live secret key
			'api_public_key' => $api_public_key, // Add the public key for API calls
			'first_name' => $first_name,
			'last_name' => $last_name,
			'request_for' => $request_for,
			'amount' => $amount,
			'redirect_url' => $redirect_url,
			'redirect_time' => 3,
			'ip_address' => $ip_address,
			'source' => 'wordpress',
			'meta_data' => $meta_data_array,
			'remarks' => 'Order ' . $order->get_order_number(),
			// Add billing address details to the request
			'email' => $email,
			'phone_number' => $phone,
			'country_code' => $country_code,
			'billing_address_1' => $billing_address_1,
			'billing_address_2' => $billing_address_2,
			'billing_city' => $billing_city,
			'billing_postcode' => $billing_postcode,
			'billing_country' => $billing_country,
			'billing_state' => $billing_state,
			'is_sandbox' => $is_sandbox,
		];
	}

	// Helper function to get client IP address
	private function bnfttransak_get_client_ip()
	{
		$ip = '';

		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			// Sanitize the client's IP directly on $_SERVER access
			$ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// Sanitize and handle multiple proxies
			$ip_list = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
			$ip = trim($ip_list[0]); // Take the first IP in the list and trim any whitespace
		} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
			// Sanitize the remote address directly
			$ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
		}

		// Validate the IP after retrieving it
		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
	}

	/**
	 * Add a custom label next to the order status in the order list.
	 *
	 * @param array $line_items The order line items array.
	 * @param WC_Order $order The WooCommerce order object.
	 * @return array Modified line items array.
	 */
	public function bnfttransak_add_custom_label_to_order_row($line_items, $order)
	{
		// Get the custom meta field value (e.g. '_order_origin')
		$order_origin = $order->get_meta('_order_origin');

		// Check if the meta exists and has value
		if (!empty($order_origin)) {
			// Add the label text to the first item in the order preview
			$line_items[0]['name'] .= ' <span style="background-color: #ffeb3b; color: #000; padding: 3px 5px; border-radius: 3px; font-size: 12px;">' . esc_html($order_origin) . '</span>';
		}

		return $line_items;
	}

	/**
	 * WooCommerce not active notice.
	 */
	public function bnfttransak_woocommerce_not_active_notice()
	{
		echo '<div class="error">
        <p>' .
			esc_html__('ByteNFT transak Payment Gateway requires WooCommerce to be installed and active.', 'bytenft-transak-payment-gateway') .
			'</p>
    </div>';
	}

	/**
	 * Payment form on checkout page.
	 */
	public function payment_fields()
	{
		$description = $this->get_option('description');

		if ($description) {
			// Apply formatting
			$formatted_description = wpautop(wptexturize(trim($description)));
			// Output directly with escaping
			echo wp_kses_post($formatted_description);
		}

		// Check if the consent checkbox should be displayed
		if ('yes' === $this->get_option('show_consent_checkbox')) {
			// Add user consent checkbox with escaping
			echo '<p class="form-row form-row-wide">
                <label for="bnfttransak_consent">
                    <input type="checkbox" id="bnfttransak_consent" name="bnfttransak_consent" /> ' .
				esc_html__('I consent to the collection of my data to process this payment', 'bytenft-transak-payment-gateway') .
				'
                </label>
            </p>';

			// Add nonce field for security

			wp_nonce_field('bnfttransak_payment', 'bnfttransak_nonce');
		}
	}

	/**
	 * Validate the payment form.
	 */
	public function validate_fields()
	{
		// Check for SQL injection attempts
		if (!$this->check_for_sql_injection()) {
			return false;
		}
		// Check if the consent checkbox setting is enabled
		if ($this->get_option('show_consent_checkbox') === 'yes') {
			// Sanitize and validate the nonce field
			$nonce = isset($_POST['bnfttransak_nonce']) ? sanitize_text_field(wp_unslash($_POST['bnfttransak_nonce'])) : '';
			if (empty($nonce) || !wp_verify_nonce($nonce, 'bnfttransak_payment')) {
				wc_add_notice(__('Nonce verification failed. Please try again.', 'bytenft-transak-payment-gateway'), 'error');
				return false;
			}

			// Sanitize the consent checkbox input
			$consent = isset($_POST['bnfttransak_consent']) ? sanitize_text_field(wp_unslash($_POST['bnfttransak_consent'])) : '';

			// Validate the consent checkbox was checked
			if ($consent !== 'on') {
				wc_add_notice(__('You must consent to the collection of your data to process this payment.', 'bytenft-transak-payment-gateway'), 'error');
				return false;
			}
		}

		return true;
	}

	/**
	 * Enqueue stylesheets for the plugin.
	 */
	public function bnfttransak_enqueue_styles_and_scripts()
	{
		if (is_checkout()) {
			// Enqueue stylesheets
			wp_enqueue_style(
				'bnfttransak-payment-loader-styles',
				plugins_url('../assets/css/frontend.css', __FILE__),
				[], // Dependencies (if any)
				'1.0', // Version number
				'all' // Media
			);

			// Enqueue bnfttransak.js script
			wp_enqueue_script(
				'bnfttransak-js',
				plugins_url('../assets/js/bytenft-transak.js', __FILE__),
				['jquery'], // Dependencies
				'1.0', // Version number
				true // Load in footer
			);

			// Localize script with parameters that need to be passed to bytenft-transak.js
			wp_localize_script('bnfttransak-js', 'bnfttransak_params', [
				'ajax_url' => admin_url('admin-ajax.php'),
				'checkout_url' => wc_get_checkout_url(),
				'bnfttransak_loader' => plugins_url('../assets/images/loader.gif', __FILE__),
				'bnfttransak_nonce' => wp_create_nonce('bnfttransak_payment'), // Create a nonce for verification
				'payment_method' => $this->id,
			]);
		}
	}

	function bnfttransak_admin_scripts($hook)
	{

		if (
		    'woocommerce_page_wc-settings' !== $hook ||
		    (sanitize_text_field(wp_unslash($_GET['section'] ?? '')) !== $this->id) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
		    return;
		}

		// Enqueue Admin CSS
		wp_enqueue_style('bnfttransak-font-awesome', plugins_url('../assets/css/font-awesome.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/font-awesome.css'), 'all');

		// Enqueue Admin CSS
		wp_enqueue_style('bnfttransak-admin-css', plugins_url('../assets/css/admin.css', __FILE__), [], filemtime(plugin_dir_path(__FILE__) . '../assets/css/admin.css'), 'all');

		// Register and enqueue your script
		wp_enqueue_script('bnfttransak-admin-script', plugins_url('../assets/js/bytenft-transak-admin.js', __FILE__), ['jquery'], filemtime(plugin_dir_path(__FILE__) . '../assets/js/bytenft-transak-admin.js'), true);

		wp_localize_script('bnfttransak-admin-script', 'bnfttransak_admin_data', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('bnfttransak_sync_nonce'),
			'gateway_id' => $this->id,
		]);
	}

	public function bnft_transak_hide_custom_payment_gateway_conditionally($available_gateways)
	{
		$gateway_id = $this->id;

	    if (!isset($available_gateways[$gateway_id])) {
	        return $available_gateways;
	    }

	    $cache_key = 'bnfttransak_gateway_visibility_' . $gateway_id;

		 // Unique cache/log key per cart state
	    $cart_hash = WC()->cart ? WC()->cart->get_cart_hash() : 'no_cart';

		// Skip logging if cart_hash is empty or 'no_cart' (cart not initialized)
	    if (empty($cart_hash) || $cart_hash === 'no_cart') {
	        return $available_gateways;
	    }

	    $cache_key = 'bnfttransak_gateway_visibility_' . $gateway_id . '_' . $cart_hash;

	    // ✅ Avoid running multiple times for the same cart_hash in the same request
	    static $processed_hashes = [];
	    if (in_array($cart_hash, $processed_hashes, true)) {
	        return $available_gateways;
	    }
	    $processed_hashes[] = $cart_hash;

		$this->log_info_once_per_session(
	        'gateway_check_start_' . $cart_hash,
	        'Payment Option Check Started',
	        ['cart_hash' => $cart_hash]
	    );

		// ✅ Handle both page load & AJAX differently
	    $is_ajax_order_review = (
	        defined('DOING_AJAX') &&
	        DOING_AJAX &&
	        isset($_REQUEST['wc-ajax']) &&
	        $_REQUEST['wc-ajax'] === 'update_order_review'
	    );

	   $this->log_info_once_per_session(
		    'request_context_' . $cart_hash,
		    'Checking payment option visibility',
		    [
		        'cart_hash'       => $cart_hash,
		        'Request Type'    => $is_ajax_order_review ? 'Cart update (AJAX)' : 'Checkout page load',
		        'On Checkout Page'=> is_checkout() ? 'Yes' : 'No'
		    ]
		);


	    $amount = 0.00;

	    if (isset($GLOBALS[$cache_key])) {
	        return $GLOBALS[$cache_key];
	    }

	    if (!is_checkout() && !$is_ajax_order_review) {
		    return $available_gateways;
		}

	    if (is_admin()) {
			$this->log_info_once_per_session(
			    'in_admin_' . $cart_hash,
			    'Payment option check skipped (admin area)',
			    ['cart_hash' => $cart_hash]
			);

			$this->log_info_once_per_session(
			    'gateway_check_end_' . $cart_hash,
			    'Payment Option Check Finished',
			    ['cart_hash' => $cart_hash]
			);

	        $this->get_updated_account();
	        return $available_gateways;
	    }
	   
	    if (WC()->cart) {
	        if ($is_ajax_order_review) {
	            // During AJAX, cart totals are often not recalculated yet
	            // Get from totals array instead of get_total('raw')
	            $totals = WC()->cart->get_totals();
	            $amount = isset($totals['total']) ? (float) $totals['total'] : 0.00;

	            // If still zero but cart has items, skip hiding for now
	            if ($amount < 0.01 && WC()->cart->get_cart_contents_count() > 0) {
	               $this->log_info_once_per_session(
					    'ajax_skip_' . $cart_hash,
					    'Skipping hide during AJAX recalculation (cart has items, amount 0)',
					    ['cart_hash' => $cart_hash]
					);

					$this->log_info_once_per_session(
					    'gateway_check_end_' . $cart_hash,
					    'Payment Option Check Finished',
					    ['cart_hash' => $cart_hash]
					);
	                return $available_gateways;
	            }
	        } else {
	            // Normal page load
	            $amount = (float) WC()->cart->get_total('raw');
	            if ($amount < 0.01) {
	                // Try fallback
	                $totals = WC()->cart->get_totals();
	                if (!empty($totals['total'])) {
	                    $amount = (float) $totals['total'];
	                }
	            }
	        }
	    }

	    $this->log_info_once_per_session(
		    'cart_amount_' . $cart_hash,
		    'Cart total detected',
		    [
		        'Amount'    => $amount,
		        'cart_hash' => $cart_hash
		    ]
		);

	    // Hide if truly below minimum
	    if ($amount < 0.01) {
			$this->log_info_once_per_session(
			    'hide_reason_low_amount_' . $cart_hash,
			    'Payment option hidden: order total below minimum',
			    [
			        'cart_hash' => $cart_hash,
			        'Amount'    => $amount
			    ]
			);
			$this->log_info_once_per_session(
			    'gateway_check_end_' . $cart_hash,
			    'Payment Option Check Finished',
			    ['cart_hash' => $cart_hash]
			);

	        return $this->hide_gateway($available_gateways, $gateway_id);
	    }

	    $amount = number_format($amount, 2, '.', '');

	    // Get accounts
	    if (!method_exists($this, 'get_all_accounts')) {
	       $this->log_info_once_per_session(
			    'missing_get_all_accounts_' . $cart_hash,
			    'Gateway misconfigured: missing account retrieval method',
			    ['cart_hash' => $cart_hash]
			);
			$this->log_info_once_per_session(
			    'gateway_check_end_' . $cart_hash,
			    'Payment Option Check Finished',
			    ['cart_hash' => $cart_hash]
			);

	        return $this->hide_gateway($available_gateways, $gateway_id);
	    }

	    $accounts = $this->get_all_accounts();

		$this->log_info_once_per_session(
		    'account_check_' . $cart_hash,
		    'Checking payment provider accounts',
		    [
				'accounts' => $accounts,
		        'Number of Accounts Found' => count($accounts),
		        'cart_hash' => $cart_hash
		    ]
		);


	    if (empty($accounts)) {
	        $this->log_info_once_per_session(
			    'no_accounts_' . $cart_hash,
			    'Payment option hidden: no merchant accounts configured',
			    ['cart_hash' => $cart_hash]
			);
			$this->log_info_once_per_session(
			    'gateway_check_end_' . $cart_hash,
			    'Payment Option Check Finished',
			    ['cart_hash' => $cart_hash]
			);

	        return $this->hide_gateway($available_gateways, $gateway_id);
	    }

	    usort($accounts, fn($a, $b) => $a['priority'] <=> $b['priority']);

	    $transactionLimitApiUrl = $this->get_api_url('/api/dailylimit');
	    $accStatusApiUrl = $this->get_api_url('/api/check-merchant-status');

	    $user_account_active = false;
	    $all_accounts_limited = true;

	   $this->log_info_once_per_session(
		    'account_check_' . $cart_hash,
		    'Evaluating accounts for availability',
		    [
		        'cart_hash' => $cart_hash,
		        'Amount'    => $amount,
		        'Accounts'  => $accounts
		    ]
		);


	   $force_refresh = (
		    isset($_GET['refresh_accounts'], $_GET['_wpnonce']) &&
		    $_GET['refresh_accounts'] === '1' &&
		    wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')
		);

	    foreach ($accounts as $account) {
	        $public_key = $this->sandbox ? $account['sandbox_public_key'] : $account['live_public_key'];
	        $secret_key = $this->sandbox ? $account['sandbox_secret_key'] : $account['live_secret_key'];

	        $data = [
	            'is_sandbox'     => $this->sandbox,
	            'amount'         => $amount,
	            'api_public_key' => $public_key,
	            'api_secret_key' => $secret_key,
	        ];

	        $cache_base = 'bnfttransak_daily_limit_' . md5($public_key . $amount);

	        $status_data = $this->get_cached_api_response($accStatusApiUrl, $data, $cache_base . '_status', 30, $force_refresh);
	        if (!empty($status_data['status']) && $status_data['status'] === 'success') {
	            $user_account_active = true;
	        }

	        $limit_data = $this->get_cached_api_response($transactionLimitApiUrl, $data, $cache_base . '_limit');
	       $this->log_info_once_per_session(
			    'limit_response_' . $public_key . '_' . $cart_hash,
			    'Transaction limit response',
			    [
			        'cart_hash' => $cart_hash,
			        'Sandbox'   => $this->sandbox,
			        'Data'      => $limit_data
			    ]
			);


	        if (!empty($limit_data['status']) && $limit_data['status'] === 'success') {
	            $all_accounts_limited = false;
	        }

	        if ($user_account_active && !$all_accounts_limited) {
	            break;
	        }
	    }

	    if (!$user_account_active) {
	       $this->log_info_once_per_session(
			    'no_active_accounts_' . $cart_hash,
			    'Payment option hidden: no active accounts',
			    ['cart_hash' => $cart_hash]
			);

			$this->log_info_once_per_session(
			    'gateway_check_end_' . $cart_hash,
			    'Payment Option Check Finished',
			    ['cart_hash' => $cart_hash]
			);

	        return $this->hide_gateway($available_gateways, $gateway_id);
	    }

	    if ($all_accounts_limited) {
	       $this->log_info_once_per_session(
			    'accounts_limited_' . $cart_hash,
			    'Payment option hidden: all accounts reached transaction limits',
			    ['cart_hash' => $cart_hash]
			);

			$this->log_info_once_per_session(
			    'gateway_check_end_' . $cart_hash,
			    'Payment Option Check Finished',
			    ['cart_hash' => $cart_hash]
			);

	        return $this->hide_gateway($available_gateways, $gateway_id);
	    }

		$this->log_info_once_per_session(
		    'gateway_active_' . $cart_hash,
		    'Payment option available: account active and within limits',
		    ['cart_hash' => $cart_hash]
		);

		// End log
		$this->log_info_once_per_session(
		    'gateway_check_end_' . $cart_hash,
		    'Payment Option Check Finished',
		    ['cart_hash' => $cart_hash]
		);


	    $GLOBALS[$cache_key] = $available_gateways;
	    return $available_gateways;
	}

	private function hide_gateway($available_gateways, $gateway_id)
	{
	    unset($available_gateways[$gateway_id]);
	    $GLOBALS['bnfttransak_gateway_visibility_' . $this->id] = $available_gateways;
	    return $available_gateways;
	}

	private function log_info_once_per_session($key, $message, $context = [])
	{
	    if (!WC()->session) {
	        return; // Session not started yet
	    }

	    // Extract cart_hash from context if provided, else fallback
	    $cart_hash = isset($context['cart_hash']) ? $context['cart_hash'] : 'no_cart';

	    // Make the log key unique for both the event key and current cart state
	    $log_key = 'bnfttransak_log_once_' . md5($key . $this->id . $cart_hash);

	    // Check if we've already logged for this cart state
	    if (WC()->session->get($log_key)) {
	        return;
	    }

	    // Mark as logged for this cart state
	    WC()->session->set($log_key, true);

	    // Perform the actual logging
	    if (!empty($context)) {
	        $this->log_info($message, $context);
	    } else {
	        $this->log_info($message);
	    }
	}



	/**
	 * Validate an individual account.
	 *
	 * @param array $account The account data to validate.
	 * @param int $index The index of the account (for error messages).
	 * @return bool|string True if valid, error message if invalid.
	 */
	protected function validate_account($account, $index)
	{
		$is_empty = empty($account['title']) && empty($account['sandbox_public_key']) && empty($account['sandbox_secret_key']) && empty($account['live_public_key']) && empty($account['live_secret_key']);
		$is_filled = !empty($account['title']) && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']) && !empty($account['live_public_key']) && !empty($account['live_secret_key']);

		if (!$is_empty && !$is_filled) {
			/* Translators: %d is the keys are valid or leave empty.*/
			return sprintf(__('Account %d is invalid. Please fill all fields or leave the account empty.', 'bytenft-transak-payment-gateway'), $index + 1);
		}

		return true;
	}

	/**
	 * Validate all accounts.
	 *
	 * @param array $accounts The list of accounts to validate.
	 * @return bool|string True if valid, error message if invalid.
	 */
	protected function validate_accounts($accounts)
	{
		$valid_accounts = [];
		$errors = [];

		foreach ($accounts as $index => $account) {
			// Check if the account is completely empty
			$is_empty = empty($account['title']) && empty($account['sandbox_public_key']) && empty($account['sandbox_secret_key']) && empty($account['live_public_key']) && empty($account['live_secret_key']);

			// Check if the account is completely filled
			$is_filled = !empty($account['title']) && !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']) && !empty($account['live_public_key']) && !empty($account['live_secret_key']);

			// If the account is neither empty nor fully filled, it's invalid
			if (!$is_empty && !$is_filled) {
				/* Translators: %d is the keys are valid or leave empty.*/
				$errors[] = sprintf(__('Account %d is invalid. Please fill all fields or leave the account empty.', 'bytenft-transak-payment-gateway'), $index + 1);
			} elseif ($is_filled) {
				// If the account is fully filled, add it to the valid accounts array
				$valid_accounts[] = $account;
			}
		}

		// If there are validation errors, return them
		if (!empty($errors)) {
			return ['errors' => $errors, 'valid_accounts' => $valid_accounts];
		}

		// If no errors, return the valid accounts
		return ['valid_accounts' => $valid_accounts];
	}

	private function get_cached_api_response($url, $data, $cache_key, $ttl = 120, $force_refresh = false)
	{
	    // Allow ?refresh_accounts=1&_wpnonce=... in URL to force-refresh cache (useful for testing)
		if (
		    !$force_refresh &&
		    isset($_GET['refresh_accounts']) &&
		    $_GET['refresh_accounts'] === '1' &&
		    isset($_GET['_wpnonce']) &&
		    wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'refresh_accounts_nonce')
		) {
		    $force_refresh = true;
		}

	    // If not forcing refresh, return cached version if it exists
	    if (!$force_refresh) {
	        $cached_response = get_transient($cache_key);
	        if ($cached_response !== false) {
	            return $cached_response;
	        }
	    } else {
	        delete_transient($cache_key); // Clear previous cached version
	    }

	    // Make the API call
	    $response = wp_remote_post($url, [
	        'method'  => 'POST',
	        'timeout' => 30,
	        'body'    => $data,
	        'headers' => [
	            'Content-Type'  => 'application/x-www-form-urlencoded',
	            'Authorization' => 'Bearer ' . $data['api_public_key'],
	        ],
	        'sslverify' => true,
	    ]);

	    if (is_wp_error($response)) {
	        return ['status' => 'error', 'message' => $response->get_error_message()];
	    }

	    $response_body = wp_remote_retrieve_body($response);
	    $response_data = json_decode($response_body, true);

	    // Cache the response
	    set_transient($cache_key, $response_data, $ttl);

	    return $response_data;
	}



	private function get_all_accounts()
	{
	    $accounts = get_option('woocommerce_bnfttransak_payment_gateway_accounts', []);

	    if (is_string($accounts)) {
	        $unserialized = maybe_unserialize($accounts);
	        $accounts = is_array($unserialized) ? $unserialized : [];
	        wc_get_logger()->debug(
			    'Unserialized accounts.',
			    [
			        'source'  => 'bytenft-transak-payment-gateway',
			        'context' => [
			            'accounts' => $accounts,
			        ],
			    ]
			);

	    }

	    $valid_accounts = [];

	    foreach ($accounts as $i => $account) {

	        if ($this->sandbox) {
	            $status = strtolower($account['sandbox_status'] ?? '');
	            $has_keys = !empty($account['sandbox_public_key']) && !empty($account['sandbox_secret_key']);
	    
	            if ($status === 'active' && $has_keys) {
	                $valid_accounts[] = $account;
	            }
	        } else {
	            $status = strtolower($account['live_status'] ?? '');
	            $has_keys = !empty($account['live_public_key']) && !empty($account['live_secret_key']);
	            if ($status === 'active' && $has_keys) {
	                $valid_accounts[] = $account;
	            }
	        }
	    }

	    $this->accounts = $valid_accounts;
	    return $valid_accounts;
	}


	function bnfttransak_enqueue_admin_styles($hook)
	{
		// Load only on WooCommerce settings pages
		if (strpos($hook, 'woocommerce') === false) {
			return;
		}

		wp_enqueue_style('bnfttransak-admin-style', plugin_dir_url(__FILE__) . 'assets/css/admin-style.css', [], '1.0.0');
	}

	/**
	 * Send an email notification via byteNFT API
	 */
	private function send_account_switch_email($oldAccount, $newAccount)
	{
		$tytenfttransakApiUrl = $this->get_api_url('/api/switch-account-email'); // byteNFT API Endpoint

		// Use the credentials of the old (current) account to authenticate
		$api_key = $this->sandbox ? $oldAccount['sandbox_public_key'] : $oldAccount['live_public_key'];
		$api_secret = $this->sandbox ? $oldAccount['sandbox_secret_key'] : $oldAccount['live_secret_key'];

		// Prepare data for API request
		$emailData = [
			'old_account' => [
				'title' => $oldAccount['title'],
				'secret_key' => $api_secret,
			],
			'new_account' => [
				'title' => $newAccount['title'],
			],
			'message' => "Payment processing account has been switched. Please review the details.",
		];
		$emailData['is_sandbox'] = $this->sandbox;

		// API request headers using old account credentials
		$headers = [
			'Content-Type' => 'application/json',
			'Authorization' => 'Bearer ' . sanitize_text_field($api_key),
		];

		// Send data to byteNFT API
		$response = wp_remote_post($tytenfttransakApiUrl, [
			'method' => 'POST',
			'timeout' => 30,
			'body' => json_encode($emailData),
			'headers' => $headers,
			'sslverify' => true,
		]);

		// Handle API response
		if (is_wp_error($response)) {
			wc_get_logger()->error('Failed to send switch email: ' . $response->get_error_message(), ['source' => 'bytenft-transak-payment-gateway']);
			return false;
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);
		$response_data = json_decode($response_body, true);

		// Check if authentication failed
		if ($response_code == 401 || $response_code == 403 || (!empty($response_data['error']) && strpos($response_data['error'], 'invalid credentials') !== false)) {
			wc_get_logger()->error('Email Sending Failed : Authentication failed: Invalid API key or secret for old account', ['source' => 'bytenft-transak-payment-gateway']);
			return false; // Stop further execution
		}

		// Check if the API response has errors
		if (!empty($response_data['error'])) {
			wc_get_logger()->error('byteNFT API Error: ' . json_encode($response_data), ['source' => 'bytenft-transak-payment-gateway']);
			return false;
		}

		wc_get_logger()->info("Switch email successfully sent to: '{$oldAccount['title']}'", ['source' => 'bytenft-transak-payment-gateway']);
		return true;
	}

	/**
	 * Get the next available payment account, handling concurrency.
	 */
	private function get_next_available_account($used_accounts = [])
	{
		global $wpdb;

		// Fetch all accounts ordered by priority
		$settings = get_option('woocommerce_bnfttransak_payment_gateway_accounts', []);

		if (is_string($settings)) {
			$settings = maybe_unserialize($settings);
		}

		if (!is_array($settings)) {
			return false;
		}

		$mode = $this->sandbox ? 'sandbox' : 'live';
		$status_key = $mode . '_status';
		$public_key = $mode . '_public_key';
		$secret_key = $mode . '_secret_key';

		// Filter out used accounts and check correct mode status & keys
		$available_accounts = array_filter($settings, function ($account) use ($used_accounts, $status_key, $public_key, $secret_key) {
			return !in_array($account[$public_key], $used_accounts, true)
				&& isset($account[$status_key]) && ($account[$status_key] === 'active' || $account[$status_key] === 'Active')
				&& !empty($account[$public_key]) && !empty($account[$secret_key]);
		});


		if (empty($available_accounts)) {
			return false;
		}

		// Sort by priority (lower number = higher priority)
		usort($available_accounts, function ($a, $b) {
			return $a['priority'] <=> $b['priority'];
		});

		// Concurrency Handling: Lock the selected account
		foreach ($available_accounts as $account) {
			$sanitized_title = preg_replace('/\s+/', '_', $account['title']);
			$lock_key = "bnfttransak_lock_{$sanitized_title}";

			// Try to acquire lock
			if ($this->acquire_lock($lock_key)) {
				$account['lock_key'] = $lock_key;
				return $account;
			}
		}

		return false;
	}

	/**
	 * Acquire a lock to prevent concurrent access to the same account.
	 */
	private function acquire_lock($lock_key)
	{
	    $lock_timeout = 500; // 5 minutes
	    $now = time();

	    $existing_lock = get_option($lock_key);
	    if ($existing_lock && intval($existing_lock) > $now) {
	        // Lock already held by another process
	        wc_get_logger()->info("Lock already held for '{$lock_key}', skipping.", ['source' => 'bytenft-transak-payment-gateway']);
	        return false;
	    }

	    // Lock is expired or doesn't exist, acquire it
	    $lock_value = $now + $lock_timeout;
	    update_option($lock_key, $lock_value, false);

	    wc_get_logger()->info("Lock acquired for '{$lock_key}'", ['source' => 'bytenft-transak-payment-gateway']);
	    return true;
	}


	/**
	 * Release a lock after payment processing is complete.
	 */
	private function release_lock($lock_key)
	{
		// Delete the lock entry using WordPress options API
		delete_option($lock_key);

		// Log the release of the lock
		wc_get_logger()->info("Released lock for '{$lock_key}'", ['source' => 'bytenft-transak-payment-gateway']);
	}


	function check_for_sql_injection()
	{

		$sql_injection_patterns = ['/\b(SELECT|INSERT|UPDATE|DELETE|DROP|ALTER)\b(?![^{}]*})/i', '/(\-\-|\#|\/\*|\*\/)/i', '/(\b(AND|OR)\b\s*\d+\s*[=<>])/i'];

		$errors = []; // Store multiple errors

		// Get checkout fields dynamically
		$checkout_fields = WC()
			->checkout()
			->get_checkout_fields();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce checkout nonce
		foreach ($_POST as $key => $value) {
			if (is_string($value)) {
				foreach ($sql_injection_patterns as $pattern) {
					if (preg_match($pattern, $value)) {
						// Get the field label dynamically
						$field_label = isset($checkout_fields['billing'][$key]['label'])
							? $checkout_fields['billing'][$key]['label']
							: (isset($checkout_fields['shipping'][$key]['label'])
								? $checkout_fields['shipping'][$key]['label']
								: (isset($checkout_fields['account'][$key]['label'])
									? $checkout_fields['account'][$key]['label']
									: (isset($checkout_fields['order'][$key]['label'])
										? $checkout_fields['order'][$key]['label']
										: ucfirst(str_replace('_', ' ', $key)))));

						// Log error for debugging
						$ip_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
						wc_get_logger()->info(
							"Potential SQL Injection Attempt - Field: $field_label, Value: $value, IP: {$ip_address}",
							['source' => 'bytenft-transak-payment-gateway']
						);
						// This comment must be directly above the i18n function call with no blank line
						/* translators: %s is the field label, like "Email Address" or "Username". */
						$errors[] = sprintf(esc_html__('Please enter a valid "%s".', 'bytenft-transak-payment-gateway'), $field_label);
						break; // Stop checking other patterns for this field
					}
				}
			}
		}

		// Display all collected errors at once
		if (!empty($errors)) {
			foreach ($errors as $error) {
				wc_add_notice($error, 'error');
			}
			return false;
		}

		return true;
	}
}
