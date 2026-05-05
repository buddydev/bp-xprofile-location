/* global google */
/**
 * BP xProfile Location — Google Places autocomplete handler.
 *
 * This function is registered as the Google Maps API load callback
 * (via the `callback=ppLocationInit` URL parameter).  It finds every
 * location field on the page (identified by `data-pp-location="1"`) and
 * attaches a Places Autocomplete instance to it.
 *
 * Per-field configuration is read from data attributes:
 *   data-pp-save-geocode   "1" to capture lat/lng into a hidden input.
 *   data-pp-geocode-input  The id of the hidden geocode input element.
 */
function ppLocationInit() {
	document.querySelectorAll( '[data-pp-location="1"]' ).forEach( function ( input ) {

		// Prevent accidental form submission when the user presses Enter
		// while the Places autocomplete dropdown is open.
		input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
			}
		} );

		// BuddyPress can serialise an empty value as "a:0:{}" — clear it.
		if ( input.value === 'a:0:{}' ) {
			input.value = '';
		}

		var autocomplete = new google.maps.places.Autocomplete( input, {
			types: [ 'geocode' ],
		} );

		autocomplete.setFields( [ 'geometry', 'formatted_address' ] );

		var saveGeocode  = input.dataset.ppSaveGeocode === '1';
		var geocodeInput = saveGeocode
			? document.getElementById( input.dataset.ppGeocodeInput )
			: null;

		autocomplete.addListener( 'place_changed', function () {
			var place = autocomplete.getPlace();

			if ( place.formatted_address ) {
				input.value = place.formatted_address;
			}

			if ( saveGeocode && geocodeInput && place.geometry ) {
				var lat = place.geometry.location.lat();
				var lng = place.geometry.location.lng();
				geocodeInput.value = lat + ',' + lng;
			}
		} );
	} );
}
