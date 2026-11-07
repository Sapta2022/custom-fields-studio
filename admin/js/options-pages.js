/**
 * Custom Fields Studio — Options Pages manager: add / remove page rows.
 */
/* global jQuery */
( function ( $ ) {
	'use strict';

	$( function () {
		var $rows = $( '#cfs-options-pages-rows' );
		var next = $rows.children( '.cfs-options-page-row' ).length;

		$( '#cfs-add-options-page' ).on( 'click', function () {
			var html = $( '#tmpl-cfs-options-page-row' ).html().replace( /__INDEX__/g, 'n' + next );
			next++;
			$rows.append( html );
		} );

		$rows.on( 'click', '.cfs-remove-options-page', function () {
			$( this ).closest( '.cfs-options-page-row' ).remove();
		} );
	} );
} )( jQuery );
