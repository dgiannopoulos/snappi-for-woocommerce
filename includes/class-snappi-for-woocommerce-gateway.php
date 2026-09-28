<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WC_Snappi_Gateway extends WC_Payment_Gateway {

	const UAT_URL        = 'https://merchantbnpl.snappibank.com.gr';
	const PRODUCTION_URL = 'https://merchantbnplapi.snappibank.com';

	public function __construct() {
		$icon = Snappi_Pay_Later::plugin_url().'/assets/img/snappi_logo.png';
		$this->id = 'snappi_gateway';
		$this->order_button_text = __( 'Proceed for payment', 'snappi-for-woocommerce' );
		$this->icon = apply_filters( 'snappi_gateway_icon', $icon );
		$this->has_fields = false;
		$this->method_description = __('Shop now and pay in 4 installments. No interest, no credit card, no hidden fees. Enjoy the flexibility and safety of Snappi, the 1st Greek EU-licensed neobank.', 'snappi-for-woocommerce');
		$this->method_title = __('Snappi Pay Later','snappi-for-woocommerce');
		$this->supports           = array('products','subscriptions');

		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option('title');
		$this->description = $this->get_option('description');

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_wc_'.$this->id, array($this, 'check_snappi_response'));
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
	}

	public function admin_options() {
		echo '<h3>' . __('Snappi Pay Later', 'snappi-for-woocommerce') . '</h3>';
		echo '<p>' . __('Shop now and pay in 4 installments. No interest, no credit card, no hidden fees. Enjoy the flexibility and safety of Snappi, the 1st Greek EU-licensed neobank.', 'snappi-for-woocommerce') . '</p>';

		echo '<p>' . esc_html__( 'Register these two URLs in the Snappi portal exactly as shown:', 'snappi-for-woocommerce' ) . '</p>';
		echo '<p><code>' . esc_html( $this->get_callback_url( 'success' ) ) . '</code><br />';
		echo '<code>' . esc_html( $this->get_callback_url( 'fail' ) ) . '</code></p>';

		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $this->get_option( 'instructions' ) && ! $sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status( 'processing' ) ) {
			echo wp_kses_post( wpautop( wptexturize( $this->get_option( 'instructions' ) ) ) . PHP_EOL );
		}
	}

	function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title' => __('Enable/Disable', 'snappi-for-woocommerce'),
				'type' => 'checkbox',
				'label' => __('Enable Snappi Pay Later', 'snappi-for-woocommerce'),
				'description' => __('Enable or disable the gateway.', 'snappi-for-woocommerce'),
				'desc_tip' => true,
				'default' => 'yes'
			),
			'title' => array(
				'title' => __('Title', 'snappi-for-woocommerce'),
				'type' => 'text',
				'description' => __('This controls the title which the user sees during checkout.', 'snappi-for-woocommerce'),
				'default' => __('Snappi Pay Later', 'snappi-for-woocommerce'),
				'desc_tip' => true
			),
			'description' => array(
				'title' => __('Description', 'snappi-for-woocommerce'),
				'type' => 'textarea',
				'description' => __('This controls the description which the user sees during checkout.', 'snappi-for-woocommerce'),
				'default' => __('Shop now and pay in 4 installments. No interest, no credit card, no hidden fees. Enjoy the flexibility and safety of Snappi, the 1st Greek EU-licensed neobank.', 'snappi-for-woocommerce'),
				'desc_tip' => true,
			),
			'instructions' => array(
				'title'       => __( 'Instructions', 'snappi-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Instructions that will be added to the thank you page and emails.', 'snappi-for-woocommerce' ),
				'default'     => '', // Empty by default
				'desc_tip'    => true,
			),
			'applicationID' => array(
				'title' => __('Application ID', 'snappi-for-woocommerce'),
				'type' => 'text',
				'description' => __('Enter your Snappi Application ID', 'snappi-for-woocommerce'),
				'default' => '',
				'desc_tip' => true
			),
			'subscriptionKey' => array(
				'title' => __('Subscription Key', 'snappi-for-woocommerce'),
				'type' => 'text',
				'description' => __('Enter your Snappi Subscription Key', 'snappi-for-woocommerce'),
				'default' => '',
				'desc_tip' => true
			),
			'enforce_verification_key' => array(
				'title' => __('Callback security key', 'snappi-for-woocommerce'),
				'type' => 'checkbox',
				'label' => __('Reject payment callbacks that do not carry this site\'s security key', 'snappi-for-woocommerce'),
				'description' => __('Enable this only after the callback URLs shown above have been saved in the Snappi portal, otherwise every payment confirmation will be rejected.', 'snappi-for-woocommerce'),
				'default' => 'no'
			),
			'verification_key' => array(
				'title' => __('Security key', 'snappi-for-woocommerce'),
				'type' => 'text',
				'description' => __('Generated automatically. Changing it requires updating the callback URLs in the Snappi portal.', 'snappi-for-woocommerce'),
				'default' => '',
				'desc_tip' => true,
				'custom_attributes' => array( 'readonly' => 'readonly' ),
			),
			'api_environment' => array(
				'title' => __('API environment', 'snappi-for-woocommerce'),
				'type' => 'select',
				'label' => __('Environment', 'snappi-for-woocommerce'),
				'description' => __('This control enables test or live API environment', 'snappi-for-woocommerce'),
				'desc_tip' => true,
				'options'     => array(
					'sandbox' => __( 'UAT', 'snappi-for-woocommerce' ),
					'live' => __( 'Production', 'snappi-for-woocommerce' ),
				),
				'default'     => 'sandbox',
			)
		);
	}

	/**
	 * Per-site secret embedded in the callback URLs registered with Snappi.
	 *
	 * Snappi substitutes {orderIdentifier} in a URL template the merchant configures and passes
	 * everything else through verbatim without re-encoding (confirmed 2026-09-28), so a secret we
	 * put in that template comes back with every callback. Without it, anyone who can obtain an
	 * orderIdentifier can mark an order paid.
	 */
	protected function get_verification_key() {
		$key = $this->get_option( 'verification_key' );

		if ( empty( $key ) ) {
			$key = wp_generate_password( 32, false, false );
			$this->update_option( 'verification_key', $key ); // also refreshes $this->settings.
		}

		return $key;
	}

	protected function get_callback_url( $action ) {
		$base = add_query_arg(
			array(
				'action' => $action,
				'k'      => $this->get_verification_key(),
			),
			WC()->api_request_url( 'WC_Snappi_Gateway' )
		);

		// Appended raw: Snappi substitutes the placeholder, so it must not be URL-encoded.
		return $base . '&id={orderIdentifier}';
	}

	protected function callback_key_is_valid() {
		if ( 'yes' !== $this->get_option( 'enforce_verification_key', 'no' ) ) {
			return true;
		}

		$supplied = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';

		return '' !== $supplied && hash_equals( $this->get_verification_key(), $supplied );
	}

	protected function check_settings() {
		return !empty($this->get_option('applicationID')) && !empty($this->get_option('subscriptionKey'));
	}

	protected function get_api_url() {
		return $this->get_option('api_environment') === 'sandbox' ? self::UAT_URL : self::PRODUCTION_URL;
	}

	protected function get_api_headers() {
		return array(
			'Accept'                    => 'application/json',
			'X-Application-ID'          => $this->get_option('applicationID'),
			'Ocp-Apim-Subscription-Key' => $this->get_option('subscriptionKey'),
		);
	}

	function process_payment($order_id) {
		$result       = 'failure';
		$redirectLink = '';
		$order        = wc_get_order($order_id);

		try {
			if ( ! $this->check_settings() ) {
				throw new Exception( __( 'Order payment failed. To make a successful payment using Snappi, please review the gateway settings.', 'snappi-for-woocommerce' ) );
			}

			$url     = $this->get_api_url();
			$headers = $this->get_api_headers();

			// Check eligibility
			$response = wp_remote_get("{$url}/merchant/checkbasketeligibilityforbnpl?basketValue=" . floatval($order->get_total()), array(
				'headers' => $headers,
				'timeout' => 10,
			));

			if (is_wp_error($response)) {
				throw new Exception($response->get_error_message());
			}

			$data = json_decode(wp_remote_retrieve_body($response), true);

			if (empty($data['isBNPLEligible'])) {
				throw new Exception(__('Snappi is not available.', 'snappi-for-woocommerce'));
			}

			// Prepare cart items
			$basket_products = array();

			foreach ($order->get_items() as $item_id => $item) {
				$product = $item->get_product();
				if (!$product) continue;

				$unit_price     = floatval($order->get_item_total($item, false, true));
				$tax_amount     = floatval($order->get_item_tax($item));
				$total_price    = floatval($order->get_item_total($item, true, true));
				$tax_percentage = ($unit_price > 0) ? round(($tax_amount / $unit_price) * 100, 2) : 0;

				if ($unit_price<=0) continue;
				$basket_products[] = array(
					"Product Number"        => strval($product->get_id()),
					"name"                  => $product->get_name(),
					"quantity"              => intval($item->get_quantity()),
					"Quantity Units"        => "pcs",
					"Unit Price"            => $unit_price,
					"Tax Rate"              => round($tax_percentage) / 100,
					"Total Amount"          => $total_price,
					"Total Discount Amount" => floatval($order->get_total_discount()),
					"Total Tax Amount"      => floatval($order->get_total_tax()),
					"Product URL"           => esc_url(get_permalink($product->get_id())),
					"Product Image URL"     => esc_url(wp_get_attachment_url($product->get_image_id())),
				);
			}

			// Create basket.
			// The identifier keeps the historic "<order number>-<unix time>" prefix so Snappi-side
			// reconciliation still parses, plus a random alphanumeric suffix: the identifier is the
			// only thing the callback carries, so it must not be derivable from the order number.
			// Snappi confirmed (2026-09-28) there is no length limit and alphanumerics are accepted.
			$orderIdentifier = $order->get_order_number() . "-" . time() . "-" . wp_generate_password( 16, false, false );
			$requestId       = wp_generate_uuid4();
			$headers['Content-Type'] = 'application/json';

			$response = wp_remote_post("{$url}/createbasket", array(
				'headers' => $headers,
				'body'    => json_encode(array(
					"basketValue"     => floatval($order->get_total()),
					"requestId"       => $requestId,
					"orderIdentifier" => $orderIdentifier,
					"basketProducts"  => $basket_products,
					"Merchant Data"   => $order->get_formatted_billing_full_name(),
					"phoneNumber"     => $order->get_billing_phone(),
					"email"           => $order->get_billing_email(),
				)),
				'timeout' => 10,
			));

			if (is_wp_error($response)) {
				throw new Exception($response->get_error_message());
			}

			$response_body = json_decode(wp_remote_retrieve_body($response), true);
			$order->update_meta_data('_snappi_orderIdentifier', $orderIdentifier);
			$order->update_meta_data('_snappi_requestId', $requestId);
			$order->save();

			if (!empty($response_body) && !empty($response_body['redirectUrl'])) {
				// _snappi_requestId used to hold the redirect URL, which made reconciliation by
				// requestId impossible. The URL now lives under its own key; on orders created
				// before 1.0.8 _snappi_requestId still contains an "https://..." value.
				$order->update_meta_data('_snappi_redirectUrl', $response_body['redirectUrl']);
				$order->update_meta_data('_snappi_basketId', isset($response_body['basketId']) ? $response_body['basketId'] : '');
				$order->update_meta_data('_snappi_qrCodeData', isset($response_body['qrCodeData']) ? $response_body['qrCodeData'] : '');
				// Snappi expire the QR after 15 minutes; recorded for support and reconciliation.
				$order->update_meta_data('_snappi_basketCreatedAt', time());
				$order->save();

				$result       = 'success';
				$redirectLink = $response_body['redirectUrl'];
			} else {
				throw new Exception(__('Order payment failed.', 'snappi-for-woocommerce'));
			}
		} catch (\Throwable $th) {
			$result       = 'failure';
			$redirectLink = $this->get_return_url($order);
			wc_add_notice($th->getMessage(), 'error');
		}

		return array(
			'result'   => $result,
			'redirect' => $redirectLink,
		);
	}

	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		if ( ! is_checkout() ) {
			return false;
		}

		if ( WC()->cart === null ) {
			return false;
		}

		if ( ! $this->check_settings() ) {
			return false;
		}

		$basket_value = floatval(WC()->cart->get_total('edit'));
		$cache_key    = 'snappi_eligible_' . md5($basket_value . '_' . $this->get_option('api_environment'));
		$cached       = get_transient($cache_key);

		if ($cached !== false) {
			return $cached === 'yes';
		}

		try {
			$response = wp_remote_get($this->get_api_url() . "/merchant/checkbasketeligibilityforbnpl?basketValue=" . $basket_value, array(
				'headers' => $this->get_api_headers(),
				'timeout' => 5,
			));

			if (is_wp_error($response)) {
				return false;
			}

			$data     = json_decode(wp_remote_retrieve_body($response), true);
			$eligible = !empty($data['isBNPLEligible']);

			set_transient($cache_key, $eligible ? 'yes' : 'no', 10 * MINUTE_IN_SECONDS);

			return $eligible;
		} catch (\Throwable $th) {
			return false;
		}
	}

	/**
	 * Is this order one that legitimately opened a Snappi basket and is still waiting for it?
	 *
	 * @param WC_Order $order Order to test.
	 * @return bool
	 */
	protected function order_awaits_snappi_payment( $order ) {
		if ( $this->id !== $order->get_payment_method() ) {
			return false;
		}

		if ( '' === (string) $order->get_meta( '_snappi_basketId' ) ) {
			return false;
		}

		// Mirror WooCommerce's own list rather than inventing one. It includes 'cancelled' on
		// purpose: "Hold stock (minutes)" auto-cancels unpaid pending orders, and a customer
		// paying by QR on their phone can cross that window. Rejecting those would leave a paid
		// order cancelled.
		$valid_statuses = apply_filters(
			'woocommerce_valid_order_statuses_for_payment_complete',
			array( 'on-hold', 'pending', 'failed', 'cancelled' ),
			$order
		);

		return $order->has_status( $valid_statuses );
	}

	/**
	 * Resolve the order that a Snappi orderIdentifier belongs to.
	 *
	 * Deliberately a direct $wpdb lookup. wc_get_orders()/WP_Query meta filters are NOT reliable
	 * here:
	 *   - the legacy (post-based) order data store silently DROPS `meta_query`
	 *     (WC_Data_Store_WP::get_wp_query_args() skips the key; WC >= 9.2 also fires
	 *     wc_doing_it_wrong "Order query argument (meta_query) is not supported"), so the query
	 *     degrades to "the newest orders of the whole shop";
	 *   - a theme/plugin on pre_get_posts that calls $query->set( 'meta_query', ... ) replaces
	 *     ours outright.
	 * Either way an unrelated order would be handed to a code path that completes a payment.
	 *
	 * @param string $identifier Value of _snappi_orderIdentifier.
	 * @return WC_Order|false
	 */
	protected function get_order_by_identifier( $identifier ) {
		global $wpdb;

		$identifier = (string) $identifier;

		if ( '' === $identifier ) {
			return false;
		}

		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$order_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_snappi_orderIdentifier' AND meta_value = %s ORDER BY order_id DESC LIMIT 1",
					$identifier
				)
			);
		} else {
			$order_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_snappi_orderIdentifier' AND meta_value = %s ORDER BY post_id DESC LIMIT 1",
					$identifier
				)
			);
		}

		if ( empty( $order_id ) ) {
			return false;
		}

		$order = wc_get_order( (int) $order_id );

		// Fail closed: re-read the identifier off the order itself before it can drive a write.
		if ( ! $order || (string) $order->get_meta( '_snappi_orderIdentifier' ) !== $identifier ) {
			return false;
		}

		return $order;
	}

	function check_snappi_response() {
		$action     = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$order_hash = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';

		if ( ! $this->callback_key_is_valid() ) {
			wc_get_logger()->warning(
				'Snappi callback rejected: missing or wrong security key (action=' . $action . ').',
				array( 'source' => 'snappi-gateway' )
			);
			wc_add_notice( __( 'Payment via Snappi failed. Please try again.', 'snappi-for-woocommerce' ), 'error' );
			wp_redirect( wc_get_checkout_url() );
			exit;
		}

		if ( 'success' === $action && '' !== $order_hash ) {
			$order = $this->get_order_by_identifier( $order_hash );

			if ( ! $order ) {
				wc_get_logger()->warning(
					'Snappi success callback for an unknown orderIdentifier.',
					array( 'source' => 'snappi-gateway' )
				);
				wc_add_notice( __( 'Payment via Snappi failed. Please try again.', 'snappi-for-woocommerce' ), 'error' );
				wp_redirect( wc_get_checkout_url() );
				exit;
			}

			// Idempotency: claim the order BEFORE completing it, so a repeated callback cannot
			// fire payment_complete() and the success hook twice.
			if ( (int) $order->get_meta( '_snappi_finalized' ) === 1 ) {
				wp_redirect( $this->get_return_url( $order ) );
				exit;
			}

			// Defence in depth: only ever complete an order that actually opened a Snappi basket
			// and is still waiting for it. Nothing here should be able to touch a COD order.
			if ( ! $this->order_awaits_snappi_payment( $order ) ) {
				wc_get_logger()->warning(
					sprintf(
						'Snappi success callback ignored for order #%d (method=%s, status=%s, basketId=%s).',
						$order->get_id(),
						$order->get_payment_method(),
						$order->get_status(),
						$order->get_meta( '_snappi_basketId' )
					),
					array( 'source' => 'snappi-gateway' )
				);
				wp_redirect( $this->get_return_url( $order ) );
				exit;
			}

			$order->update_meta_data( '_snappi_finalized', 1 );
			$order->save();

			$order->add_order_note( __( 'Payment via Snappi.', 'snappi-for-woocommerce' ) );
			$order->payment_complete( $order->get_meta( '_snappi_basketId' ) );

			wc_add_notice(
				__( 'Thank you for choosing us for your online shopping.<br />Your transaction was successful, payment was received.<br />Your order is currently being processed.', 'snappi-for-woocommerce' ),
				'success'
			);

			do_action( 'webexpert_woocommerce_snappi_success', $order->get_id() );

			wp_redirect( $this->get_return_url( $order ) );
			exit;
		}

		if ( 'fail' === $action && '' !== $order_hash ) {
			$order = $this->get_order_by_identifier( $order_hash );

			if ( ! $order ) {
				wc_add_notice( __( 'Payment via Snappi failed. Please try again.', 'snappi-for-woocommerce' ), 'error' );
				wp_redirect( wc_get_checkout_url() );
				exit;
			}

			if ( (int) $order->get_meta( '_snappi_finalized' ) === 1 || ! $this->order_awaits_snappi_payment( $order ) ) {
				wp_redirect( $this->get_return_url( $order ) );
				exit;
			}

			$order->add_order_note( __( 'Payment via Snappi failed.', 'snappi-for-woocommerce' ) );

			wc_add_notice(
				__( 'Thank you for choosing us for your online shopping. <br />However, the transaction wasn\'t successful, payment wasn\'t received.', 'snappi-for-woocommerce' ),
				'error'
			);

			do_action( 'webexpert_woocommerce_snappi_failed', $order->get_id() );
			$order->update_status( 'failed', '' );

			wp_redirect( $order->get_cancel_order_url_raw() );
			exit;
		}

		wp_redirect( wc_get_checkout_url() );
		exit;
	}
}
