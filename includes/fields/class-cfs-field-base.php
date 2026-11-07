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
	 * How deeply nested the repeating field being rendered is (0 = top
	 * level). Repeater and Flexible Content share this one counter, because
	 * they can sit inside each other. Each level's JS row template uses its
	 * own placeholder token (__ROW0__, __ROW1__, ...) so that cloning an
	 * outer row never consumes an inner level's token.
	 *
	 * @var int
	 */
	protected static $row_depth = 0;


	/**
	 * Whether this type holds a value. Layout-only types (message, tab,
	 * accordion) return false: nothing is stored, loaded or returned.
	 */
	public static function stores_value() {
		return true;
	}

	/**
	 * Whether the meta box wraps this type's input in the usual label +
	 * instructions block. Tabs and accordions draw their own marker.
	 */
	public static function has_wrapper() {
		return true;
	}

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

	/**
	 * Read this field's raw stored value. Simple fields live in one key;
	 * composite fields (repeater) override this to reassemble their value
	 * from several keys.
	 *
	 * @param array       $field Field config.
	 * @param int|string  $ctx   Post ID or 'option'.
	 * @param string|null $key   Storage key. Defaults to the field name;
	 *                           composite parents pass a compound key
	 *                           (e.g. "rows_0_title") for nested fields.
	 * @return mixed '' when nothing is stored.
	 */
	public static function load_value( $field, $ctx, $key = null ) {
		$key = ( null === $key ) ? $field['name'] : $key;

		return CFS_Storage::get( $key, $ctx );
	}

	/**
	 * Sanitize a posted value and store it, plus the reference key that
	 * lets the template API map a stored key back to its field config.
	 *
	 * @param array       $field      Field config.
	 * @param mixed       $raw_posted Raw (unslashed) posted value, '' if none.
	 * @param int|string  $ctx        Post ID or 'option'.
	 * @param string|null $key        Storage key, defaults to the field name.
	 */
	public static function save_value( $field, $raw_posted, $ctx, $key = null ) {
		$key = ( null === $key ) ? $field['name'] : $key;

		CFS_Storage::update( $key, static::sanitize_value( $raw_posted, $field ), $ctx );
		CFS_Storage::update( '_' . $key, $field['key'], $ctx );
	}

	/**
	 * Remove everything this field stored under $key. Composite fields
	 * override this to remove their child keys too.
	 *
	 * @param array       $field Field config.
	 * @param int|string  $ctx   Post ID or 'option'.
	 * @param string|null $key   Storage key, defaults to the field name.
	 */
	public static function delete_value( $field, $ctx, $key = null ) {
		$key = ( null === $key ) ? $field['name'] : $key;

		CFS_Storage::delete( $key, $ctx );
		CFS_Storage::delete( '_' . $key, $ctx );
	}

	/* ------------------------------------------------------------------
	 * Small helpers for rendering builder settings rows.
	 * ---------------------------------------------------------------- */

	/** A labelled <input> settings row. */
	protected static function setting_input( $type, $prefix, $key, $label, $field, $input = 'text', $desc = '', $default = '' ) {
		$value = $field[ $key ] ?? $default;
		?>
		<div class="cfs-field-setting" data-type="<?php echo esc_attr( $type ); ?>">
			<label><?php echo esc_html( $label ); ?></label>
			<input type="<?php echo esc_attr( $input ); ?>" name="<?php echo esc_attr( $prefix ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( is_scalar( $value ) ? $value : '' ); ?>"<?php echo 'number' === $input ? ' step="any"' : ''; ?> />
			<?php if ( $desc ) : ?>
				<p class="description"><?php echo esc_html( $desc ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** A labelled <select> settings row. $options is value => label. */
	protected static function setting_select( $type, $prefix, $key, $label, $field, $options, $default = '' ) {
		$value = $field[ $key ] ?? $default;
		?>
		<div class="cfs-field-setting" data-type="<?php echo esc_attr( $type ); ?>">
			<label><?php echo esc_html( $label ); ?></label>
			<select name="<?php echo esc_attr( $prefix ); ?>[<?php echo esc_attr( $key ); ?>]">
				<?php foreach ( $options as $opt_value => $opt_label ) : ?>
					<option value="<?php echo esc_attr( $opt_value ); ?>" <?php selected( (string) $value, (string) $opt_value ); ?>><?php echo esc_html( $opt_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	/** A checkbox settings row. */
	protected static function setting_checkbox( $type, $prefix, $key, $label, $field ) {
		?>
		<div class="cfs-field-setting" data-type="<?php echo esc_attr( $type ); ?>">
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $field[ $key ] ) ); ?> />
				<?php echo esc_html( $label ); ?>
			</label>
		</div>
		<?php
	}

	/** Pick a value from an allow-list, falling back to $default. */
	protected static function pick( $raw, $key, $allowed, $default ) {
		$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_text_field( wp_unslash( $raw[ $key ] ) ) : '';

		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/** Sanitize a text setting. */
	protected static function text_setting( $raw, $key, $default = '' ) {
		return isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? sanitize_text_field( wp_unslash( $raw[ $key ] ) ) : $default;
	}

	/** Sanitize a numeric setting; '' when blank or not numeric. */
	protected static function number_setting( $raw, $key ) {
		if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) || ! is_numeric( trim( (string) $raw[ $key ] ) ) ) {
			return '';
		}

		return 0 + trim( (string) $raw[ $key ] );
	}
}
