/**
 * Custom Fields Studio — field group builder.
 *
 * Handles: adding/removing/reordering field rows (top level AND a
 * repeater's nested sub-field rows), showing only the settings block
 * matching a row's selected type, and the location-rules AND/OR builder.
 *
 * Rows are never renamed after they're created. A new row gets a unique
 * key in its input names ("cfs_fields[n3][label]"), and PHP only looks at
 * the order the rows arrive in, which is DOM order — so drag-reordering
 * needs no re-indexing, and nesting can't get its indices out of step.
 */
/* global jQuery, cfsBuilder */
( function ( $ ) {
	'use strict';

	var ruleGroupIndex = $( '#cfs-location-rules .cfs-location-group' ).length;
	var rowKeyCounter = 0;

	function nextRowKey() {
		rowKeyCounter++;
		return 'n' + rowKeyCounter;
	}

	/** A random unique field key, same shape as PHP's CFS_Field_Group::new_key(). */
	function newFieldKey() {
		var bytes = new Uint8Array( 6 );
		window.crypto.getRandomValues( bytes );

		return (
			'field_' +
			Array.prototype.map
				.call( bytes, function ( b ) {
					return ( '0' + b.toString( 16 ) ).slice( -2 );
				} )
				.join( '' )
		);
	}

	/**
	 * The settings panel belonging to exactly this row (not to a nested
	 * sub-field row inside it).
	 */
	function panelOf( $row ) {
		return $row.children( 'td' ).children( '.cfs-field-settings-panel' );
	}

	/**
	 * Show only the settings block(s) matching a row's current type.
	 * Scoped to the row's own panel's direct children, so a repeater's
	 * nested rows keep their own state.
	 *
	 * @param {jQuery} $row The .cfs-field-row row.
	 */
	function syncFieldTypeVisibility( $row ) {
		var type = $row.children( 'td' ).children( '.cfs-field-row-main' ).find( '.cfs-field-type-select' ).val();

		panelOf( $row )
			.children( '.cfs-field-setting' )
			.each( function () {
				var $setting = $( this );
				$setting.toggle( $setting.data( 'type' ) === type );
			} );
	}

	/**
	 * Make every row list sortable (top level and nested). Safe to call
	 * repeatedly: lists that already are sortable are skipped.
	 */
	function initSortables() {
		$( '.cfs-fields-editor-top tbody.cfs-rows' )
			.not( '.ui-sortable' )
			.sortable( {
				items: '> tr.cfs-field-row',
				// Direct handle only, so dragging a nested row never also
				// drags the repeater row that contains it.
				handle: '> td.cfs-col-handle > .cfs-drag-handle',
				axis: 'y',
				tolerance: 'pointer',
			} );
	}

	/**
	 * Clone the shared row template into a row list.
	 *
	 * @param {jQuery} $tbody The tbody.cfs-rows to add to.
	 */
	function addField( $tbody ) {
		var prefix = $tbody.attr( 'data-base' ) + '[' + nextRowKey() + ']';
		var html = $( '#tmpl-cfs-field-row' ).html().replace( /__PREFIX__/g, prefix );
		var $row = $( '<table><tbody>' + html + '</tbody></table>' ).find( 'tbody' ).first().children( 'tr.cfs-field-row' );

		// The template is rendered once by PHP, so every clone would carry
		// the same field key. Give each new row its own.
		$row.find( '.cfs-field-key' ).first().val( newFieldKey() );

		$tbody.append( $row );
		syncFieldTypeVisibility( $row );
		initSortables();
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
		var $editor = $( '.cfs-fields-editor-top' );
		var $locationWrap = $( '#cfs-location-rules' );

		// Initial visibility sync for rows already on the page, nested ones too.
		$editor.find( '.cfs-field-row' ).each( function () {
			syncFieldTypeVisibility( $( this ) );
		} );

		initSortables();

		// One delegated set of handlers covers top-level rows and every
		// nested sub-field list, including ones added later.
		$editor.on( 'click', '.cfs-add-field', function () {
			addField( $( this ).closest( '.cfs-fields-editor' ).children( 'table' ).children( 'tbody.cfs-rows' ) );
		} );

		$editor.on( 'change', '.cfs-field-type-select', function () {
			syncFieldTypeVisibility( $( this ).closest( '.cfs-field-row' ) );
		} );

		$editor.on( 'click', '.cfs-toggle-field-settings', function () {
			panelOf( $( this ).closest( '.cfs-field-row' ) ).slideToggle( 120 );
		} );

		// Flexible Content layouts: clone the shared layout template, with
		// the same never-renamed unique-key scheme as field rows.
		$editor.on( 'click', '.cfs-add-layout', function () {
			var $wrap = $( this ).closest( '.cfs-layouts' );
			var prefix = $wrap.attr( 'data-base' ) + '[' + nextRowKey() + ']';
			var html = $( '#tmpl-cfs-layout' ).html().replace( /__PREFIX__/g, prefix );

			$wrap.children( '.cfs-layouts-list' ).append( html );
			initSortables();
		} );

		$editor.on( 'click', '.cfs-remove-layout', function () {
			// eslint-disable-next-line no-alert
			if ( window.confirm( cfsBuilder.i18n.confirmRemove ) ) {
				$( this ).closest( '.cfs-layout-block' ).remove();
			}
		} );

		$editor.on( 'click', '.cfs-remove-field', function () {
			var $row = $( this ).closest( '.cfs-field-row' );

			// The top-level list always keeps one row so the builder never
			// looks empty; a repeater's sub-field list may be emptied.
			if ( $row.parent().is( '#cfs-fields-rows' ) && $row.siblings( '.cfs-field-row' ).length < 1 ) {
				return;
			}

			// eslint-disable-next-line no-alert
			if ( ! window.confirm( cfsBuilder.i18n.confirmRemove ) ) {
				return;
			}

			$row.remove();
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
