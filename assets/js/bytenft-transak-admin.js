jQuery(document).ready(function ($) {

	if (typeof bnfttransak_admin_data === 'undefined' || typeof bnfttransak_admin_data.gateway_id === 'undefined') {
		console.error('bnfttransak_admin_data or bnfttransak_admin_data.gateway_id is not defined. Please ensure wp_localize_script is correctly set up.');
		return; // Exit if the required object is not available
	}

	// Get the payment method ID from the localized object
	var gatewayId = bnfttransak_admin_data.gateway_id;
	var formClass = gatewayId + '-gateway-settings-form';
	var gatewaySettingsForm = $('form#mainform'); // Common ID for WooCommerce settings forms

	if (gatewaySettingsForm.length && gatewaySettingsForm.find('input[name^="woocommerce_' + gatewayId + '_"]').length) {
		gatewaySettingsForm.addClass(gatewayId + '-gateway-settings-form');

		// Automatically run sync when the page loads
		runAccountSync(gatewayId);

		// Helper: Show error next to input or target container
		function showErrorMessage(inputField, message) {
			const $input = $(inputField);
			
			$input.addClass("error");

			// Remove existing error message near this input
			$input.siblings(".error-message").remove();

			// Create and insert error message after input
			const errorMessage = $("<div>").addClass("error-message").text(message);
			$input.after(errorMessage);
		}

		// Helper: Clear all error states/messages
		function clearErrorMessages() {
			$(".error-message").remove();
			$(".error").removeClass("error");
		}

		if (typeof gatewayId === 'string' && gatewayId.trim()) {
			const gateway_id = gatewayId.trim();

			// Selector class helpers
			const accountClass = `.${gateway_id}-account`;
			const addAccountBtnClass = `.${gateway_id}-add-account`;
			const containerClass = `.${gateway_id}-accounts-container`;
			const deleteBtnClass = `.delete-account-btn`;
			const nameDisplayClass = `.${gateway_id}-name-display`;
			const toggleBtnClass = `.${gateway_id}-toggle-btn`;
			const accountInfoClass = `.${gateway_id}-info`;
			const sandboxCheckboxClass = `.${gateway_id}-sandbox-checkbox`;

			/**
			 * Toggle Sandbox Keys visibility
			 */
			$(document).on("change", sandboxCheckboxClass, function () {
				const $account = $(this).closest(accountClass);
				const $sandboxContainer = $account.find(`.${gateway_id}-sandbox-keys`);
				$sandboxContainer.toggle($(this).is(":checked"));
			});

			/**
			 * Update dynamic index-based input names
			 */
			function updateAccountIndices() {
				$(accountClass).each(function (index) {
					$(this).attr("data-index", index);
					$(this).find("input, select").each(function () {
						let name = $(this).attr("name");
						if (name) {
							name = name.replace(/\[.*?\]/, "[" + index + "]");
							$(this).attr("name", name);
						}
					});
				});
			}

			/**
			 * Delete account logic
			 */
			$(document).on("click", deleteBtnClass, function () {
				const $accounts = $(accountClass);

				// Remove any existing error message
				$(".delete-account-error").remove();

				if ($accounts.length === 1) {
					$accounts.first().before(`<div class="delete-account-error" style="color: red; margin-bottom: 10px;">At least one account must be present.</div>`);
					return;
				}

				const $account = $(this).closest(accountClass);
				$account.find("input").attr("name", ""); // Prevent submission
				$account.remove();
				updateAccountIndices();
			});

			/**
			 * Add new account block
			 */
			$(document).on("click", addAccountBtnClass, function () {
				const newAccountHtml = `
					<div class="${gateway_id}-account">
						<div class="title-blog">
							<h4>
								<span class="${gateway_id}-name-display account-name-display">Untitled Account</span>
								&nbsp;<i class="fa fa-caret-down ${gateway_id}-toggle-btn" aria-hidden="true"></i>
							</h4>
							<div class="action-button">
								<button type="button" class="delete-account-btn"><i class="fa fa-trash" aria-hidden="true"></i></button>
							</div>
						</div>

						<div class="${gateway_id}-info" style="display: none;">
							<div class="add-blog title-priority">
								<div class="account-input account-name">
									<label>Account Name</label>
									<input type="text" class="${gateway_id}-title account-title" name="accounts[][title]" placeholder="Account Title">
								</div>
								<div class="account-input priority-name">
									<label>Priority</label>
									<input type="number" class="account-priority" name="accounts[][priority]" placeholder="Priority" min="1" value="${$(accountClass).length + 1}">
								</div>
							</div>

							<div class="add-blog ${gateway_id}-production-keys">
								<div class="account-input">
									<label>Live Keys</label>
									<input type="text" class="live-public-key" name="accounts[][live_public_key]" placeholder="Public Key">
								</div>
								<div class="account-input">
									<input type="text" class="live-secret-key" name="accounts[][live_secret_key]" placeholder="Secret Key">
								</div>
							</div>

							<div class="account-checkbox">
								<input type="checkbox" class="${gateway_id}-sandbox-checkbox" name="accounts[][has_sandbox]">
								Do you have the sandbox keys?
							</div>

							<div class="${gateway_id}-sandbox-keys" style="display: none;">
								<div class="add-blog">
									<div class="account-input">
										<label>Sandbox Keys</label>
										<input type="text" class="sandbox-public-key" name="accounts[][sandbox_public_key]" placeholder="Public Key">
									</div>
									<div class="account-input">
										<input type="text" class="sandbox-secret-key" name="accounts[][sandbox_secret_key]" placeholder="Secret Key">
									</div>
								</div>
							</div>
						</div>
					</div>`;

				$(containerClass + " .empty-account").remove(); // Remove placeholder
				$(this).closest(".add-account-btn").before(newAccountHtml);
				updateAccountIndices();
			});

			/**
			 * Toggle account info block
			 */
			$(document).on("click", toggleBtnClass, function () {
				const $info = $(this).closest(accountClass).find(accountInfoClass);
				$info.slideToggle();
				$(this).toggleClass("rotated");
			});

			/**
			 * Live update of account title
			 */
			$(document).on("input", `${accountClass} .account-title`, function () {
				const newTitle = $(this).val().trim() || "Untitled Account";
				$(this).closest(accountClass).find(".account-name-display").text(newTitle);
			});
		}

		// Form submission validation
		$(document).off("submit", "." + formClass).on("submit", "." + formClass, function (event) {
			clearErrorMessages(); // Clear previous errors

			let allKeys = new Set();       // For unique key validation
			let prioritySet = new Set();   // For unique priority validation
			let titleSet = new Set();      // For unique title validation
			let hasErrors = false;

			function validateKeyUniqueness(inputField, keyValue, label) {
				if (allKeys.has(keyValue)) {
					showErrorMessage(inputField, `${label} must be unique across all accounts and key types.`);
					hasErrors = true;
				} else {
					allKeys.add(keyValue);
				}
			}

			// Validate gateway title
			const globalTitleField = $('#woocommerce_' + $.escapeSelector(gatewayId) + '_title');
			const globalTitleVal = globalTitleField.val()?.trim() || '';
			if (!globalTitleVal) {
				showErrorMessage(globalTitleField, "Enter a name for this payment method (shown to customers at checkout).");
				hasErrors = true;
			}

			// Validate gateway description
			const globalDescField = $('#woocommerce_' + $.escapeSelector(gatewayId) + '_description');
			const globalDescVal = globalDescField.val()?.trim() || '';
			if (!globalDescVal) {
				showErrorMessage(globalDescField, "Add a brief description shown to customers at checkout.");
				hasErrors = true;
			}

			$("." + gatewayId + "-account").each(function () {
				const $account = $(this);

				const livePublicKey = $account.find(".live-public-key");
				const liveSecretKey = $account.find(".live-secret-key");
				const sandboxPublicKey = $account.find(".sandbox-public-key");
				const sandboxSecretKey = $account.find(".sandbox-secret-key");
				const sandboxCheckbox = $account.find("." + gatewayId + "-sandbox-checkbox");
				const title = $account.find(".account-title");
				const priority = $account.find(".account-priority");

				const titleVal = title.val()?.trim() || '';
				const priorityVal = priority.val()?.trim() || '';
				const livePublicKeyVal = livePublicKey.val()?.trim() || '';
				const liveSecretKeyVal = liveSecretKey.val()?.trim() || '';
				const sandboxPublicKeyVal = sandboxPublicKey.val()?.trim() || '';
				const sandboxSecretKeyVal = sandboxSecretKey.val()?.trim() || '';

				// Title required and unique
				if (!titleVal) {
					showErrorMessage(title, "Title is required.");
					hasErrors = true;
				} else if (titleSet.has(titleVal)) {
					showErrorMessage(title, "Title must be unique.");
					hasErrors = true;
				} else {
					titleSet.add(titleVal);
				}

				// Priority required and unique
				if (!priorityVal) {
					showErrorMessage(priority, "Priority is required.");
					hasErrors = true;
				} else if (prioritySet.has(priorityVal)) {
					showErrorMessage(priority, "Priority must be unique.");
					hasErrors = true;
				} else {
					prioritySet.add(priorityVal);
				}

				// Live keys required
				if (!livePublicKeyVal) {
					showErrorMessage(livePublicKey, "Live Public Key is required.");
					hasErrors = true;
				}
				if (!liveSecretKeyVal) {
					showErrorMessage(liveSecretKey, "Live Secret Key is required.");
					hasErrors = true;
				}

				// Sandbox keys required (only if checkbox checked)
				const sandboxRequired = sandboxCheckbox.is(":checked");
				if (sandboxRequired) {
					if (!sandboxPublicKeyVal) {
						showErrorMessage(sandboxPublicKey, "Sandbox Public Key is required.");
						hasErrors = true;
					}
					if (!sandboxSecretKeyVal) {
						showErrorMessage(sandboxSecretKey, "Sandbox Secret Key is required.");
						hasErrors = true;
					}
				}

				// Global uniqueness across keys
				if (livePublicKeyVal) {
					validateKeyUniqueness(livePublicKey, livePublicKeyVal, "Live Public Key");
				}
				if (liveSecretKeyVal) {
					validateKeyUniqueness(liveSecretKey, liveSecretKeyVal, "Live Secret Key");
				}
				if (sandboxPublicKeyVal) {
					validateKeyUniqueness(sandboxPublicKey, sandboxPublicKeyVal, "Sandbox Public Key");
				}
				if (sandboxSecretKeyVal) {
					validateKeyUniqueness(sandboxSecretKey, sandboxSecretKeyVal, "Sandbox Secret Key");
				}

				// Same-account key duplication checks
				if (livePublicKeyVal && liveSecretKeyVal && livePublicKeyVal === liveSecretKeyVal) {
					showErrorMessage(liveSecretKey, "Live Secret Key must be different from Live Public Key.");
					hasErrors = true;
				}
				if (sandboxPublicKeyVal && sandboxSecretKeyVal && sandboxPublicKeyVal === sandboxSecretKeyVal) {
					showErrorMessage(sandboxSecretKey, "Sandbox Public Key and Sandbox Secret Key must be different.");
					hasErrors = true;
				}

				// Cross-type key collision (e.g., live vs sandbox)
				if (livePublicKeyVal && sandboxPublicKeyVal && livePublicKeyVal === sandboxPublicKeyVal) {
					showErrorMessage(sandboxPublicKey, "Live Public Key and Sandbox Public Key must be different.");
					hasErrors = true;
				}
				if (liveSecretKeyVal && sandboxSecretKeyVal && liveSecretKeyVal === sandboxSecretKeyVal) {
					showErrorMessage(sandboxSecretKey, "Live Secret Key and Sandbox Secret Key must be different.");
					hasErrors = true;
				}
			});

			// Final form blocking check
			if (hasErrors) {
				console.log("Form blocked due to validation errors.");
				event.preventDefault();
				$(this).find('[type="submit"]').removeClass('is-busy');
			} else {
				console.log("Form passed validation.");
			}
		});


		// need to check below
		function runAccountSync(gatewayId) {
			if (typeof gatewayId !== 'string' || !gatewayId.trim()) return;

			const id = gatewayId.trim();

			const $button = $(`#${id}-sync-accounts`);
			const $status = $(`#${id}-sync-status`);
			const originalButtonText = $button.text();

			// Set loading state
			$button.prop('disabled', true).html('<span class="spinner is-active" style="float: none; margin: 0;"></span> Syncing...');
			$status.removeClass('error success').text('Syncing accounts...').show();

			$.ajax({
				url: bnfttransak_admin_data.ajax_url,
				method: 'POST',
				dataType: 'json',
				data: {
					action: `${id}_manual_sync`,
					nonce: bnfttransak_admin_data.nonce
				},
				success: function (response) {
					if (response.success) {
						$status
							.removeClass('error')
							.addClass('success')
							.text(response.data.message || 'Sync completed successfully!')
							.fadeIn()
							.delay(4000)
							.fadeOut();

						// Update statuses (pass gateway ID if needed)
						if (typeof updateAccountStatuses === 'function') {
							updateAccountStatuses(response.data.statuses, id);
						}
					} else {
						$status
							.removeClass('success')
							.addClass('error')
							.text(response.data.message || 'Sync failed. Please try again.')
							.fadeIn()
							.delay(4000)
							.fadeOut();
					}
				},
				error: function (xhr, status, error) {
					let errorMessage = 'AJAX Error: ';
					if (xhr.responseJSON?.data?.message) {
						errorMessage += xhr.responseJSON.data.message;
					} else {
						errorMessage += error;
					}
					$status.addClass('error').text(errorMessage);
				},
				complete: function () {
					$button.prop('disabled', false).text(originalButtonText);
				}
			});
		}

		$('#'+gatewayId+'-sync-accounts').on('click', function (e) {
			e.preventDefault();
			runAccountSync(gatewayId);
		});

		// For the sandbox toggle (if it's a checkbox or select)
		$('#woocommerce_'+gatewayId+'_sandbox').on('change', function () {
			runAccountSync(gatewayId);
		});

		// Function to update all account statuses
		function updateAccountStatuses(statuses) {
			if (!Array.isArray(statuses)) return;

			statuses.forEach(function (statusItem) {
				var accountTitle = statusItem.title;
				var mode = statusItem.mode;
				var newStatus = statusItem.status;

				// Loop through all accounts to find matching title
				$('.'+gatewayId+'-account').each(function () {
					var $account = $(this);
					var currentTitle = $.trim($account.find('.account-title').val());

					if (currentTitle === accountTitle) {
						// Update hidden inputs
						if (mode === 'live') {
							$account.find('.live-status').val(newStatus);
						} else if (mode === 'sandbox') {
							$account.find('.sandbox-status').val(newStatus);
						}

						// Update visible label
						var sandboxEnabled = $('#woocommerce_'+gatewayId+'_sandbox').is(':checked'); // <-- Updated
						var statusLabel = $account.find('.account-status-label');

						// Tooltip content based on status
						const statusTooltips = {
							active: 'The account is valid and ready to use.',
							inactive: 'The account is currently inactive. Please check your settings.',
							invalid: 'The account credentials are incorrect or incomplete.',
							unknown: 'The account status could not be determined.',
						};

						// Determine tooltip content
						const tooltipText = statusTooltips[newStatus.toLowerCase()] || '';

						if ((sandboxEnabled && mode === 'sandbox') || (!sandboxEnabled && mode === 'live')) {
							statusLabel
								.removeClass('active inactive invalid unknown')
								.addClass(newStatus.toLowerCase())
								.attr('title', tooltipText)
								.text((mode === 'sandbox' ? 'Sandbox Account Status: ' : 'Live Account Status: ') + capitalize(newStatus));
						}
					}
				});
			});
		}

		function capitalize(text) {
			if (!text) return '';
			return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
		}

		// ✅ Correct: Run sync again when sandbox toggle is changed
		$('#woocommerce_'+gatewayId+'_sandbox').on('change', function () {
			runAccountSync(gatewayId);
		});


	} else {
		console.log('Could not identify form for gateway: ' + gatewayId);
	}
});