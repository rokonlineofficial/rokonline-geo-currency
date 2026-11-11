<?php
/**
 * Plugin Name:          Rok Geo Currency
 * Description:          Lightweight multi-currency for WooCommerce. Detects the visitor's country via GeoIP, switches the shop currency automatically and offers an optional frontend switcher.
 * Author:            Rok Online
 * Author URI:        https://rokonline.com/
 * Plugin URI:        https://rokonline.com/
 * License:           GPL-2.0-or-later
 * Version:              3.0.0
 * Text Domain:          rokonline-geo-currency
 * Domain Path:          /languages
 * Requires Plugins:     woocommerce
 * @package Rokonline_Geo_Currency
 */

defined( 'ABSPATH' ) || exit;

define( 'ROKGC_VERSION', '1.0.0' );
define( 'ROKGC_PLUGIN_FILE', __FILE__ );
define( 'ROKGC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ROKGC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ROKGC_COOKIE', 'rokgc_currency' );
define( 'ROKGC_OPTION', 'rokgc_settings' );

/**
 * Plugin bootstrap.
 */
final class ROKGC_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var ROKGC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return ROKGC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
	}

	/**
	 * Declare HPOS and Cart/Checkout Blocks compatibility.
	 *
	 * @return void
	 */
	public function declare_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ROKGC_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', ROKGC_PLUGIN_FILE, true );
		}
	}

	/**
	 * Load classes once WooCommerce is available.
	 *
	 * @return void
	 */
	public function init() {
		load_plugin_textdomain( 'rokonline-geo-currency', false, dirname( plugin_basename( ROKGC_PLUGIN_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'missing_wc_notice' ) );
			return;
		}

		require_once ROKGC_PLUGIN_DIR . 'includes/class-rokgc-geoip.php';
		require_once ROKGC_PLUGIN_DIR . 'includes/class-rokgc-rates.php';
		require_once ROKGC_PLUGIN_DIR . 'includes/class-rokgc-wc-hooks.php';
		require_once ROKGC_PLUGIN_DIR . 'includes/class-rokgc-switcher.php';

		ROKGC_Geoip::instance();
		ROKGC_Rates::instance();
		ROKGC_WC_Hooks::instance();
		ROKGC_Switcher::instance();

		if ( is_admin() ) {
			require_once ROKGC_PLUGIN_DIR . 'includes/class-rokgc-admin.php';
			ROKGC_Admin::instance();
		}
	}

	/**
	 * Admin notice shown when WooCommerce is inactive.
	 *
	 * @return void
	 */
	public function missing_wc_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Rokonline Geo Currency requires WooCommerce to be installed and active.', 'rokonline-geo-currency' );
		echo '</p></div>';
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		$wc_currency = get_option( 'woocommerce_currency', 'USD' );

		return array(
			'geoip_enabled'     => 1,
			'base_currency'     => $wc_currency,
			'fallback_currency' => $wc_currency,
			'rate_provider'     => 'currencyapi',
			'oxr_app_id'        => '',
			'cache_hours'       => 12,
			'cache_mode'        => 0,
			'test_country'      => '',
			'currencies'        => array(),
		);
	}

	/**
	 * Get settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$saved    = get_option( ROKGC_OPTION, array() );
		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::get_defaults() );

		if ( ! is_array( $settings['currencies'] ) ) {
			$settings['currencies'] = array();
		}

		return $settings;
	}

	/**
	 * Base currency + every configured target currency.
	 *
	 * @return string[]
	 */
	public static function get_enabled_codes() {
		$settings = self::get_settings();
		$codes    = array_map( 'strval', array_keys( $settings['currencies'] ) );
		array_unshift( $codes, $settings['base_currency'] );

		return array_values( array_unique( $codes ) );
	}

	/**
	 * Activation: seed default settings.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( ROKGC_OPTION, false ) ) {
			add_option( ROKGC_OPTION, self::get_defaults() );
		}
	}

	/**
	 * Uninstall: remove options and transients.
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		delete_option( ROKGC_OPTION );
		delete_option( 'rokgc_rates_last_good' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_rokgc_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_rokgc_' ) . '%'
			)
		);
	}
}

register_activation_hook( __FILE__, array( 'ROKGC_Plugin', 'activate' ) );
register_uninstall_hook( __FILE__, array( 'ROKGC_Plugin', 'uninstall' ) );

ROKGC_Plugin::instance();
