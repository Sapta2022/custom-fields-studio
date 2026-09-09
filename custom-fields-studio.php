<?php
/**
 * Plugin Name:       Custom Fields Studio
 * Plugin URI:        https://techshu.digital
 * Description:       Internal custom fields framework — field groups, location rules, repeater & flexible content fields, options pages, and a template API.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Saptarshi
 * Text Domain:       cfs
 * License:           Proprietary — internal agency use
 *
 * @package CustomFieldsStudio
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CFS_VERSION', '0.1.0' );
define( 'CFS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CFS_URL', plugin_dir_url( __FILE__ ) );
define( 'CFS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload / require core files.
 *
 * Phase 1 scope: Field Group CPT, admin builder UI, basic field types,
 * post-type location rules. Template API and value rendering land in Phase 2.
 */
require_once CFS_PATH . 'includes/fields/class-cfs-field-base.php';
require_once CFS_PATH . 'includes/class-cfs-field-registry.php';
require_once CFS_PATH . 'includes/fields/class-cfs-field-text.php';
require_once CFS_PATH . 'includes/fields/class-cfs-field-textarea.php';
require_once CFS_PATH . 'includes/fields/class-cfs-field-select.php';
require_once CFS_PATH . 'includes/fields/class-cfs-field-true-false.php';
require_once CFS_PATH . 'includes/fields/class-cfs-field-wysiwyg.php';
require_once CFS_PATH . 'includes/fields/class-cfs-field-image.php';
require_once CFS_PATH . 'includes/class-cfs-location-rules.php';
require_once CFS_PATH . 'includes/class-cfs-field-group.php';
require_once CFS_PATH . 'includes/class-cfs-loader.php';

/**
 * Boot the plugin.
 */
function cfs_run() {
	$loader = new CFS_Loader();
	$loader->init();
}
add_action( 'plugins_loaded', 'cfs_run' );

/**
 * Activation: register CPT then flush rewrite rules so the (non-public)
 * post type's admin routes resolve immediately.
 */
function cfs_activate() {
	CFS_Field_Group::register_post_type();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cfs_activate' );

/**
 * Deactivation: flush rewrite rules only. Field group data is left intact —
 * deactivating is not the same as uninstalling.
 */
function cfs_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'cfs_deactivate' );
