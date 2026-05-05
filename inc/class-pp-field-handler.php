<?php
/**
 * Business logic: script enqueueing, field registration, data persistence,
 * signup handling, and display filters for the Location xProfile field type.
 *
 * @package BP_xProfile_Location
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'PP_Location_Field_Handler' ) ) :

class PP_Location_Field_Handler {

	public function __construct() {
		// Scripts.
		add_action( 'wp_enqueue_scripts',    array( $this, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );

		// Field type registration.
		add_filter( 'bp_xprofile_get_field_types', array( $this, 'register_field_type' ) );

		// Prevent BuddyPress from storing bogus option rows for this type.
		add_filter( 'xprofile_field_options_before_save', array( $this, 'clear_field_options' ), 20, 2 );

		// Profile data display filters.
		add_filter( 'xprofile_get_field_data',        array( $this, 'filter_field_data' ),  10, 2 );
		add_filter( 'bp_get_the_profile_field_value', array( $this, 'filter_field_value' ), 10, 3 );

		// Data / field lifecycle hooks.
		add_action( 'xprofile_data_after_save',    array( $this, 'after_data_save' ) );
		add_action( 'xprofile_data_after_delete',  array( $this, 'after_data_delete' ) );
		add_action( 'xprofile_field_after_save',   array( $this, 'after_field_save' ) );
		add_action( 'xprofile_field_after_delete', array( $this, 'after_field_delete' ), 99, 2 );

		// Signup / registration hooks.
		add_action( 'bp_signup_validate',    array( $this, 'validate_signup' ) );
		add_filter( 'bp_signup_usermeta',    array( $this, 'capture_signup_geocode' ) );
		add_action( 'bp_core_signup_user',   array( $this, 'save_signup_geocode' ), 15, 5 );
		add_action( 'bp_core_activated_user', array( $this, 'save_activation_geocode' ), 15, 3 );
	}

	// -------------------------------------------------------------------------
	// Script enqueueing
	// -------------------------------------------------------------------------

	/**
	 * Enqueues Google Places + our init script on relevant front-end pages.
	 */
	public function enqueue_frontend(): void {
		$needs_maps = bp_is_user_profile_edit()
			|| bp_is_register_page()
			|| bp_is_members_directory();

		if ( ! $needs_maps ) {
			return;
		}

		$this->enqueue_maps_scripts();
	}

	/**
	 * Enqueues Google Places + our init script on the BP admin profile-edit page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_admin( string $hook ): void {
		if ( 'users_page_bp-profile-edit' !== $hook ) {
			return;
		}

		$this->enqueue_maps_scripts();
	}

	/**
	 * Registers and enqueues the location field JS and the Google Maps API.
	 *
	 * pp-location-field must load before google-places-api so that
	 * ppLocationInit() is defined when the Maps API calls it on load.
	 */
	private function enqueue_maps_scripts(): void {
		$api_key = (string) bp_get_option( 'pp_gapikey', '' );

		wp_register_script(
			'pp-location-field',
			PP_LOC_URL . 'assets/js/location-field.js',
			array(),
			PP_LOC_VERSION,
			true
		);

		// The callback=ppLocationInit parameter causes the Maps API to call
		// ppLocationInit() once it has finished loading.
		$maps_url = add_query_arg(
			array(
				'key'       => $api_key,
				'libraries' => 'places',
				'callback'  => 'ppLocationInit',
			),
			'https://maps.googleapis.com/maps/api/js'
		);

		wp_register_script(
			'google-places-api',
			$maps_url,
			array( 'pp-location-field' ), // ensures pp-location-field loads first
			null,
			true
		);

		wp_enqueue_script( 'google-places-api' );
	}

	// -------------------------------------------------------------------------
	// Field type registration
	// -------------------------------------------------------------------------

	/**
	 * Registers the 'location' field type with BuddyPress.
	 *
	 * @param array $field_types Registered field type map.
	 *
	 * @return array
	 */
	public function register_field_type( array $field_types ): array {
		$field_types['location'] = 'PP_Location_Field_Type';

		return $field_types;
	}

	// -------------------------------------------------------------------------
	// Display filters
	// -------------------------------------------------------------------------

	/**
	 * Filters raw field data returned by xprofile_get_field_data().
	 *
	 * @param mixed $value    Raw stored value.
	 * @param int   $field_id xProfile field ID.
	 *
	 * @return mixed
	 */
	public function filter_field_data( $value, int $field_id ) {
		$field = new BP_XProfile_Field( $field_id );

		if ( 'location' !== $field->type ) {
			return $value;
		}

		// Discard serialised-empty-array artefact.
		$unserialized = maybe_unserialize( $value );
		if ( is_array( $unserialized ) && empty( $unserialized ) ) {
			return '';
		}

		$clean = wp_strip_all_tags( $value );

		if ( '' === $clean ) {
			return '';
		}

		return apply_filters( 'pp_loc_field_data', $clean, $field_id );
	}

	/**
	 * Filters the field value output by bp_get_the_profile_field_value().
	 *
	 * @param string $value Field value.
	 * @param string $type  Field type slug.
	 * @param int    $id    xProfile field ID.
	 *
	 * @return string
	 */
	public function filter_field_value( string $value, string $type, $id ): string {
		if ( 'location' !== $type ) {
			return $value;
		}

		// Discard serialised-empty-array artefact.
		$unserialized = maybe_unserialize( $value );
		if ( is_array( $unserialized ) && empty( $unserialized ) ) {
			return '';
		}

		$clean = wp_strip_all_tags( $value );

		if ( '' === $clean ) {
			return '';
		}

		return apply_filters( 'pp_loc_field_value', $clean, $type, $id );
	}

	// -------------------------------------------------------------------------
	// Field options
	// -------------------------------------------------------------------------

	/**
	 * Prevents BuddyPress from storing option rows for the location type.
	 *
	 * BP calls this filter before saving field options.  Returning an empty
	 * string for location fields avoids storing empty/bogus option rows.
	 *
	 * @param mixed  $post_option Submitted options value.
	 * @param string $type        Field type slug.
	 *
	 * @return mixed
	 */
	public function clear_field_options( $post_option, string $type ) {
		if ( 'location' === $type ) {
			return '';
		}

		return $post_option;
	}

	// -------------------------------------------------------------------------
	// Data / field lifecycle
	// -------------------------------------------------------------------------

	/**
	 * After profile data is saved: delete the geocode if the field was cleared,
	 * or update it from the submitted hidden input.
	 *
	 * @param object $data xProfile data object (field_id, user_id, value).
	 */
	public function after_data_save( object $data ): void {
		$field = new BP_XProfile_Field( $data->field_id );

		if ( 'location' !== $field->type ) {
			return;
		}

		$unserialized = maybe_unserialize( $data->value );

		if ( is_array( $unserialized ) && empty( $unserialized ) ) {
			// Field was cleared - remove xprofile data row and geocode.
			xprofile_delete_field_data( $data->field_id, $data->user_id );
			delete_user_meta( $data->user_id, 'geocode_' . $data->field_id );
			return;
		}

		$geocode_key = 'pp_' . $data->field_id . '_geocode';

		if ( ! empty( $_POST[ $geocode_key ] ) ) {
			$geocode = sanitize_text_field( wp_unslash( $_POST[ $geocode_key ] ) );
			update_user_meta( $data->user_id, 'geocode_' . $data->field_id, $geocode );
		}
	}

	/**
	 * After profile data is deleted: remove the associated geocode usermeta.
	 *
	 * @param object $obj xProfile data object (field_id, user_id).
	 */
	public function after_data_delete( object $obj ): void {
		delete_user_meta( $obj->user_id, 'geocode_' . $obj->field_id );
	}

	/**
	 * After a location field is saved in admin: persist the geocode option
	 * (Yes / No) chosen in the field editor.
	 *
	 * @param object $obj Saved BP_XProfile_Field object.
	 */
	public function after_field_save( object $obj ): void {
		if ( 'location' !== $obj->type ) {
			return;
		}

		if ( isset( $_POST['location_option'][1] ) ) {
			$geocode_option = absint( $_POST['location_option'][1] );
			bp_xprofile_update_meta( $obj->id, 'data', 'geocode', $geocode_option );
		}
	}

	/**
	 * After a location field is deleted: purge all associated data rows,
	 * meta entries, and geocode usermeta for every member.
	 *
	 * @param object $data   Deleted BP_XProfile_Field object.
	 * @param bool   $delete Whether the delete succeeded.
	 */
	public function after_field_delete( object $data, bool $delete ): void {
		global $wpdb;

		$bp         = buddypress();
		$field_id   = (int) $data->id;
		$geocode_key = 'geocode_' . $field_id;

		// Remove all member geocodes for this field.
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $geocode_key ), array( '%s' ) );

		// Remove field metadata.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$bp->profile->table_name_meta} WHERE object_id = %d",
				$field_id
			)
		);

		// Remove all stored field data.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$bp->profile->table_name_data} WHERE field_id = %d",
				$field_id
			)
		);
	}

	// -------------------------------------------------------------------------
	// Signup / registration
	// -------------------------------------------------------------------------

	/**
	 * Validates required location fields on the registration form.
	 */
	public function validate_signup(): void {
		global $bp;

		if ( ! bp_is_active( 'xprofile' ) ) {
			return;
		}

		if ( empty( $_POST['signup_profile_field_ids'] ) ) {
			return;
		}

		$field_ids = explode( ',', sanitize_text_field( wp_unslash( $_POST['signup_profile_field_ids'] ) ) );

		foreach ( $field_ids as $field_id ) {
			$field_id = (int) $field_id;
			$field    = new BP_XProfile_Field( $field_id );

			if ( 'location' === $field->type && $field->is_required ) {
				if ( empty( $_POST[ 'field_' . $field_id ] ) ) {
					$bp->signup->errors[ 'field_' . $field_id ] = __( 'This is a required field', 'buddypress' );
				}
			}
		}
	}

	/**
	 * Captures a geocode posted alongside a location field on the signup form
	 * and stores it in the usermeta array so it can be persisted on activation.
	 *
	 * @param array $meta Signup usermeta array.
	 *
	 * @return array
	 */
	public function capture_signup_geocode( array $meta ): array {
		if ( empty( $meta['signup_profile_field_ids'] ) ) {
			return $meta;
		}

		$field_ids = explode( ',', $meta['signup_profile_field_ids'] );

		foreach ( $field_ids as $field_id ) {
			$field_id = (int) $field_id;
			$field    = new BP_XProfile_Field( $field_id );

			if ( 'location' !== $field->type ) {
				continue;
			}

			if ( empty( $_POST[ 'field_' . $field_id ] ) ) {
				continue;
			}

			$geocode_key = 'pp_' . $field_id . '_geocode';

			if ( ! empty( $_POST[ $geocode_key ] ) ) {
				$meta[ 'geocode_' . $field_id ] = sanitize_text_field( wp_unslash( $_POST[ $geocode_key ] ) );
			}
		}

		return $meta;
	}

	/**
	 * Saves geocodes after a new user is created (single-site only).
	 *
	 * On multisite the activation email flow is used instead; see
	 * save_activation_geocode() below.
	 *
	 * @param int    $user_id       New user ID.
	 * @param string $user_login    Username.
	 * @param string $user_password Password.
	 * @param string $user_email    Email address.
	 * @param array  $usermeta      Signup usermeta.
	 */
	public function save_signup_geocode(
		int $user_id,
		string $user_login,
		string $user_password,
		string $user_email,
		array $usermeta
	): void {
		// On multisite, geocodes are saved after email activation instead.
		if ( is_multisite() ) {
			return;
		}

		if ( ! $user_id || ! bp_is_active( 'xprofile' ) ) {
			return;
		}

		if ( empty( $usermeta['profile_field_ids'] ) ) {
			return;
		}

		$field_ids = explode( ',', $usermeta['profile_field_ids'] );

		foreach ( $field_ids as $field_id ) {
			$field_id = (int) $field_id;
			$field    = new BP_XProfile_Field( $field_id );

			if ( 'location' !== $field->type ) {
				continue;
			}

			if ( ! empty( $usermeta[ 'geocode_' . $field_id ] ) ) {
				$geocode = sanitize_text_field( $usermeta[ 'geocode_' . $field_id ] );
				update_user_meta( $user_id, 'geocode_' . $field_id, $geocode );
			}
		}
	}

	/**
	 * Saves geocodes after a user activates their account via email
	 * (single-site only - see note in save_signup_geocode()).
	 *
	 * @param int    $user_id New user ID.
	 * @param string $key     Activation key.
	 * @param array  $user    Signup user data array.
	 */
	public function save_activation_geocode( int $user_id, string $key, array $user ): void {
		if ( is_multisite() ) {
			return;
		}

		if ( empty( $user['meta']['profile_field_ids'] ) ) {
			return;
		}

		$field_ids = explode( ',', $user['meta']['profile_field_ids'] );

		foreach ( $field_ids as $field_id ) {
			$field_id = (int) $field_id;
			$field    = new BP_XProfile_Field( $field_id );

			if ( 'location' !== $field->type ) {
				continue;
			}

			if ( ! empty( $user['meta'][ 'geocode_' . $field_id ] ) ) {
				$geocode = sanitize_text_field( $user['meta'][ 'geocode_' . $field_id ] );
				update_user_meta( $user_id, 'geocode_' . $field_id, $geocode );
			}
		}
	}
}

endif;
