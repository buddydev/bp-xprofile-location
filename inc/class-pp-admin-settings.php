<?php
/**
 * Admin settings: Google Maps API key field.
 *
 * BuddyPress path:  Settings > BuddyPress > Options > Profile Settings.
 * BuddyBoss path:   BuddyBoss > PhiloPress (custom submenu page).
 *
 * @package BP_xProfile_Location
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'PP_Location_Admin_Settings' ) ) :

class PP_Location_Admin_Settings {

	/**
	 * Registers hooks for the appropriate platform.
	 */
	public static function init(): void {
		if ( bp_xprofile_location()->is_buddyboss() ) {
			add_filter( 'bp_core_get_admin_tabs', array( __CLASS__, 'add_buddyboss_tab' ) );
			add_action( 'bp_init', array( __CLASS__, 'register_buddyboss_menu' ) );
		} else {
			add_action( 'bp_register_admin_settings', array( __CLASS__, 'register_bp_settings' ) );
		}
	}

	// -------------------------------------------------------------------------
	// BuddyPress settings
	// -------------------------------------------------------------------------

	/**
	 * Registers the API key field under Settings > BuddyPress > Options.
	 */
	public static function register_bp_settings(): void {
		add_settings_field(
			'pp_gapikey',
			__( 'Google Maps API key', 'bp-xprofile-location' ),
			array( __CLASS__, 'render_bp_api_key_field' ),
			'buddypress',
			'bp_xprofile'
		);

		register_setting(
			'buddypress',
			'pp_gapikey',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);
	}

	/**
	 * Callback for add_settings_field(). WordPress passes an $args array as the
	 * first argument, so this wrapper accepts it and delegates to the shared renderer.
	 *
	 * @param array $args Settings field args (unused).
	 */
	public static function render_bp_api_key_field( array $args = array() ): void {
		self::render_api_key_field();
	}

	// -------------------------------------------------------------------------
	// BuddyBoss settings
	// -------------------------------------------------------------------------

	/**
	 * Adds a PhiloPress tab to the BuddyBoss admin tab bar.
	 *
	 * @param array $tabs Existing admin tabs.
	 *
	 * @return array
	 */
	public static function add_buddyboss_tab( array $tabs ): array {
		$tabs['99'] = array(
			'href'  => bp_get_admin_url( add_query_arg( array( 'page' => 'philopress' ), 'admin.php' ) ),
			'name'  => 'PhiloPress',
			'class' => 'philopress',
		);

		return $tabs;
	}

	/**
	 * Registers the PhiloPress submenu page under BuddyBoss Platform.
	 */
	public static function register_buddyboss_menu(): void {
		add_action(
			bp_core_admin_hook(),
			static function (): void {
				add_submenu_page(
					'buddyboss-platform',
					'PhiloPress',
					'PhiloPress',
					'manage_options',
					'philopress',
					array( PP_Location_Admin_Settings::class, 'render_buddyboss_page' )
				);
			}
		);
	}

	/**
	 * Renders the BuddyBoss PhiloPress admin page.
	 */
	public static function render_buddyboss_page(): void {
		// Handle the form submission before any output.
		self::handle_buddyboss_save();

		$api_key = (string) bp_get_option( 'pp_gapikey', '' );
		?>
		<div class="wrap">
			<h2 class="nav-tab-wrapper"><?php bp_core_admin_tabs( 'PhiloPress' ); ?></h2>

			<div class="bp-admin-card section-bp_main">
				<h2><?php esc_html_e( 'PhiloPress Settings', 'bp-xprofile-location' ); ?></h2>
				<h3><?php esc_html_e( 'Google Maps API Key', 'bp-xprofile-location' ); ?></h3>

				<form action="<?php echo esc_url( admin_url( 'admin.php?page=philopress' ) ); ?>" method="post">
					<?php wp_nonce_field( 'pp_settings_save', 'pp_settings_nonce' ); ?>

					<?php self::render_api_key_field( $api_key ); ?>

					<p class="submit">
						<input type="submit"
						       name="pp_settings_submit"
						       class="button-primary"
						       value="<?php esc_attr_e( 'Save PhiloPress Settings', 'bp-xprofile-location' ); ?>" />
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Processes the BuddyBoss settings form submission.
	 *
	 * Must be called before any HTML output (notice may be printed inline).
	 */
	private static function handle_buddyboss_save(): void {
		// Only act when our form was submitted.
		if ( empty( $_POST['pp_settings_nonce'] ) ) {
			return;
		}

		check_admin_referer( 'pp_settings_save', 'pp_settings_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'bp-xprofile-location' ) );
		}

		if ( ! empty( $_POST['pp_gapikey'] ) ) {
			$api_key = sanitize_text_field( wp_unslash( $_POST['pp_gapikey'] ) );
			bp_update_option( 'pp_gapikey', $api_key );
			?>
			<div class="notice notice-success is-dismissible">
				<p><strong><?php esc_html_e( 'PhiloPress Settings saved.', 'bp-xprofile-location' ); ?></strong></p>
			</div>
			<?php
		} else {
			// Allow clearing the key.
			bp_update_option( 'pp_gapikey', '' );
			?>
			<div class="notice notice-warning is-dismissible">
				<p><?php esc_html_e( 'Google Maps API key cleared.', 'bp-xprofile-location' ); ?></p>
			</div>
			<?php
		}
	}

	// -------------------------------------------------------------------------
	// Shared field renderer
	// -------------------------------------------------------------------------

	/**
	 * Renders the API key text input.
	 *
	 * Used by the BuddyPress settings API callback (no $api_key argument
	 * needed - value read from options) and directly by the BuddyBoss page.
	 *
	 * @param string|null $api_key Pre-loaded value (optional).
	 */
	public static function render_api_key_field( ?string $api_key = null ): void {
		if ( null === $api_key ) {
			$api_key = (string) bp_get_option( 'pp_gapikey', '' );
		}
		?>
		<input type="text"
		       size="50"
		       id="pp_gapikey"
		       name="pp_gapikey"
		       placeholder="<?php esc_attr_e( 'Paste Your Google Maps API Key Here', 'bp-xprofile-location' ); ?>"
		       value="<?php echo esc_attr( $api_key ); ?>" />
		<p class="description">
			<?php esc_html_e( 'A key is required. If you do not have one, follow these instructions:', 'bp-xprofile-location' ); ?>
			<br>
			<a href="https://buddydev.com/docs/general/how-to-create-google-map-api-key/" target="_blank" rel="noopener">
				<?php esc_html_e( 'Get a Google Maps API Key', 'bp-xprofile-location' ); ?>
			</a>
		</p>
		<?php
	}
}

endif;
