<?php
/**
 * GeoIP detection: IP -> country -> currency.
 *
 * Lookup order: CDN country header -> per-IP cache -> WooCommerce MaxMind
 * database -> free HTTPS APIs (ipwho.is, country.is, ipapi.co).
 *
 * @package Rokonline_Geo_Currency
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ROKGC_Geoip.
 */
class ROKGC_Geoip {

	/**
	 * Singleton instance.
	 *
	 * @var ROKGC_Geoip|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return ROKGC_Geoip
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
	 * Get the visitor's public IP. Returns an empty string for
	 * localhost / private / reserved addresses.
	 *
	 * @return string
	 */
	public function get_ip() {
		$headers = apply_filters(
			'rokgc_ip_headers',
			array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' )
		);

		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$raw = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

			foreach ( explode( ',', $raw ) as $candidate ) {
				$candidate = trim( $candidate );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $candidate;
				}
			}
		}

		return '';
	}

	/**
	 * Normalise a country code. Returns '' for invalid / unknown values.
	 *
	 * @param mixed $code Raw code.
	 * @return string
	 */
	public function sanitize_country( $code ) {
		$code = strtoupper( trim( (string) $code ) );

		if ( ! preg_match( '/^[A-Z]{2}$/', $code ) || in_array( $code, array( 'XX', 'T1' ), true ) ) {
			return '';
		}

		return $code;
	}

	/**
	 * Detect the visitor's ISO 3166-1 alpha-2 country code.
	 *
	 * @return string Country code or empty string when unknown.
	 */
	public function get_country() {
		$ip = $this->get_ip();

		// Localhost / private network: use the "test country" from settings.
		if ( '' === $ip ) {
			$settings = ROKGC_Plugin::get_settings();
			return $this->sanitize_country( $settings['test_country'] );
		}

		// 1. Country header supplied by Cloudflare / CloudFront / the web server.
		$country = $this->country_from_headers();
		if ( '' !== $country ) {
			return $country;
		}

		// 2. Cached lookup.
		$cache_key = 'rokgc_geo_' . md5( $ip );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return 'XX' === $cached ? '' : (string) $cached;
		}

		// 3. WooCommerce's bundled MaxMind integration, then 4. HTTP APIs.
		$country = $this->lookup_maxmind( $ip );
		if ( '' === $country ) {
			$country = $this->lookup_api( $ip );
		}

		// Cache failures briefly so a dead API is not hammered.
		set_transient( $cache_key, '' !== $country ? $country : 'XX', '' !== $country ? DAY_IN_SECONDS : HOUR_IN_SECONDS );

		return $country;
	}

	/**
	 * Read a trusted country header, if present.
	 *
	 * @return string
	 */
	private function country_from_headers() {
		$headers = apply_filters(
			'rokgc_country_headers',
			array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE', 'MM_COUNTRY_CODE' )
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$country = $this->sanitize_country( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				if ( '' !== $country ) {
					return $country;
				}
			}
		}

		return '';
	}

	/**
	 * Local MaxMind lookup via WooCommerce (needs a MaxMind licence key in
	 * WooCommerce > Settings > General).
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	private function lookup_maxmind( $ip ) {
		if ( ! class_exists( 'WC_Geolocation' ) ) {
			return '';
		}

		$data = WC_Geolocation::geolocate_ip( $ip, false, false );

		return isset( $data['country'] ) ? $this->sanitize_country( $data['country'] ) : '';
	}

	/**
	 * Free HTTPS GeoIP API fallbacks.
	 *
	 * @param string $ip Validated IP address.
	 * @return string
	 */
	private function lookup_api( $ip ) {
		$providers = apply_filters(
			'rokgc_geoip_providers',
			array(
				'ipwho'     => array(
					'url'  => 'https://ipwho.is/%s?fields=success,country_code',
					'path' => 'country_code',
				),
				'countryis' => array(
					'url'  => 'https://api.country.is/%s',
					'path' => 'country',
				),
				'ipapi'     => array(
					'url'  => 'https://ipapi.co/%s/country/',
					'path' => '',
				),
			)
		);

		foreach ( $providers as $provider ) {
			$response = wp_safe_remote_get(
				sprintf( $provider['url'], $ip ), // $ip is already validated by filter_var().
				array(
					'timeout'     => 2,
					'redirection' => 1,
					'user-agent'  => 'Rokonline-Geo-Currency/' . ROKGC_VERSION,
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}

			$body = trim( wp_remote_retrieve_body( $response ) );

			if ( '' === $provider['path'] ) {
				$candidate = $body;
			} else {
				$json      = json_decode( $body, true );
				$candidate = is_array( $json ) && isset( $json[ $provider['path'] ] ) ? $json[ $provider['path'] ] : '';
			}

			$country = $this->sanitize_country( $candidate );
			if ( '' !== $country ) {
				return $country;
			}
		}

		return '';
	}

	/**
	 * Groups of countries per currency. The first entry of each group is the
	 * country used for the switcher's flag.
	 *
	 * @return array
	 */
	private static function get_groups() {
		return array(
			'USD' => 'US,PR,GU,VI,AS,MP,EC,SV,PA,TL,MH,FM,PW,TC,VG',
			'EUR' => 'EU,AT,BE,BG,HR,CY,EE,FI,FR,DE,GR,IE,IT,LV,LT,LU,MT,NL,PT,SK,SI,ES,AD,MC,SM,VA,ME,XK',
			'GBP' => 'GB,GG,JE,IM',
			'CAD' => 'CA',
			'AUD' => 'AU,CX,CC,NF,KI,NR,TV',
			'NZD' => 'NZ,CK,NU,TK,PN',
			'CHF' => 'CH,LI',
			'JPY' => 'JP',
			'CNY' => 'CN',
			'HKD' => 'HK',
			'SGD' => 'SG',
			'INR' => 'IN',
			'KRW' => 'KR',
			'MXN' => 'MX',
			'BRL' => 'BR',
			'ZAR' => 'ZA',
			'SEK' => 'SE',
			'NOK' => 'NO',
			'DKK' => 'DK,FO,GL',
			'PLN' => 'PL',
			'CZK' => 'CZ',
			'HUF' => 'HU',
			'RON' => 'RO',
			'TRY' => 'TR',
			'ILS' => 'IL',
			'THB' => 'TH',
			'MYR' => 'MY',
			'IDR' => 'ID',
			'PHP' => 'PH',
			'ISK' => 'IS',
			'AED' => 'AE',
			'SAR' => 'SA',
			'QAR' => 'QA',
			'KWD' => 'KW',
			'EGP' => 'EG',
			'NGN' => 'NG',
			'KES' => 'KE',
			'GHS' => 'GH',
			'MAD' => 'MA',
			'ARS' => 'AR',
			'CLP' => 'CL',
			'COP' => 'CO',
			'PEN' => 'PE',
			'VND' => 'VN',
			'TWD' => 'TW',
			'PKR' => 'PK',
			'BDT' => 'BD',
			'UAH' => 'UA',
			'ALL' => 'AL',
			'AMD' => 'AM',
			'AZN' => 'AZ',
			'BAM' => 'BA',
			'BND' => 'BN',
			'BOB' => 'BO',
			'BSD' => 'BS',
			'BWP' => 'BW',
			'BZD' => 'BZ',
			'CRC' => 'CR',
			'DOP' => 'DO',
			'DZD' => 'DZ',
			'ETB' => 'ET',
			'FJD' => 'FJ',
			'GEL' => 'GE',
			'GIP' => 'GI',
			'GTQ' => 'GT',
			'GYD' => 'GY',
			'HNL' => 'HN',
			'JMD' => 'JM',
			'JOD' => 'JO',
			'BHD' => 'BH',
			'OMR' => 'OM',
			'KGS' => 'KG',
			'KHR' => 'KH',
			'KZT' => 'KZ',
			'LAK' => 'LA',
			'LBP' => 'LB',
			'LKR' => 'LK',
			'MDL' => 'MD',
			'MKD' => 'MK',
			'MNT' => 'MN',
			'MOP' => 'MO',
			'MUR' => 'MU',
			'MVR' => 'MV',
			'MZN' => 'MZ',
			'NAD' => 'NA',
			'NIO' => 'NI',
			'NPR' => 'NP',
			'PGK' => 'PG',
			'PYG' => 'PY',
			'RSD' => 'RS',
			'RWF' => 'RW',
			'TND' => 'TN',
			'TTD' => 'TT',
			'TZS' => 'TZ',
			'UGX' => 'UG',
			'UYU' => 'UY',
			'UZS' => 'UZ',
			'ZMW' => 'ZM',
			'WST' => 'WS',
			'TOP' => 'TO',
			'VUV' => 'VU',
			'SBD' => 'SB',
			'AWG' => 'AW',
			'BBD' => 'BB',
			'BMD' => 'BM',
			'KYD' => 'KY',
			'SCR' => 'SC',
			'SRD' => 'SR',
			'AFN' => 'AF',
			'MWK' => 'MW',
			'LRD' => 'LR',
			'GMD' => 'GM',
			'GNF' => 'GN',
			'MGA' => 'MG',
			'CDF' => 'CD',
			'BIF' => 'BI',
			'DJF' => 'DJ',
			'KMF' => 'KM',
			'SOS' => 'SO',
			'HTG' => 'HT',
			'SZL' => 'SZ',
			'LSL' => 'LS',
			'XCD' => 'AG,DM,GD,KN,LC,VC,AI,MS',
			'XOF' => 'SN,CI,ML,BF,BJ,NE,TG,GW',
			'XAF' => 'CM,GA,CG,TD,CF,GQ',
			'XPF' => 'PF,NC,WF',
			'ANG' => 'CW,SX',
		);
	}

	/**
	 * Built-in country => currency map.
	 *
	 * @return array
	 */
	public static function get_country_currency_map() {
		static $map = null;

		if ( null === $map ) {
			$map = array();
			foreach ( self::get_groups() as $currency => $list ) {
				foreach ( explode( ',', $list ) as $country ) {
					if ( ! isset( $map[ $country ] ) ) {
						$map[ $country ] = $currency;
					}
				}
			}
		}

		return apply_filters( 'rokgc_country_currency_map', $map );
	}

	/**
	 * Country used to draw a currency's flag (empty when unknown).
	 *
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function get_flag_country( $currency ) {
		$groups = self::get_groups();

		if ( ! isset( $groups[ $currency ] ) ) {
			return '';
		}

		$list = explode( ',', $groups[ $currency ] );

		return $list[0];
	}

	/**
	 * Resolve a currency for a country among the enabled currencies.
	 * Admin-defined country lists win over the built-in map.
	 *
	 * @param string $country ISO country code.
	 * @return string Currency code or '' if none matches.
	 */
	public function get_currency_for_country( $country ) {
		$country = strtoupper( (string) $country );
		if ( '' === $country ) {
			return '';
		}

		$settings = ROKGC_Plugin::get_settings();
		$enabled  = ROKGC_Plugin::get_enabled_codes();

		foreach ( $settings['currencies'] as $code => $config ) {
			if ( empty( $config['countries'] ) ) {
				continue;
			}
			$countries = array_map( 'trim', explode( ',', $config['countries'] ) );
			if ( in_array( $country, $countries, true ) ) {
				return (string) $code;
			}
		}

		$map = self::get_country_currency_map();
		if ( isset( $map[ $country ] ) && in_array( $map[ $country ], $enabled, true ) ) {
			return $map[ $country ];
		}

		return '';
	}

	/**
	 * Currency for the current visitor (falls back to the fallback currency).
	 *
	 * @return string
	 */
	public function get_visitor_currency() {
		$currency = $this->get_currency_for_country( $this->get_country() );

		if ( '' === $currency ) {
			$settings = ROKGC_Plugin::get_settings();
			$currency = $settings['fallback_currency'];
		}

		return $currency;
	}
}
