<?php
/**
 * Layout types: message, tab, accordion (display only), and group (a
 * named set of sub fields).
 *
 * @package CustomFieldsStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Base for the display-only types: nothing is stored. */
abstract class CFS_Field_Layout_Base extends CFS_Field_Base {

	public static function stores_value() {
		return false;
	}

	public static function load_value( $field, $ctx, $key = null ) {
		return '';
	}

	public static function save_value( $field, $raw_posted, $ctx, $key = null ) {
		// Nothing to store.
	}

	public static function delete_value( $field, $ctx, $key = null ) {
		// Nothing was stored.
	}

	public static function format_value( $value, $field ) {
		return null;
	}
}

/** A block of explanatory text between fields. */
class CFS_Field_Message extends CFS_Field_Layout_Base {

	public static $type  = 'message';
	public static $label = 'Message';

	public static function defaults() {
		return array(
			'message'   => '',
			'esc_html'  => 0,
			'new_lines' => 'wpautop',
		);
	}

	public static function render_settings( $field, $prefix ) {
		?>
		<div class="cfs-field-setting" data-type="message">
			<label><?php esc_html_e( 'Message', 'custom-fields-studio' ); ?></label>
			<textarea name="<?php echo esc_attr( $prefix ); ?>[message]" rows="4"><?php echo esc_textarea( $field['message'] ?? '' ); ?></textarea>
		</div>
		<?php
		self::setting_select(
			'message',
			$prefix,
			'new_lines',
			__( 'New Lines', 'custom-fields-studio' ),
			$field,
			array(
				'wpautop' => __( 'Automatically add paragraphs', 'custom-fields-studio' ),
				'br'      => __( 'Automatically add <br>', 'custom-fields-studio' ),
				''        => __( 'No formatting', 'custom-fields-studio' ),
			),
			'wpautop'
		);
		self::setting_checkbox( 'message', $prefix, 'esc_html', __( 'Show HTML as text (escape it)', 'custom-fields-studio' ), $field );
	}

	public static function sanitize( $raw ) {
		return array(
			'message'   => isset( $raw['message'] ) && is_scalar( $raw['message'] ) ? str_replace( array( "\r\n", "\r" ), "\n", wp_kses_post( wp_unslash( $raw['message'] ) ) ) : '',
			'esc_html'  => ! empty( $raw['esc_html'] ) ? 1 : 0,
			'new_lines' => self::pick( $raw, 'new_lines', array( 'wpautop', 'br', '' ), 'wpautop' ),
		);
	}

	public static function render_input( $field, $value, $name ) {
		$text = (string) ( $field['message'] ?? '' );
		if ( ! empty( $field['esc_html'] ) ) {
			$text = esc_html( $text );
		}
		$mode = $field['new_lines'] ?? 'wpautop';
		if ( 'wpautop' === $mode ) {
			$text = wpautop( $text );
		} elseif ( 'br' === $mode ) {
			$text = nl2br( $text );
		}
		echo '<div class="cfs-message">' . wp_kses_post( $text ) . '</div>';
	}
}

/** Starts a new tab; the fields that follow belong to it. Top level only. */
class CFS_Field_Tab extends CFS_Field_Layout_Base {

	public static $type  = 'tab';
	public static $label = 'Tab';

	public static function has_wrapper() {
		return false;
	}

	public static function render_settings( $field, $prefix ) {
		?>
		<div class="cfs-field-setting" data-type="tab">
			<p class="description"><?php esc_html_e( 'The fields that follow this one are shown in this tab, until the next tab. Tabs work at the top level of a field group.', 'custom-fields-studio' ); ?></p>
		</div>
		<?php
	}

	public static function render_input( $field, $value, $name ) {
		echo '<div class="cfs-tab-marker" data-label="' . esc_attr( $field['label'] ?? '' ) . '"></div>';
	}
}

/** A collapsible section; the fields that follow are inside it. */
class CFS_Field_Accordion extends CFS_Field_Layout_Base {

	public static $type  = 'accordion';
	public static $label = 'Accordion';

	public static function has_wrapper() {
		return false;
	}

	public static function defaults() {
		return array( 'open' => 0 );
	}

	public static function render_settings( $field, $prefix ) {
		self::setting_checkbox( 'accordion', $prefix, 'open', __( 'Open by default', 'custom-fields-studio' ), $field );
		?>
		<div class="cfs-field-setting" data-type="accordion">
			<p class="description"><?php esc_html_e( 'The fields that follow this one go inside it, until the next accordion or tab. Works at the top level of a field group.', 'custom-fields-studio' ); ?></p>
		</div>
		<?php
	}

	public static function sanitize( $raw ) {
		return array( 'open' => ! empty( $raw['open'] ) ? 1 : 0 );
	}

	public static function render_input( $field, $value, $name ) {
		printf(
			'<div class="cfs-accordion-marker" data-label="%1$s" data-open="%2$d"></div>',
			esc_attr( $field['label'] ?? '' ),
			empty( $field['open'] ) ? 0 : 1
		);
	}
}

/**
 * Group: sub fields stored one level down, e.g.
 *   hero_title / hero_image (+ _hero_title references), and `hero` = ''.
 */
class CFS_Field_Group_Field extends CFS_Field_Base {

	public static $type  = 'group';
	public static $label = 'Group';

	public static function defaults() {
		return array(
			'layout'     => 'block',
			'sub_fields' => array(),
		);
	}

	public static function render_settings( $field, $prefix ) {
		self::setting_select(
			'group',
			$prefix,
			'layout',
			__( 'Layout', 'custom-fields-studio' ),
			$field,
			array(
				'block' => __( 'Block (sub fields stacked)', 'custom-fields-studio' ),
				'row'   => __( 'Row (sub fields side by side)', 'custom-fields-studio' ),
			),
			'block'
		);
		// The Sub Fields editor is shared with Repeater (see its render_settings()).
	}

	public static function sanitize( $raw ) {
		return array(
			'layout'     => self::pick( $raw, 'layout', array( 'block', 'row' ), 'block' ),
			'sub_fields' => CFS_Field_Group::sanitize_fields( $raw['sub_fields'] ?? array() ),
		);
	}

	protected static function sub_key( $key, $sub_field ) {
		return $key . '_' . $sub_field['name'];
	}

	protected static function usable_sub_fields( $field ) {
		$usable = array();
		foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub_field ) {
			if ( empty( $sub_field['name'] ) || empty( $sub_field['type'] ) ) {
				continue;
			}
			$class = CFS_Field_Registry::get_class( $sub_field['type'] );
			if ( $class && $class::stores_value() ) {
				$usable[] = array( 'field' => $sub_field, 'class' => $class );
			}
		}

		return $usable;
	}

	/** null when this group has never been saved (so sub fields show defaults). */
	public static function load_value( $field, $ctx, $key = null ) {
		$key = ( null === $key ) ? $field['name'] : $key;
		if ( ! CFS_Storage::exists( '_' . $key, $ctx ) ) {
			return null;
		}
		$out = array();
		foreach ( self::usable_sub_fields( $field ) as $sub ) {
			$out[ $sub['field']['name'] ] = $sub['class']::load_value( $sub['field'], $ctx, self::sub_key( $key, $sub['field'] ) );
		}

		return $out;
	}

	public static function save_value( $field, $raw_posted, $ctx, $key = null ) {
		$key = ( null === $key ) ? $field['name'] : $key;
		$raw = is_array( $raw_posted ) ? $raw_posted : array();
		foreach ( self::usable_sub_fields( $field ) as $sub ) {
			$sub['class']::save_value( $sub['field'], $raw[ $sub['field']['name'] ] ?? '', $ctx, self::sub_key( $key, $sub['field'] ) );
		}
		CFS_Storage::update( $key, '', $ctx );
		CFS_Storage::update( '_' . $key, $field['key'], $ctx );
	}

	public static function delete_value( $field, $ctx, $key = null ) {
		$key = ( null === $key ) ? $field['name'] : $key;
		foreach ( self::usable_sub_fields( $field ) as $sub ) {
			$sub['class']::delete_value( $sub['field'], $ctx, self::sub_key( $key, $sub['field'] ) );
		}
		CFS_Storage::delete( $key, $ctx );
		CFS_Storage::delete( '_' . $key, $ctx );
	}

	public static function format_value( $value, $field ) {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$out = array();
		foreach ( self::usable_sub_fields( $field ) as $sub ) {
			$name         = $sub['field']['name'];
			$out[ $name ] = $sub['class']::format_value( array_key_exists( $name, $value ) ? $value[ $name ] : '', $sub['field'] );
		}

		return $out;
	}

	public static function render_input( $field, $value, $name ) {
		$subs = self::usable_sub_fields( $field );
		if ( ! $subs && empty( $field['sub_fields'] ) ) {
			echo '<p class="description">' . esc_html__( 'This group has no sub fields yet. Add some in the field group editor.', 'custom-fields-studio' ) . '</p>';
			return;
		}
		$value = is_array( $value ) ? $value : array();
		echo '<div class="cfs-group-fields cfs-group-' . esc_attr( 'row' === ( $field['layout'] ?? 'block' ) ? 'row' : 'block' ) . '">';
		foreach ( (array) $field['sub_fields'] as $sub_field ) {
			if ( empty( $sub_field['name'] ) || empty( $sub_field['type'] ) ) {
				continue;
			}
			if ( ! empty( $field['_lazy'] ) ) {
				$sub_field['_lazy'] = true;
			}
			// Never saved before: null lets each sub field apply its default.
			$sub_value = array_key_exists( $sub_field['name'], $value ) ? $value[ $sub_field['name'] ] : null;
			CFS_Meta_Box::render_field( $sub_field, $sub_value, $name . '[' . $sub_field['name'] . ']' );
		}
		echo '</div>';
	}
}
