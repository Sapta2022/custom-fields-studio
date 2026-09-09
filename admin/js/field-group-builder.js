/**
 * Custom Fields Studio — field group builder.
 *
 * Handles: adding/removing field rows, drag-reordering fields, toggling
 * a row's type-specific settings panel, showing only the settings block
 * matching the row's selected type, and the location-rules AND/OR builder.
 */
/* global jQuery, cfsBuilder */
( function ( $ ) {
	'use strict';

	var fieldIndex = $( '#cfs-fields-rows .cfs-field-row' ).length;
	var ruleGroupIndex = $( '#cfs-location-rules .cfs-location-group' ).length;

	/**
	 * Show only the settings block(s) matching a row's current type.
	 *
	 * @param {jQuery} $row The .cfs-field-row row.
	 */
	function syncFieldTypeVisibility( $row ) {
		var type = $row.find( '.cfs-field-type-select' ).val();

		$row.find( '.cfs-field-setting' ).each( function () {
			var $setting = $( this );
			$setting.toggle( $setting.data( 'type' ) === type );
		} );
	}

	/**
	 * Re-index all field rows' name="cfs_fields[N][...]" attributes and
	 * data-index after add/remove/reorder, so PHP receives a clean
	 * sequential array.
	 */
	function reindexFields() {
		$( '#cfs-fields-rows .cfs-field-row' ).each( function ( i ) {
			var $row = $( this );
			$row.attr( 'data-index', i );
			$row.find( '[name*="cfs_fields["]' ).each( function () {
				this.name = this.name.replace( /cfs_fields\[[^\]]*\]/, 'cfs_fields[' + i + ']' );
			} );
		} );
	}

	/**
	 * Build a new field row from the hidden template and append it.
	 */
	function addField() {
		var template = $( '#tmpl-cfs-field-row' ).html().replace( /__INDEX__/g, fieldIndex );
		var $tmp = $( '<table><tbody>' + template + '</tbody></table>' );
		var $row = $tmp.find( 'tr.cfs-field-row' );

		$( '#cfs-fields-rows' ).append( $row );
		fieldIndex++;
		reindexFields();
		syncFieldTypeVisibility( $row );
	}

	/**
	 * Re-index location rule groups/rules after add/remove.
	 */
	function reindexLocationRules() {
		$( '#cfs-location-rules .cfs-location-group' ).each( function ( groupIndex ) {
			$( this )
				.attr( 'data-group', groupIndex )
				.find( '.cfs-location-rule' )
				.each( function ( ruleIndex ) {
					$( this )
						.find( '[name*="cfs_location["]' )
						.each( function () {
							this.name = this.name.replace(
								/cfs_location\[[^\]]*\]\[[^\]]*\]/,
								'cfs_location[' + groupIndex + '][' + ruleIndex + ']'
							);
						} );
				} );
		} );
	}

	$( function () {
		var $fieldsWrap = $( '#cfs-fields-rows' );
		var $locationWrap = $( '#cfs-location-rules' );

		// Initial visibility sync for rows already on the page.
		$fieldsWrap.find( '.cfs-field-row' ).each( function () {
			syncFieldTypeVisibility( $( this ) );
		} );

		$( '#cfs-add-field' ).on( 'click', function () {
			addField();
		} );

		$fieldsWrap.on( 'change', '.cfs-field-type-select', function () {
			syncFieldTypeVisibility( $( this ).closest( '.cfs-field-row' ) );
		} );

		$fieldsWrap.on( 'click', '.cfs-toggle-field-settings', function () {
			$( this ).closest( '.cfs-field-row' ).find( '.cfs-field-settings-panel' ).slideToggle( 120 );
		} );

		$fieldsWrap.on( 'click', '.cfs-remove-field', function () {
			var $row = $( this ).closest( '.cfs-field-row' );

			if ( $fieldsWrap.find( '.cfs-field-row' ).length <= 1 ) {
				// Always keep at least one row so the builder never looks broken/empty.
				return;
			}

			// eslint-disable-next-line no-alert
			if ( ! window.confirm( cfsBuilder.i18n.confirmRemove ) ) {
				return;
			}

			$row.remove();
			reindexFields();
		} );

		// Drag-reorder via jQuery UI Sortable — each field is now a single
		// <tr>, so no pairing logic is needed.
		$fieldsWrap.sortable( {
			handle: '.cfs-drag-handle',
			axis: 'y',
			items: '.cfs-field-row',
			stop: function () {
				reindexFields();
			},
		} );

		// --- Location rules builder ---

		$locationWrap.on( 'click', '.cfs-add-rule', function () {
			var $group = $( this ).closest( '.cfs-location-group' );
			var groupIndex = $group.data( 'group' );
			var ruleIndex = $group.find( '.cfs-location-rule' ).length;
			var template = $( '#tmpl-cfs-location-rule-row' )
				.html()
				.replace( /__GROUP__/g, groupIndex )
				.replace( /__RULE__/g, ruleIndex );

			$group.find( 'tbody' ).append( template );
		} );

		$locationWrap.on( 'click', '.cfs-remove-rule', function () {
			var $group = $( this ).closest( '.cfs-location-group' );

			if ( $group.find( '.cfs-location-rule' ).length <= 1 ) {
				// A group needs at least one rule, or remove the whole group instead.
				if ( $locationWrap.find( '.cfs-location-group' ).length > 1 ) {
					$group.prev( '.cfs-location-or' ).remove();
					$group.remove();
					reindexLocationRules();
				}
				return;
			}

			$( this ).closest( '.cfs-location-rule' ).remove();
			reindexLocationRules();
		} );

		$locationWrap.parent().on( 'click', '.cfs-add-rule-group', function () {
			var template = $( '#tmpl-cfs-location-rule-row' )
				.html()
				.replace( /__GROUP__/g, ruleGroupIndex )
				.replace( /__RULE__/g, 0 );

			var $newGroup = $(
				'<div class="cfs-location-or">OR</div>' +
					'<div class="cfs-location-group" data-group="' +
					ruleGroupIndex +
					'">' +
					'<table class="cfs-location-rules-table"><tbody>' +
					template +
					'</tbody></table>' +
					'<button type="button" class="button cfs-add-rule">+ Add Rule (AND)</button>' +
					'</div>'
			);

			$( this ).before( $newGroup );
			ruleGroupIndex++;
		} );
	} );
} )( jQuery );
