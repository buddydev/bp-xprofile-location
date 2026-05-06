<?php
/**
 * BP Profile Search (BPS 5.x) and BuddyBoss profile search integration.
 *
 * Loaded only when BPS_VERSION is defined and >= 5.0.
 *
 * BPS integration:
 * When BPS processes xProfile fields it emits the `bps_custom_field` action
 * for any field whose format resolves to 'custom' (i.e. an unrecognised type).
 * We hook in to mark location fields as format 'location' and point their
 * search callback to our handler.
 *
 * BuddyBoss profile search integration:
 * BuddyBoss uses the `bp_ps_add_fields` filter to build its field list.
 * We patch location fields there with the same format and search callback.
 *
 * @package BP_xProfile_Location
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'PP_Location_BPS_Integration' ) ) :

class PP_Location_BPS_Integration {

	/**
	 * Registers all BPS / BuddyBoss profile search hooks.
	 */
	public static function init(): void {
		// Standard BuddyPress + BP Profile Search.
		add_action( 'bps_custom_field', array( __CLASS__, 'configure_bps_field' ) );

		// BuddyBoss Platform profile search.
		if ( bp_xprofile_location()->is_buddyboss() ) {
			add_filter( 'bp_ps_add_fields', array( __CLASS__, 'configure_boss_fields' ), 999 );
		}

		// Filters for the [membersmap] shortcode page integration with BPS.
		add_filter( 'pp_location_bps_filter_member_ids', array( __CLASS__, 'filter_member_ids_via_bps' ) );
		add_filter( 'bps_current_page',  array( __CLASS__, 'detect_membersmap_page' ), 999 );
		add_filter( 'bps_add_directory', array( __CLASS__, 'add_membersmap_directories' ) );
		add_action( 'bps_before_search_form', array( __CLASS__, 'set_membersmap_form_action' ) );
	}

	// -------------------------------------------------------------------------
	// BPS field configuration
	// -------------------------------------------------------------------------

	/**
	 * Configures a location field for BP Profile Search.
	 *
	 * Called via the `bps_custom_field` action which BPS emits when a field's
	 * format is 'custom' (i.e. unrecognised by BPS itself).
	 *
	 * @param object $field BPS field descriptor.
	 */
	public static function configure_bps_field( object $field ): void {
		if ( 'location' !== $field->type ) {
			return;
		}

		$field->format        = 'location';
		$field->script_handle = 'google-places-api';
		$field->search        = array( __CLASS__, 'dispatch_search' );
	}

	/**
	 * Configures location fields for BuddyBoss profile search.
	 *
	 * @param array $fields Array of BuddyBoss profile search field objects.
	 *
	 * @return array
	 */
	public static function configure_boss_fields( array $fields ): array {
		foreach ( $fields as $field ) {
			if ( isset( $field->type ) && 'location' === $field->type ) {
				$field->format        = 'location';
				$field->script_handle = 'google-places-api';
				$field->search        = array( __CLASS__, 'dispatch_search' );
			}
		}

		return $fields;
	}

	// -------------------------------------------------------------------------
	// Search dispatcher
	// -------------------------------------------------------------------------

	/**
	 * Dispatches a search request to either the text handler or the radial
	 * (distance) handler.
	 *
	 * @param object $field BPS / BuddyBoss field search descriptor.
	 *
	 * @return array User IDs matching the search.
	 */
	public static function dispatch_search( object $field ): array {
		if ( 'distance' !== $field->filter ) {
			// Delegate plain-text search to the platform's own handler.
			if ( bp_xprofile_location()->is_buddyboss() ) {
				return (array) bp_ps_xprofile_search( $field );
			}

			return (array) bps_xprofile_search( $field );
		}

		return self::radial_search( $field );
	}

	// -------------------------------------------------------------------------
	// Radial (distance) search
	// -------------------------------------------------------------------------

	/**
	 * Entry point for distance-based member search.
	 *
	 * Expects $field->value to contain:
	 *   lat      (float) Centre-point latitude.
	 *   lng      (float) Centre-point longitude.
	 *   distance (int)   Radius.
	 *   units    (string) 'km' or 'miles'.
	 *
	 * @param object $field BPS field descriptor.
	 *
	 * @return array User IDs within the specified radius.
	 */
	private static function radial_search( object $field ): array {
		$center_lat  = (float) $field->value['lat'];
		$center_lng  = (float) $field->value['lng'];
		$radius      = (int)   $field->value['distance'];
		$field_id    = (int)   $field->id;
		$meta_key    = 'geocode_' . $field_id;

		// Earth radius: 3 959 miles or 6 371 km.
		$earth_radius = ( 'km' === $field->value['units'] ) ? 6371 : 3959;

		return self::members_within_radius( $center_lat, $center_lng, $radius, $meta_key, $earth_radius );
	}

	/**
	 * Loads all member geocodes and returns IDs of those within the radius.
	 *
	 * @param float  $center_lat   Centre latitude (degrees).
	 * @param float  $center_lng   Centre longitude (degrees).
	 * @param int    $radius       Search radius (miles or km).
	 * @param string $meta_key     Usermeta key storing each member's geocode.
	 * @param float  $earth_radius Earth radius in the same units as $radius.
	 *
	 * @return array Matching user IDs.
	 */
	private static function members_within_radius(
		float $center_lat,
		float $center_lng,
		int $radius,
		string $meta_key,
		float $earth_radius
	): array {
		global $wpdb;

		if ( $radius < 1 ) {
			$radius = 10;
		}

		// Fetch all geocodes for this field.  On large sites this query is the
		// limiting factor; spatial indexing is a future optimisation.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$meta_key
			)
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$user_ids = array();

		foreach ( $rows as $row ) {
			$parts = explode( ',', $row->meta_value );

			if ( count( $parts ) < 2 ) {
				continue; // Malformed geocode, skip.
			}

			$lat = (float) $parts[0];
			$lng = (float) $parts[1];

			if ( self::haversine_distance( $center_lat, $center_lng, $lat, $lng, $earth_radius ) <= $radius ) {
				$user_ids[] = (int) $row->user_id;
			}
		}

		return $user_ids;
	}

	/**
	 * Calculates the great-circle distance between two points using the
	 * Haversine formula.
	 *
	 * @param float $lat1         Origin latitude  (degrees).
	 * @param float $lng1         Origin longitude (degrees).
	 * @param float $lat2         Target latitude  (degrees).
	 * @param float $lng2         Target longitude (degrees).
	 * @param float $earth_radius Earth radius in the desired unit.
	 *
	 * @return float Distance in the same unit as $earth_radius.
	 */
	private static function haversine_distance(
		float $lat1,
		float $lng1,
		float $lat2,
		float $lng2,
		float $earth_radius
	): float {
		$lat1_r = deg2rad( $lat1 );
		$lng1_r = deg2rad( $lng1 );
		$lat2_r = deg2rad( $lat2 );
		$lng2_r = deg2rad( $lng2 );

		$d_lat = $lat2_r - $lat1_r;
		$d_lng = $lng2_r - $lng1_r;

		$a = pow( sin( $d_lat / 2 ), 2 )
		   + cos( $lat1_r ) * cos( $lat2_r ) * pow( sin( $d_lng / 2 ), 2 );

		return 2 * asin( sqrt( $a ) ) * $earth_radius;
	}

	// -------------------------------------------------------------------------
	// [membersmap] shortcode / BPS directory helpers
	// -------------------------------------------------------------------------

	/**
	 * Runs a BPS search and returns filtered member IDs for a membersmap page.
	 *
	 * Hooked to `pp_location_bps_filter_member_ids`.
	 *
	 * @param array $user_ids Current member IDs.
	 *
	 * @return array
	 */
	public static function filter_member_ids_via_bps( array $user_ids ): array {
		$request = bps_get_request( 'search' );

		if ( empty( $request ) ) {
			return $user_ids;
		}

		$results = bps_search( $request );

		if ( ! empty( $results['validated'] ) ) {
			$user_ids = $results['users'];
		}

		return $user_ids;
	}

	/**
	 * Ensures BPS treats a [membersmap] page as the current directory.
	 *
	 * Hooked to `bps_current_page`.
	 *
	 * @param string $current Current page path as detected by BPS.
	 *
	 * @return string
	 */
	public static function detect_membersmap_page( string $current ): string {
		foreach ( bps_directories() as $dir ) {
			$membersmap_path = $dir->path . 'membersmap/';

			if ( $current === $membersmap_path ) {
				return $membersmap_path;
			}
		}

		return $current;
	}

	/**
	 * Registers pages containing the [membersmap] shortcode as BPS directories.
	 *
	 * Hooked to `bps_add_directory`.
	 *
	 * @param array $dirs Existing BPS directory objects.
	 *
	 * @return array
	 */
	public static function add_membersmap_directories( array $dirs ): array {
		$pages = get_posts(
			[
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'numberposts'    => -1,
				'fields'         => 'all',
			]
		);

		foreach ( $pages as $page ) {
			if ( ! has_shortcode( $page->post_content, 'membersmap' ) ) {
				continue;
			}

			$dir        = new stdClass();
			$dir->id    = $page->ID;
			$dir->title = $page->post_title;
			$dir->path  = wp_parse_url( get_permalink( $page->ID ), PHP_URL_PATH );

			$dirs[ $page->ID ] = $dir;
		}

		return $dirs;
	}

	/**
	 * Sets the BPS search form action to the current [membersmap] page URL.
	 *
	 * Hooked to `bps_before_search_form`.
	 *
	 * @param object $form BPS form descriptor.
	 */
	public static function set_membersmap_form_action( object $form ): void {
		$requested_url  = bp_get_requested_url();
		$members_slug   = bp_get_members_slug();

		if (
			false !== strpos( $requested_url, $members_slug ) &&
			false !== strpos( $requested_url, 'membersmap' )
		) {
			$form->action = $requested_url;
		}
	}
}

endif;
