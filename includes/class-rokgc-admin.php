<?php
/**
 * Admin settings page: WooCommerce > Geo Currency.
 *
 * @package Rokonline_Geo_Currency
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class ROKGC_Admin.
 */
class ROKGC_Admin {

	const GROUP = 'rokgc_settings_group';
	const SLUG  = 'rokgc-settings';

	/**
	 * Singleton instance.
	 *
	 * @var ROKGC_Admin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return ROKGC_Admin
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
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_rokgc_sync_rates', array( $this, 'handle_sync' ) );
		add_action( 'admin_post_rokgc_bulk_add', array( $this, 'handle_bulk_add' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( $this, 'settings_capability' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ROKGC_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Capability used by options.php for our settings group.
	 *
	 * @return string
	 */
	public function settings_capability() {
		return 'manage_woocommerce';
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'rokonline-geo-currency' ) . '</a>' );

		return $links;
	}

	/**
	 * Add the submenu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Geo Currency', 'rokonline-geo-currency' ),
			__( 'Geo Currency', 'rokonline-geo-currency' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option with its sanitiser.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			ROKGC_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => ROKGC_Plugin::get_defaults(),
			)
		);
	}

	/**
	 * Admin CSS/JS (only on our page).
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_add_inline_style(
			'common',
			'.rokgc-table{max-width:1100px;border-collapse:collapse;background:#fff}.rokgc-table th,.rokgc-table td{padding:8px 10px;border:1px solid #dcdcde;text-align:left;vertical-align:middle}.rokgc-table input[type=number]{width:90px}.rokgc-table .rokgc-symbol{width:60px}.rokgc-table .rokgc-countries{width:180px}.rokgc-diag td{padding:4px 12px 4px 0}'
		);

		wp_register_script( 'rokgc-admin', '', array(), ROKGC_VERSION, true );
		wp_enqueue_script( 'rokgc-admin' );
		wp_add_inline_script(
			'rokgc-admin',
			<<<'JS'
(function () {
	var body = document.getElementById('rokgc-rows');
	var tpl = document.getElementById('rokgc-row-template');
	var add = document.getElementById('rokgc-add-row');
	if (!body || !tpl || !add) { return; }
	var idx = parseInt(body.getAttribute('data-next'), 10) || 0;
	add.addEventListener('click', function (e) {
		e.preventDefault();
		body.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__INDEX__/g, String(idx++)));
	});
	body.addEventListener('click', function (e) {
		var btn = e.target.closest('.rokgc-remove-row');
		if (btn) {
			e.preventDefault();
			var row = btn.closest('tr');
			if (row) { row.remove(); }
		}
	});
	body.addEventListener('change', function (e) {
		if (e.target.classList.contains('rokgc-code-select')) {
			var row = e.target.closest('tr');
			var sym = row.querySelector('.rokgc-symbol');
			var opt = e.target.options[e.target.selectedIndex];
			if (sym && !sym.value && opt) { sym.value = opt.getAttribute('data-symbol') || ''; }
		}
	});
})();
JS
		);
	}

	/* ---------------------------------------------------------------------
	 * Sanitisation
	 * ------------------------------------------------------------------ */

	/**
	 * Sanitise the whole settings array. Idempotent: it accepts both the form
	 * layout (indexed rows) and the stored layout (rows keyed by code).
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = ROKGC_Plugin::get_defaults();
		$all      = get_woocommerce_currencies();
		$out      = array();

		$out['geoip_enabled'] = empty( $input['geoip_enabled'] ) ? 0 : 1;
		$out['cache_mode']    = empty( $input['cache_mode'] ) ? 0 : 1;

		$base = isset( $input['base_currency'] ) ? strtoupper( sanitize_text_field( $input['base_currency'] ) ) : $defaults['base_currency'];
		if ( ! isset( $all[ $base ] ) ) {
			$base = $defaults['base_currency'];
		}
		$out['base_currency'] = $base;

		$provider              = isset( $input['rate_provider'] ) ? sanitize_key( $input['rate_provider'] ) : 'currencyapi';
		$out['rate_provider']  = in_array( $provider, array( 'currencyapi', 'frankfurter', 'oxr' ), true ) ? $provider : 'currencyapi';
		$out['oxr_app_id']     = isset( $input['oxr_app_id'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( $input['oxr_app_id'] ) ) : '';
		$out['cache_hours']    = isset( $input['cache_hours'] ) ? max( 1, min( 168, absint( $input['cache_hours'] ) ) ) : 12;
		$test_country          = isset( $input['test_country'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', sanitize_text_field( $input['test_country'] ) ) ) : '';
		$out['test_country']   = 2 === strlen( $test_country ) ? $test_country : '';
		$out['currencies']     = array();

		$rows = isset( $input['currencies'] ) && is_array( $input['currencies'] ) ? $input['currencies'] : array();

		foreach ( $rows as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$code = isset( $row['code'] ) ? $row['code'] : ( is_string( $key ) ? $key : '' );
			$code = strtoupper( sanitize_text_field( $code ) );

			if ( '' === $code || $code === $base || ! isset( $all[ $code ] ) ) {
				continue;
			}

			$mode = isset( $row['mode'] ) && 'manual' === $row['mode'] ? 'manual' : 'auto';
			$rate = isset( $row['rate'] ) ? (float) str_replace( ',', '.', sanitize_text_field( (string) $row['rate'] ) ) : 0.0;

			if ( 'manual' === $mode && $rate <= 0 ) {
				/* translators: %s: currency code */
				add_settings_error( 'rokgc_settings', 'rokgc_rate_' . $code, sprintf( __( '%s was skipped: a manual rate must be greater than zero.', 'rokonline-geo-currency' ), $code ), 'error' );
				continue;
			}

			$countries = array();
			if ( ! empty( $row['countries'] ) ) {
				$list = preg_split( '/[\s,;]+/', strtoupper( sanitize_text_field( $row['countries'] ) ) );
				foreach ( $list as $country ) {
					if ( preg_match( '/^[A-Z]{2}$/', $country ) ) {
						$countries[] = $country;
					}
				}
			}

			$out['currencies'][ $code ] = array(
				'code'      => $code,
				'symbol'    => isset( $row['symbol'] ) ? sanitize_text_field( $row['symbol'] ) : '',
				'mode'      => $mode,
				'rate'      => $rate,
				'decimals'  => isset( $row['decimals'] ) ? max( 0, min( 4, absint( $row['decimals'] ) ) ) : 2,
				'countries' => implode( ',', array_unique( $countries ) ),
			);
		}

		$fallback = isset( $input['fallback_currency'] ) ? strtoupper( sanitize_text_field( $input['fallback_currency'] ) ) : $base;
		if ( $fallback !== $base && ! isset( $out['currencies'][ $fallback ] ) ) {
			$fallback = $base;
		}
		$out['fallback_currency'] = $fallback;

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	/**
	 * "Sync rates now" button handler.
	 *
	 * @return void
	 */
	public function handle_sync() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'rokonline-geo-currency' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'rokgc_sync_rates' );

		$result = ROKGC_Rates::instance()->sync_now();

		if ( is_wp_error( $result ) ) {
			$notice = array(
				'type' => 'error',
				'text' => $result->get_error_message(),
			);
		} else {
			$notice = array(
				'type' => 'success',
				/* translators: %d: number of currencies */
				'text' => sprintf( _n( 'Rates synced for %d currency.', 'Rates synced for %d currencies.', $result, 'rokonline-geo-currency' ), $result ),
			);
		}

		set_transient( 'rokgc_admin_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Common currencies that Stripe supports for card payments. Availability
	 * still depends on the Stripe account's country: always verify against
	 * https://docs.stripe.com/currencies .
	 *
	 * @return string[]
	 */
	private function get_stripe_common() {
		return array( 'USD', 'EUR', 'GBP', 'CAD', 'NZD', 'SGD', 'HKD', 'JPY', 'KRW', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'RON', 'ILS', 'AED', 'MXN', 'INR', 'THB', 'MYR', 'PHP', 'IDR', 'ZAR', 'BRL', 'TWD' );
	}

	/**
	 * "Bulk add" handler: adds many currencies at once, then syncs rates.
	 *
	 * @return void
	 */
	public function handle_bulk_add() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'rokonline-geo-currency' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'rokgc_bulk_add' );

		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'custom';

		if ( 'preset' === $mode ) {
			$codes = $this->get_stripe_common();
		} else {
			$raw   = isset( $_POST['rokgc_codes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rokgc_codes'] ) ) : '';
			$codes = preg_split( '/[\s,;]+/', strtoupper( $raw ), -1, PREG_SPLIT_NO_EMPTY );
		}

		$all       = get_woocommerce_currencies();
		$settings  = ROKGC_Plugin::get_settings();
		$zero_dec  = array( 'JPY', 'KRW', 'VND', 'CLP', 'ISK', 'UGX', 'XAF', 'XOF', 'XPF', 'BIF', 'DJF', 'GNF', 'KMF', 'MGA', 'PYG', 'RWF', 'VUV' );
		$symbols   = array(
			'USD' => 'US$',
			'AUD' => 'A$',
			'CAD' => 'C$',
			'NZD' => 'NZ$',
			'SGD' => 'S$',
			'HKD' => 'HK$',
			'MXN' => 'MX$',
			'TWD' => 'NT$',
			'CNY' => 'CN¥',
		);
		$added     = 0;
		$unknown   = array();

		foreach ( array_unique( $codes ) as $code ) {
			if ( ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
				continue;
			}
			if ( $code === $settings['base_currency'] || isset( $settings['currencies'][ $code ] ) ) {
				continue;
			}
			if ( ! isset( $all[ $code ] ) ) {
				$unknown[] = $code;
				continue;
			}

			$settings['currencies'][ $code ] = array(
				'code'      => $code,
				'symbol'    => isset( $symbols[ $code ] ) ? $symbols[ $code ] : '',
				'mode'      => 'auto',
				'rate'      => 0,
				'decimals'  => in_array( $code, $zero_dec, true ) ? 0 : 2,
				'countries' => '',
			);
			++$added;
		}

		update_option( ROKGC_OPTION, $settings );

		$sync = ROKGC_Rates::instance()->sync_now();

		/* translators: %d: number of currencies */
		$text = sprintf( _n( '%d currency added.', '%d currencies added.', $added, 'rokonline-geo-currency' ), $added );

		if ( ! empty( $unknown ) ) {
			/* translators: %s: list of currency codes */
			$text .= ' ' . sprintf( __( 'Not known to WooCommerce, skipped: %s.', 'rokonline-geo-currency' ), implode( ', ', $unknown ) );
		}

		if ( is_wp_error( $sync ) ) {
			$text .= ' ' . sprintf(
				/* translators: %s: error message */
				__( 'Rates could not be synced: %s', 'rokonline-geo-currency' ),
				$sync->get_error_message()
			);
		}

		set_transient(
			'rokgc_admin_notice_' . get_current_user_id(),
			array(
				'type' => is_wp_error( $sync ) ? 'error' : 'success',
				'text' => $text,
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Currency choices for selects: code => array( label, symbol ).
	 *
	 * @return array
	 */
	private function get_choices() {
		static $choices = null;

		if ( null === $choices ) {
			$choices = array();
			foreach ( get_woocommerce_currencies() as $code => $label ) {
				$choices[ $code ] = array(
					'label'  => $code . ' — ' . $label,
					'symbol' => html_entity_decode( get_woocommerce_currency_symbol( $code ), ENT_QUOTES, 'UTF-8' ),
				);
			}
		}

		return $choices;
	}

	/**
	 * Render a single currency row.
	 *
	 * @param string|int $index Row index.
	 * @param array      $row   Row data.
	 * @return void
	 */
	private function render_row( $index, $row ) {
		$row  = wp_parse_args(
			$row,
			array(
				'code'      => '',
				'symbol'    => '',
				'mode'      => 'auto',
				'rate'      => '',
				'decimals'  => 2,
				'countries' => '',
			)
		);
		$name = ROKGC_OPTION . '[currencies][' . $index . ']';
		?>
		<tr>
			<td>
				<select name="<?php echo esc_attr( $name . '[code]' ); ?>" class="rokgc-code-select">
					<option value=""><?php esc_html_e( 'Select…', 'rokonline-geo-currency' ); ?></option>
					<?php foreach ( $this->get_choices() as $code => $choice ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" data-symbol="<?php echo esc_attr( $choice['symbol'] ); ?>" <?php selected( $row['code'], $code ); ?>><?php echo esc_html( $choice['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" class="rokgc-symbol" name="<?php echo esc_attr( $name . '[symbol]' ); ?>" value="<?php echo esc_attr( $row['symbol'] ); ?>" maxlength="10" /></td>
			<td>
				<select name="<?php echo esc_attr( $name . '[mode]' ); ?>">
					<option value="auto" <?php selected( $row['mode'], 'auto' ); ?>><?php esc_html_e( 'Automatic', 'rokonline-geo-currency' ); ?></option>
					<option value="manual" <?php selected( $row['mode'], 'manual' ); ?>><?php esc_html_e( 'Manual', 'rokonline-geo-currency' ); ?></option>
				</select>
			</td>
			<td><input type="number" step="any" min="0" name="<?php echo esc_attr( $name . '[rate]' ); ?>" value="<?php echo esc_attr( $row['rate'] ); ?>" /></td>
			<td><input type="number" step="1" min="0" max="4" name="<?php echo esc_attr( $name . '[decimals]' ); ?>" value="<?php echo esc_attr( $row['decimals'] ); ?>" style="width:60px" /></td>
			<td><input type="text" class="rokgc-countries" name="<?php echo esc_attr( $name . '[countries]' ); ?>" value="<?php echo esc_attr( $row['countries'] ); ?>" placeholder="DE,FR,IT" /></td>
			<td><button type="button" class="button-link-delete rokgc-remove-row"><?php esc_html_e( 'Remove', 'rokonline-geo-currency' ); ?></button></td>
		</tr>
		<?php
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rokonline-geo-currency' ), '', array( 'response' => 403 ) );
		}

		$settings = ROKGC_Plugin::get_settings();
		$choices  = $this->get_choices();
		$geo      = ROKGC_Geoip::instance();
		$rates    = ROKGC_Rates::instance();
		$name     = ROKGC_OPTION;
		$country  = $geo->get_country();
		$ip       = $geo->get_ip();
		$last     = get_option( ROKGC_Rates::LAST_GOOD );
		$notice   = get_transient( 'rokgc_admin_notice_' . get_current_user_id() );
		$wc_base  = get_option( 'woocommerce_currency', 'USD' );

		if ( $notice ) {
			delete_transient( 'rokgc_admin_notice_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Geo Currency', 'rokonline-geo-currency' ); ?></h1>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo 'success' === $notice['type'] ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
			<?php endif; ?>

			<?php settings_errors(); ?>

			<?php if ( $settings['base_currency'] !== $wc_base ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					printf(
						/* translators: 1: plugin base currency, 2: WooCommerce currency */
						esc_html__( 'The base currency here (%1$s) differs from WooCommerce > Settings > General (%2$s). Product prices must be entered in the base currency set below.', 'rokonline-geo-currency' ),
						esc_html( $settings['base_currency'] ),
						esc_html( $wc_base )
					);
					?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'GeoIP auto switch', 'rokonline-geo-currency' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[geoip_enabled]" value="1" <?php checked( 1, (int) $settings['geoip_enabled'] ); ?> /> <?php esc_html_e( 'Detect the visitor\'s country and pick the currency automatically', 'rokonline-geo-currency' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="rokgc_base"><?php esc_html_e( 'Base currency', 'rokonline-geo-currency' ); ?></label></th>
						<td>
							<select id="rokgc_base" name="<?php echo esc_attr( $name ); ?>[base_currency]">
								<?php foreach ( $choices as $code => $choice ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['base_currency'], $code ); ?>><?php echo esc_html( $choice['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The currency your product prices are stored in. Should match WooCommerce > Settings > General.', 'rokonline-geo-currency' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rokgc_fallback"><?php esc_html_e( 'Fallback currency', 'rokonline-geo-currency' ); ?></label></th>
						<td>
							<select id="rokgc_fallback" name="<?php echo esc_attr( $name ); ?>[fallback_currency]">
								<?php foreach ( $choices as $code => $choice ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['fallback_currency'], $code ); ?>><?php echo esc_html( $choice['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Used when the country is unknown or has no matching currency. Must be the base currency or one of the currencies below.', 'rokonline-geo-currency' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rokgc_provider"><?php esc_html_e( 'Rate provider', 'rokonline-geo-currency' ); ?></label></th>
						<td>
							<select id="rokgc_provider" name="<?php echo esc_attr( $name ); ?>[rate_provider]">
								<option value="currencyapi" <?php selected( $settings['rate_provider'], 'currencyapi' ); ?>><?php esc_html_e( 'Open currency data (free, no key, ~200 currencies)', 'rokonline-geo-currency' ); ?></option>
								<option value="frankfurter" <?php selected( $settings['rate_provider'], 'frankfurter' ); ?>><?php esc_html_e( 'Frankfurter / ECB (free, no key, ~30 currencies)', 'rokonline-geo-currency' ); ?></option>
								<option value="oxr" <?php selected( $settings['rate_provider'], 'oxr' ); ?>><?php esc_html_e( 'Open Exchange Rates (App ID required)', 'rokonline-geo-currency' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rokgc_oxr"><?php esc_html_e( 'Open Exchange Rates App ID', 'rokonline-geo-currency' ); ?></label></th>
						<td><input type="text" id="rokgc_oxr" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $name ); ?>[oxr_app_id]" value="<?php echo esc_attr( $settings['oxr_app_id'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="rokgc_hours"><?php esc_html_e( 'Rate cache (hours)', 'rokonline-geo-currency' ); ?></label></th>
						<td><input type="number" id="rokgc_hours" min="1" max="168" name="<?php echo esc_attr( $name ); ?>[cache_hours]" value="<?php echo esc_attr( $settings['cache_hours'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Page-cache safe mode', 'rokonline-geo-currency' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[cache_mode]" value="1" <?php checked( 1, (int) $settings['cache_mode'] ); ?> /> <?php esc_html_e( 'Detect the country in the browser (AJAX) instead of on page load', 'rokonline-geo-currency' ); ?></label>
							<p class="description"><?php esc_html_e( 'Enable when using Cloudflare APO, LiteSpeed, Nginx FastCGI, WP Rocket etc. Also configure your cache to vary on the "rokgc_currency" cookie.', 'rokonline-geo-currency' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rokgc_test"><?php esc_html_e( 'Test country (localhost)', 'rokonline-geo-currency' ); ?></label></th>
						<td>
							<input type="text" id="rokgc_test" maxlength="2" size="3" name="<?php echo esc_attr( $name ); ?>[test_country]" value="<?php echo esc_attr( $settings['test_country'] ); ?>" placeholder="DE" />
							<p class="description"><?php esc_html_e( 'Only used when the visitor IP is private/local (development). ISO code, e.g. DE.', 'rokonline-geo-currency' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Target currencies', 'rokonline-geo-currency' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Rate = how many units of the target currency equal 1 unit of the base currency. In Automatic mode the rate field is refreshed by the API and used as a fallback. Leave "Countries" empty to use the built-in country map.', 'rokonline-geo-currency' ); ?></p>

				<table class="rokgc-table widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Currency', 'rokonline-geo-currency' ); ?></th>
							<th><?php esc_html_e( 'Symbol', 'rokonline-geo-currency' ); ?></th>
							<th><?php esc_html_e( 'Rate mode', 'rokonline-geo-currency' ); ?></th>
							<th><?php esc_html_e( 'Rate', 'rokonline-geo-currency' ); ?></th>
							<th><?php esc_html_e( 'Decimals', 'rokonline-geo-currency' ); ?></th>
							<th><?php esc_html_e( 'Countries (optional)', 'rokonline-geo-currency' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody id="rokgc-rows" data-next="<?php echo esc_attr( count( $settings['currencies'] ) ); ?>">
						<?php
						$i = 0;
						foreach ( $settings['currencies'] as $row ) {
							$this->render_row( $i++, $row );
						}
						?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="rokgc-add-row"><?php esc_html_e( 'Add currency', 'rokonline-geo-currency' ); ?></button></p>

				<script type="text/template" id="rokgc-row-template">
					<?php
					ob_start();
					$this->render_row( '__INDEX__', array() );
					echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render_row().
					?>
				</script>

				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Bulk add currencies', 'rokonline-geo-currency' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rokgc_bulk_add" />
				<?php wp_nonce_field( 'rokgc_bulk_add' ); ?>
				<p class="description"><?php esc_html_e( 'Paste currency codes (for example the list from Stripe\'s "Supported currencies" page for your country), separated by spaces or commas. New currencies are added with automatic rates.', 'rokonline-geo-currency' ); ?></p>
				<p><textarea name="rokgc_codes" rows="4" class="large-text code" placeholder="USD EUR GBP NZD SGD ..."></textarea></p>
				<p>
					<button type="submit" name="mode" value="custom" class="button button-primary"><?php esc_html_e( 'Add pasted currencies', 'rokonline-geo-currency' ); ?></button>
					<button type="submit" name="mode" value="preset" class="button"><?php esc_html_e( 'Add common Stripe currencies', 'rokonline-geo-currency' ); ?></button>
				</p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Exchange rates', 'rokonline-geo-currency' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rokgc_sync_rates" />
				<?php wp_nonce_field( 'rokgc_sync_rates' ); ?>
				<?php submit_button( __( 'Sync rates now', 'rokonline-geo-currency' ), 'secondary', 'submit', false ); ?>
				<?php if ( is_array( $last ) && ! empty( $last['time'] ) ) : ?>
					<span class="description">
						<?php
						/* translators: %s: human-readable time difference */
						printf( esc_html__( 'Last successful sync: %s ago.', 'rokonline-geo-currency' ), esc_html( human_time_diff( (int) $last['time'] ) ) );
						?>
					</span>
				<?php endif; ?>
			</form>
			<?php if ( '' !== $rates->get_last_error() ) : ?>
				<p style="color:#b32d2e"><?php echo esc_html( $rates->get_last_error() ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Detection test (your current connection)', 'rokonline-geo-currency' ); ?></h2>
			<table class="rokgc-diag">
				<tr><td><strong><?php esc_html_e( 'IP', 'rokonline-geo-currency' ); ?></strong></td><td><?php echo '' !== $ip ? esc_html( $ip ) : esc_html__( 'Private / local address', 'rokonline-geo-currency' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Country', 'rokonline-geo-currency' ); ?></strong></td><td><?php echo '' !== $country ? esc_html( $country ) : esc_html__( 'Unknown', 'rokonline-geo-currency' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Resulting currency', 'rokonline-geo-currency' ); ?></strong></td><td><?php echo esc_html( $geo->get_visitor_currency() ); ?></td></tr>
			</table>
		</div>
		<?php
	}
}
