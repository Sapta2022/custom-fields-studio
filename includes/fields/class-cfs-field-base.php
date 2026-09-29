<?php
/**
 * Abstract base for a field type.
 *
 * Each concrete field type (text, textarea, select, ...) extends this and
 * declares its own extra settings. Phase 1 covers the settings-panel side
 * (used in the field group builder). Phase 2 adds the other half: actually
 * rendering the field's input on a target post edit screen, sanitizing the
 * posted value on save, and formatting the stored value for the template API.
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

	/**
	 * Render this field's actual input on a post edit screen (inside the
	 * CFS meta box). $name is the fully-formed HTML name attribute to use
	 * for the input, already unique per field, e.g. "cfs[my_text]" — field
	 * type classes should not invent their own naming scheme.
	 *
	 * @param array  $field Field config (label, name, type, + type-specific settings).
	 * @param mixed  $value Current stored value (already the raw/unformatted value), or null if unset.
	 * @param string $name  HTML name attribute for the input.
	 */
	public static function render_input( $field, $value, $name ) {
		// Default: no-op. Concrete types must implement this to be usable.
	}

	/**
	 * Sanitize a posted value for this field type before it's saved as
	 * post meta / option. Receives the raw $_POST value for just this
	 * field (already wp_unslash()'d by the caller).
	 *
	 * @param mixed $raw_value Raw posted value.
	 * @param array $field     Field config.
	 * @return mixed Sanitized value, ready to store.
	 */
	public static function sanitize_value( $raw_value, $field ) {
		return is_scalar( $raw_value ) ? sanitize_text_field( $raw_value ) : '';
	}

	/**
	 * Format a stored value for the template API (cfs_get_field() etc).
	 * Most types return the value unchanged; a few (image, select) shape
	 * it based on the field's own settings (return_format, choices, ...).
	 *
	 * @param mixed $value Raw stored value.
	 * @param array $field Field config.
	 * @return mixed
	 */
	public static function format_value( $value, $field ) {
		return $value;
	}
}
