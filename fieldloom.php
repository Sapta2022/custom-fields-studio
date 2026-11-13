<?php
/**
 * Plugin Name:       Fieldloom Custom Fields
 * Plugin URI:        https://github.com/Sapta2022/custom-fields-studio
 * Description:       Add custom fields to posts, pages, users, terms, options pages and Gutenberg blocks. Repeater, flexible content, clone, conditional logic, JSON import/export and a REST API.
 * Version:           0.14.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Sapatarshi Samanta
 * Author URI:        https://github.com/Sapta2022
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fieldloom
 *
 * @package Fieldloom
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CFSTUDIO_VERSION', '0.14.0' );
define( 'CFSTUDIO_PATH', plugin_dir_path( __FILE__ ) );
define( 'CFSTUDIO_URL', plugin_dir_url( __FILE__ ) );
define( 'CFSTUDIO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload / require core files.
 *
 * Phase 1: Field Group CPT, admin builder UI, basic field types, post-type
 * location rules.
 * Phase 2: rendering field groups as meta boxes on post edit screens,
 * saving posted values as post meta, and the cfstudio_get_field()-style
 * template API.
 * Phase 3: repeater field (compound-key storage, row UI, loop API).
 * Phase 4: flexible content field (layouts, per-row layout storage).
 * Phase 5: options pages (code + UI registry, options_page location rule,
 * 'option' context in the template API).
 */
require_once CFSTUDIO_PATH . 'includes/class-cfstudio-storage.php';
require_once CFSTUDIO_PATH . 'includes/class-cfstudio-migrate.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-base.php';
require_once CFSTUDIO_PATH . 'includes/class-cfstudio-field-registry.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-text.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-textarea.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-select.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-true-false.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-wysiwyg.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-image.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-date-picker.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-gallery.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-relationship.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-taxonomy.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-simple.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-choice.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-file.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-picker.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-link.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-layout.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-clone.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-google-map.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-post-object.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-user.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-repeater.php';
require_once CFSTUDIO_PATH . 'includes/fields/class-cfstudio-field-flexible.php';
require_once CFSTUDIO_PATH . 'includes/class-cfstudio-conditional.php';
require_once CFSTUDIO_PATH . 'includes/class-cfstudio-location-rules.php';
require_once CFSTUDIO_PATH . 'includes/class-cfstudio-field-group.php';
require_once CFSTUDIO_PATH . 'includes/class-cfstudio-i18n.php';
require_once CFSTUDIO_PATH . 'includes/class-cfstudio-blocks.php';
require_once CFSTUDIO_PATH . 'includes/class-cfstudio-json.php';
require_once CFSTUDIO_PATH . 'includes/class-cfstudio-rest.php';
require_once CFSTUDIO_PATH . 'includes/class-cfstudio-meta-box.php';
require_once CFSTUDIO_PATH . 'includes/class-cfstudio-options-page.php';
require_once CFSTUDIO_PATH . 'includes/template-functions.php';
require_once CFSTUDIO_PATH . 'includes/class-cfstudio-shortcode.php';
require_once CFSTUDIO_PATH . 'includes/class-cfstudio-loader.php';

/**
 * Boot the plugin.
 */
function cfstudio_run() {
	$loader = new CFStudio_Loader();
	$loader->init();
}
add_action( 'plugins_loaded', 'cfstudio_run' );

/**
 * Activation: register CPT then flush rewrite rules so the (non-public)
 * post type's admin routes resolve immediately.
 */
function cfstudio_activate() {
	CFStudio_Field_Group::register_post_type();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cfstudio_activate' );

/**
 * Deactivation: flush rewrite rules only. Field group data is left intact —
 * deactivating is not the same as uninstalling.
 */
function cfstudio_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'cfstudio_deactivate' );
