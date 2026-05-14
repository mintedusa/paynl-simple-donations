<?php
/**
 * Plugin Name:       PAY.nl Simple Donations
 * Plugin URI:        https://elisia-europe.org
 * Description:       Simple, fast donation form integrated with PAY.nl API (v1). Preset amounts + custom amount, one-step checkout. Confirmation emails + customizable form description.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Cristian
 * License:           GPL-2.0-or-later
 * Text Domain:       paynl-donations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PAYNL_DONATIONS_VERSION', '1.1.0' );
define( 'PAYNL_DONATIONS_FILE', __FILE__ );
define( 'PAYNL_DONATIONS_PATH', plugin_dir_path( __FILE__ ) );
define( 'PAYNL_DONATIONS_URL', plugin_dir_url( __FILE__ ) );
define( 'PAYNL_DONATIONS_API_BASE', 'https://connect.pay.nl/v1' );

class PayNL_Simple_Donations {

	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',           [ $this, 'register_settings_page' ] );
		add_action( 'admin_init',           [ $this, 'register_settings' ] );
		add_action( 'init',                 [ $this, 'register_shortcode' ] );
		add_action( 'wp_enqueue_scripts',   [ $this, 'maybe_enqueue_assets' ] );
		add_action( 'rest_api_init',        [ $this, 'register_rest_routes' ] );

		// AJAX (logged-in + guests)
		add_action( 'wp_ajax_paynl_create_order',        [ $this, 'ajax_create_order' ] );
		add_action( 'wp_ajax_nopriv_paynl_create_order', [ $this, 'ajax_create_order' ] );

		// Admin test-email action
		add_action( 'admin_post_paynl_send_test_email', [ $this, 'admin_send_test_email' ] );
	}

	/* -------------------------------------------------------------------------
	 * Settings
	 * ---------------------------------------------------------------------- */

	public function register_settings_page() {
		add_options_page(
			'PAY.nl Donations',
			'PAY.nl Donations',
			'manage_options',
			'paynl-donations',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings() {
		$fields = [
			// Credentials
			'paynl_token_code',
			'paynl_api_token',
			'paynl_service_id',
			'paynl_test_mode',
			// Donation settings
			'paynl_currency',
			'paynl_preset_amounts',
			'paynl_allow_custom',
			'paynl_min_amount',
			'paynl_max_amount',
			'paynl_description',
			'paynl_success_url',
			'paynl_cancel_url',
			'paynl_require_email',
			'paynl_require_name',
			'paynl_button_label',
			// Form display (new)
			'paynl_form_description',
			'paynl_show_form_description',
			'paynl_privacy_url',
			// Email (new)
			'paynl_email_enabled',
			'paynl_email_from_name',
			'paynl_email_from_address',
			'paynl_email_reply_to',
			'paynl_email_admin_bcc',
			'paynl_email_subject',
			'paynl_email_body',
		];
		foreach ( $fields as $f ) {
			register_setting( 'paynl_donations_group', $f );
		}
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$webhook_url = rest_url( 'paynl-donations/v1/webhook' );

		// Show admin notices for test email
		if ( isset( $_GET['paynl_test_email'] ) ) {
			$status = sanitize_text_field( wp_unslash( $_GET['paynl_test_email'] ) );
			if ( $status === 'sent' ) {
				echo '<div class="notice notice-success is-dismissible"><p>✓ Test email sent successfully.</p></div>';
			} elseif ( $status === 'fail' ) {
				echo '<div class="notice notice-error is-dismissible"><p>✗ Test email failed. Check your SMTP configuration / mail logs.</p></div>';
			} elseif ( $status === 'noaddr' ) {
				echo '<div class="notice notice-warning is-dismissible"><p>Please enter an email address to send the test to.</p></div>';
			}
		}
		?>
		<div class="wrap">
			<h1>PAY.nl Simple Donations</h1>

			<div class="notice notice-info" style="padding:12px;">
				<p><strong>Webhook URL (set this in PAY.nl admin panel as exchange URL):</strong></p>
				<code style="display:inline-block;padding:6px 10px;background:#fff;"><?php echo esc_html( $webhook_url ); ?></code>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'paynl_donations_group' ); ?>

				<h2>API credentials</h2>
				<table class="form-table">
					<tr>
						<th><label>Token code (AT-...)</label></th>
						<td><input type="text" name="paynl_token_code" value="<?php echo esc_attr( get_option( 'paynl_token_code' ) ); ?>" class="regular-text" placeholder="AT-1234-5678"></td>
					</tr>
					<tr>
						<th><label>API token (40-char hash)</label></th>
						<td><input type="password" name="paynl_api_token" value="<?php echo esc_attr( get_option( 'paynl_api_token' ) ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label>Service ID (SL-...)</label></th>
						<td><input type="text" name="paynl_service_id" value="<?php echo esc_attr( get_option( 'paynl_service_id' ) ); ?>" class="regular-text" placeholder="SL-1234-5678"></td>
					</tr>
					<tr>
						<th><label>Test mode</label></th>
						<td><label><input type="checkbox" name="paynl_test_mode" value="1" <?php checked( get_option( 'paynl_test_mode' ), '1' ); ?>> Use PAY.nl test environment</label></td>
					</tr>
				</table>

				<h2>Donation settings</h2>
				<table class="form-table">
					<tr>
						<th><label>Currency</label></th>
						<td><input type="text" name="paynl_currency" value="<?php echo esc_attr( get_option( 'paynl_currency', 'EUR' ) ); ?>" class="small-text" maxlength="3"></td>
					</tr>
					<tr>
						<th><label>Preset amounts</label></th>
						<td>
							<input type="text" name="paynl_preset_amounts" value="<?php echo esc_attr( get_option( 'paynl_preset_amounts', '10,25,50,100' ) ); ?>" class="regular-text" placeholder="10,25,50,100">
							<p class="description">Comma-separated whole numbers in your currency.</p>
						</td>
					</tr>
					<tr>
						<th><label>Allow custom amount</label></th>
						<td><label><input type="checkbox" name="paynl_allow_custom" value="1" <?php checked( get_option( 'paynl_allow_custom', '1' ), '1' ); ?>> Show "Other amount" field</label></td>
					</tr>
					<tr>
						<th><label>Min / Max amount</label></th>
						<td>
							<input type="number" name="paynl_min_amount" value="<?php echo esc_attr( get_option( 'paynl_min_amount', '2' ) ); ?>" class="small-text" min="1"> /
							<input type="number" name="paynl_max_amount" value="<?php echo esc_attr( get_option( 'paynl_max_amount', '10000' ) ); ?>" class="small-text" min="1">
						</td>
					</tr>
					<tr>
						<th><label>Statement description</label></th>
						<td><input type="text" name="paynl_description" value="<?php echo esc_attr( get_option( 'paynl_description', 'Donation' ) ); ?>" class="regular-text" maxlength="30"><p class="description">Max 30 chars — shown on donor's bank statement.</p></td>
					</tr>
					<tr>
						<th><label>Require email</label></th>
						<td><label><input type="checkbox" name="paynl_require_email" value="1" <?php checked( get_option( 'paynl_require_email', '1' ), '1' ); ?>> Required for receipt email</label></td>
					</tr>
					<tr>
						<th><label>Require name</label></th>
						<td><label><input type="checkbox" name="paynl_require_name" value="1" <?php checked( get_option( 'paynl_require_name', '0' ), '1' ); ?>> Ask for donor name</label></td>
					</tr>
					<tr>
						<th><label>Button label</label></th>
						<td><input type="text" name="paynl_button_label" value="<?php echo esc_attr( get_option( 'paynl_button_label', 'Doneer nu' ) ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label>Success URL</label></th>
						<td><input type="url" name="paynl_success_url" value="<?php echo esc_attr( get_option( 'paynl_success_url' ) ); ?>" class="regular-text" placeholder="<?php echo esc_url( home_url( '/bedankt' ) ); ?>"></td>
					</tr>
					<tr>
						<th><label>Cancel URL</label></th>
						<td><input type="url" name="paynl_cancel_url" value="<?php echo esc_attr( get_option( 'paynl_cancel_url' ) ); ?>" class="regular-text" placeholder="<?php echo esc_url( home_url( '/donatie-geannuleerd' ) ); ?>"></td>
					</tr>
				</table>

				<h2>Form display</h2>
				<p class="description">Optional explanatory text shown above the donation buttons (e.g. "Your gift supports our work with…").</p>
				<table class="form-table">
					<tr>
						<th><label>Show description on form</label></th>
						<td><label><input type="checkbox" name="paynl_show_form_description" value="1" <?php checked( get_option( 'paynl_show_form_description', '0' ), '1' ); ?>> Display the text below above the donation form</label></td>
					</tr>
					<tr>
						<th><label>Form description text</label></th>
						<td>
							<textarea name="paynl_form_description" rows="5" class="large-text" placeholder="Your donation supports our work to…"><?php echo esc_textarea( get_option( 'paynl_form_description', '' ) ); ?></textarea>
							<p class="description">Plain text or basic HTML (&lt;p&gt;, &lt;strong&gt;, &lt;em&gt;, &lt;a&gt;). Line breaks become paragraphs automatically.</p>
						</td>
					</tr>
					<tr>
						<th><label>Privacy policy URL</label></th>
						<td>
							<input type="url" name="paynl_privacy_url" value="<?php echo esc_attr( get_option( 'paynl_privacy_url' ) ); ?>" class="regular-text" placeholder="https://elisia-europe.org/privacy">
							<p class="description">If set, a small "Privacy policy" link appears under the donation button.</p>
						</td>
					</tr>
				</table>

				<h2>Confirmation email</h2>
				<p class="description">Sent automatically to the donor when PAY.nl confirms a successful payment (via webhook).</p>
				<table class="form-table">
					<tr>
						<th><label>Enable confirmation email</label></th>
						<td><label><input type="checkbox" name="paynl_email_enabled" value="1" <?php checked( get_option( 'paynl_email_enabled', '0' ), '1' ); ?>> Send a thank-you / receipt email after each successful donation</label></td>
					</tr>
					<tr>
						<th><label>From name</label></th>
						<td><input type="text" name="paynl_email_from_name" value="<?php echo esc_attr( get_option( 'paynl_email_from_name', get_bloginfo( 'name' ) ) ); ?>" class="regular-text" placeholder="Elisia Europe"></td>
					</tr>
					<tr>
						<th><label>From address</label></th>
						<td>
							<input type="email" name="paynl_email_from_address" value="<?php echo esc_attr( get_option( 'paynl_email_from_address', get_option( 'admin_email' ) ) ); ?>" class="regular-text" placeholder="no-reply@elisia-europe.org">
							<p class="description">Must be on a domain you own. Otherwise emails go to spam.</p>
						</td>
					</tr>
					<tr>
						<th><label>Reply-To address</label></th>
						<td>
							<input type="email" name="paynl_email_reply_to" value="<?php echo esc_attr( get_option( 'paynl_email_reply_to' ) ); ?>" class="regular-text" placeholder="contact@elisia-europe.org">
							<p class="description">Optional. Donors will reply here instead of the From address.</p>
						</td>
					</tr>
					<tr>
						<th><label>BCC admin</label></th>
						<td>
							<input type="email" name="paynl_email_admin_bcc" value="<?php echo esc_attr( get_option( 'paynl_email_admin_bcc' ) ); ?>" class="regular-text" placeholder="admin@elisia-europe.org">
							<p class="description">Optional. Sends a hidden copy of each receipt to the ONG so you see every donation in your inbox.</p>
						</td>
					</tr>
					<tr>
						<th><label>Subject</label></th>
						<td>
							<input type="text" name="paynl_email_subject" value="<?php echo esc_attr( get_option( 'paynl_email_subject', $this->default_email_subject() ) ); ?>" class="regular-text">
							<p class="description">Placeholders: <code>{name}</code>, <code>{amount}</code>, <code>{currency}</code>, <code>{reference}</code>, <code>{date}</code>, <code>{org_name}</code></p>
						</td>
					</tr>
					<tr>
						<th><label>Email body (HTML)</label></th>
						<td>
							<textarea name="paynl_email_body" rows="14" class="large-text code"><?php echo esc_textarea( get_option( 'paynl_email_body', $this->default_email_body() ) ); ?></textarea>
							<p class="description">HTML allowed. Same placeholders as Subject. Leave empty to use the default template.</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2>Test email</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#f7f5f0;padding:14px 18px;border-radius:8px;max-width:640px;">
				<input type="hidden" name="action" value="paynl_send_test_email">
				<?php wp_nonce_field( 'paynl_test_email' ); ?>
				<p>Send a sample confirmation email to verify formatting & delivery. Uses placeholder values.</p>
				<p>
					<input type="email" name="test_to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" class="regular-text" required>
					<button type="submit" class="button button-secondary">Send test email</button>
				</p>
				<p class="description">⚠️ Save settings first if you've made changes above.</p>
			</form>

			<h2>Usage</h2>
			<p>Place this shortcode on any page/post where you want the donation form to appear:</p>
			<code>[paynl_donate]</code>
			<p>Optional attributes:</p>
			<ul style="list-style:disc;margin-left:24px;">
				<li><code>[paynl_donate amounts="5,10,20,50"]</code> — override preset amounts</li>
				<li><code>[paynl_donate title="Support our work"]</code> — custom heading above the form</li>
				<li><code>[paynl_donate description="May campaign"]</code> — override statement description</li>
				<li><code>[paynl_donate show_description="0"]</code> — force-hide the form description on this page</li>
				<li><code>[paynl_donate show_description="1"]</code> — force-show the form description on this page</li>
			</ul>
		</div>
		<?php
	}

	/* -------------------------------------------------------------------------
	 * Shortcode + assets
	 * ---------------------------------------------------------------------- */

	public function register_shortcode() {
		add_shortcode( 'paynl_donate', [ $this, 'render_shortcode' ] );
	}

	public function maybe_enqueue_assets() {
		// Lightweight: enqueue only when shortcode is present
		global $post;
		if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'paynl_donate' ) ) {
			$this->enqueue_assets();
		}
	}

	private function enqueue_assets() {
		wp_enqueue_style(
			'paynl-donate',
			PAYNL_DONATIONS_URL . 'assets/donate.css',
			[],
			PAYNL_DONATIONS_VERSION
		);
		wp_enqueue_script(
			'paynl-donate',
			PAYNL_DONATIONS_URL . 'assets/donate.js',
			[],
			PAYNL_DONATIONS_VERSION,
			true
		);
		wp_localize_script( 'paynl-donate', 'paynlDonate', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'paynl_donate_nonce' ),
			'i18n'    => [
				'processing'      => __( 'Processing...', 'paynl-donations' ),
				'invalidAmount'   => __( 'Please select or enter a valid amount.', 'paynl-donations' ),
				'invalidEmail'    => __( 'Please enter a valid email.', 'paynl-donations' ),
				'invalidName'     => __( 'Please enter your name.', 'paynl-donations' ),
				'genericError'    => __( 'Something went wrong. Please try again.', 'paynl-donations' ),
			],
		] );
	}

	public function render_shortcode( $atts ) {
		$this->enqueue_assets(); // safety net if has_shortcode missed it

		$atts = is_array( $atts ) ? $atts : [];

		$presets       = $atts['amounts']     ?? get_option( 'paynl_preset_amounts', '10,25,50,100' );
		$title         = $atts['title']       ?? '';
		$currency      = get_option( 'paynl_currency', 'EUR' );
		$currency_sym  = $this->currency_symbol( $currency );
		$allow_custom  = get_option( 'paynl_allow_custom', '1' ) === '1';
		$require_email = get_option( 'paynl_require_email', '1' ) === '1';
		$require_name  = get_option( 'paynl_require_name', '0' ) === '1';
		$button_label  = get_option( 'paynl_button_label', 'Doneer nu' );
		$min           = (int) get_option( 'paynl_min_amount', 2 );
		$max           = (int) get_option( 'paynl_max_amount', 10000 );
		$privacy_url   = trim( (string) get_option( 'paynl_privacy_url', '' ) );

		// Form description: admin setting can be overridden per shortcode via show_description="0" or "1"
		$show_desc_default = get_option( 'paynl_show_form_description', '0' ) === '1';
		if ( isset( $atts['show_description'] ) ) {
			$show_desc = in_array( (string) $atts['show_description'], [ '1', 'true', 'yes', 'on' ], true );
		} else {
			$show_desc = $show_desc_default;
		}
		$form_description = (string) get_option( 'paynl_form_description', '' );

		$preset_arr = array_filter( array_map( 'trim', explode( ',', $presets ) ), 'is_numeric' );

		$allowed_html = [
			'p'      => [],
			'br'     => [],
			'strong' => [],
			'em'     => [],
			'b'      => [],
			'i'      => [],
			'a'      => [ 'href' => [], 'title' => [], 'target' => [], 'rel' => [] ],
			'ul'     => [],
			'ol'     => [],
			'li'     => [],
		];

		ob_start();
		?>
		<div class="paynl-donate-wrap" data-currency="<?php echo esc_attr( $currency ); ?>">
			<?php if ( $title ) : ?>
				<h3 class="paynl-donate-title"><?php echo esc_html( $title ); ?></h3>
			<?php endif; ?>

			<?php if ( $show_desc && trim( $form_description ) !== '' ) : ?>
				<div class="paynl-form-description">
					<?php echo wp_kses( wpautop( $form_description ), $allowed_html ); ?>
				</div>
			<?php endif; ?>

			<form class="paynl-donate-form" novalidate>
				<?php if ( ! empty( $atts['description'] ) ) : ?>
					<input type="hidden" name="description" value="<?php echo esc_attr( $atts['description'] ); ?>">
				<?php endif; ?>

				<div class="paynl-amounts" role="radiogroup" aria-label="<?php esc_attr_e( 'Donation amount', 'paynl-donations' ); ?>">
					<?php foreach ( $preset_arr as $i => $amt ) : ?>
						<button type="button" class="paynl-amt-btn<?php echo $i === 1 ? ' is-selected' : ''; ?>" data-amount="<?php echo esc_attr( $amt ); ?>" aria-pressed="<?php echo $i === 1 ? 'true' : 'false'; ?>">
							<?php echo esc_html( $currency_sym . $amt ); ?>
						</button>
					<?php endforeach; ?>
					<?php if ( $allow_custom ) : ?>
						<button type="button" class="paynl-amt-btn paynl-amt-custom-btn" data-amount="custom" aria-pressed="false">
							<?php esc_html_e( 'Other', 'paynl-donations' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<?php if ( $allow_custom ) : ?>
					<div class="paynl-custom-wrap" hidden>
						<label class="paynl-label" for="paynl-custom-amount"><?php esc_html_e( 'Your amount', 'paynl-donations' ); ?></label>
						<div class="paynl-input-prefix">
							<span class="paynl-prefix"><?php echo esc_html( $currency_sym ); ?></span>
							<input type="number" id="paynl-custom-amount" name="custom_amount" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="1" inputmode="numeric">
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $require_name ) : ?>
					<div class="paynl-field">
						<label class="paynl-label" for="paynl-name"><?php esc_html_e( 'Name', 'paynl-donations' ); ?></label>
						<input type="text" id="paynl-name" name="name" autocomplete="name" required>
					</div>
				<?php endif; ?>

				<?php if ( $require_email ) : ?>
					<div class="paynl-field">
						<label class="paynl-label" for="paynl-email"><?php esc_html_e( 'Email', 'paynl-donations' ); ?> <span class="paynl-hint">(<?php esc_html_e( 'for receipt', 'paynl-donations' ); ?>)</span></label>
						<input type="email" id="paynl-email" name="email" autocomplete="email" required>
					</div>
				<?php endif; ?>

				<button type="submit" class="paynl-submit"><?php echo esc_html( $button_label ); ?></button>

				<?php if ( $privacy_url ) : ?>
					<p class="paynl-privacy">
						<?php
						printf(
							/* translators: %s = link to privacy policy */
							esc_html__( 'By donating, you accept our %s.', 'paynl-donations' ),
							'<a href="' . esc_url( $privacy_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'privacy policy', 'paynl-donations' ) . '</a>'
						);
						?>
					</p>
				<?php endif; ?>

				<div class="paynl-error" role="alert" hidden></div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/* -------------------------------------------------------------------------
	 * AJAX — create PAY.nl order, return checkout URL
	 * ---------------------------------------------------------------------- */

	public function ajax_create_order() {
		check_ajax_referer( 'paynl_donate_nonce', 'nonce' );

		$amount = isset( $_POST['amount'] ) ? (float) $_POST['amount'] : 0;
		$min    = (int) get_option( 'paynl_min_amount', 2 );
		$max    = (int) get_option( 'paynl_max_amount', 10000 );

		if ( $amount < $min || $amount > $max ) {
			wp_send_json_error( [ 'message' => 'Invalid amount' ], 400 );
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$name  = isset( $_POST['name'] )  ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		if ( get_option( 'paynl_require_email', '1' ) === '1' && ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => 'Invalid email' ], 400 );
		}

		$description_override = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';
		$description = $description_override ?: get_option( 'paynl_description', 'Donation' );
		$description = substr( $description, 0, 30 ); // PAY.nl statement max length

		$currency   = get_option( 'paynl_currency', 'EUR' );
		$service_id = trim( get_option( 'paynl_service_id', '' ) );
		$test_mode  = get_option( 'paynl_test_mode', '0' ) === '1';

		// Build payload for PAY.nl Order:Create v1
		// Amount must be integer cents
		$payload = [
			'serviceId'   => $service_id,
			'description' => $description,
			'reference'   => 'DON' . time() . wp_rand( 100, 999 ),
			'amount'      => [
				'value'    => (int) round( $amount * 100 ),
				'currency' => $currency,
			],
			'returnUrl'   => $this->build_return_url(),
			'exchangeUrl' => rest_url( 'paynl-donations/v1/webhook' ),
			'integration' => [
				'test' => $test_mode,
			],
		];

		if ( $email || $name ) {
			$payload['customer'] = array_filter( [
				'email'     => $email ?: null,
				'firstName' => $name ?: null,
			] );
		}

		$response = $this->call_paynl_api( '/orders', 'POST', $payload );

		if ( is_wp_error( $response ) ) {
			error_log( '[PayNL Donations] API error: ' . $response->get_error_message() );
			wp_send_json_error( [ 'message' => 'Payment provider error' ], 502 );
		}

		$checkout_url = $response['links']['redirect'] ?? $response['links']['checkout'] ?? null;
		if ( ! $checkout_url ) {
			error_log( '[PayNL Donations] No checkout URL in response: ' . wp_json_encode( $response ) );
			wp_send_json_error( [ 'message' => 'No checkout URL returned' ], 502 );
		}

		// Optional: log the order ID for debugging
		error_log( sprintf(
			'[PayNL Donations] Order created %s amount=%s%.2f email=%s',
			$response['id'] ?? '?',
			$currency,
			$amount,
			$email
		) );

		wp_send_json_success( [ 'checkoutUrl' => $checkout_url ] );
	}

	private function build_return_url() {
		$success = get_option( 'paynl_success_url' );
		if ( ! $success ) {
			$success = home_url( '/' );
		}
		// PAY.nl appends ?orderId=... by default
		return $success;
	}

	/* -------------------------------------------------------------------------
	 * Webhook — receive exchange callback from PAY.nl
	 * ---------------------------------------------------------------------- */

	public function register_rest_routes() {
		register_rest_route( 'paynl-donations/v1', '/webhook', [
			'methods'             => [ 'GET', 'POST' ],
			'callback'            => [ $this, 'handle_webhook' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function handle_webhook( WP_REST_Request $request ) {
		// PAY.nl v1 sends orderId (or order_id) — we fetch full order info.
		$order_id = $request->get_param( 'orderId' )
				 ?: $request->get_param( 'order_id' )
				 ?: $request->get_param( 'id' );

		if ( ! $order_id ) {
			return new WP_REST_Response( 'FALSE| missing order id', 400 );
		}

		// Fetch full order (includes customer, amount, reference, status)
		$order = $this->call_paynl_api( '/orders/' . rawurlencode( $order_id ), 'GET' );

		if ( is_wp_error( $order ) ) {
			error_log( '[PayNL Donations] Webhook order fetch failed: ' . $order->get_error_message() );
			return new WP_REST_Response( 'FALSE| ' . $order->get_error_message(), 500 );
		}

		$state = $order['status']['action'] ?? 'UNKNOWN';
		$code  = $order['status']['code']   ?? '?';
		error_log( sprintf( '[PayNL Donations] Webhook order=%s status=%s (code %s)', $order_id, $state, $code ) );

		// Send confirmation email on PAID — once per order
		if ( $state === 'PAID' && get_option( 'paynl_email_enabled', '0' ) === '1' ) {
			$emailed_key = 'paynl_emailed_' . md5( $order_id );
			if ( ! get_transient( $emailed_key ) ) {
				$sent = $this->send_confirmation_email( $order );
				set_transient( $emailed_key, $sent ? 'sent' : 'failed', MONTH_IN_SECONDS );
				error_log( sprintf( '[PayNL Donations] Confirmation email %s for %s', $sent ? 'sent' : 'failed', $order_id ) );
			}
		}

		// Hook for other plugins/themes to act on the donation
		do_action( 'paynl_donations_status_update', $order_id, $state, $order );

		// PAY.nl expects "TRUE| ..." on success
		return new WP_REST_Response( 'TRUE| ' . $state, 200 );
	}

	/* -------------------------------------------------------------------------
	 * Email — confirmation receipt to donor (+ optional BCC to admin)
	 * ---------------------------------------------------------------------- */

	private function send_confirmation_email( $order ) {
		$email = $order['customer']['email'] ?? '';
		if ( ! is_email( $email ) ) {
			error_log( '[PayNL Donations] Skipping email — no valid customer email on order ' . ( $order['id'] ?? '?' ) );
			return false;
		}

		$amount    = (float) ( $order['amount']['value'] ?? 0 ) / 100;
		$currency  = $order['amount']['currency'] ?? get_option( 'paynl_currency', 'EUR' );
		$name      = $order['customer']['firstName'] ?? '';
		$reference = $order['reference'] ?? ( $order['id'] ?? '' );
		$completed = $order['completedAt'] ?? '';
		$date      = $completed
			? wp_date( get_option( 'date_format' ), strtotime( $completed ) )
			: wp_date( get_option( 'date_format' ) );

		$vars = [
			'name'      => $name ?: __( 'friend', 'paynl-donations' ),
			'amount'    => number_format_i18n( $amount, 2 ),
			'currency'  => $currency,
			'reference' => $reference,
			'date'      => $date,
			'org_name'  => get_option( 'paynl_email_from_name' ) ?: get_bloginfo( 'name' ),
		];

		$subject = $this->replace_placeholders(
			get_option( 'paynl_email_subject' ) ?: $this->default_email_subject(),
			$vars
		);

		$body = $this->replace_placeholders(
			get_option( 'paynl_email_body' ) ?: $this->default_email_body(),
			$vars
		);

		// Build headers
		$from_name = get_option( 'paynl_email_from_name' ) ?: get_bloginfo( 'name' );
		$from_addr = get_option( 'paynl_email_from_address' ) ?: get_option( 'admin_email' );
		$reply_to  = trim( (string) get_option( 'paynl_email_reply_to', '' ) );
		$admin_bcc = trim( (string) get_option( 'paynl_email_admin_bcc', '' ) );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_addr ),
		];
		if ( is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}
		if ( is_email( $admin_bcc ) ) {
			$headers[] = 'Bcc: ' . $admin_bcc;
		}

		return wp_mail( $email, $subject, $body, $headers );
	}

	private function replace_placeholders( $template, $vars ) {
		$replacements = [];
		foreach ( $vars as $k => $v ) {
			$replacements[ '{' . $k . '}' ] = (string) $v;
		}
		return strtr( $template, $replacements );
	}

	private function default_email_subject() {
		return 'Thank you for your donation to {org_name}';
	}

	private function default_email_body() {
		return '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 580px; margin: 0 auto; padding: 24px; color: #2a2a2a; line-height: 1.6;">

  <h2 style="font-size: 24px; font-weight: 600; margin: 0 0 16px;">Thank you, {name}.</h2>

  <p>Your donation has been received and we are sincerely grateful for your support.</p>

  <div style="background: #f7f5f0; padding: 16px 20px; border-radius: 10px; margin: 24px 0;">
    <p style="margin: 4px 0;"><strong>Amount:</strong> {currency} {amount}</p>
    <p style="margin: 4px 0;"><strong>Date:</strong> {date}</p>
    <p style="margin: 4px 0;"><strong>Reference:</strong> {reference}</p>
  </div>

  <p>Please keep this email as confirmation of your donation. It serves as your receipt.</p>

  <p style="margin-top: 32px;">With gratitude,<br>The {org_name} team</p>

  <hr style="border: none; border-top: 1px solid #e0dcd5; margin: 32px 0 16px;">
  <p style="color: #888; font-size: 13px;">This is an automated confirmation. If you have questions about your donation, just reply to this email and we will get back to you.</p>

</div>';
	}

	/* -------------------------------------------------------------------------
	 * Test email handler (admin only)
	 * ---------------------------------------------------------------------- */

	public function admin_send_test_email() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized', 403 );
		}
		check_admin_referer( 'paynl_test_email' );

		$to = isset( $_POST['test_to'] ) ? sanitize_email( wp_unslash( $_POST['test_to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			wp_safe_redirect( add_query_arg( 'paynl_test_email', 'noaddr', admin_url( 'options-general.php?page=paynl-donations' ) ) );
			exit;
		}

		// Build a fake "order" with sample data to render the template
		$fake_order = [
			'id'          => 'test-' . wp_generate_uuid4(),
			'reference'   => 'TEST' . time(),
			'completedAt' => current_time( 'c' ),
			'amount'      => [ 'value' => 2500, 'currency' => get_option( 'paynl_currency', 'EUR' ) ],
			'customer'    => [ 'email' => $to, 'firstName' => 'Test Donor' ],
		];

		$sent = $this->send_confirmation_email( $fake_order );

		wp_safe_redirect( add_query_arg( 'paynl_test_email', $sent ? 'sent' : 'fail', admin_url( 'options-general.php?page=paynl-donations' ) ) );
		exit;
	}

	/* -------------------------------------------------------------------------
	 * API helper — Basic Auth against connect.pay.nl/v1
	 * ---------------------------------------------------------------------- */

	private function call_paynl_api( $path, $method = 'GET', $body = null ) {
		$token_code = trim( get_option( 'paynl_token_code', '' ) );
		$api_token  = trim( get_option( 'paynl_api_token', '' ) );

		if ( ! $token_code || ! $api_token ) {
			return new WP_Error( 'paynl_no_credentials', 'PAY.nl credentials are not configured.' );
		}

		$auth = base64_encode( $token_code . ':' . $api_token );
		$url  = PAYNL_DONATIONS_API_BASE . $path;

		$args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Basic ' . $auth,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			],
		];

		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$json = json_decode( $raw, true );

		if ( $code >= 400 ) {
			$msg = $json['message'] ?? $json['detail'] ?? ( 'HTTP ' . $code );
			return new WP_Error( 'paynl_http_' . $code, $msg, $json );
		}

		return is_array( $json ) ? $json : [];
	}

	/* -------------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------- */

	private function currency_symbol( $code ) {
		$map = [ 'EUR' => '€', 'USD' => '$', 'GBP' => '£', 'RON' => 'lei ' ];
		return $map[ strtoupper( $code ) ] ?? ( $code . ' ' );
	}
}

PayNL_Simple_Donations::instance();
