<?php
/*
Plugin Name: Snappi Pay Later
Plugin URI:  https://www.snappibank.com
Description: Snappi Pay Later allows you to accept payments through various credit cards such as Maestro, Mastercard, and Visa on your Woocommerce Site.
Version: 1.0.3
Requires at least: 4.0
Requires PHP:      7.0
Author: Web Expert
Author URI: http://www.webexpert.gr
License: Web Expert license
Text Domain: snappi-for-woocommerce
WC requires at least: 3.0
WC tested up to: 9.9.5
*/

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define('Snappi_Pay_Later_Version', '1.0.3');

require plugin_dir_path( __FILE__ ).'includes/update/plugin-update-checker.php';
$myUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/dgiannopoulos/snappi-for-woocommerce',
	__FILE__,
	'snappi-for-woocommerce'
);
$myUpdateChecker->setBranch('main');

class Snappi_Pay_Later {
	public static function init() {
		load_plugin_textdomain('snappi-for-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/');
		add_action( 'plugins_loaded', array( __CLASS__, 'includes' ), 0 );
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateway' ) );
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'blocks_support' ) );
		add_action('plugin_action_links', array( __CLASS__, 'action_links' ),10, 2);
	}

	public static function add_gateway( $gateways ) {
		$options = get_option( 'woocommerce_snappi_gateway_settings', array() );

		if ( isset( $options['hide_for_non_admin_users'] ) ) {
			$hide_for_non_admin_users = $options['hide_for_non_admin_users'];
		} else {
			$hide_for_non_admin_users = 'no';
		}

		if ( ( 'yes' === $hide_for_non_admin_users && current_user_can( 'manage_options' ) ) || 'no' === $hide_for_non_admin_users ) {
			$gateways[] = 'WC_Snappi_Gateway';
		}
		return $gateways;
	}

	public static function includes() {
		if ( class_exists( 'WC_Payment_Gateway' ) ) {
			require_once 'includes/class-snappi-for-woocommerce-gateway.php';
		}
	}

	public static function plugin_url() {
		return untrailingslashit( plugins_url( '/', __FILE__ ) );
	}

	public static function plugin_abspath() {
		return trailingslashit( plugin_dir_path( __FILE__ ) );
	}

	static function action_links($links, $file) {
		static $this_plugin;
		if (!$this_plugin) {
			$this_plugin = plugin_basename(__FILE__);
		}

		if ($file == $this_plugin) {
			$settings_link = '<a href="' . admin_url("admin.php?page=wc-settings&tab=checkout&section=snappi_gateway").'">'.__('Settings','snappi-for-woocommerce').'</a>';
			array_unshift($links, $settings_link);
		}
		return $links;
	}

	public static function blocks_support() {
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			require_once 'includes/blocks/class-snappi-for-woocommerce-gateway-blocks.php';
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
					$payment_method_registry->register( new Snappi_Pay_Later_Blocks_Support() );
				}
			);
		}
	}
}
Snappi_Pay_Later::init();

add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );