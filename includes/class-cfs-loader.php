<?php
/**
 * Central hook loader.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Loader {

	/**
	 * Wire up all plugin hooks. Called on `plugins_loaded`.
	 */
	public function init() {
		CFS_Field_Registry::register_defaults();

		$field_group = new CFS_Field_Group();
		$field_group->hooks();

		$meta_box = new CFS_Meta_Box();
		$meta_box->hooks();

		CFS_Options_Page::hooks();
		CFS_JSON::hooks();
		CFS_REST::hooks();
		CFS_I18n::hooks();
		CFS_Blocks::hooks();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueue builder JS/CSS only on the Field Group edit screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		global $post_type;

		if ( 'cfs_field_group' !== $post_type ) {
			return;
		}

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'cfs-admin',
			CFS_URL . 'admin/css/admin.css',
			array(),
			CFS_VERSION
		);

		wp_enqueue_script( 'jquery-ui-sortable' );

		wp_enqueue_script(
			'cfs-field-group-builder',
			CFS_URL . 'admin/js/field-group-builder.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			CFS_VERSION,
			true
		);

		wp_localize_script(
			'cfs-field-group-builder',
			'cfsBuilder',
			array(
				'i18n' => array(
					'confirmRemove' => __( 'Remove this field?', 'custom-fields-studio' ),
					'newFieldLabel' => __( 'New Field', 'custom-fields-studio' ),
					/* translators: %s: the duplicated field name. */
					'duplicateName' => __( 'Field names must be unique within a list. Duplicated: %s', 'custom-fields-studio' ),
					'copySuffix'    => __( ' (copy)', 'custom-fields-studio' ),
					'noValues'      => __( '— none available —', 'custom-fields-studio' ),
				),
				// Every location param's values, to refill the value dropdown
				// when a rule's param changes.
				'locationValues' => CFS_Location_Rules::get_all_values(),
				'condOps'        => CFS_Conditional::operators(),
			)
		);
	}
}
