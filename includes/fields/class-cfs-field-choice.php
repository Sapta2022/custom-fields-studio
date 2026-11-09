<?php
/**
 * Choice types: checkbox, radio, button group.
 *
 * Choices use the same "value : Label" one-per-line format as Select.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared behaviour for the three choice types. */
abstract class CFS_Field_Choice_Base extends CFS_Field_Base {

	/** Whether the stored value is an array of values. */
	protected static $multi = false;

	public static function defaults() {
		return array(
			'choices'       => '',
			'default_value' => '',
			'layout'        => 'vertical',
			'return_format' => 'value',
			'allow_null'    => 0,
		);
	}

	public static function render_settings( $field, $prefix ) {
		$type = static::$type;
		?>
		<div class="cfs-field-setting" data-type="<?php echo esc_attr( $type ); ?>">
			<label><?php esc_html_e( 'Choices', 'custom-fields-studio' ); ?></label>
			<textarea name="<?php echo esc_attr( $prefix ); ?>[choices]" rows="4" placeholder="red : Red&#10;blue : Blue"><?php echo esc_textarea( $field['choices'] ?? '' ); ?></textarea>
			<p class="description"><?php esc_html_e( 'One choice per line. Format: value : Label', 'custom-fields-studio' ); ?></p>
		</div>
		<?php
		self::setting_input(
			$type,
			$prefix,
			'default_value',
			static::$multi ? __( 'Default Values', 'custom-fields-studio' ) : __( 'Default Value', 'custom-fields-studio' ),
			$field,
			'text',
			static::$multi ? __( 'Comma-separated values.', 'custom-fields-studio' ) : ''
		);
		self::setting_select(
			$type,
			$prefix,
			'layout',
			__( 'Layout', 'custom-fields-studio' ),
			$field,
			array(
				'vertical'   => __( 'Vertical', 'custom-fields-studio' ),
				'horizontal' => __( 'Horizontal', 'custom-fields-studio' ),
			),
			'vertical'
		);
		self::setting_select(
			$type,
			$prefix,
			'return_format',
			__( 'Return Format', 'custom-fields-studio' ),
			$field,
			array(
				'value' => __( 'Value', 'custom-fields-studio' ),
				'label' => __( 'Label', 'custom-fields-studio' ),
				'array' => __( 'Both (Array)', 'custom-fields-studio' ),
			),
			'value'
		);
		if ( ! static::$multi ) {
			self::setting_checkbox( $type, $prefix, 'allow_null', __( 'Allow Null (lets the choice be cleared)', 'custom-fields-studio' ), $field );
		}
	}

	public static function sanitize( $raw ) {
		return array(
			'choices'       => isset( $raw['choices'] ) ? str_replace( array( "\r\n", "\r" ), "\n", sanitize_textarea_field( wp_unslash( $raw['choices'] ) ) ) : '',
			'default_value' => self::text_setting( $raw, 'default_value' ),
			'layout'        => self::pick( $raw, 'layout', array( 'vertical', 'horizontal' ), 'vertical' ),
			'return_format' => self::pick( $raw, 'return_format', array( 'value', 'label', 'array' ), 'value' ),
			'allow_null'    => ( ! static::$multi && ! empty( $raw['allow_null'] ) ) ? 1 : 0,
		);
	}

	/** Currently selected values as strings. */
	protected static function selected( $field, $value ) {
		if ( null === $value ) {
			$value = $field['default_value'] ?? '';
			if ( static::$multi ) {
				$value = '' === $value ? array() : array_map( 'trim', explode( ',', $value ) );
			}
		}

		return array_map( 'strval', (array) $value );
	}

	public static function sanitize_value( $raw_value, $field ) {
		$choices = CFS_Field_Select::parse_choices( $field['choices'] ?? '' );

		if ( static::$multi ) {
			$clean = array();
			foreach ( is_array( $raw_value ) ? $raw_value : array() as $one ) {
				$one = is_scalar( $one ) ? sanitize_text_field( $one ) : '';
				if ( array_key_exists( $one, $choices ) && ! in_array( $one, $clean, true ) ) {
					$clean[] = $one;
				}
			}

			return $clean;
		}

		$raw_value = is_scalar( $raw_value ) ? sanitize_text_field( $raw_value ) : '';

		return array_key_exists( $raw_value, $choices ) ? $raw_value : '';
	}

	public static function format_value( $value, $field ) {
		$choices = CFS_Field_Select::parse_choices( $field['choices'] ?? '' );
		$format  = $field['return_format'] ?? 'value';
		$one     = function ( $v ) use ( $choices, $format ) {
			$label = $choices[ $v ] ?? $v;
			if ( 'label' === $format ) {
				return $label;
			}

			return 'array' === $format ? array( 'value' => $v, 'label' => $label ) : $v;
		};

		if ( static::$multi ) {
			return array_map( $one, array_values( array_filter( (array) $value, 'is_scalar' ) ) );
		}

		return ( '' === $value || null === $value || is_array( $value ) ) ? null : $one( $value );
	}
}

class CFS_Field_Checkbox extends CFS_Field_Choice_Base {

	public static $type  = 'checkbox';
	public static $label = 'Checkbox';

	protected static $multi = true;

	public static function render_input( $field, $value, $name ) {
		$selected = self::selected( $field, $value );
		// Hidden empty value so un-ticking every box still saves "nothing".
		printf( '<input type="hidden" name="%s[]" value="" />', esc_attr( $name ) );
		echo '<ul class="cfs-choice-list cfs-layout-' . esc_attr( $field['layout'] ?? 'vertical' ) . '">';
		foreach ( CFS_Field_Select::parse_choices( $field['choices'] ?? '' ) as $v => $label ) {
			printf(
				'<li><label><input type="checkbox" name="%1$s[]" value="%2$s"%3$s /> %4$s</label></li>',
				esc_attr( $name ),
				esc_attr( $v ),
				checked( in_array( (string) $v, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</ul>';
	}
}

class CFS_Field_Radio extends CFS_Field_Choice_Base {

	public static $type  = 'radio';
	public static $label = 'Radio Button';

	public static function render_input( $field, $value, $name ) {
		$selected = self::selected( $field, $value );
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $name ) );
		echo '<ul class="cfs-choice-list cfs-layout-' . esc_attr( $field['layout'] ?? 'vertical' ) . '">';
		foreach ( CFS_Field_Select::parse_choices( $field['choices'] ?? '' ) as $v => $label ) {
			printf(
				'<li><label><input type="radio" name="%1$s" value="%2$s"%3$s /> %4$s</label></li>',
				esc_attr( $name ),
				esc_attr( $v ),
				checked( in_array( (string) $v, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
		if ( ! empty( $field['allow_null'] ) ) {
			echo '<li><a href="#" class="cfs-choice-clear">' . esc_html__( 'Clear', 'custom-fields-studio' ) . '</a></li>';
		}
		echo '</ul>';
	}
}

class CFS_Field_Button_Group extends CFS_Field_Choice_Base {

	public static $type  = 'button_group';
	public static $label = 'Button Group';

	public static function render_input( $field, $value, $name ) {
		$selected = self::selected( $field, $value );
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $name ) );
		echo '<div class="cfs-button-group cfs-layout-' . esc_attr( $field['layout'] ?? 'horizontal' ) . '">';
		foreach ( CFS_Field_Select::parse_choices( $field['choices'] ?? '' ) as $v => $label ) {
			printf(
				'<label class="button%1$s"><input type="radio" name="%2$s" value="%3$s"%4$s /> %5$s</label>',
				in_array( (string) $v, $selected, true ) ? ' cfs-active' : '',
				esc_attr( $name ),
				esc_attr( $v ),
				checked( in_array( (string) $v, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</div>';
	}
}
