<?php
/**
 * Exchange rates: Frankfurter (free, no key) or Open Exchange Rates, cached
 * with the Transients API. Manual rates are always available as a fallback.
 *
 * @package Rokonline_Geo_Currency
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ROKGC_Rates.
 */
class ROKGC_Rates {

	const FRANKFURTER_URL = 'https://api.frankfurter.app';
	const OXR_URL         = 'https://openexchangerates.org/api/latest.json';
	const LAST_GOOD       = 'rokgc_rates_last_good';

	/**
	 * Singleton instance.
	 *
	 * @var ROKGC_Rates|null
	 */
	private static $instance = null;

	/**
	 * In-request memo of auto rates.
	 *
	 * @var array|null
	 */
	private $memo = null;

	/**
	 * Last fetch error.
	 *
	 * @var WP_Error|null
	 */
	private $last_error = null;

	/**
	 * Get the singleton.
	 *
	 * @return ROKGC_Rates
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
	private function __construct() {}

	/**
	 * Cache lifetime in seconds (1 - 168 hours).
	 *
	 * @return int
	 */
	public function get_ttl() {
		$settings = ROKGC_Plugin::get_settings();
		$hours    = max( 1, min( 168, (int) $settings['cache_hours'] ) );

		return $hours * HOUR_IN_SECONDS;
	}

	/**
	 * Currencies that use automatic rates.
	 *
	 * @return string[]
	 */
	private function get_auto_targets() {
		$settings = ROKGC_Plugin::get_settings();
		$targets  = array();

		foreach ( $settings['currencies'] as $code => $config ) {
			if ( isset( $config['mode'] ) && 'auto' === $config['mode'] ) {
				$targets[] = (string) $code;
			}
		}

		sort( $targets );

		return $targets;
	}

	/**
	 * Transient key, unique per provider / base / target set.
	 *
	 * @param string   $provider Provider slug.
	 * @param string   $base     Base currency.
	 * @param string[] $targets  Target currencies.
	 * @return string
	 */
	private function transient_key( $provider, $base, $targets ) {
		return 'rokgc_rates_' . md5( $provider . '|' . $base . '|' . implode( ',', $targets ) );
	}

	/**
	 * Get the automatic rates (base => target), using the transient cache.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array code => rate
	 */
	public function get_auto_rates( $force = false ) {
		$settings = ROKGC_Plugin::get_settings();
		$targets  = $this->get_auto_targets();

		if ( empty( $targets ) ) {
			return array();
		}

		$base = $settings['base_currency'];
		$key  = $this->transient_key( $settings['rate_provider'], $base, $targets );

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
			// A recent failure: don't retry on every page view.
			if ( get_transient( $key . '_f' ) ) {
				return $this->get_stale( $base );
			}
		}

		$fetched = $this->fetch( $settings['rate_provider'], $base, $targets );

		if ( is_wp_error( $fetched ) ) {
			$this->last_error = $fetched;
			set_transient( $key . '_f', 1, 10 * MINUTE_IN_SECONDS );
			return $this->get_stale( $base );
		}

		$this->store( $key, $base, $fetched );

		return $fetched;
	}

	/**
	 * Persist rates in the transient and the "last good" option.
	 *
	 * @param string $key   Transient key.
	 * @param string $base  Base currency.
	 * @param array  $rates Rates.
	 * @return void
	 */
	private function store( $key, $base, $rates ) {
		set_transient( $key, $rates, $this->get_ttl() );
		delete_transient( $key . '_f' );
		update_option(
			self::LAST_GOOD,
			array(
				'base'  => $base,
				'rates' => $rates,
				'time'  => time(),
			),
			false
		);
	}

	/**
	 * Last successfully fetched rates for this base (survives API outages).
	 *
	 * @param string $base Base currency.
	 * @return array
	 */
	private function get_stale( $base ) {
		$stale = get_option( self::LAST_GOOD );

		if ( is_array( $stale ) && isset( $stale['base'], $stale['rates'] ) && $stale['base'] === $base && is_array( $stale['rates'] ) ) {
			return $stale['rates'];
		}

		return array();
	}

	/**
	 * Rate to multiply base-currency amounts with. 0.0 = unusable.
	 *
	 * @param string $currency Currency code.
	 * @return float
	 */
	public function get_rate( $currency ) {
		$settings = ROKGC_Plugin::get_settings();

		if ( $currency === $settings['base_currency'] ) {
			return 1.0;
		}

		if ( ! isset( $settings['currencies'][ $currency ] ) ) {
			return 0.0;
		}

		$config = $settings['currencies'][ $currency ];

		if ( isset( $config['mode'] ) && 'auto' === $config['mode'] ) {
			if ( null === $this->memo ) {
				$this->memo = $this->get_auto_rates();
			}
			if ( ! empty( $this->memo[ $currency ] ) ) {
				return (float) $this->memo[ $currency ];
			}
		}

		return isset( $config['rate'] ) ? max( 0.0, (float) $config['rate'] ) : 0.0;
	}

	/**
	 * Convert an amount from the base currency.
	 *
	 * @param float  $amount   Amount in base currency.
	 * @param string $currency Target currency.
	 * @return float
	 */
	public function convert( $amount, $currency ) {
		return (float) $amount * $this->get_rate( $currency );
	}

	/**
	 * Force-refresh rates and write them into the saved settings.
	 *
	 * @return int|WP_Error Number of updated currencies.
	 */
	public function sync_now() {
		$this->memo = null;
		$settings   = ROKGC_Plugin::get_settings();
		$targets    = $this->get_auto_targets();

		if ( empty( $targets ) ) {
			return new WP_Error( 'rokgc_no_targets', __( 'No currency is set to automatic rates.', 'rokonline-geo-currency' ) );
		}

		$base    = $settings['base_currency'];
		$fetched = $this->fetch( $settings['rate_provider'], $base, $targets );

		if ( is_wp_error( $fetched ) ) {
			$this->last_error = $fetched;
			return $fetched;
		}

		$this->store( $this->transient_key( $settings['rate_provider'], $base, $targets ), $base, $fetched );

		$count = 0;
		foreach ( $settings['currencies'] as $code => $config ) {
			if ( isset( $config['mode'], $fetched[ $code ] ) && 'auto' === $config['mode'] ) {
				$settings['currencies'][ $code ]['rate'] = $fetched[ $code ];
				++$count;
			}
		}

		update_option( ROKGC_OPTION, $settings );

		return $count;
	}

	/**
	 * Last error message (for the admin screen).
	 *
	 * @return string
	 */
	public function get_last_error() {
		return $this->last_error instanceof WP_Error ? $this->last_error->get_error_message() : '';
	}

	/**
	 * Dispatch to the selected provider.
	 *
	 * @param string   $provider Provider slug.
	 * @param string   $base     Base currency.
	 * @param string[] $targets  Target currencies.
	 * @return array|WP_Error
	 */
	private function fetch( $provider, $base, $targets ) {
		switch ( $provider ) {
			case 'oxr':
				return $this->fetch_oxr( $base, $targets );
			case 'frankfurter':
				return $this->fetch_frankfurter( $base, $targets );
			default:
				return $this->fetch_currencyapi( $base, $targets );
		}
	}

	/**
	 * Keyless open-data provider (fawazahmed0/exchange-api): ~200 currencies,
	 * served as static JSON from two CDNs (the second is the fallback).
	 *
	 * @param string   $base    Base currency.
	 * @param string[] $targets Target currencies.
	 * @return array|WP_Error
	 */
	private function fetch_currencyapi( $base, $targets ) {
		$slug = strtolower( $base );
		$urls = apply_filters(
			'rokgc_currencyapi_urls',
			array(
				'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/' . $slug . '.min.json',
				'https://latest.currency-api.pages.dev/v1/currencies/' . $slug . '.min.json',
			),
			$slug
		);

		$data  = null;
		$error = null;

		foreach ( $urls as $url ) {
			$result = $this->remote_json( $url );
			if ( is_wp_error( $result ) ) {
				$error = $result;
				continue;
			}
			if ( isset( $result[ $slug ] ) && is_array( $result[ $slug ] ) ) {
				$data = $result[ $slug ];
				break;
			}
		}

		if ( null === $data ) {
			return $error ? $error : new WP_Error( 'rokgc_bad_payload', __( 'Unexpected response from the exchange rate API.', 'rokonline-geo-currency' ) );
		}

		$rates = array();
		foreach ( $targets as $code ) {
			$key = strtolower( $code );
			if ( ! empty( $data[ $key ] ) && (float) $data[ $key ] > 0 ) {
				$rates[ $code ] = (float) $data[ $key ];
			}
		}

		if ( empty( $rates ) ) {
			return new WP_Error( 'rokgc_no_rates', __( 'The rate API returned none of the requested currencies.', 'rokonline-geo-currency' ) );
		}

		return $rates;
	}

	/**
	 * Frankfurter (ECB reference rates, ~30 currencies, no API key).
	 *
	 * @param string   $base    Base currency.
	 * @param string[] $targets Target currencies.
	 * @return array|WP_Error
	 */
	private function fetch_frankfurter( $base, $targets ) {
		$root      = untrailingslashit( apply_filters( 'rokgc_frankfurter_url', self::FRANKFURTER_URL ) );
		$supported = $this->get_frankfurter_currencies( $root );

		if ( is_wp_error( $supported ) ) {
			return $supported;
		}

		if ( ! isset( $supported[ $base ] ) ) {
			/* translators: %s: currency code */
			return new WP_Error( 'rokgc_unsupported_base', sprintf( __( 'Frankfurter does not support the base currency %s. Use manual rates or Open Exchange Rates.', 'rokonline-geo-currency' ), $base ) );
		}

		$targets = array_values( array_intersect( $targets, array_keys( $supported ) ) );
		if ( empty( $targets ) ) {
			return new WP_Error( 'rokgc_unsupported_targets', __( 'None of the automatic currencies are supported by Frankfurter. Switch them to manual rates.', 'rokonline-geo-currency' ) );
		}

		$url  = add_query_arg(
			array(
				'from' => $base,
				'to'   => implode( ',', $targets ),
			),
			$root . '/latest'
		);
		$data = $this->remote_json( $url );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['rates'] ) || ! is_array( $data['rates'] ) ) {
			return new WP_Error( 'rokgc_bad_payload', __( 'Unexpected response from the exchange rate API.', 'rokonline-geo-currency' ) );
		}

		$rates = array();
		foreach ( $data['rates'] as $code => $value ) {
			if ( (float) $value > 0 ) {
				$rates[ strtoupper( $code ) ] = (float) $value;
			}
		}

		return $rates;
	}

	/**
	 * Currencies supported by Frankfurter (cached for 7 days).
	 *
	 * @param string $root API root URL.
	 * @return array|WP_Error
	 */
	private function get_frankfurter_currencies( $root ) {
		$cached = get_transient( 'rokgc_ff_currencies' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = $this->remote_json( $root . '/currencies' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		set_transient( 'rokgc_ff_currencies', $data, WEEK_IN_SECONDS );

		return $data;
	}

	/**
	 * Open Exchange Rates (free plan is USD-based; cross rates are computed).
	 *
	 * @param string   $base    Base currency.
	 * @param string[] $targets Target currencies.
	 * @return array|WP_Error
	 */
	private function fetch_oxr( $base, $targets ) {
		$settings = ROKGC_Plugin::get_settings();

		if ( '' === $settings['oxr_app_id'] ) {
			return new WP_Error( 'rokgc_no_key', __( 'Open Exchange Rates needs an App ID.', 'rokonline-geo-currency' ) );
		}

		$data = $this->remote_json( add_query_arg( 'app_id', rawurlencode( $settings['oxr_app_id'] ), self::OXR_URL ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( empty( $data['rates'] ) || ! is_array( $data['rates'] ) || empty( $data['rates'][ $base ] ) ) {
			/* translators: %s: currency code */
			return new WP_Error( 'rokgc_bad_payload', sprintf( __( 'Open Exchange Rates returned no rate for %s.', 'rokonline-geo-currency' ), $base ) );
		}

		$rates = array();
		foreach ( $targets as $code ) {
			if ( ! empty( $data['rates'][ $code ] ) ) {
				$rates[ $code ] = (float) $data['rates'][ $code ] / (float) $data['rates'][ $base ];
			}
		}

		if ( empty( $rates ) ) {
			return new WP_Error( 'rokgc_bad_payload', __( 'No usable rates in the API response.', 'rokonline-geo-currency' ) );
		}

		return $rates;
	}

	/**
	 * GET a URL and decode JSON.
	 *
	 * @param string $url URL.
	 * @return array|WP_Error
	 */
	private function remote_json( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 8,
				'headers'    => array( 'Accept' => 'application/json' ),
				'user-agent' => 'Rokonline-Geo-Currency/' . ROKGC_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'rokgc_http', sprintf( __( 'Exchange rate API returned HTTP %d.', 'rokonline-geo-currency' ), $code ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'rokgc_json', __( 'Exchange rate API returned invalid JSON.', 'rokonline-geo-currency' ) );
		}

		return $data;
	}
}
