<?php
/**
 * Abstract base for a field type.
 *
 * Each concrete field type (text, textarea, select, ...) extends this and
 * declares its own extra settings. Phase 1 only needs the settings-panel
 * side of this (used in the field group builder) — rendering the field on
 * a target post edit screen and reading/writing its value is Phase 2.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class CFS_Field_Base {

	/**
	 * Unique field type key, e.g. 'text'. Set by subclass.
	 *
	 * @var string
	 */
	public static $type = '';

	/**
	 * Human readable label shown in the "Field Type" dropdown.
	 *
	 * @var string
	 */
	public static $label = '';

	/**
	 * Default values for this field type's settings, merged over the
	 * generic field defaults (label, name, instructions, required).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array();
	}

	/**
	 * Render this field type's extra settings rows inside a field row of
	 * the builder. $field is the full field config array; $prefix is the
	 * HTML name-attribute prefix, e.g. "cfs_fields[3]".
	 *
	 * Each setting row should carry class="cfs-field-setting"
	 * data-type="{type}" so the builder JS can show/hide it when the
	 * "Field Type" dropdown changes.
	 *
	 * @param array  $field  Field config.
	 * @param string $prefix Name attribute prefix.
	 */
	public static function render_settings( $field, $prefix ) {
		// Default: no extra settings.
	}

	/**
	 * Sanitize this field type's extra settings on save. Receives the raw
	 * posted sub-array for one field row; must return the cleaned array
	 * (generic keys like label/name/required are sanitized by the caller
	 * already and don't need to be repeated here).
	 *
	 * @param array $raw Raw $_POST field row.
	 * @return array
	 */
	public static function sanitize( $raw ) {
		return array();
	}
}
