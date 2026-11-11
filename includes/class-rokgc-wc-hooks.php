<?php
/**
 * WooCommerce integration: resolves the active currency and converts prices,
 * shipping, coupons and currency formatting on the fly. Nothing is written to
 * product data.
 *
 * @package Rokonline_Geo_Currency
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ROKGC_WC_Hooks.
 */
class ROKGC_WC_Hooks {

	/**
	 * Singleton instance.
	 *
	 * @var ROKGC_WC_Hooks|null
	 */
	private static $instance = null;

	/**
	 * Rates service.
	 *
	 * @var ROKGC_Rates
	 */
	private $rates;

	/**
	 * Resolved currency for this request.
	 *
	 * @var string|null
	 */
	private $current = null;

	/**
	 * Memoised conversion rate.
	 *
	 * @var float|null
	 */
	private $rate = null;

	/**
	 * Free-shipping method objects already converted.
	 *
	 * @var array
	 */
	private $converted_methods = array();

	/**
	 * Get the singleton.
	 *
	 * @return ROKGC_WC_Hooks
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: register hooks.
	 */
	private function __construct() {
		$this->rates = ROKGC_Rates::instance();

		// Currency formatting.
		add_filter( 'woocommerce_currency', array( $this, 'filter_currency' ), 99 );
		add_filter( 'woocommerce_currency_symbol', array( $this, 'filter_symbol' ), 99, 2 );
		add_filter( 'wc_get_price_decimals', array( $this, 'filter_decimals' ), 99 );
		add_filter( 'wc_price_args', array( $this, 'filter_price_args' ), 99 );

		// Product prices (simple, variation, regular, sale).
		$price_filters = array(
			'woocommerce_product_get_price',
			'woocommerce_product_get_regular_price',
			'woocommerce_product_get_sale_price',
			'woocommerce_product_variation_get_price',
			'woocommerce_product_variation_get_regular_price',
			'woocommerce_product_variation_get_sale_price',
			'woocommerce_variation_prices_price',
			'woocommerce_variation_prices_regular_price',
			'woocommerce_variation_prices_sale_price',
		);
		foreach ( $price_filters as $tag ) {
			add_filter( $tag, array( $this, 'convert_price' ), 99 );
		}

		// Variable product price cache must differ per currency / rate.
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'filter_variation_hash' ), 99 );

		// Shipping.
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'filter_shipping_packages' ), 99 );
		add_filter( 'woocommerce_package_rates', array( $this, 'filter_package_rates' ), 99 );
		add_filter( 'woocommerce_shipping_zone_shipping_methods', array( $this, 'filter_zone_methods' ), 99 );

		// Coupons (fixed amounts only; percentages are currency-neutral).
		add_filter( 'woocommerce_coupon_get_amount', array( $this, 'filter_coupon_amount' ), 99, 2 );
		add_filter( 'woocommerce_coupon_get_minimum_amount', array( $this, 'convert_price' ), 99 );
		add_filter( 'woocommerce_coupon_get_maximum_amount', array( $this, 'convert_price' ), 99 );
	}

	/* ---------------------------------------------------------------------
	 * Cookie / cache helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Store the visitor's currency choice.
	 *
	 * @param string $currency Currency code.
	 * @return void
	 */
	public static function set_cookie( $currency ) {
		$_COOKIE[ ROKGC_COOKIE ] = $currency;

		if ( headers_sent() ) {
			return;
		}

		setcookie(
			ROKGC_COOKIE,
			$currency,
			array(
				'expires'  => time() + ( 30 * DAY_IN_SECONDS ),
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false, // The frontend script must read it.
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Tell page caches not to store this response.
	 *
	 * @return void
	 */
	public static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * Crude crawler check: bots get the fallback currency, no cookie.
	 *
	 * @return bool
	 */
	private function is_bot() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return true;
		}

		$agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );

		return (bool) preg_match( '/bot|crawl|spider|slurp|bing|facebookexternalhit|lighthouse|pingdom|monitor/i', $agent );
	}

	/* ---------------------------------------------------------------------
	 * Currency resolution
	 * ------------------------------------------------------------------ */

	/**
	 * Should prices be converted in this request? Not in wp-admin, cron or
	 * CLI, nor for admin REST/AJAX calls (the Store API is converted).
	 *
	 * @return bool
	 */
	public function should_convert() {
		$convert = true;

		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$convert = false;
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$uri     = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$convert = false !== strpos( $uri, '/wc/store' );
		} elseif ( wp_doing_ajax() ) {
			$referer = wp_get_referer();
			$convert = ! ( $referer && 0 === strpos( $referer, admin_url() ) );
		} elseif ( is_admin() ) {
			$convert = false;
		}

		return (bool) apply_filters( 'rokgc_should_convert', $convert );
	}

	/**
	 * Resolve the currency for the current visitor.
	 *
	 * Priority: ?rokgc_cur= param > cookie > GeoIP > fallback currency.
	 *
	 * @return string
	 */
	public function get_current_currency() {
		$settings = ROKGC_Plugin::get_settings();
		$base     = $settings['base_currency'];

		if ( ! $this->should_convert() ) {
			return $base;
		}

		if ( null !== $this->current ) {
			return $this->current;
		}

		$enabled  = ROKGC_Plugin::get_enabled_codes();
		$currency = '';

		// 1. Explicit switch via URL (cache-busting, no-JS fallback).
		if ( isset( $_GET['rokgc_cur'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preference only, no state change.
			$candidate = strtoupper( sanitize_text_field( wp_unslash( $_GET['rokgc_cur'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( in_array( $candidate, $enabled, true ) ) {
				$currency = $candidate;
				self::set_cookie( $currency );
				self::no_cache();
			}
		}

		// 2. Cookie.
		if ( '' === $currency && isset( $_COOKIE[ ROKGC_COOKIE ] ) ) {
			$candidate = strtoupper( sanitize_text_field( wp_unslash( $_COOKIE[ ROKGC_COOKIE ] ) ) );
			if ( in_array( $candidate, $enabled, true ) ) {
				$currency = $candidate;
			}
		}

		// 3. GeoIP (server-side). In cache-safe mode the browser detects instead.
		if ( '' === $currency && ! empty( $settings['geoip_enabled'] ) && empty( $settings['cache_mode'] ) && ! $this->is_bot() ) {
			$candidate = ROKGC_Geoip::instance()->get_visitor_currency();
			if ( in_array( $candidate, $enabled, true ) ) {
				$currency = $candidate;
				self::set_cookie( $currency );
			}
		}

		// 4. Fallback.
		if ( '' === $currency ) {
			$currency = $settings['fallback_currency'];
		}

		if ( ! in_array( $currency, $enabled, true ) ) {
			$currency = $base;
		}

		// A currency without a usable rate must never be shown.
		if ( $currency !== $base && $this->rates->get_rate( $currency ) <= 0 ) {
			$currency = $base;
		}

		$this->current = $currency;

		return $currency;
	}

	/**
	 * Rate for the current currency (1.0 when not converting).
	 *
	 * @return float
	 */
	public function get_rate() {
		if ( ! $this->should_convert() ) {
			return 1.0;
		}

		if ( null === $this->rate ) {
			$this->rate = $this->rates->get_rate( $this->get_current_currency() );
			if ( $this->rate <= 0 ) {
				$this->rate = 1.0;
			}
		}

		return $this->rate;
	}

	/* ---------------------------------------------------------------------
	 * Formatting filters
	 * ------------------------------------------------------------------ */

	/**
	 * `woocommerce_currency`.
	 *
	 * @param string $currency Store currency.
	 * @return string
	 */
	public function filter_currency( $currency ) {
		return $this->should_convert() ? $this->get_current_currency() : $currency;
	}

	/**
	 * `woocommerce_currency_symbol`: custom symbols per currency.
	 *
	 * @param string $symbol   Symbol.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public function filter_symbol( $symbol, $currency ) {
		$settings = ROKGC_Plugin::get_settings();

		if ( ! empty( $settings['currencies'][ $currency ]['symbol'] ) ) {
			return $settings['currencies'][ $currency ]['symbol'];
		}

		return $symbol;
	}

	/**
	 * `wc_get_price_decimals`: per-currency decimals (e.g. 0 for JPY).
	 *
	 * @param int $decimals Decimals.
	 * @return int
	 */
	public function filter_decimals( $decimals ) {
		if ( ! $this->should_convert() ) {
			return $decimals;
		}

		$settings = ROKGC_Plugin::get_settings();
		$current  = $this->get_current_currency();

		if ( $current !== $settings['base_currency'] && isset( $settings['currencies'][ $current ]['decimals'] ) ) {
			return absint( $settings['currencies'][ $current ]['decimals'] );
		}

		return $decimals;
	}

	/**
	 * `wc_price_args`: correct decimals when wc_price() is called with an
	 * explicit currency (e.g. historical orders in another currency).
	 *
	 * @param array $args wc_price() arguments.
	 * @return array
	 */
	public function filter_price_args( $args ) {
		if ( empty( $args['currency'] ) ) {
			return $args;
		}

		$settings = ROKGC_Plugin::get_settings();

		if ( $args['currency'] === $settings['base_currency'] ) {
			$args['decimals'] = absint( get_option( 'woocommerce_price_num_decimals', 2 ) );
		} elseif ( isset( $settings['currencies'][ $args['currency'] ]['decimals'] ) ) {
			$args['decimals'] = absint( $settings['currencies'][ $args['currency'] ]['decimals'] );
		}

		return $args;
	}

	/* ---------------------------------------------------------------------
	 * Price conversion
	 * ------------------------------------------------------------------ */

	/**
	 * Convert a base-currency amount to the current currency.
	 *
	 * @param mixed $price Price.
	 * @return mixed
	 */
	public function convert_price( $price ) {
		if ( '' === $price || null === $price || ! is_numeric( $price ) ) {
			return $price;
		}

		$rate = $this->get_rate();
		if ( abs( $rate - 1.0 ) < 0.0000001 ) {
			return $price;
		}

		return round( (float) $price * $rate, wc_get_rounding_precision() );
	}

	/**
	 * Add currency + rate to the variable-price cache hash.
	 *
	 * @param array $hash Hash parts.
	 * @return array
	 */
	public function filter_variation_hash( $hash ) {
		if ( $this->should_convert() ) {
			$hash[] = $this->get_current_currency();
			$hash[] = (string) $this->get_rate();
		}

		return $hash;
	}

	/* ---------------------------------------------------------------------
	 * Shipping
	 * ------------------------------------------------------------------ */

	/**
	 * Make shipping package hashes currency-aware so stored rates are never
	 * reused across currencies.
	 *
	 * @param array $packages Packages.
	 * @return array
	 */
	public function filter_shipping_packages( $packages ) {
		if ( ! $this->should_convert() ) {
			return $packages;
		}

		$current = $this->get_current_currency();
		foreach ( $packages as $index => $package ) {
			$packages[ $index ]['rokgc_currency'] = $current;
		}

		return $packages;
	}

	/**
	 * Convert shipping costs and shipping taxes.
	 *
	 * @param array $rates Shipping rates.
	 * @return array
	 */
	public function filter_package_rates( $rates ) {
		if ( ! $this->should_convert() || abs( $this->get_rate() - 1.0 ) < 0.0000001 ) {
			return $rates;
		}

		foreach ( $rates as $rate ) {
			$rate->set_cost( (string) $this->convert_price( $rate->get_cost() ) );

			$taxes = $rate->get_taxes();
			if ( is_array( $taxes ) ) {
				foreach ( $taxes as $key => $tax ) {
					$taxes[ $key ] = $this->convert_price( $tax );
				}
				$rate->set_taxes( $taxes );
			}
		}

		return $rates;
	}

	/**
	 * Convert the "minimum order amount" of Free Shipping methods.
	 *
	 * @param array $methods Shipping methods of a zone.
	 * @return array
	 */
	public function filter_zone_methods( $methods ) {
		if ( ! $this->should_convert() || abs( $this->get_rate() - 1.0 ) < 0.0000001 ) {
			return $methods;
		}

		foreach ( $methods as $method ) {
			if ( $method instanceof WC_Shipping_Free_Shipping
				&& is_numeric( $method->min_amount )
				&& (float) $method->min_amount > 0
				&& ! in_array( $method, $this->converted_methods, true )
			) {
				$method->min_amount        = (float) $method->min_amount * $this->get_rate();
				$this->converted_methods[] = $method;
			}
		}

		return $methods;
	}

	/* ---------------------------------------------------------------------
	 * Coupons
	 * ------------------------------------------------------------------ */

	/**
	 * Convert fixed-amount coupons only.
	 *
	 * @param mixed     $amount Coupon amount.
	 * @param WC_Coupon $coupon Coupon.
	 * @return mixed
	 */
	public function filter_coupon_amount( $amount, $coupon ) {
		if ( in_array( $coupon->get_discount_type(), array( 'fixed_cart', 'fixed_product' ), true ) ) {
			return $this->convert_price( $amount );
		}

		return $amount;
	}
}
