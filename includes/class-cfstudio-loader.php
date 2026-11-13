<?php
/**
 * Central hook loader.
 *
 * @package Fieldloom
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFStudio_Loader {

	/**
	 * Wire up all plugin hooks. Called on `plugins_loaded`.
	 */
	public function init() {
		CFStudio_Migrate::maybe_run();
		CFStudio_Field_Registry::register_defaults();

		$field_group = new CFStudio_Field_Group();
		$field_group->hooks();

		$meta_box = new CFStudio_Meta_Box();
		$meta_box->hooks();

		CFStudio_Options_Page::hooks();
		CFStudio_JSON::hooks();
		CFStudio_REST::hooks();
		CFStudio_I18n::hooks();
		CFStudio_Blocks::hooks();

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueue builder JS/CSS only on the Field Group edit screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		global $post_type;

		if ( 'cfstudio_field_group' !== $post_type ) {
			return;
		}

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'cfstudio-admin',
			CFSTUDIO_URL . 'admin/css/admin.css',
			array(),
			CFSTUDIO_VERSION
		);

		wp_enqueue_script( 'jquery-ui-sortable' );

		wp_enqueue_script(
			'cfstudio-field-group-builder',
			CFSTUDIO_URL . 'admin/js/field-group-builder.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			CFSTUDIO_VERSION,
			true
		);

		wp_localize_script(
			'cfstudio-field-group-builder',
			'cfstudioBuilder',
			array(
				'i18n'           => array(
					'confirmRemove' => __( 'Remove this field?', 'fieldloom' ),
					'newFieldLabel' => __( 'New Field', 'fieldloom' ),
					/* translators: %s: the duplicated field name. */
					'duplicateName' => __( 'Field names must be unique within a list. Duplicated: %s', 'fieldloom' ),
					'copySuffix'    => __( ' (copy)', 'fieldloom' ),
					'noValues'      => __( '— none available —', 'fieldloom' ),
				),
				// Every location param's values, to refill the value dropdown
				// when a rule's param changes.
				'locationValues' => CFStudio_Location_Rules::get_all_values(),
				'condOps'        => CFStudio_Conditional::operators(),
			)
		);
	}
}
