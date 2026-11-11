<?php
/**
 * Frontend currency switcher: [rokgc_currency_switcher] + AJAX endpoints.
 *
 * @package Rokonline_Geo_Currency
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ROKGC_Switcher.
 */
class ROKGC_Switcher {

	/**
	 * Singleton instance.
	 *
	 * @var ROKGC_Switcher|null
	 */
	private static $instance = null;

	/**
	 * Counter for unique element IDs.
	 *
	 * @var int
	 */
	private static $counter = 0;

	/**
	 * Get the singleton.
	 *
	 * @return ROKGC_Switcher
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
		add_shortcode( 'rokgc_currency_switcher', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		add_action( 'wp_ajax_rokgc_switch_currency', array( $this, 'ajax_switch' ) );
		add_action( 'wp_ajax_nopriv_rokgc_switch_currency', array( $this, 'ajax_switch' ) );
		add_action( 'wp_ajax_rokgc_detect_currency', array( $this, 'ajax_detect' ) );
		add_action( 'wp_ajax_nopriv_rokgc_detect_currency', array( $this, 'ajax_detect' ) );
	}

	/**
	 * Register the stylesheet and enqueue the script with its runtime data.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style( 'rokgc-style', ROKGC_PLUGIN_URL . 'assets/css/rokgc-style.css', array(), ROKGC_VERSION );

		$settings = ROKGC_Plugin::get_settings();
		$enabled  = ROKGC_Plugin::get_enabled_codes();

		// Nothing to switch between.
		if ( count( $enabled ) < 2 ) {
			return;
		}

		wp_enqueue_script(
			'rokgc-script',
			ROKGC_PLUGIN_URL . 'assets/js/rokgc-script.js',
			array(),
			ROKGC_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'rokgc-script',
			'rokgcData',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'rokgc_switch_currency' ),
				// Currency this HTML was rendered with (cached along with the page).
				'rendered'     => ROKGC_WC_Hooks::instance()->get_current_currency(),
				'currencies'   => $enabled,
				'geoip'        => ! empty( $settings['geoip_enabled'] ),
				'cacheMode'    => ! empty( $settings['cache_mode'] ),
				'cookieName'   => ROKGC_COOKIE,
				'cookiePath'   => COOKIEPATH ? COOKIEPATH : '/',
				'cookieDomain' => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'days'         => 30,
			)
		);
	}

	/**
	 * Flag emoji for a country code.
	 *
	 * @param string $country ISO country code (or "EU").
	 * @return string
	 */
	private function flag( $country ) {
		$country = strtoupper( $country );

		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) || ! function_exists( 'mb_chr' ) ) {
			return '';
		}

		return mb_chr( 0x1F1E6 + ord( $country[0] ) - 65, 'UTF-8' ) . mb_chr( 0x1F1E6 + ord( $country[1] ) - 65, 'UTF-8' );
	}

	/**
	 * Build the list of switchable currencies.
	 *
	 * @param bool $with_flags Prefix labels with flags.
	 * @return array code => label
	 */
	private function get_options( $with_flags ) {
		$options = array();

		foreach ( ROKGC_Plugin::get_enabled_codes() as $code ) {
			$symbol = html_entity_decode( get_woocommerce_currency_symbol( $code ), ENT_QUOTES, 'UTF-8' );
			$flag   = $with_flags ? $this->flag( ROKGC_Geoip::get_flag_country( $code ) ) : '';
			$label  = trim( $flag . ' ' . $code . ' (' . $symbol . ')' );

			$options[ $code ] = $label;
		}

		return $options;
	}

	/**
	 * Shortcode: [rokgc_currency_switcher style="dropdown|buttons" flags="yes|no"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'style' => 'dropdown',
				'flags' => 'yes',
			),
			$atts,
			'rokgc_currency_switcher'
		);

		$options = $this->get_options( 'yes' === strtolower( $atts['flags'] ) );

		if ( count( $options ) < 2 ) {
			return '';
		}

		wp_enqueue_style( 'rokgc-style' );

		$style   = 'buttons' === strtolower( $atts['style'] ) ? 'buttons' : 'dropdown';
		$current = ROKGC_WC_Hooks::instance()->get_current_currency();
		$id      = 'rokgc-switcher-' . ( ++self::$counter );

		ob_start();
		?>
		<div class="rokgc-switcher rokgc-switcher--<?php echo esc_attr( $style ); ?>" data-rokgc-switcher>
			<?php if ( 'dropdown' === $style ) : ?>
				<label class="rokgc-switcher__label" for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Currency', 'rokonline-geo-currency' ); ?></label>
				<select id="<?php echo esc_attr( $id ); ?>" class="rokgc-switcher__select">
					<?php foreach ( $options as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current, $code ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<ul class="rokgc-switcher__list">
					<?php foreach ( $options as $code => $label ) : ?>
						<li>
							<button type="button" class="rokgc-switcher__btn<?php echo $current === $code ? ' is-active' : ''; ?>" data-rokgc-currency="<?php echo esc_attr( $code ); ?>" aria-pressed="<?php echo $current === $code ? 'true' : 'false'; ?>"><?php echo esc_html( $label ); ?></button>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<noscript>
				<ul class="rokgc-switcher__list">
					<?php foreach ( $options as $code => $label ) : ?>
						<li><a class="rokgc-switcher__btn" href="<?php echo esc_url( add_query_arg( 'rokgc_cur', $code ) ); ?>" rel="nofollow"><?php echo esc_html( $label ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</noscript>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX: manual currency switch. Sets the cookie server-side.
	 * If the nonce is stale (cached page), the JS falls back to ?rokgc_cur=.
	 *
	 * @return void
	 */
	public function ajax_switch() {
		nocache_headers();

		if ( ! check_ajax_referer( 'rokgc_switch_currency', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'rokonline-geo-currency' ) ), 403 );
		}

		$currency = isset( $_POST['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) ) : '';

		if ( ! in_array( $currency, ROKGC_Plugin::get_enabled_codes(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown currency.', 'rokonline-geo-currency' ) ), 400 );
		}

		ROKGC_WC_Hooks::set_cookie( $currency );

		wp_send_json_success( array( 'currency' => $currency ) );
	}

	/**
	 * AJAX: browser-side GeoIP detection (page-cache safe mode).
	 * No nonce: it only reads the caller's IP and stores a currency preference,
	 * and a nonce embedded in cached HTML would expire.
	 *
	 * @return void
	 */
	public function ajax_detect() {
		nocache_headers();

		$settings = ROKGC_Plugin::get_settings();
		$enabled  = ROKGC_Plugin::get_enabled_codes();

		if ( empty( $settings['geoip_enabled'] ) ) {
			wp_send_json_error( array( 'message' => 'disabled' ) );
		}

		// Respect an existing valid choice.
		if ( isset( $_COOKIE[ ROKGC_COOKIE ] ) ) {
			$existing = strtoupper( sanitize_text_field( wp_unslash( $_COOKIE[ ROKGC_COOKIE ] ) ) );
			if ( in_array( $existing, $enabled, true ) ) {
				wp_send_json_success( array( 'currency' => $existing ) );
			}
		}

		$geo      = ROKGC_Geoip::instance();
		$country  = $geo->get_country();
		$currency = $geo->get_visitor_currency();

		if ( ! in_array( $currency, $enabled, true ) ) {
			$currency = $settings['base_currency'];
		}

		ROKGC_WC_Hooks::set_cookie( $currency );

		wp_send_json_success(
			array(
				'currency' => $currency,
				'country'  => $country,
			)
		);
	}
}
