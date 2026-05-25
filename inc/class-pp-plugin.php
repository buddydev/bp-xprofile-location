<?php
/**
 * Main plugin singleton.
 *
 * @package BP_xProfile_Location
 */

defined( 'ABSPATH' ) || exit;

class PP_Location_Plugin {

	/** @var PP_Location_Plugin|null */
	private static ?PP_Location_Plugin $instance = null;

	private function __construct() {}

	/**
	 * Returns the singleton instance.
	 */
	public static function get_instance(): PP_Location_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Registers all hooks.
	 */
	private function init(): void {
		add_action( 'bp_init',    array( $this, 'load_translations' ) );
		add_action( 'bp_init',    array( $this, 'load_admin' ) );
		add_action( 'bp_include', array( $this, 'load_components' ) );
	}

	/**
	 * Loads plugin text domain.
	 */
	public function load_translations(): void {
		load_plugin_textdomain(
			'bp-xprofile-location',
			false,
			dirname( PP_LOC_FILE ) . '/languages'
		);
	}

	/**
	 * Loads admin settings class (depends on BuddyPress being active).
	 */
	public function load_admin(): void {
		if ( ! function_exists( 'buddypress' ) ) {
			return;
		}

		require_once PP_LOC_DIR . 'inc/class-pp-admin-settings.php';
		PP_Location_Admin_Settings::init();
	}

	/**
	 * Loads field type, field handler, and optional BPS integration.
	 */
	public function load_components(): void {
		$this->define_constants();
		require_once PP_LOC_DIR . 'inc/class-pp-field-type.php';
		require_once PP_LOC_DIR . 'inc/class-pp-field-handler.php';

		new PP_Location_Field_Handler();

		// BPS integration: only load when BP Profile Search 5.x is active.
		if ( defined( 'BPS_VERSION' ) || $this->is_buddyboss() ) {
			require_once PP_LOC_DIR . 'inc/class-pp-bps-integration.php';
			PP_Location_BPS_Integration::init();
		}
	}

	/**
	 * Defines constants for dependent plugin backward compatibility(member maps)
	 */
	private function define_constants(): void {
		$bp = buddypress();

		if ( ! defined( 'PP_BOSS' ) ) {
			if ( ! empty( $bp->buddyboss ) ) {
				define( 'PP_BOSS', true );
			} else {
				define( 'PP_BOSS', false );
			}
		}

		if ( defined( 'PP_BPS' ) ) {
			return;
		}

		if ( defined( 'BPS_VERSION' ) && version_compare( BPS_VERSION, '4.9.8', '>' ) ) {
			define( 'PP_BPS', true );
		} else {
			define( 'PP_BPS', false );
		}
	}

	/**
	 * Returns true when running under BuddyBoss Platform.
	 */
	public function is_buddyboss(): bool {
		return defined( 'BP_PLATFORM_VERSION' ) && BP_PLATFORM_VERSION;
	}
}

/**
 * Returns the singleton instance of PP_Location_Plugin.
 * Back-compat alias: external code can still call bp_xprofile_location().
 *
 * @return PP_Location_Plugin
 */
function bp_xprofile_location(): PP_Location_Plugin {
	return PP_Location_Plugin::get_instance();
}
