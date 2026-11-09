<?php
/**
 * Simple single-value input types: number, range, email, url, password.
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Number input with min / max / step and optional prepend / append text. */
class CFStudio_Field_Number extends CFStudio_Field_Base {

	public static $type  = 'number';
	public static $label = 'Number';

	public static function defaults() {
		return array(
			'default_value' => '',
			'placeholder'   => '',
			'min'           => '',
			'max'           => '',
			'step'          => '',
			'prepend'       => '',
			'append'        => '',
		);
	}

	public static function render_settings( $field, $prefix ) {
		self::setting_input( 'number', $prefix, 'default_value', __( 'Default Value', 'custom-fields-studio' ), $field, 'number' );
		self::setting_input( 'number', $prefix, 'placeholder', __( 'Placeholder Text', 'custom-fields-studio' ), $field );
		self::setting_input( 'number', $prefix, 'min', __( 'Minimum Value', 'custom-fields-studio' ), $field, 'number' );
		self::setting_input( 'number', $prefix, 'max', __( 'Maximum Value', 'custom-fields-studio' ), $field, 'number' );
		self::setting_input( 'number', $prefix, 'step', __( 'Step Size', 'custom-fields-studio' ), $field, 'number', __( 'Leave blank for 1 (use e.g. 0.01 for decimals).', 'custom-fields-studio' ) );
		self::setting_input( 'number', $prefix, 'prepend', __( 'Prepend', 'custom-fields-studio' ), $field );
		self::setting_input( 'number', $prefix, 'append', __( 'Append', 'custom-fields-studio' ), $field );
	}

	public static function sanitize( $raw ) {
		$step = self::number_setting( $raw, 'step' );

		return array(
			'default_value' => self::number_setting( $raw, 'default_value' ),
			'placeholder'   => self::text_setting( $raw, 'placeholder' ),
			'min'           => self::number_setting( $raw, 'min' ),
			'max'           => self::number_setting( $raw, 'max' ),
			'step'          => ( '' !== $step && $step > 0 ) ? $step : '',
			'prepend'       => self::text_setting( $raw, 'prepend' ),
			'append'        => self::text_setting( $raw, 'append' ),
		);
	}

	public static function render_input( $field, $value, $name ) {
		if ( null === $value ) {
			$value = $field['default_value'] ?? '';
		}
		echo '<span class="cfs-number-wrap">';
		if ( ! empty( $field['prepend'] ) ) {
			echo '<span class="cfs-prepend">' . esc_html( $field['prepend'] ) . '</span> ';
		}
		printf(
			'<input type="number" name="%1$s" value="%2$s" placeholder="%3$s" min="%4$s" max="%5$s" step="%6$s" />',
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $field['placeholder'] ?? '' ),
			esc_attr( $field['min'] ?? '' ),
			esc_attr( $field['max'] ?? '' ),
			esc_attr( ( isset( $field['step'] ) && '' !== $field['step'] ) ? $field['step'] : 'any' )
		);
		if ( ! empty( $field['append'] ) ) {
			echo ' <span class="cfs-append">' . esc_html( $field['append'] ) . '</span>';
		}
		echo '</span>';
	}

	public static function sanitize_value( $raw_value, $field ) {
		if ( ! is_scalar( $raw_value ) || ! is_numeric( trim( (string) $raw_value ) ) ) {
			return '';
		}
		$number = 0 + trim( (string) $raw_value );

		if ( isset( $field['min'] ) && '' !== $field['min'] && $number < $field['min'] ) {
			$number = 0 + $field['min'];
		}
		if ( isset( $field['max'] ) && '' !== $field['max'] && $number > $field['max'] ) {
			$number = 0 + $field['max'];
		}

		return $number;
	}

	public static function format_value( $value, $field ) {
		return ( '' === $value || null === $value || ! is_numeric( $value ) ) ? null : 0 + $value;
	}
}

/** Slider with a live value read-out. */
class CFStudio_Field_Range extends CFStudio_Field_Base {

	public static $type  = 'range';
	public static $label = 'Range';

	public static function defaults() {
		return array(
			'default_value' => '',
			'min'           => 0,
			'max'           => 100,
			'step'          => 1,
			'append'        => '',
		);
	}

	public static function render_settings( $field, $prefix ) {
		self::setting_input( 'range', $prefix, 'default_value', __( 'Default Value', 'custom-fields-studio' ), $field, 'number' );
		self::setting_input( 'range', $prefix, 'min', __( 'Minimum Value', 'custom-fields-studio' ), $field, 'number', '', 0 );
		self::setting_input( 'range', $prefix, 'max', __( 'Maximum Value', 'custom-fields-studio' ), $field, 'number', '', 100 );
		self::setting_input( 'range', $prefix, 'step', __( 'Step Size', 'custom-fields-studio' ), $field, 'number', '', 1 );
		self::setting_input( 'range', $prefix, 'append', __( 'Append', 'custom-fields-studio' ), $field );
	}

	public static function sanitize( $raw ) {
		$min  = self::number_setting( $raw, 'min' );
		$max  = self::number_setting( $raw, 'max' );
		$step = self::number_setting( $raw, 'step' );
		$min  = '' === $min ? 0 : $min;
		$max  = '' === $max ? 100 : $max;
		if ( $max <= $min ) {
			$max = $min + 100;
		}

		return array(
			'default_value' => self::number_setting( $raw, 'default_value' ),
			'min'           => $min,
			'max'           => $max,
			'step'          => ( '' !== $step && $step > 0 ) ? $step : 1,
			'append'        => self::text_setting( $raw, 'append' ),
		);
	}

	public static function render_input( $field, $value, $name ) {
		if ( null === $value || '' === $value ) {
			$value = ( isset( $field['default_value'] ) && '' !== $field['default_value'] ) ? $field['default_value'] : ( $field['min'] ?? 0 );
		}
		printf(
			'<input type="range" class="cfs-range" name="%1$s" value="%2$s" min="%3$s" max="%4$s" step="%5$s" /> <output class="cfs-range-out">%2$s</output>%6$s',
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $field['min'] ?? 0 ),
			esc_attr( $field['max'] ?? 100 ),
			esc_attr( $field['step'] ?? 1 ),
			! empty( $field['append'] ) ? ' <span class="cfs-append">' . esc_html( $field['append'] ) . '</span>' : ''
		);
	}

	public static function sanitize_value( $raw_value, $field ) {
		return CFStudio_Field_Number::sanitize_value(
			$raw_value,
			array(
				'min' => $field['min'] ?? 0,
				'max' => $field['max'] ?? 100,
			)
		);
	}

	public static function format_value( $value, $field ) {
		return CFStudio_Field_Number::format_value( $value, $field );
	}
}

/** Email address. */
class CFStudio_Field_Email extends CFStudio_Field_Base {

	public static $type  = 'email';
	public static $label = 'Email';

	public static function defaults() {
		return array(
			'default_value' => '',
			'placeholder'   => '',
		);
	}

	public static function render_settings( $field, $prefix ) {
		self::setting_input( 'email', $prefix, 'default_value', __( 'Default Value', 'custom-fields-studio' ), $field );
		self::setting_input( 'email', $prefix, 'placeholder', __( 'Placeholder Text', 'custom-fields-studio' ), $field );
	}

	public static function sanitize( $raw ) {
		return array(
			'default_value' => sanitize_email( self::text_setting( $raw, 'default_value' ) ),
			'placeholder'   => self::text_setting( $raw, 'placeholder' ),
		);
	}

	public static function render_input( $field, $value, $name ) {
		if ( null === $value ) {
			$value = $field['default_value'] ?? '';
		}
		printf(
			'<input type="email" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" />',
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $field['placeholder'] ?? '' )
		);
	}

	public static function sanitize_value( $raw_value, $field ) {
		if ( ! is_scalar( $raw_value ) ) {
			return '';
		}
		$email = sanitize_email( trim( (string) $raw_value ) );

		return is_email( $email ) ? $email : '';
	}
}

/** URL (http, https, mailto, tel and similar safe protocols). */
class CFStudio_Field_Url extends CFStudio_Field_Base {

	public static $type  = 'url';
	public static $label = 'URL';

	public static function defaults() {
		return array(
			'default_value' => '',
			'placeholder'   => '',
		);
	}

	public static function render_settings( $field, $prefix ) {
		self::setting_input( 'url', $prefix, 'default_value', __( 'Default Value', 'custom-fields-studio' ), $field );
		self::setting_input( 'url', $prefix, 'placeholder', __( 'Placeholder Text', 'custom-fields-studio' ), $field );
	}

	public static function sanitize( $raw ) {
		return array(
			'default_value' => esc_url_raw( self::text_setting( $raw, 'default_value' ) ),
			'placeholder'   => self::text_setting( $raw, 'placeholder' ),
		);
	}

	public static function render_input( $field, $value, $name ) {
		if ( null === $value ) {
			$value = $field['default_value'] ?? '';
		}
		printf(
			'<input type="url" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" />',
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $field['placeholder'] ?? '' )
		);
	}

	public static function sanitize_value( $raw_value, $field ) {
		if ( ! is_scalar( $raw_value ) ) {
			return '';
		}

		return esc_url_raw( trim( (string) $raw_value ) );
	}
}

/** Password. The value is stored as typed (trimmed of tags only). */
class CFStudio_Field_Password extends CFStudio_Field_Base {

	public static $type  = 'password';
	public static $label = 'Password';

	public static function defaults() {
		return array( 'placeholder' => '' );
	}

	public static function render_settings( $field, $prefix ) {
		self::setting_input( 'password', $prefix, 'placeholder', __( 'Placeholder Text', 'custom-fields-studio' ), $field );
	}

	public static function sanitize( $raw ) {
		return array( 'placeholder' => self::text_setting( $raw, 'placeholder' ) );
	}

	public static function render_input( $field, $value, $name ) {
		printf(
			'<input type="password" class="regular-text" autocomplete="new-password" name="%1$s" value="%2$s" placeholder="%3$s" />',
			esc_attr( $name ),
			esc_attr( (string) $value ),
			esc_attr( $field['placeholder'] ?? '' )
		);
	}

	public static function sanitize_value( $raw_value, $field ) {
		return is_scalar( $raw_value ) ? trim( wp_strip_all_tags( (string) $raw_value ) ) : '';
	}
}
