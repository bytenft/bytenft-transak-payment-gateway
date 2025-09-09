jQuery(function ($) {
	var isSubmitting = false;
	var popupInterval;
	var paymentStatusInterval;
	var orderId;
	var $button;
	var originalButtonText;
	var isPollingActive = false;

	var loaderUrl = bnfttransak_params.bnfttransak_loader ? encodeURI(bnfttransak_params.bnfttransak_loader) : '';
	$('body').append(
		'<div class="bnfttransak-loader-background"></div>' +
		'<div class="bnfttransak-loader"><img src="' + loaderUrl + '" alt="Loading..." /></div>'
	);

	// Prevent default WooCommerce form submission for our method
	$('form.checkout').on('checkout_place_order', function () {
		var selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
		if (selectedPaymentMethod === bnfttransak_params.payment_method) {
			return false;
		}
	});

	// Assign or remove custom form ID based on selected method
	function markCheckoutFormIfNeeded() {
		var $form = $("form.checkout");
		var selectedMethod = $form.find('input[name="payment_method"]:checked').val();
		var expectedId = bnfttransak_params.payment_method + '-checkout-form';

		if (selectedMethod === bnfttransak_params.payment_method) {
			$form.attr('id', expectedId);
		} else {
			// Only remove the ID if it matches ours
			if ($form.attr('id') === expectedId) {
				$form.removeAttr('id');
			}
		}
	}

	function bindCheckoutHandler() {
		var formId = '#' + bnfttransak_params.payment_method + '-checkout-form';
		$(formId).off("submit.bytenft-transak").on("submit.bytenft-transak", function (e) {
			if ($(this).find('input[name="payment_method"]:checked').val() === bnfttransak_params.payment_method) {
				handleFormSubmit.call(this, e);
				return false;
			}
		});
	}

	// Handle WooCommerce hooks
	$(document.body).on("updated_checkout", function () {
		markCheckoutFormIfNeeded();
		bindCheckoutHandler();
	});

	$(document.body).on("change", 'input[name="payment_method"]', function () {
		markCheckoutFormIfNeeded();
		bindCheckoutHandler();
	});

	// Initial binding
	markCheckoutFormIfNeeded();
	bindCheckoutHandler();

	function handleFormSubmit(e) {
		e.preventDefault();
		var $form = $(this);

		if (isSubmitting) {
			console.warn("Checkout already submitting...");
			return false;
		}

		isSubmitting = true;

		var selectedPaymentMethod = $form.find('input[name="payment_method"]:checked').val();
		if (selectedPaymentMethod !== bnfttransak_params.payment_method) {
			isSubmitting = false;
			return true;
		}

		$button = $form.find('button[type="submit"][name="woocommerce_checkout_place_order"]');
		originalButtonText = $button.text();
		$button.prop('disabled', true).text('Processing...');

		$('.bnfttransak-loader-background, .bnfttransak-loader').show();

		var data = $form.serialize();

		$.ajax({
			type: 'POST',
			url: wc_checkout_params.checkout_url,
			data: data,
			dataType: 'json',
			success: function (response) {
				handleResponse(response, $form);
			},
			error: function () {
				handleError($form);
			},
			complete: function () {
				isSubmitting = false;
			},
		});

		return false;
	}

	function openPaymentLink(paymentLink) {
		var sanitizedPaymentLink = encodeURI(paymentLink);
		var width = 700;
		var height = 700;
		var left = window.innerWidth / 2 - width / 2;
		var top = window.innerHeight / 2 - height / 2;
		var popupWindow = window.open(
			sanitizedPaymentLink,
			'paymentPopup',
			'width=' + width + ',height=' + height + ',scrollbars=yes,top=' + top + ',left=' + left
		);

		if (!popupWindow || popupWindow.closed || typeof popupWindow.closed === 'undefined') {
			let popup;
			if (window.innerWidth <= 768) { // mobile breakpoint
				popup = window.open(sanitizedPaymentLink, '_blank');
			} else {
				popup = window.open(
					sanitizedPaymentLink,
					'paymentPopup',
					'width=600,height=700,scrollbars=yes,resizable=yes'
				);
			}
			resetButton();
		} else {
			popupInterval = setInterval(function () {
				if (popupWindow.closed) {
					clearInterval(popupInterval);
					clearInterval(paymentStatusInterval);
					isPollingActive = false;

					$.ajax({
						type: 'POST',
						url: bnfttransak_params.ajax_url,
						data: {
							action: 'bnfttransak_popup_closed_event',
							order_id: orderId,
							security: bnfttransak_params.bnfttransak_nonce,
						},
						dataType: 'json',
						success: function (response) {
							if (response.success && response.data.redirect_url) {
								window.location.href = response.data.redirect_url;
							}
						},
						complete: function () {
							resetButton();
						}
					});
				}
			}, 500);

			if (!isPollingActive) {
				isPollingActive = true;
				paymentStatusInterval = setInterval(function () {
					$.ajax({
						type: 'POST',
						url: bnfttransak_params.ajax_url,
						data: {
							action: 'bnfttransak_check_payment_status',
							order_id: orderId,
							security: bnfttransak_params.bnfttransak_nonce,
						},
						dataType: 'json',
						success: function (statusResponse) {
							if (['success', 'failed', 'cancelled'].includes(statusResponse.data.status)) {
								clearInterval(paymentStatusInterval);
								clearInterval(popupInterval);
								isPollingActive = false;

								try {
									if (popupWindow && !popupWindow.closed) {
										popupWindow.close();
									}
								} catch (e) {
									console.warn('Unable to close popup window:', e);
								}

								if (statusResponse.data.redirect_url) {
									window.location.href = statusResponse.data.redirect_url;
								}
							}
						}
					});
				}, 5000);
			}
		}
	}

	function handleResponse(response, $form) {
		$('.bnfttransak-loader-background, .bnfttransak-loader').hide();
		$('.wc_er').remove();

		try {
			if (response.result === 'success') {
				orderId = response.order_id;
				var paymentLink = response.payment_link;
				openPaymentLink(paymentLink);
				$form.removeAttr('data-result').removeAttr('data-redirect-url');
			} else {
				throw response.messages || 'An error occurred during checkout.';
			}
		} catch (err) {
			displayError(err, $form);
		}
	}

	function handleError($form) {
		$('.wc_er').remove();
		$form.prepend('<div class="wc_er">An error occurred during checkout. Please try again.</div>');
		$('html, body').animate({ scrollTop: $('.wc_er').offset().top - 300 }, 500);
		resetButton();
	}

	function displayError(err, $form) {
		$('.wc_er').remove();
		$form.prepend('<div class="wc_er">' + err + '</div>');
		$('html, body').animate({ scrollTop: $('.wc_er').offset().top - 300 }, 500);
		resetButton();
	}

	function resetButton() {
		isSubmitting = false;
		if ($button) {
			$button.prop('disabled', false).text(originalButtonText);
		}
		$('.bnfttransak-loader-background, .bnfttransak-loader').hide();
	}
});
