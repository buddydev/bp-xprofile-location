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

