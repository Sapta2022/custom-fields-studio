<?php
/**
 * Select field type.
 *
 * Choices are authored one per line as `value : Label`. If no colon is
 * given, the same text is used for both value and label.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFS_Field_Select extends CFS_Field_Base {

	public static $type  = 'select';
	public static $label = 'Select';

	public static function defaults() {
		return array(
			'choices'       => '',
			'default_value' => '',
			'allow_null'    => 0,
			'multiple'      => 0,
		);
	}

	public static function render_settings( $field, $prefix ) {
		?>
		<div class="cfs-field-setting" data-type="select">
			<label><?php esc_html_e( 'Choices', 'cfs' ); ?></label>
			<textarea name="<?php echo esc_attr( $prefix ); ?>[choices]" rows="4" placeholder="red : Red&#10;blue : Blue"><?php echo esc_textarea( $field['choices'] ?? '' ); ?></textarea>
			<p class="description"><?php esc_html_e( 'One choice per line. Format: value : Label', 'cfs' ); ?></p>
		</div>
		<div class="cfs-field-setting" data-type="select">
			<label><?php esc_html_e( 'Default Value', 'cfs' ); ?></label>
			<input type="text" name="<?php echo esc_attr( $prefix ); ?>[default_value]" value="<?php echo esc_attr( $field['default_value'] ?? '' ); ?>" />
		</div>
		<div class="cfs-field-setting" data-type="select">
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[allow_null]" value="1" <?php checked( ! empty( $field['allow_null'] ) ); ?> />
				<?php esc_html_e( 'Allow Null (adds an empty first choice)', 'cfs' ); ?>
			</label>
		</div>
		<div class="cfs-field-setting" data-type="select">
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[multiple]" value="1" <?php checked( ! empty( $field['multiple'] ) ); ?> />
				<?php esc_html_e( 'Select Multiple Values', 'cfs' ); ?>
			</label>
		</div>
		<?php
	}

	public static function sanitize( $raw ) {
		return array(
			'choices'       => isset( $raw['choices'] ) ? sanitize_textarea_field( wp_unslash( $raw['choices'] ) ) : '',
			'default_value' => isset( $raw['default_value'] ) ? sanitize_text_field( wp_unslash( $raw['default_value'] ) ) : '',
			'allow_null'    => ! empty( $raw['allow_null'] ) ? 1 : 0,
			'multiple'      => ! empty( $raw['multiple'] ) ? 1 : 0,
		);
	}

	/**
	 * Parse the raw "value : Label" textarea into an assoc array.
	 * Used later (Phase 2) when rendering the actual select on a post.
	 *
	 * @param string $raw_choices Raw textarea content.
	 * @return array
	 */
	public static function parse_choices( $raw_choices ) {
		$choices = array();
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $raw_choices );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( strpos( $line, ':' ) !== false ) {
				list( $value, $text ) = array_map( 'trim', explode( ':', $line, 2 ) );
			} else {
				$value = $line;
				$text  = $line;
			}
			$choices[ $value ] = $text;
		}

		return $choices;
	}

	public static function render_input( $field, $value, $name ) {
		$choices  = self::parse_choices( $field['choices'] ?? '' );
		$multiple = ! empty( $field['multiple'] );

		if ( null === $value ) {
			$value = $field['default_value'] ?? '';
		}
		// Normalize to an array for uniform "is this selected" checks below,
		// whether the field is single or multiple select.
		$selected = $multiple ? (array) $value : array( $value );

		printf(
			'<select name="%1$s"%2$s>',
			esc_attr( $multiple ? $name . '[]' : $name ),
			$multiple ? ' multiple="multiple"' : ''
		);

		if ( ! $multiple && ! empty( $field['allow_null'] ) ) {
			echo '<option value="">' . esc_html__( '— Select —', 'cfs' ) . '</option>';
		}

		foreach ( $choices as $choice_value => $choice_label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $choice_value ),
				in_array( (string) $choice_value, array_map( 'strval', $selected ), true ) ? ' selected="selected"' : '',
				esc_html( $choice_label )
			);
		}

		echo '</select>';
	}

	public static function sanitize_value( $raw_value, $field ) {
		$choices = self::parse_choices( $field['choices'] ?? '' );

		if ( ! empty( $field['multiple'] ) ) {
			$raw_values = is_array( $raw_value ) ? $raw_value : array();
			$clean      = array();
			foreach ( $raw_values as $one ) {
				$one = sanitize_text_field( $one );
				if ( array_key_exists( $one, $choices ) ) {
					$clean[] = $one;
				}
			}
			return $clean;
		}

		$raw_value = is_array( $raw_value ) ? '' : sanitize_text_field( $raw_value );
		return array_key_exists( $raw_value, $choices ) ? $raw_value : '';
	}
}
