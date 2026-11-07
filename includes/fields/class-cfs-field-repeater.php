<?php
/**
 * Repeater field type — a list of rows, each row holding the same set of
 * sub fields.
 *
 * STORAGE (ACF-compatible shape, so existing tooling and meta queries
 * behave the way people expect):
 *
 *   gallery            = 2                  (row count)
 *   _gallery           = field_abc123       (reference to this field's key)
 *   gallery_0_caption  = "First"            (row 0, sub field "caption")
 *   _gallery_0_caption = field_def456
 *   gallery_1_caption  = "Second"
 *
 * Sub fields can themselves be repeaters: their keys just get longer
 * ("gallery_0_tags_1_name"), because every level builds its children's
 * keys from its own key and the load/save/delete hooks recurse.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Field_Repeater extends CFS_Field_Base {

	public static $type  = 'repeater';
	public static $label = 'Repeater';

	public static function defaults() {
		return array(
			'layout'       => 'table',
			'min'          => 0,
			'max'          => 0,
			'button_label' => 'Add Row',
			'sub_fields'   => array(),
		);
	}

	/* ------------------------------------------------------------------
	 * Builder (field group edit screen)
	 * ---------------------------------------------------------------- */

	public static function render_settings( $field, $prefix ) {
		$layout = $field['layout'] ?? 'table';
		?>
		<div class="cfs-field-setting" data-type="repeater">
			<label><?php esc_html_e( 'Layout', 'cfs' ); ?></label>
			<select name="<?php echo esc_attr( $prefix ); ?>[layout]">
				<option value="table" <?php selected( $layout, 'table' ); ?>><?php esc_html_e( 'Row (sub fields side by side)', 'cfs' ); ?></option>
				<option value="block" <?php selected( $layout, 'block' ); ?>><?php esc_html_e( 'Block (sub fields stacked)', 'cfs' ); ?></option>
			</select>
		</div>
		<div class="cfs-field-setting" data-type="repeater">
			<label><?php esc_html_e( 'Minimum Rows', 'cfs' ); ?></label>
			<input type="number" min="0" name="<?php echo esc_attr( $prefix ); ?>[min]" value="<?php echo esc_attr( $field['min'] ?? 0 ); ?>" />
		</div>
		<div class="cfs-field-setting" data-type="repeater">
			<label><?php esc_html_e( 'Maximum Rows', 'cfs' ); ?></label>
			<input type="number" min="0" name="<?php echo esc_attr( $prefix ); ?>[max]" value="<?php echo esc_attr( $field['max'] ?? 0 ); ?>" />
			<p class="description"><?php esc_html_e( '0 means no limit.', 'cfs' ); ?></p>
		</div>
		<div class="cfs-field-setting" data-type="repeater">
			<label><?php esc_html_e( 'Add Row Button Label', 'cfs' ); ?></label>
			<input type="text" name="<?php echo esc_attr( $prefix ); ?>[button_label]" value="<?php echo esc_attr( $field['button_label'] ?? 'Add Row' ); ?>" />
		</div>
		<div class="cfs-field-setting" data-type="repeater">
			<label><?php esc_html_e( 'Sub Fields', 'cfs' ); ?></label>
			<?php
			$sub_fields = isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ? $field['sub_fields'] : array();
			CFS_Field_Group::render_fields_editor( $sub_fields, $prefix . '[sub_fields]' );
			?>
		</div>
		<?php
	}

	public static function sanitize( $raw ) {
		$layout = ( isset( $raw['layout'] ) && 'block' === $raw['layout'] ) ? 'block' : 'table';
		$min    = isset( $raw['min'] ) ? absint( $raw['min'] ) : 0;
		$max    = isset( $raw['max'] ) ? absint( $raw['max'] ) : 0;

		// A minimum above the maximum can never be satisfied.
		if ( $max > 0 && $min > $max ) {
			$min = $max;
		}

		$button = isset( $raw['button_label'] ) ? sanitize_text_field( $raw['button_label'] ) : '';

		return array(
			'layout'       => $layout,
			'min'          => $min,
			'max'          => $max,
			'button_label' => '' !== $button ? $button : 'Add Row',
			'sub_fields'   => CFS_Field_Group::sanitize_fields( $raw['sub_fields'] ?? array() ),
		);
	}

	/* ------------------------------------------------------------------
	 * Storage
	 * ---------------------------------------------------------------- */

	/**
	 * Compound storage key for one sub field in one row.
	 *
	 * @param string $key       The repeater's own storage key.
	 * @param int    $row_index Zero-based row index.
	 * @param array  $sub_field Sub field config.
	 * @return string
	 */
	protected static function sub_key( $key, $row_index, $sub_field ) {
		return $key . '_' . (int) $row_index . '_' . $sub_field['name'];
	}

	/**
	 * Sub fields that can actually be stored (known type and a name).
	 *
	 * @param array $field Repeater config.
	 * @return array List of array( 'field' => config, 'class' => type class ).
	 */
	protected static function usable_sub_fields( $field ) {
		$usable = array();

		foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub_field ) {
			if ( empty( $sub_field['name'] ) || empty( $sub_field['type'] ) ) {
				continue;
			}
			$type_class = CFS_Field_Registry::get_class( $sub_field['type'] );
			if ( $type_class ) {
				$usable[] = array(
					'field' => $sub_field,
					'class' => $type_class,
				);
			}
		}

		return $usable;
	}

	/**
	 * Raw rows: a list of arrays of sub-name => raw stored value.
	 */
	public static function load_value( $field, $ctx, $key = null ) {
		$key   = ( null === $key ) ? $field['name'] : $key;
		$count = (int) CFS_Storage::get( $key, $ctx );
		$subs  = self::usable_sub_fields( $field );
		$rows  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$row = array();
			foreach ( $subs as $sub ) {
				$row[ $sub['field']['name'] ] = $sub['class']::load_value(
					$sub['field'],
					$ctx,
					self::sub_key( $key, $i, $sub['field'] )
				);
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Save the posted rows. $raw_posted is the array posted under this
	 * field's name: row-key => ( sub-name => raw value ), in DOM order.
	 * The row keys themselves are meaningless (new rows get throwaway
	 * keys from the JS) — only the order matters, so rows are re-indexed
	 * 0..n-1 here.
	 */
	public static function save_value( $field, $raw_posted, $ctx, $key = null ) {
		$key       = ( null === $key ) ? $field['name'] : $key;
		$subs      = self::usable_sub_fields( $field );
		$old_count = (int) CFS_Storage::get( $key, $ctx );

		$rows = is_array( $raw_posted ) ? array_values( array_filter( $raw_posted, 'is_array' ) ) : array();

		$max = isset( $field['max'] ) ? (int) $field['max'] : 0;
		if ( $max > 0 ) {
			$rows = array_slice( $rows, 0, $max );
		}

		foreach ( $rows as $i => $row ) {
			foreach ( $subs as $sub ) {
				// A missing entry (unchecked checkbox, empty row) still goes
				// through save_value() so the "off"/empty state is stored.
				$sub['class']::save_value(
					$sub['field'],
					$row[ $sub['field']['name'] ] ?? '',
					$ctx,
					self::sub_key( $key, $i, $sub['field'] )
				);
			}
		}

		// Rows that existed before but are gone now: remove their keys so
		// no orphaned "name_4_sub" values linger in the database.
		for ( $i = count( $rows ); $i < $old_count; $i++ ) {
			foreach ( $subs as $sub ) {
				$sub['class']::delete_value( $sub['field'], $ctx, self::sub_key( $key, $i, $sub['field'] ) );
			}
		}

		CFS_Storage::update( $key, count( $rows ), $ctx );
		CFS_Storage::update( '_' . $key, $field['key'], $ctx );
	}

	public static function delete_value( $field, $ctx, $key = null ) {
		$key   = ( null === $key ) ? $field['name'] : $key;
		$count = (int) CFS_Storage::get( $key, $ctx );
		$subs  = self::usable_sub_fields( $field );

		for ( $i = 0; $i < $count; $i++ ) {
			foreach ( $subs as $sub ) {
				$sub['class']::delete_value( $sub['field'], $ctx, self::sub_key( $key, $i, $sub['field'] ) );
			}
		}

		CFS_Storage::delete( $key, $ctx );
		CFS_Storage::delete( '_' . $key, $ctx );
	}

	/**
	 * Template API shape: a list of rows, each an assoc array of
	 * sub-name => formatted value (images become arrays/URLs, true/false
	 * becomes a real boolean, a nested repeater becomes its own list of
	 * rows, ...).
	 */
	public static function format_value( $value, $field ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$subs = self::usable_sub_fields( $field );
		$out  = array();

		foreach ( $value as $row ) {
			$formatted = array();
			foreach ( $subs as $sub ) {
				$name              = $sub['field']['name'];
				$raw               = is_array( $row ) && array_key_exists( $name, $row ) ? $row[ $name ] : '';
				$formatted[ $name ] = $sub['class']::format_value( $raw, $sub['field'] );
			}
			$out[] = $formatted;
		}

		return $out;
	}

	/* ------------------------------------------------------------------
	 * Post edit screen
	 * ---------------------------------------------------------------- */

	public static function render_input( $field, $value, $name ) {
		$subs = self::usable_sub_fields( $field );

		if ( empty( $subs ) ) {
			echo '<p class="description">' . esc_html__( 'This repeater has no sub fields yet. Add some in the field group editor.', 'cfs' ) . '</p>';
			return;
		}

		$rows   = is_array( $value ) ? array_values( $value ) : array();
		$min    = isset( $field['min'] ) ? (int) $field['min'] : 0;
		$max    = isset( $field['max'] ) ? (int) $field['max'] : 0;
		$layout = ( isset( $field['layout'] ) && 'block' === $field['layout'] ) ? 'block' : 'table';
		$button = ! empty( $field['button_label'] ) ? $field['button_label'] : __( 'Add Row', 'cfs' );
		$token  = '__ROW' . self::$row_depth . '__';

		// Show at least "min" rows so the editor can fill them in.
		while ( count( $rows ) < $min ) {
			$rows[] = array();
		}

		// Everything rendered below (this level's rows AND its row
		// template) may contain nested repeaters, which must use the next
		// token level.
		self::$row_depth++;
		?>
		<div class="cfs-repeater cfs-layout-<?php echo esc_attr( $layout ); ?>"
			data-token="<?php echo esc_attr( $token ); ?>"
			data-min="<?php echo esc_attr( $min ); ?>"
			data-max="<?php echo esc_attr( $max ); ?>">
			<div class="cfs-repeater-rows">
				<?php
				foreach ( $rows as $index => $row ) {
					self::render_row( $subs, $name, $index, is_array( $row ) ? $row : array() );
				}
				?>
			</div>
			<?php
			/*
			 * <template> (not <script type="text/html">) because a
			 * nested repeater's template sits inside this one, and a
			 * <script> would end at the first inner </script>. Its
			 * contents are inert: inputs inside are never submitted.
			 */
			?>
			<template class="cfs-repeater-template">
				<?php self::render_row( $subs, $name, $token, array() ); ?>
			</template>
			<p class="cfs-repeater-footer">
				<button type="button" class="button cfs-repeater-add"><?php echo esc_html( $button ); ?></button>
			</p>
		</div>
		<?php
		self::$row_depth--;
	}

	/**
	 * One row: drag handle + row number, the sub fields, a remove button.
	 *
	 * @param array      $subs  Usable sub fields.
	 * @param string     $name  Input name of the repeater, e.g. "cfs[gallery]".
	 * @param int|string $index Row index, or the level's __ROWn__ token.
	 * @param array      $row   Raw values keyed by sub-field name.
	 */
	protected static function render_row( $subs, $name, $index, $row ) {
		?>
		<div class="cfs-repeater-row">
			<div class="cfs-repeater-row-handle">
				<span class="cfs-drag-handle dashicons dashicons-menu" title="<?php esc_attr_e( 'Drag to reorder', 'cfs' ); ?>"></span>
				<span class="cfs-row-number"></span>
			</div>
			<div class="cfs-repeater-fields">
				<?php
				foreach ( $subs as $sub ) {
					$sub_field = $sub['field'];
					$sub_value = $row[ $sub_field['name'] ] ?? null;

					if ( '' === $sub_value ) {
						$sub_value = null;
					}

					// Rows are added by cloning HTML in the browser, which can't
					// run PHP — so inputs that need server-rendered scripts
					// (WYSIWYG) switch to a mode their JS can initialize later.
					$sub_field['_lazy'] = true;

					CFS_Meta_Box::render_field(
						$sub_field,
						$sub_value,
						$name . '[' . $index . '][' . $sub_field['name'] . ']'
					);
				}
				?>
			</div>
			<div class="cfs-repeater-row-actions">
				<button type="button" class="button-link cfs-repeater-remove" aria-label="<?php esc_attr_e( 'Remove row', 'cfs' ); ?>">&times;</button>
			</div>
		</div>
		<?php
	}
}
