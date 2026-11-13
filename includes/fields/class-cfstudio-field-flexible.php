<?php
/**
 * Flexible Content field type — an ordered list of rows where each row picks
 * one of several named layouts, and each layout has its own sub fields.
 *
 * STORAGE (ACF-compatible shape):
 *
 *   sections            = a:2:{i:0;s:4:"hero";i:1;s:4:"text";}   (layout name per row)
 *   _sections           = field_abc123
 *   sections_0_title    = "Welcome"          (row 0 is a "hero" row)
 *   sections_1_body     = "<p>Hello</p>"     (row 1 is a "text" row)
 *
 * The layout list at the field's own key is what says which sub fields
 * belong to which row, so loading, saving and deleting all start from it.
 * Sub fields may be repeaters or flexible content fields themselves; as with
 * the repeater, every level builds its children's keys from its own key.
 *
 * @package Fieldloom
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFStudio_Field_Flexible extends CFStudio_Field_Base {

	public static $type  = 'flexible_content';
	public static $label = 'Flexible Content';

	/**
	 * Key a row's layout name is exposed under in values returned by the
	 * template API (and posted under by the edit screen).
	 */
	const LAYOUT_KEY = 'cfs_layout';

	public static function defaults() {
		return array(
			'min'          => 0,
			'max'          => 0,
			'button_label' => 'Add Row',
			'layouts'      => array(),
		);
	}

	/* ------------------------------------------------------------------
	 * Builder (field group edit screen)
	 * ---------------------------------------------------------------- */

	public static function render_settings( $field, $prefix ) {
		$layouts = isset( $field['layouts'] ) && is_array( $field['layouts'] ) ? $field['layouts'] : array();
		?>
		<div class="cfs-field-setting" data-type="flexible_content">
			<label><?php esc_html_e( 'Minimum Rows', 'fieldloom' ); ?></label>
			<input type="number" min="0" name="<?php echo esc_attr( $prefix ); ?>[min]" value="<?php echo esc_attr( $field['min'] ?? 0 ); ?>" />
		</div>
		<div class="cfs-field-setting" data-type="flexible_content">
			<label><?php esc_html_e( 'Maximum Rows', 'fieldloom' ); ?></label>
			<input type="number" min="0" name="<?php echo esc_attr( $prefix ); ?>[max]" value="<?php echo esc_attr( $field['max'] ?? 0 ); ?>" />
			<p class="description"><?php esc_html_e( '0 means no limit.', 'fieldloom' ); ?></p>
		</div>
		<div class="cfs-field-setting" data-type="flexible_content">
			<label><?php esc_html_e( 'Add Row Button Label', 'fieldloom' ); ?></label>
			<input type="text" name="<?php echo esc_attr( $prefix ); ?>[button_label]" value="<?php echo esc_attr( $field['button_label'] ?? 'Add Row' ); ?>" />
		</div>
		<div class="cfs-field-setting" data-type="flexible_content">
			<label><?php esc_html_e( 'Layouts', 'fieldloom' ); ?></label>
			<div class="cfs-layouts" data-base="<?php echo esc_attr( $prefix ); ?>[layouts]">
				<div class="cfs-layouts-list">
					<?php
					foreach ( $layouts as $index => $layout ) {
						self::render_layout_editor( $layout, $prefix . '[layouts][' . $index . ']' );
					}
					?>
				</div>
				<p><button type="button" class="button cfs-add-layout"><?php esc_html_e( '+ Add Layout', 'fieldloom' ); ?></button></p>
			</div>
		</div>
		<?php
	}

	/**
	 * One layout block in the builder: label, name, display style and its
	 * own nested list of sub fields. Public because the builder prints one
	 * empty copy as the shared JS template ("__PREFIX__" as the prefix).
	 *
	 * @param array  $layout Layout config.
	 * @param string $prefix Name prefix, e.g. "cfstudio_fields[0][layouts][1]".
	 */
	public static function render_layout_editor( $layout, $prefix ) {
		$display    = ( isset( $layout['display'] ) && 'table' === $layout['display'] ) ? 'table' : 'block';
		$sub_fields = isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ? $layout['sub_fields'] : array();
		?>
		<div class="cfs-layout-block">
			<div class="cfs-layout-block-head">
				<input type="text" class="cfs-layout-label" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $layout['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Layout Label', 'fieldloom' ); ?>" />
				<input type="text" class="cfs-layout-name" name="<?php echo esc_attr( $prefix ); ?>[name]" value="<?php echo esc_attr( $layout['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'layout_name', 'fieldloom' ); ?>" />
				<select name="<?php echo esc_attr( $prefix ); ?>[display]">
					<option value="block" <?php selected( $display, 'block' ); ?>><?php esc_html_e( 'Block', 'fieldloom' ); ?></option>
					<option value="table" <?php selected( $display, 'table' ); ?>><?php esc_html_e( 'Row', 'fieldloom' ); ?></option>
				</select>
				<button type="button" class="button-link cfs-remove-layout" aria-label="<?php esc_attr_e( 'Remove layout', 'fieldloom' ); ?>">&times;</button>
			</div>
			<div class="cfs-layout-block-body">
				<?php CFStudio_Field_Group::render_fields_editor( $sub_fields, $prefix . '[sub_fields]' ); ?>
			</div>
		</div>
		<?php
	}

	public static function sanitize( $raw ) {
		$min = isset( $raw['min'] ) ? absint( $raw['min'] ) : 0;
		$max = isset( $raw['max'] ) ? absint( $raw['max'] ) : 0;

		if ( $max > 0 && $min > $max ) {
			$min = $max;
		}

		$button = isset( $raw['button_label'] ) ? sanitize_text_field( $raw['button_label'] ) : '';

		$layouts = array();
		$used    = array();

		foreach ( (array) ( $raw['layouts'] ?? array() ) as $raw_layout ) {
			if ( ! is_array( $raw_layout ) ) {
				continue;
			}

			$label = isset( $raw_layout['label'] ) ? sanitize_text_field( $raw_layout['label'] ) : '';
			$name  = isset( $raw_layout['name'] ) ? sanitize_key( $raw_layout['name'] ) : '';

			if ( '' === $name && '' !== $label ) {
				$name = sanitize_key( $label );
			}

			// An unused template row, or a layout with nothing to identify it.
			if ( '' === $name ) {
				continue;
			}

			// Layout names identify rows in storage, so they must be unique.
			$base = $name;
			$n    = 2;
			while ( isset( $used[ $name ] ) ) {
				$name = $base . '_' . $n++;
			}
			$used[ $name ] = true;

			$layouts[] = array(
				'label'      => '' !== $label ? $label : $name,
				'name'       => $name,
				'display'    => ( isset( $raw_layout['display'] ) && 'table' === $raw_layout['display'] ) ? 'table' : 'block',
				'sub_fields' => CFStudio_Field_Group::sanitize_fields( $raw_layout['sub_fields'] ?? array() ),
			);
		}

		return array(
			'min'          => $min,
			'max'          => $max,
			'button_label' => '' !== $button ? $button : 'Add Row',
			'layouts'      => $layouts,
		);
	}

	/* ------------------------------------------------------------------
	 * Storage
	 * ---------------------------------------------------------------- */

	/**
	 * Compound storage key for one sub field in one row.
	 */
	protected static function sub_key( $key, $row_index, $sub_field ) {
		return $key . '_' . (int) $row_index . '_' . $sub_field['name'];
	}

	/**
	 * The layout config with this name, or null.
	 */
	protected static function find_layout( $field, $name ) {
		foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
			if ( isset( $layout['name'] ) && $layout['name'] === $name ) {
				return $layout;
			}
		}

		return null;
	}

	/**
	 * Storable sub fields of a layout (known type and a name).
	 *
	 * @return array List of array( 'field' => config, 'class' => type class ).
	 */
	protected static function usable_sub_fields( $layout ) {
		$usable = array();

		foreach ( (array) ( $layout['sub_fields'] ?? array() ) as $sub_field ) {
			if ( empty( $sub_field['name'] ) || empty( $sub_field['type'] ) ) {
				continue;
			}
			$type_class = CFStudio_Field_Registry::get_class( $sub_field['type'] );
			if ( $type_class && $type_class::stores_value() ) {
				$usable[] = array(
					'field' => $sub_field,
					'class' => $type_class,
				);
			}
		}

		return $usable;
	}

	/**
	 * The stored list of layout names, one per row.
	 */
	protected static function stored_layouts( $key, $ctx ) {
		$names = CFStudio_Storage::get( $key, $ctx );

		return is_array( $names ) ? array_values( array_map( 'strval', $names ) ) : array();
	}

	/**
	 * Raw rows: a list of arrays holding the layout name under LAYOUT_KEY
	 * plus sub-name => raw stored value for that layout's sub fields.
	 */
	public static function load_value( $field, $ctx, $key = null ) {
		$key  = ( null === $key ) ? $field['name'] : $key;
		$rows = array();

		foreach ( self::stored_layouts( $key, $ctx ) as $i => $layout_name ) {
			$layout = self::find_layout( $field, $layout_name );
			if ( ! $layout ) {
				// The layout was deleted from the field group; its stored
				// rows are skipped (the data is left alone until next save).
				continue;
			}

			$row = array( self::LAYOUT_KEY => $layout_name );
			foreach ( self::usable_sub_fields( $layout ) as $sub ) {
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
	 * Save posted rows ( row-key => ( LAYOUT_KEY => name, sub-name => value ),
	 * in DOM order). Row keys are throwaway; order is what counts.
	 *
	 * Old rows are deleted first, then the new ones written: unlike a
	 * repeater, the layout (and so the set of sub fields) at a given row
	 * index can change between saves, so a diff would leave stale keys.
	 */
	public static function save_value( $field, $raw_posted, $ctx, $key = null ) {
		$key = ( null === $key ) ? $field['name'] : $key;

		self::delete_rows( $field, $ctx, $key );

		$posted = is_array( $raw_posted ) ? array_values( array_filter( $raw_posted, 'is_array' ) ) : array();
		$max    = isset( $field['max'] ) ? (int) $field['max'] : 0;

		$names = array();

		foreach ( $posted as $row ) {
			if ( $max > 0 && count( $names ) >= $max ) {
				break;
			}

			$layout_name = isset( $row[ self::LAYOUT_KEY ] ) ? sanitize_key( $row[ self::LAYOUT_KEY ] ) : '';
			$layout      = self::find_layout( $field, $layout_name );
			if ( ! $layout ) {
				continue;
			}

			$i = count( $names );

			foreach ( self::usable_sub_fields( $layout ) as $sub ) {
				$sub['class']::save_value(
					$sub['field'],
					$row[ $sub['field']['name'] ] ?? '',
					$ctx,
					self::sub_key( $key, $i, $sub['field'] )
				);
			}

			$names[] = $layout_name;
		}

		CFStudio_Storage::update( $key, $names, $ctx );
		CFStudio_Storage::update( '_' . $key, $field['key'], $ctx );
	}

	/**
	 * Remove every stored sub field value of the existing rows (not the
	 * field's own list/reference keys).
	 */
	protected static function delete_rows( $field, $ctx, $key ) {
		foreach ( self::stored_layouts( $key, $ctx ) as $i => $layout_name ) {
			$layout = self::find_layout( $field, $layout_name );
			if ( ! $layout ) {
				continue;
			}
			foreach ( self::usable_sub_fields( $layout ) as $sub ) {
				$sub['class']::delete_value( $sub['field'], $ctx, self::sub_key( $key, $i, $sub['field'] ) );
			}
		}
	}

	public static function delete_value( $field, $ctx, $key = null ) {
		$key = ( null === $key ) ? $field['name'] : $key;

		self::delete_rows( $field, $ctx, $key );

		CFStudio_Storage::delete( $key, $ctx );
		CFStudio_Storage::delete( '_' . $key, $ctx );
	}

	/**
	 * Template API shape: a list of rows, each an assoc array with the
	 * layout name under 'cfs_layout' and formatted sub-name => value pairs.
	 */
	public static function format_value( $value, $field ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$layout = self::find_layout( $field, $row[ self::LAYOUT_KEY ] ?? '' );
			if ( ! $layout ) {
				continue;
			}

			$formatted = array( self::LAYOUT_KEY => $layout['name'] );
			foreach ( self::usable_sub_fields( $layout ) as $sub ) {
				$name               = $sub['field']['name'];
				$raw                = array_key_exists( $name, $row ) ? $row[ $name ] : '';
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
		$layouts = array();
		foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
			if ( ! empty( $layout['name'] ) ) {
				$layouts[ $layout['name'] ] = $layout;
			}
		}

		if ( empty( $layouts ) ) {
			echo '<p class="description">' . esc_html__( 'This flexible content field has no layouts yet. Add some in the field group editor.', 'fieldloom' ) . '</p>';
			return;
		}

		$rows   = is_array( $value ) ? array_values( $value ) : array();
		$min    = isset( $field['min'] ) ? (int) $field['min'] : 0;
		$max    = isset( $field['max'] ) ? (int) $field['max'] : 0;
		$button = ! empty( $field['button_label'] ) ? $field['button_label'] : __( 'Add Row', 'fieldloom' );
		$token  = '__ROW' . self::$row_depth . '__';

		// Nested repeating fields in rows/templates use the next token level.
		++self::$row_depth;
		?>
		<div class="cfs-flex"
			data-token="<?php echo esc_attr( $token ); ?>"
			data-min="<?php echo esc_attr( $min ); ?>"
			data-max="<?php echo esc_attr( $max ); ?>">
			<div class="cfs-flex-rows">
				<?php
				foreach ( $rows as $index => $row ) {
					$layout_name = is_array( $row ) ? ( $row[ self::LAYOUT_KEY ] ?? '' ) : '';
					if ( isset( $layouts[ $layout_name ] ) ) {
						self::render_row( $layouts[ $layout_name ], $name, $index, $row );
					}
				}
				?>
			</div>
			<?php foreach ( $layouts as $layout_name => $layout ) : ?>
				<template class="cfs-flex-template" data-layout="<?php echo esc_attr( $layout_name ); ?>">
					<?php self::render_row( $layout, $name, $token, array() ); ?>
				</template>
			<?php endforeach; ?>
			<p class="cfs-flex-footer">
				<button type="button" class="button cfs-flex-add"><?php echo esc_html( $button ); ?></button>
				<span class="cfs-flex-menu" hidden>
					<?php foreach ( $layouts as $layout_name => $layout ) : ?>
						<button type="button" class="button-link cfs-flex-add-layout" data-layout="<?php echo esc_attr( $layout_name ); ?>"><?php echo esc_html( $layout['label'] ?? $layout_name ); ?></button>
					<?php endforeach; ?>
				</span>
			</p>
		</div>
		<?php
		--self::$row_depth;
	}

	/**
	 * One row: handle + number, a title naming the layout (with the hidden
	 * input that records it), the layout's sub fields, a remove button.
	 *
	 * @param array      $layout Layout config.
	 * @param string     $name   Input name of the field, e.g. "cfstudio[sections]".
	 * @param int|string $index  Row index, or the level's __ROWn__ token.
	 * @param array      $row    Raw values keyed by sub-field name.
	 */
	protected static function render_row( $layout, $name, $index, $row ) {
		$display = ( isset( $layout['display'] ) && 'table' === $layout['display'] ) ? 'table' : 'block';
		?>
		<div class="cfs-flex-row cfs-flex-display-<?php echo esc_attr( $display ); ?>" data-layout="<?php echo esc_attr( $layout['name'] ); ?>">
			<div class="cfs-flex-row-handle">
				<span class="cfs-drag-handle dashicons dashicons-menu" title="<?php esc_attr_e( 'Drag to reorder', 'fieldloom' ); ?>"></span>
				<span class="cfs-row-number"></span>
			</div>
			<div class="cfs-flex-row-body">
				<div class="cfs-flex-row-title">
					<?php echo esc_html( $layout['label'] ?? $layout['name'] ); ?>
					<input type="hidden" name="<?php echo esc_attr( $name . '[' . $index . '][' . self::LAYOUT_KEY . ']' ); ?>" value="<?php echo esc_attr( $layout['name'] ); ?>" />
				</div>
				<div class="cfs-flex-row-fields">
					<?php
					foreach ( self::usable_sub_fields( $layout ) as $sub ) {
						$sub_field = $sub['field'];
						$sub_value = $row[ $sub_field['name'] ] ?? null;

						// A saved row keeps '' as the editor's deliberate empty value; only
						// rows with no entry at all (new / padded to min) get field defaults.

						// Rows are cloned in the browser, so WYSIWYG sub fields
						// print a plain textarea that JS upgrades.
						$sub_field['_lazy'] = true;

						CFStudio_Meta_Box::render_field(
							$sub_field,
							$sub_value,
							$name . '[' . $index . '][' . $sub_field['name'] . ']'
						);
					}
					?>
				</div>
			</div>
			<div class="cfs-flex-row-actions">
				<button type="button" class="button-link cfs-flex-remove" aria-label="<?php esc_attr_e( 'Remove row', 'fieldloom' ); ?>">&times;</button>
			</div>
		</div>
		<?php
	}
}
