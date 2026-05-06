<?php
/**
 * Plugin Name: BP xProfile Location
 * Plugin URI:  https://www.buddydev.com/plugins/bp-xprofile-location
 * Description: Adds an xProfile Location field type that uses the Google Places API to complete and validate addresses.
 * Version:     5.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      PhiloPress
 * Author URI:  https://www.philopress.com/
 * Text Domain: bp-xprofile-location
 * Domain Path: /languages/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Do not allow direct access over web.
defined( 'ABSPATH' ) || exit;

define( 'PP_LOC_VERSION', '5.0.0' );
define( 'PP_LOC_FILE',    __FILE__ );
define( 'PP_LOC_DIR',     plugin_dir_path( __FILE__ ) );
define( 'PP_LOC_URL',     plugin_dir_url( __FILE__ ) );

require_once PP_LOC_DIR . 'inc/class-pp-plugin.php';

PP_Location_Plugin::get_instance();
