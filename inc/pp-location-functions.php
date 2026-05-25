<?php


/**
 * Filter the member ids that are used to display members on the map, via BPS.
 *
 * @param array $user_ids Current member IDs.
 *
 * @return array
 */
function bps_filter_pp_location_member_ids( $user_ids ) {
	return PP_Location_BPS_Integration::filter_member_ids_via_bps( $user_ids );
}

/**
 * Returns member IDs within a certain radius of a lat/lng point.
 *
 * @param float $lat Latitude of the center point.
 * @param float $lng Longitude of the center point.
 * @param int   $radius Radius in miles or kilometers.
 * @param string $key Meta key for location data.
 * @param int   $earthRadius Earth radius in miles or kilometers.
 *
 * @return array Array of member IDs within the specified radius.
 */
function pp_location_members_radial_distance( $lat, $lng, $radius, $key, $earthRadius ): array {
	return PP_Location_BPS_Integration::members_within_radius( $lat, $lng, $radius, $key, $earthRadius );
}
