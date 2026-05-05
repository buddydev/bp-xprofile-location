<?php
/**
 * Custom xProfile field type: Location.
 *
 * Extends BP_XProfile_Field_Type to provide a Google Places autocomplete
 * address field. All JS is enqueued externally; no inline scripts here.
 *
 * @package BP_xProfile_Location
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BP_XProfile_Field_Type' ) && ! class_exists( 'PP_Location_Field_Type' ) ) :

class PP_Location_Field_Type extends BP_XProfile_Field_Type {

	public function __construct() {
		parent::__construct();

		$this->category           = _x( 'Single Fields', 'xprofile field type category', 'buddypress' );
		$this->name               = _x( 'Location', 'xprofile field type', 'bp-profile-location' );
		$this->accepts_null_value = true;
		$this->supports_options   = true;

		// Accepts any non-empty string (formatted address from Google Places).
		$this->set_format( '/^.+$/', 'replace' );

		do_action( 'bp_xprofile_field_type_location', $this );
	}

	/**
	 * Renders a read-only text input in the xProfile field admin list.
	 *
	 * @param array $raw_properties Extra HTML attributes.
	 */
	public function admin_field_html( array $raw_properties = array() ): void {
		$html = $this->get_edit_field_html_elements(
			array_merge( array( 'type' => 'text' ), $raw_properties )
		);
		?>
		<input <?php echo $html; ?>>
		<?php
	}

	/**
	 * Renders the field on the member profile edit / registration form.
	 *
	 * JS initialisation data is output via data-attributes; the actual
	 * Google Places autocomplete is set up by assets/js/location-field.js.
	 *
	 * @param array $raw_properties Extra HTML attributes.
	 */
	public function edit_field_html( array $raw_properties = array() ): void {
		if ( isset( $raw_properties['user_id'] ) ) {
			unset( $raw_properties['user_id'] );
		}

		if ( bp_get_the_profile_field_is_required() ) {
			$raw_properties['required'] = 'required';
		}

		$field_id     = bp_get_the_profile_field_id();
		$value        = bp_get_the_profile_field_edit_value();
		$save_geocode = bp_xprofile_get_meta( $field_id, 'data', 'geocode' );

		if ( empty( $save_geocode ) ) {
			$save_geocode = '0';
		}

		// Discard the serialised-empty-array artefact BuddyPress can leave.
		$unserialized = maybe_unserialize( $value );
		if ( is_array( $unserialized ) && empty( $unserialized ) ) {
			$value = '';
		}

		$html = $this->get_edit_field_html_elements(
			array_merge(
				array(
					'type'                  => 'text',
					'value'                 => $value,
					'placeholder'           => __( 'Start typing an address. Then make a selection...', 'bp-profile-location' ),
					'class'                 => 'form-control',
					'autocomplete'          => 'off',
					// Data attributes drive assets/js/location-field.js.
					'data-pp-location'      => '1',
					'data-pp-save-geocode'  => esc_attr( $save_geocode ),
					'data-pp-geocode-input' => esc_attr( 'pp_' . $field_id . '_geocode' ),
				),
				$raw_properties
			)
		);

		/**
		 * Setting autocomplete="off" on an input is often ignored by browsers
		 * when the label name matches a well-known autofill token (e.g. "Address").
		 * Inserting a zero-width space in the label text prevents the heuristic match.
		 */
		$label_name = bp_get_the_profile_field_name();
		$label_name = substr_replace( $label_name, '&#8203;', 1, 0 );
		?>
		<legend for="<?php bp_the_profile_field_input_name(); ?>">
			<?php echo wp_kses_post( $label_name ); ?>
			<?php if ( bp_get_the_profile_field_is_required() ) : ?>
				<?php esc_html_e( '(required)', 'buddypress' ); ?>
			<?php endif; ?>
		</legend>

		<?php do_action( bp_get_the_profile_field_errors_action() ); ?>

		<input <?php echo $html; ?> />

		<?php if ( bp_get_the_profile_field_description() ) : ?>
			<p class="description" tabindex="0"><?php bp_the_profile_field_description(); ?></p>
		<?php endif; ?>

		<?php if ( '1' === $save_geocode ) : ?>
			<input type="hidden"
			       id="<?php echo esc_attr( 'pp_' . $field_id . '_geocode' ); ?>"
			       name="<?php echo esc_attr( 'pp_' . $field_id . '_geocode' ); ?>" />
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the geocode option (Yes / No) when creating or editing a field
	 * in the xProfile admin.
	 *
	 * @param BP_XProfile_Field $current_field The field being created/edited.
	 * @param string            $control_type  Unused.
	 */
	public function admin_new_field_html( BP_XProfile_Field $current_field, $control_type = '' ): void {
		$type = array_search( get_class( $this ), bp_xprofile_get_field_types() );
		if ( false === $type ) {
			return;
		}

		$class          = $current_field->type !== $type ? 'display: none;' : '';
		$geocode_option = bp_xprofile_get_meta( $current_field->id, 'data', 'geocode' );

		if ( false === $geocode_option ) {
			$geocode_option = 1; // Default: save geocode.
		}
		?>
		<div id="<?php echo esc_attr( $type ); ?>"
		     class="postbox bp-options-box"
		     style="<?php echo esc_attr( $class ); ?> margin-top: 15px;">
			<div class="inside">
				<h4><?php esc_html_e( 'Do you want this field to save a geocode for each member?', 'bp-profile-location' ); ?></h4>
				<p>
					<select name="<?php echo esc_attr( "{$type}_option[1]" ); ?>"
					        id="<?php echo esc_attr( "{$type}_option1" ); ?>">
						<option value="1"<?php selected( 1, (int) $geocode_option ); ?>><?php esc_html_e( 'Yes', 'bp-profile-location' ); ?></option>
						<option value="2"<?php selected( 2, (int) $geocode_option ); ?>><?php esc_html_e( 'No', 'bp-profile-location' ); ?></option>
					</select>
				</p>
				<p><?php esc_html_e( 'The geocode will be saved in the usermeta table in this format:', 'bp-profile-location' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'meta_key = geocode_[field id]', 'bp-profile-location' ); ?></li>
					<li><?php esc_html_e( 'meta_value = [latitude],[longitude]', 'bp-profile-location' ); ?></li>
				</ul>
				<p>
					<?php esc_html_e( 'You can then use the geocode in your mapping solution.', 'bp-profile-location' ); ?>
					<br>
					<?php
					printf(
						/* translators: %s = product link */
						wp_kses(
							__( 'Or use a solution like <a href="%s">BP Maps for Members</a>.', 'bp-profile-location' ),
							[ 'a' => [ 'href' => [] ] ]
						),
						'https://www.philopress.com/products/bp-maps-for-members/'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Clears the validation whitelist so any non-empty string is accepted.
	 *
	 * @param mixed $values The submitted value(s).
	 *
	 * @return bool
	 */
	public function is_valid( $values ): bool {
		$this->validation_whitelist = null;

		return parent::is_valid( $values );
	}
}

endif;
