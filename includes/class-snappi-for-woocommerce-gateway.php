<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class WC_Snappi_Gateway extends WC_Payment_Gateway {
	public $instructions;
	public $notify_url;
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
			'api_environment' => array(
				'title' => __('API environment', 'snappi-for-woocommerce'),
				'type' => 'select',
				'label' => __('Environment', 'snappi-for-woocommerce'),
				'description' => __('This control enables test or live APi environment', 'snappi-for-woocommerce'),
				'desc_tip' => true,
				'options'     => array(
					'sandbox' => __( 'UAT', 'snappi-for-woocommerce' ),
					'live' => __( 'Production', 'snappi-for-woocommerce' ),
				),
				'default'     => 'sandbox',
			)
		);
	}

	protected function check_settings() {
		return !empty($this->get_option('applicationID')) && !empty($this->get_option('subscriptionKey'));
	}

	function process_payment($order_id) {
		$result = 'failure';
		$order = wc_get_order($order_id);

		$applicationID = $this->get_option( 'applicationID' );
		$subscriptionKey = $this->get_option( 'subscriptionKey' );

		try {
			if ( !empty($applicationID) && !empty($subscriptionKey) ) {
				// Prepare API headers
				$headers = array(
					'Accept' => 'application/json',
					'X-Application-ID' => $this->get_option('applicationID'),
					'Ocp-Apim-Subscription-Key' => $this->get_option('subscriptionKey')
				);

				if ($this->get_option('api_environment') == 'sandbox') {
					$url = "https://merchantbnpl.snappibank.com.gr";
				}else {
					$url = "https://merchantbnplapi.snappibank.com";
				}

				// Make API request
				$response = wp_remote_get("{$url}/merchant/checkbasketeligibilityforbnpl?basketValue=".floatval($order->get_total()), array(
					'headers' => $headers,
					'timeout' => 10, // Adjust timeout if needed
				));

				// Handle response errors
				if (is_wp_error($response)) {
					throw new Exception( __($response->get_error_message(),'snappi-for-woocommerce'));
				}

				// Retrieve and decode the response
				$body = wp_remote_retrieve_body($response);
				$data = json_decode($body, true);


				if (empty($data['isBNPLEligible'])) {
					throw new Exception( __('Snappi is not available.', 'snappi-for-woocommerce'));
				}

				// Prepare cart items
				$basket_products = array();

				foreach ($order->get_items() as $item_id => $item) {
					$product = $item->get_product();
					if (!$product) continue; // Skip if product is missing

					// Get price details
					$unit_price = floatval($order->get_item_total($item, false, true)); // Before tax
					$tax_amount = floatval($order->get_item_tax($item)); // Tax amount
					$total_price = floatval($order->get_item_total($item, true, true)); // After tax

					// Calculate tax percentage dynamically
					$tax_percentage = ($unit_price > 0) ? round(($tax_amount / $unit_price) * 100, 2) : 0;

					$product_url = get_permalink($product->get_id());
					$product_image = wp_get_attachment_url($product->get_image_id());

					$basket_products[] = array(
						"Product Number"       => strval($product->get_id()),
						"name"                 => $product->get_name(),
						"quantity"             => intval($item->get_quantity()),
						"Quantity Units"       => "pcs",
						"Unit Price"           => $unit_price,
						"Tax Rate"             => round($tax_percentage)/100, // Dynamically calculated tax rate
						"Total Amount"         => $total_price, // Total after tax
						"Total Discount Amount"=> floatval($order->get_total_discount()), // Total discount on order
						"Total Tax Amount"     => floatval($order->get_total_tax()),
						"Product URL"          => esc_url($product_url),
						"Product Image URL"    => esc_url($product_image)
					);
				}

				// API request body
                $orderIdentifier = $order->get_order_number()."-".time();
				$body = array(
					"basketValue"      => floatval($order->get_total()), // Order total
					"requestId"        => wp_generate_uuid4(),
					"orderIdentifier"  => $orderIdentifier,
					"basketProducts"   => $basket_products,
					"Merchant Data"    => $order->get_formatted_billing_full_name(),
					"phoneNumber"      => $order->get_billing_phone(),
					"email"            => $order->get_billing_email()
				);

				// API headers
				$headers = array(
					'Content-Type'                  => 'application/json',
					'Accept'                        => 'application/json',
					'X-Application-ID'              => $applicationID,
					'Ocp-Apim-Subscription-Key'     => $subscriptionKey
				);

				// Send API request
				$response = wp_remote_post("{$url}/createbasket", array(
					'headers'   => $headers,
					'body'      => json_encode($body),
					'timeout'   => 10,
				));

				// Handle response
				if (is_wp_error($response)) {
					throw new Exception( __($response->get_error_message(),'snappi-for-woocommerce'));
				}

				$response_body = json_decode(wp_remote_retrieve_body($response), true);
                $order->update_meta_data( '_snappi_orderIdentifier', $orderIdentifier );
                $order->save();

				if (!empty($response_body) && !empty($response_body['redirectUrl'])) {
                    $order->update_meta_data( '_snappi_requestId', $response_body['redirectUrl'] );
					$order->update_meta_data( '_snappi_basketId', $response_body['basketId'] );
					$order->update_meta_data( '_snappi_qrCodeData', $response_body['qrCodeData'] );
					$order->save();

					$result = "success";
					$redirectLink = $response_body['redirectUrl'];
				}else {
					$result = "error";
					$message = __( 'Order payment failed.', 'snappi-for-woocommerce' );
					throw new Exception( $message );
				}
			} else {
				$result = "error";
				$message = __( 'Order payment failed. To make a successful payment using Snappi, please review the gateway settings.', 'snappi-for-woocommerce' );
				throw new Exception( $message );
			}
		} catch (\Throwable $th) {
			wc_add_notice($th->getMessage(), "error");
			$redirectLink = $this->get_return_url($order);
		}

		return array(
			'result' => $result,
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

		$applicationID = $this->get_option( 'applicationID' );
		$subscriptionKey = $this->get_option( 'subscriptionKey' );

		try {
			if ( !empty($applicationID) && !empty($subscriptionKey) ) {
				$headers = array(
					'Accept' => 'application/json',
					'X-Application-ID' => $this->get_option('applicationID'),
					'Ocp-Apim-Subscription-Key' => $this->get_option('subscriptionKey')
				);

				if ($this->get_option('api_environment') == 'sandbox') {
					$url = "https://merchantbnpl.snappibank.com.gr";
				}else {
					$url = "https://merchantbnplapi.snappibank.com";
				}

				// Make API request
				$response = wp_remote_get("{$url}/merchant/checkbasketeligibilityforbnpl?basketValue=".floatval(WC()->cart->get_total( 'edit' )), array(
					'headers' => $headers,
					'timeout' => 5,
				));

				// Handle response errors
				if (is_wp_error($response)) {
					throw new Exception( __($response->get_error_message(),'snappi-for-woocommerce'));
				}

				// Retrieve and decode the response
				$body = wp_remote_retrieve_body($response);
				$data = json_decode($body, true);


				if (empty($data['isBNPLEligible'])) {
					return false;
				}

				return true;
			} else {
				return false;
			}
		} catch (\Throwable $th) {
			return false;
		}
	}

	function check_snappi_response() {
		global $wpdb;
		$order=null;

        if (isset($_GET['action']) && ($_GET['action'] == 'success') && !empty($_GET['id'])) {
            $order_hash = $_GET['id'];
            $orders = wc_get_orders(
                [
                    'meta_query' => [
                        [
                            'key'        => '_snappi_orderIdentifier',
                            'value'      => $order_hash,
                            'compare'    => '='
                        ],
                    ],
                ]
            );

            if (!empty($orders)) {
                $order=reset($orders);
                $order->add_order_note(__('Payment Via Snappi.','snappi-for-woocommerce'));
                $message = __('Thank you for choosing us for your online shopping.<br />Your transaction was successful, payment was received.<br />Your order is currently being processed.', 'snappi-for-woocommerce');
                $message_type = 'success';
                $order->payment_complete('transactionID');
                wc_add_notice( $message, $message_type );
                do_action( 'webexpert_woocommerce_snappi_success', $order->get_id());
                $redirect_url = $this->get_return_url($order);
                wp_redirect($redirect_url);
            }else {
                wc_add_notice(__('Payment vis Snappi failed. Please try again.', 'snappi-for-woocommerce'), 'error');
                wp_redirect(wc_get_checkout_url());
            }
            exit;

        }elseif (isset($_GET['action']) && ($_GET['action'] == 'fail') && !empty($_GET['id'])) {
            $order_hash = $_GET['id'];
            $orders = wc_get_orders(
                [
                    'meta_query' => [
                        [
                            'key'        => '_snappi_orderIdentifier',
                            'value'      => $order_hash,
                            'compare'    => '='
                        ],
                    ],
                ]
            );

            if (!empty($orders)) {
                $order=reset($orders);
                $order->add_order_note(__('Payment vis Snappi.','snappi-for-woocommerce'));
                $message = __('Thank you for choosing us for your online shopping. <br />However, the transaction wasn\'t successful, payment wasn\'t received.', 'snappi-for-woocommerce');
                $message_type = 'error';
                wc_add_notice( $message, $message_type );
                do_action( 'webexpert_woocommerce_snappi_failed', $order->get_id());
                $order->update_status('failed', '');
                $redirect_url=$order->get_cancel_order_url_raw();
                wp_redirect($redirect_url);
            }else {
                wc_add_notice(__('Payment vis Snappi failed. Please try again.', 'snappi-for-woocommerce'), 'error');
                wp_redirect(wc_get_checkout_url());
            }
            exit;
        }else {
            wp_redirect(wc_get_checkout_url());
            exit;
        }
	}
}