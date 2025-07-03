<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Snappi_Pay_Later_Blocks_Support extends AbstractPaymentMethodType {
	/**
	 * The gateway instance.
	 *
	 * @var WC_Gateway_Dummy
	 */
	private $gateway;

	/**
	 * Payment method name/id/slug.
	 *
	 * @var string
	 */
	protected $name = 'snappi_gateway';

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_snappi_gateway_settings', [] );
		$gateways       = WC()->payment_gateways->payment_gateways();
		$this->gateway  = $gateways[ $this->name ];
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return $this->gateway->is_available();
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$script_path       = '/assets/js/frontend/blocks.js';
		$script_asset_path = Snappi_Pay_Later::plugin_abspath() . 'assets/js/frontend/blocks.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require( $script_asset_path )
			: array(
				'dependencies' => array(),
				'version'      => '1.2.0'
			);
		$script_url        = Snappi_Pay_Later::plugin_url() . $script_path;

		wp_register_script(
			'woocommerce-snappi-payment-blocks',
			$script_url,
			$script_asset[ 'dependencies' ],
			$script_asset[ 'version' ],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'woocommerce-snappi-payment-blocks', 'webexpert-woocommerce-snappi-payment-gateway', Snappi_Pay_Later::plugin_abspath() . 'languages/' );
		}

		return [ 'woocommerce-snappi-payment-blocks' ];
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		if (!is_admin() && is_checkout()) {
			if (is_wc_endpoint_url('order-pay')) {
				$order_id = get_query_var('order-pay');
				$order = wc_get_order($order_id);
				$cart_total = $order->get_total();
			} else {
				$cart_total = WC()->cart->get_total('');
			}
		}

		$settings = [
			'id'          => $this->gateway->id,
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'icon'        => $this->gateway->icon,
			'supports'    => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] ),
		];

		return $settings;
	}
}