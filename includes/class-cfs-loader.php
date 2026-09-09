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
					'confirmRemove' => __( 'Remove this field?', 'cfs' ),
					'newFieldLabel' => __( 'New Field', 'cfs' ),
				),
			)
		);
	}
}
