/**
 * Fieldloom — post edit screen meta box behavior.
 *
 *  - Image field: wp.media() picker.
 *  - Repeater field: add / remove / reorder rows, nested repeaters,
 *    min/max limits, and WYSIWYG sub fields inside rows.
 *
 * Repeater rows are cloned from a <template> that PHP rendered with a
 * placeholder token (data-token, e.g. "__ROW0__") standing where the row
 * index goes in every input name. Cloning swaps the token for a unique
 * throwaway key. The keys never need to be sequential: PHP receives the
 * rows in DOM order and re-indexes them on save, so dragging a row needs
 * no renaming at all.
 */
/* global jQuery, wp, cfstudioMetaBox */
( function ( $ ) {
	'use strict';

	var rowKeyCounter = 0;
	var editorCounter = 0;

	/*
	 * Delegated handlers are registered through onDoc() so they can be
	 * replayed on another document. The block editor draws blocks inside
	 * an iframe, and events inside it never reach this page's document.
	 */
	var delegated = [];
	function onDoc() {
		var args = Array.prototype.slice.call( arguments );
		delegated.push( args );
		$( document ).on.apply( $( document ), args );
	}
	window.cfstudioBindDocument = function ( doc ) {
		if ( ! doc || doc === document || doc.__cfstudioBound ) {
			return;
		}
		doc.__cfstudioBound = true;
		delegated.forEach( function ( args ) {
			$( doc ).on.apply( $( doc ), args );
		} );
	};

	function nextRowKey() {
		rowKeyCounter++;
		return 'n' + rowKeyCounter;
	}

	/* ------------------------------------------------------------------
	 * WYSIWYG sub fields (printed as plain <textarea.cfs-wysiwyg-lazy>)
	 * ---------------------------------------------------------------- */

	function initEditors( $scope ) {
		if ( typeof wp === 'undefined' || ! wp.editor || ! wp.editor.initialize ) {
			return;
		}

		$scope.find( 'textarea.cfs-wysiwyg-lazy' ).each( function () {
			var $textarea = $( this );

			if ( $textarea.data( 'cfstudioEditor' ) ) {
				return;
			}

			if ( ! this.id ) {
				editorCounter++;
				this.id = 'cfs-wysiwyg-' + editorCounter;
			}

			$textarea.data( 'cfstudioEditor', true );

			wp.editor.initialize( this.id, {
				tinymce:
					'basic' === $textarea.attr( 'data-toolbar' )
						? { toolbar1: 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo', toolbar2: '' }
						: true,
				quicktags: true,
				mediaButtons: '1' === $textarea.attr( 'data-media' ),
			} );
		} );
	}

	/**
	 * Tear editors down before their DOM is moved or removed (moving a
	 * TinyMCE iframe in the DOM reloads it and loses its content). The
	 * textarea keeps the content; initEditors() rebuilds the editor.
	 */
	function destroyEditors( $scope ) {
		if ( typeof wp === 'undefined' || ! wp.editor || ! wp.editor.remove ) {
			return;
		}

		$scope.find( 'textarea.cfs-wysiwyg-lazy' ).each( function () {
			var $textarea = $( this );

			if ( ! $textarea.data( 'cfstudioEditor' ) ) {
				return;
			}

			if ( window.tinymce && window.tinymce.get( this.id ) ) {
				window.tinymce.get( this.id ).save();
			}

			wp.editor.remove( this.id );
			$textarea.removeData( 'cfstudioEditor' );
		} );
	}

	/* ------------------------------------------------------------------
	 * Repeater
	 * ---------------------------------------------------------------- */

	function limits( $repeater ) {
		return {
			min: parseInt( $repeater.attr( 'data-min' ), 10 ) || 0,
			max: parseInt( $repeater.attr( 'data-max' ), 10 ) || 0,
		};
	}

	function rowsOf( $repeater ) {
		return $repeater.children( '.cfs-repeater-rows' ).children( '.cfs-repeater-row' );
	}

	/** Disable "add" at the maximum and "remove" at the minimum. */
	function refreshRepeater( $repeater ) {
		var l = limits( $repeater );
		var count = rowsOf( $repeater ).length;

		$repeater
			.children( '.cfs-repeater-footer' )
			.find( '.cfs-repeater-add' )
			.prop( 'disabled', l.max > 0 && count >= l.max );

		rowsOf( $repeater )
			.children( '.cfs-repeater-row-actions' )
			.find( '.cfs-repeater-remove' )
			.prop( 'disabled', count <= l.min );
	}

	/* ------------------------------------------------------------------
	 * Flexible Content (same row model as the repeater, but each row is
	 * cloned from the <template> of the layout the editor picked)
	 * ---------------------------------------------------------------- */

	function flexRowsOf( $flex ) {
		return $flex.children( '.cfs-flex-rows' ).children( '.cfs-flex-row' );
	}

	function refreshFlex( $flex ) {
		var l = limits( $flex );
		var count = flexRowsOf( $flex ).length;

		$flex
			.children( '.cfs-flex-footer' )
			.find( '.cfs-flex-add' )
			.prop( 'disabled', l.max > 0 && count >= l.max );

		flexRowsOf( $flex )
			.children( '.cfs-flex-row-actions' )
			.find( '.cfs-flex-remove' )
			.prop( 'disabled', count <= l.min );
	}

	function addFlexRow( $flex, layout ) {
		var l = limits( $flex );

		if ( l.max > 0 && flexRowsOf( $flex ).length >= l.max ) {
			return;
		}

		var template = $flex
			.children( 'template.cfs-flex-template' )
			.filter( function () {
				return this.getAttribute( 'data-layout' ) === layout;
			} )[ 0 ];

		if ( ! template ) {
			return;
		}

		var html = template.innerHTML.split( $flex.attr( 'data-token' ) ).join( nextRowKey() );
		var $row = $( $.parseHTML( html ) ).filter( '.cfs-flex-row' );

		$flex.children( '.cfs-flex-rows' ).append( $row );

		// A new row can contain nested repeaters / flexible fields / editors.
		initRepeaters( $row );
		refreshFlex( $flex );
	}

	function removeFlexRow( $row ) {
		var $flex = $row.closest( '.cfs-flex' );

		if ( flexRowsOf( $flex ).length <= limits( $flex ).min ) {
			return;
		}

		destroyEditors( $row );
		$row.remove();
		refreshFlex( $flex );
	}

	/** Make rows draggable, start editors, and sync button state. */
	function initRepeaters( $scope ) {
		$scope
			.find( '.cfs-flex-rows' )
			.addBack( '.cfs-flex-rows' )
			.not( '.ui-sortable' )
			.sortable( {
				items: '> .cfs-flex-row',
				handle: '> .cfs-flex-row-handle > .cfs-drag-handle',
				axis: 'y',
				tolerance: 'pointer',
				start: function ( e, ui ) {
					destroyEditors( ui.item );
				},
				stop: function ( e, ui ) {
					initEditors( ui.item );
				},
			} );

		$scope
			.find( '.cfs-flex' )
			.addBack( '.cfs-flex' )
			.each( function () {
				refreshFlex( $( this ) );
			} );

		$scope
			.find( '.cfs-repeater-rows' )
			.addBack( '.cfs-repeater-rows' )
			.not( '.ui-sortable' )
			.sortable( {
				items: '> .cfs-repeater-row',
				handle: '> .cfs-repeater-row-handle > .cfs-drag-handle',
				axis: 'y',
				tolerance: 'pointer',
				start: function ( e, ui ) {
					destroyEditors( ui.item );
				},
				stop: function ( e, ui ) {
					initEditors( ui.item );
				},
			} );

		initEditors( $scope );

		$scope
			.find( '.cfs-gallery-list' )
			.addBack( '.cfs-gallery-list' )
			.not( '.ui-sortable' )
			.sortable( { tolerance: 'pointer', items: '> .cfs-gallery-item' } );

		$scope
			.find( '.cfs-repeater' )
			.addBack( '.cfs-repeater' )
			.each( function () {
				refreshRepeater( $( this ) );
			} );
	}

	function addRow( $repeater ) {
		var l = limits( $repeater );
		var template = $repeater.children( 'template.cfs-repeater-template' )[ 0 ];

		if ( ! template || ( l.max > 0 && rowsOf( $repeater ).length >= l.max ) ) {
			return;
		}

		var html = template.innerHTML.split( $repeater.attr( 'data-token' ) ).join( nextRowKey() );
		var $row = $( $.parseHTML( html ) ).filter( '.cfs-repeater-row' );

		$repeater.children( '.cfs-repeater-rows' ).append( $row );

		// A new row can contain its own (nested) repeaters and editors.
		initRepeaters( $row );
		refreshRepeater( $repeater );
	}

	function removeRow( $row ) {
		var $repeater = $row.closest( '.cfs-repeater' );

		if ( rowsOf( $repeater ).length <= limits( $repeater ).min ) {
			return;
		}

		destroyEditors( $row );
		$row.remove();
		refreshRepeater( $repeater );
	}

	/* ------------------------------------------------------------------
	 * Image field
	 * ---------------------------------------------------------------- */

	function bindImageField() {
		if ( typeof wp === 'undefined' || ! wp.media ) {
			return;
		}

		// Delegated from the document so it also works for fields inside
		// repeater rows that are cloned in after page load.
		onDoc( 'click', '.cfs-field-values .cfs-image-select', function ( e ) {
			e.preventDefault();

			var $wrap = $( this ).closest( '.cfs-image-field' );
			var $input = $wrap.find( '.cfs-image-value' );
			var $preview = $wrap.find( '.cfs-image-preview' );
			var $selectBtn = $wrap.find( '.cfs-image-select' );
			var $removeBtn = $wrap.find( '.cfs-image-remove' );

			var frame = wp.media( {
				title: cfstudioMetaBox.i18n.selectImage,
				library: { type: 'image' },
				multiple: false,
				button: { text: cfstudioMetaBox.i18n.useImage },
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var previewUrl =
					( attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url ) ||
					attachment.url;

				$input.val( attachment.id );
				$preview.empty().append( $( '<img>', { src: previewUrl, alt: '' } ) ).show();
				$selectBtn.hide();
				$removeBtn.show();
			} );

			frame.open();
		} );

		onDoc( 'click', '.cfs-field-values .cfs-image-remove', function ( e ) {
			e.preventDefault();

			var $wrap = $( this ).closest( '.cfs-image-field' );

			$wrap.find( '.cfs-image-value' ).val( '' );
			$wrap.find( '.cfs-image-preview' ).empty().hide();
			$wrap.find( '.cfs-image-select' ).show();
			$( this ).hide();
		} );
	}


	/** File, color, range, choice helpers (delegated; cloned rows just work). */
	function bindPhase9Fields() {
		var cfg = window.cfstudioMetaBox || {};
		var i18n = cfg.i18n || {};

		onDoc( 'click', '.cfs-field-values .cfs-file-select', function ( e ) {
			e.preventDefault();
			if ( typeof wp === 'undefined' || ! wp.media ) {
				return;
			}
			var $wrap = $( this ).closest( '.cfs-file-field' );
			var frame = wp.media( {
				title: i18n.selectFile || 'Select file',
				multiple: false,
				button: { text: i18n.useFile || 'Use this file' },
			} );
			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				$wrap.find( '.cfs-file-value' ).val( a.id );
				$wrap.find( '.cfs-file-name' ).attr( 'href', a.url ).text( a.filename || a.title );
				$wrap.find( '.cfs-file-info' ).show();
				$wrap.find( '.cfs-file-select' ).hide();
				$wrap.find( '.cfs-file-remove' ).show();
			} );
			frame.open();
		} );

		onDoc( 'click', '.cfs-field-values .cfs-file-remove', function ( e ) {
			e.preventDefault();
			var $wrap = $( this ).closest( '.cfs-file-field' );
			$wrap.find( '.cfs-file-value' ).val( '' );
			$wrap.find( '.cfs-file-info' ).hide();
			$wrap.find( '.cfs-file-select' ).show();
			$( this ).hide();
		} );

		// Colour: the native swatch and the text box stay in sync.
		onDoc( 'input change', '.cfs-color-field .cfs-color-pick', function () {
			$( this ).siblings( '.cfs-color-text' ).val( this.value );
		} );
		onDoc( 'input change', '.cfs-color-field .cfs-color-text', function () {
			if ( /^#[0-9a-f]{6}$/i.test( this.value ) ) {
				$( this ).siblings( '.cfs-color-pick' ).val( this.value );
			}
		} );

		onDoc( 'input change', '.cfs-range', function () {
			$( this ).siblings( '.cfs-range-out' ).text( this.value );
		} );

		onDoc( 'click', '.cfs-choice-clear', function ( e ) {
			e.preventDefault();
			$( this ).closest( '.cfs-choice-list' ).find( 'input[type=radio]' ).prop( 'checked', false );
		} );

		onDoc( 'change', '.cfs-button-group input[type=radio]', function () {
			var $g = $( this ).closest( '.cfs-button-group' );
			$g.find( 'label' ).removeClass( 'cfs-active' );
			$g.find( 'input:checked' ).closest( 'label' ).addClass( 'cfs-active' );
		} );
	}


	/* ------------------------------------------------------------------
	 * Phase 10: tabs, accordions, conditional logic
	 * ---------------------------------------------------------------- */

	/** Turn tab / accordion markers inside one .cfs-field-values into UI. */
	function buildSections( $values ) {
		if ( $values.data( 'cfstudioSections' ) ) {
			return;
		}
		$values.data( 'cfstudioSections', true );

		var $kids = $values.children();
		var hasTab = $kids.filter( '.cfs-tab-marker' ).length > 0;
		var hasAcc = $kids.filter( '.cfs-accordion-marker' ).length > 0;
		if ( ! hasTab && ! hasAcc ) {
			return;
		}

		var $bar = null;
		var $tabBody = null; // the panel receiving the fields of the current tab
		var $accBody = null; // the body receiving the fields of the current accordion
		var tabs = [];

		$kids.each( function () {
			var $el = $( this );

			if ( $el.hasClass( 'cfs-tab-marker' ) ) {
				if ( ! $bar ) {
					$bar = $( '<div class="cfs-tabs-bar" role="tablist"></div>' ).insertBefore( $el );
				}
				var $panel = $( '<div class="cfs-tab-panel" role="tabpanel"></div>' ).insertAfter( $bar.nextAll( '.cfs-tab-panel' ).last().length ? $bar.nextAll( '.cfs-tab-panel' ).last() : $bar );
				var $btn = $( '<button type="button" class="cfs-tab-button" role="tab"></button>' ).text( $el.attr( 'data-label' ) || '…' );
				$bar.append( $btn );
				tabs.push( { $btn: $btn, $panel: $panel } );
				$tabBody = $panel;
				$accBody = null;
				$el.remove();
				return;
			}

			if ( $el.hasClass( 'cfs-accordion-marker' ) ) {
				var open = '1' === String( $el.attr( 'data-open' ) );
				var $acc = $( '<div class="cfs-accordion"></div>' ).toggleClass( 'cfs-open', open );
				var $head = $( '<button type="button" class="cfs-accordion-head" aria-expanded="' + open + '"></button>' ).text( $el.attr( 'data-label' ) || '…' );
				$accBody = $( '<div class="cfs-accordion-body"></div>' ).toggle( open );
				$acc.append( $head, $accBody );
				if ( $tabBody ) {
					$tabBody.append( $acc );
				} else {
					$acc.insertBefore( $el );
				}
				$el.remove();
				return;
			}

			// An ordinary field: goes into the current accordion, else tab.
			if ( $accBody ) {
				$accBody.append( $el );
			} else if ( $tabBody ) {
				$tabBody.append( $el );
			}
		} );

		function showTab( i ) {
			$.each( tabs, function ( n, t ) {
				t.$btn.toggleClass( 'cfs-tab-active', n === i ).attr( 'aria-selected', n === i );
				t.$panel.toggle( n === i );
			} );
		}
		if ( tabs.length ) {
			$bar.on( 'click', '.cfs-tab-button', function () {
				showTab( $( this ).index() );
			} );
			showTab( 0 );
		}
	}

	/** The closest container whose direct fields a rule can refer to. */
	function scopeOf( $el ) {
		return $el.closest( '.cfs-repeater-row, .cfs-flex-row, .cfs-group-fields, .cfs-field-values' );
	}

	/** Current value of the sibling field named ref (string, or array for multi-value inputs). */
	function siblingValue( $scope, ref ) {
		var tail = '[' + ref + ']';
		var $inputs = $scope.find( ':input' ).filter( function () {
			var n = this.name || '';
			var ok = n.slice( -tail.length ) === tail || n.slice( -tail.length - 2 ) === tail + '[]';
			return ok && scopeOf( $( this ) )[ 0 ] === $scope[ 0 ];
		} );
		if ( ! $inputs.length ) {
			return '';
		}
		var $real = $inputs.not( '[type=hidden]' );
		var $use = $real.length ? $real : $inputs;
		var first = $use[ 0 ];
		var multi = /\[\]$/.test( first.name );

		if ( 'checkbox' === first.type || 'radio' === first.type ) {
			var vals = $use.filter( ':checked' ).map( function () {
				return this.value;
			} ).get();
			if ( 'checkbox' === first.type && ! multi && 1 === $use.length ) {
				return vals.length ? '1' : '';
			}
			return ( multi || $use.length > 1 ) && 'radio' !== first.type ? vals : ( vals[ 0 ] || '' );
		}
		if ( 'SELECT' === first.tagName && first.multiple ) {
			return $( first ).val() || [];
		}
		if ( multi ) {
			return $use.map( function () {
				return this.value;
			} ).get().filter( function ( v ) { return '' !== v; } );
		}
		return $( first ).val();
	}

	function ruleMatches( rule, actual ) {
		var arr = $.isArray( actual );
		var want = String( rule.value );
		switch ( rule.operator ) {
			case '==':
				return arr ? actual.indexOf( want ) > -1 : String( actual ) === want;
			case '!=':
				return arr ? actual.indexOf( want ) === -1 : String( actual ) !== want;
			case 'empty':
				return arr ? 0 === actual.length : '' === String( actual );
			case '!empty':
				return arr ? actual.length > 0 : '' !== String( actual );
			case 'contains':
				return arr ? actual.indexOf( want ) > -1 : String( actual ).indexOf( want ) > -1;
			case '>':
				return parseFloat( actual ) > parseFloat( want );
			case '<':
				return parseFloat( actual ) < parseFloat( want );
		}
		return true;
	}

	function applyConditions() {
		$( '.cfs-field[data-cfs-cond]' ).each( function () {
			var $field = $( this );
			var cond;
			try {
				cond = JSON.parse( $field.attr( 'data-cfs-cond' ) );
			} catch ( e ) {
				return;
			}
			var $scope = scopeOf( $field );
			var results = $.map( cond.rules || [], function ( rule ) {
				return ruleMatches( rule, siblingValue( $scope, rule.field ) ) ? 1 : 0;
			} );
			var show = ! results.length ||
				( 'any' === cond.match ? results.indexOf( 1 ) > -1 : results.indexOf( 0 ) === -1 );
			$field.toggleClass( 'cfs-cond-hidden', ! show );
		} );
	}

	function bindPhase10() {
		$( '.cfs-field-values' ).each( function () {
			buildSections( $( this ) );
		} );

		onDoc( 'click', '.cfs-accordion-head', function () {
			var $acc = $( this ).closest( '.cfs-accordion' );
			var open = ! $acc.hasClass( 'cfs-open' );
			$acc.toggleClass( 'cfs-open', open );
			$( this ).attr( 'aria-expanded', open );
			$acc.children( '.cfs-accordion-body' ).toggle( open );
		} );

		// Evaluated right away (cheap), so a newly added row never flashes
		// a field that should be hidden.
		onDoc( 'change input click', '.cfs-field-values', applyConditions );
		if ( window.MutationObserver ) {
			// New repeater / flexible rows and picker changes re-evaluate too.
			$( '.cfs-field-values' ).each( function () {
				new MutationObserver( applyConditions ).observe( this, { childList: true, subtree: true } );
			} );
		}
		applyConditions();
	}

	/** Gallery + Relationship (delegated, so cloned rows just work). */
	function bindGalleryAndRelationship() {
		var cfg = window.cfstudioMetaBox || {};
		var i18n = cfg.i18n || {};

		onDoc( 'click', '.cfs-field-values .cfs-gallery-add', function ( e ) {
			e.preventDefault();
			if ( typeof wp === 'undefined' || ! wp.media ) {
				return;
			}
			var $wrap = $( this ).closest( '.cfs-gallery' );
			var frame = wp.media( {
				title: i18n.addImages || 'Add images',
				button: { text: i18n.addImages || 'Add images' },
				library: { type: 'image' },
				multiple: 'add',
			} );
			frame.on( 'select', function () {
				var max = parseInt( $wrap.data( 'max' ), 10 ) || 0;
				var name = $wrap.attr( 'data-name' );
				frame.state().get( 'selection' ).each( function ( att ) {
					var $list = $wrap.find( '.cfs-gallery-list' );
					var id = att.get( 'id' );
					if ( max && $list.children().length >= max ) {
						return;
					}
					if ( $list.children( '[data-id="' + id + '"]' ).length ) {
						return;
					}
					var sizes = att.get( 'sizes' ) || {};
					var size = sizes.thumbnail || sizes.full || { url: att.get( 'url' ) };
					var $li = $( '<li class="cfs-gallery-item"></li>' ).attr( 'data-id', id );
					$( '<input type="hidden" />' ).attr( 'name', name + '[]' ).val( id ).appendTo( $li );
					$( '<img alt="" />' ).attr( 'src', size.url ).appendTo( $li );
					$( '<button type="button" class="cfs-gallery-remove">&times;</button>' ).appendTo( $li );
					$list.append( $li );
				} );
			} );
			frame.open();
		} );

		onDoc( 'click', '.cfs-field-values .cfs-gallery-remove', function ( e ) {
			e.preventDefault();
			$( this ).closest( '.cfs-gallery-item' ).remove();
		} );

		var timer;
		function search( $rel ) {
			var $results = $rel.find( '.cfs-rel-results' );
			$.post( cfg.ajaxUrl, {
				action: 'cfstudio_relationship_search',
				nonce: cfg.relationshipNonce,
				types: $rel.attr( 'data-types' ),
				s: $rel.find( '.cfs-rel-search' ).val(),
			} ).done( function ( res ) {
				$results.empty();
				var items = res && res.success ? res.data : [];
				if ( ! items.length ) {
					$( '<li class="cfs-rel-empty"></li>' ).text( i18n.noResults || 'No results' ).appendTo( $results );
					return;
				}
				$.each( items, function ( i, item ) {
					$( '<li class="cfs-rel-result"></li>' )
						.attr( 'data-id', item.id )
						.text( item.title + ' (' + item.type + ')' )
						.data( 'title', item.title )
						.appendTo( $results );
				} );
			} );
		}

		onDoc( 'focus input', '.cfs-field-values .cfs-rel-search', function () {
			var $rel = $( this ).closest( '.cfs-relationship' );
			clearTimeout( timer );
			timer = setTimeout( function () {
				search( $rel );
			}, 250 );
		} );
		onDoc( 'keydown', '.cfs-field-values .cfs-rel-search', function ( e ) {
			if ( 13 === e.which ) {
				e.preventDefault(); // Don't submit the post form.
			}
		} );

		onDoc( 'click', '.cfs-field-values .cfs-rel-result', function () {
			var $rel = $( this ).closest( '.cfs-relationship' );
			var $sel = $rel.find( '.cfs-rel-selected' );
			var id = $( this ).attr( 'data-id' );
			var max = parseInt( $rel.attr( 'data-max' ), 10 ) || 0;
			if ( $sel.children( '[data-id="' + id + '"]' ).length ) {
				return;
			}
			if ( 1 === max ) {
				// Single choice (Post Object / Page Link): replace it.
				$sel.empty();
			} else if ( max && $sel.children().length >= max ) {
				return;
			}
			var $li = $( '<li></li>' ).attr( 'data-id', id );
			$( '<input type="hidden" />' ).attr( 'name', $rel.attr( 'data-name' ) + '[]' ).val( id ).appendTo( $li );
			$( '<span class="cfs-rel-title"></span>' ).text( $( this ).data( 'title' ) ).appendTo( $li );
			$( '<button type="button" class="cfs-rel-remove">&times;</button>' ).appendTo( $li );
			$sel.append( $li );
		} );

		onDoc( 'click', '.cfs-field-values .cfs-rel-remove', function () {
			$( this ).closest( 'li' ).remove();
		} );

		onDoc( 'mouseenter', '.cfs-field-values .cfs-rel-selected:not(.ui-sortable)', function () {
			$( this ).sortable( { tolerance: 'pointer' } );
		} );
	}

	$( function () {
		bindGalleryAndRelationship();
		bindImageField();
		bindPhase9Fields();
		bindPhase10();

		onDoc( 'click', '.cfs-repeater-add', function ( e ) {
			e.preventDefault();
			addRow( $( this ).closest( '.cfs-repeater' ) );
		} );

		onDoc( 'click', '.cfs-flex-add', function ( e ) {
			e.preventDefault();
			e.stopPropagation();

			var $flex = $( this ).closest( '.cfs-flex' );
			var $menu = $( this ).siblings( '.cfs-flex-menu' );
			var $choices = $menu.children( '.cfs-flex-add-layout' );

			// One layout: nothing to choose, add it straight away.
			if ( 1 === $choices.length ) {
				addFlexRow( $flex, $choices.attr( 'data-layout' ) );
				return;
			}

			var wasHidden = $menu.prop( 'hidden' );
			$( '.cfs-flex-menu' ).prop( 'hidden', true );
			$menu.prop( 'hidden', ! wasHidden );
		} );

		onDoc( 'click', '.cfs-flex-add-layout', function ( e ) {
			e.preventDefault();

			$( this ).parent().prop( 'hidden', true );
			addFlexRow( $( this ).closest( '.cfs-flex' ), $( this ).attr( 'data-layout' ) );
		} );

		onDoc( 'click', function () {
			$( '.cfs-flex-menu' ).prop( 'hidden', true );
		} );

		onDoc( 'click', '.cfs-flex-remove', function ( e ) {
			e.preventDefault();
			removeFlexRow( $( this ).closest( '.cfs-flex-row' ) );
		} );

		onDoc( 'click', '.cfs-repeater-remove', function ( e ) {
			e.preventDefault();
			removeRow( $( this ).closest( '.cfs-repeater-row' ) );
		} );

		initRepeaters( $( '.cfs-field-values' ) );
		bindGoogleMap();

		// For fields injected after load (custom blocks).
		window.cfstudioInit = function ( $scope ) {
			initRepeaters( $scope );
		};
	} );

	/* ------------------------------------------------------------------
	 * Google Map field. Works without an API key (plain latitude /
	 * longitude inputs); with a key it adds a searchable, draggable map.
	 * ---------------------------------------------------------------- */
	function bindGoogleMap() {
		function loadApi( key, cb ) {
			if ( window.google && window.google.maps && window.google.maps.places ) {
				cb();
				return;
			}
			window.__cfstudioGmapQueue = window.__cfstudioGmapQueue || [];
			window.__cfstudioGmapQueue.push( cb );
			if ( window.__cfstudioGmapLoading ) {
				return;
			}
			window.__cfstudioGmapLoading = true;
			window.__cfstudioGmapReady = function () {
				( window.__cfstudioGmapQueue || [] ).forEach( function ( fn ) {
					fn();
				} );
			};
			var s = document.createElement( 'script' );
			s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent( key ) + '&libraries=places&callback=__cfstudioGmapReady';
			s.async = true;
			document.head.appendChild( s );
		}

		function initMap( $w ) {
			if ( $w.data( 'cfstudioMapInit' ) ) {
				return;
			}
			$w.data( 'cfstudioMapInit', true );
			var key = $w.attr( 'data-key' );
			if ( ! key ) {
				return;
			}

			loadApi( key, function () {
				var g = window.google.maps;
				var $lat = $w.find( '.cfs-gmap-lat' ), $lng = $w.find( '.cfs-gmap-lng' ), $zoom = $w.find( '.cfs-gmap-zoom' ), $addr = $w.find( '.cfs-gmap-address' );
				var $canvas = $w.find( '.cfs-gmap-canvas' ).show();
				var hasValue = $lat.val() !== '' && $lng.val() !== '';
				var cLat = parseFloat( hasValue ? $lat.val() : $w.attr( 'data-center-lat' ) );
				var cLng = parseFloat( hasValue ? $lng.val() : $w.attr( 'data-center-lng' ) );
				var center = isNaN( cLat ) || isNaN( cLng ) ? { lat: 20.5937, lng: 78.9629 } : { lat: cLat, lng: cLng };
				var map = new g.Map( $canvas[ 0 ], { center: center, zoom: parseInt( $zoom.val(), 10 ) || 14 } );
				var marker = new g.Marker( { map: map, position: center, draggable: true, visible: hasValue } );

				function set( latLng, address ) {
					$lat.val( latLng.lat().toFixed( 7 ) ).trigger( 'change' );
					$lng.val( latLng.lng().toFixed( 7 ) ).trigger( 'change' );
					if ( typeof address === 'string' ) {
						$addr.val( address ).trigger( 'change' );
					}
					marker.setPosition( latLng );
					marker.setVisible( true );
				}

				map.addListener( 'click', function ( e ) {
					set( e.latLng );
				} );
				marker.addListener( 'dragend', function () {
					set( marker.getPosition() );
				} );
				map.addListener( 'zoom_changed', function () {
					$zoom.val( map.getZoom() ).trigger( 'change' );
				} );

				var ac = new g.places.Autocomplete( $addr[ 0 ] );
				ac.addListener( 'place_changed', function () {
					var place = ac.getPlace();
					if ( place && place.geometry ) {
						map.setCenter( place.geometry.location );
						set( place.geometry.location, place.formatted_address || $addr.val() );
					}
				} );
				$addr.on( 'keydown', function ( e ) {
					if ( e.which === 13 ) {
						e.preventDefault();
					}
				} );

				$lat.add( $lng ).on( 'change', function () {
					var la = parseFloat( $lat.val() ), ln = parseFloat( $lng.val() );
					if ( ! isNaN( la ) && ! isNaN( ln ) ) {
						var p = new g.LatLng( la, ln );
						marker.setPosition( p );
						marker.setVisible( true );
						map.setCenter( p );
					}
				} );

				$w.on( 'cfs-gmap-clear', function () {
					marker.setVisible( false );
				} );
			} );
		}

		onDoc( 'click', '.cfs-gmap-clear', function ( e ) {
			e.preventDefault();
			var $w = $( this ).closest( '.cfs-gmap' );
			$w.find( '.cfs-gmap-address, .cfs-gmap-lat, .cfs-gmap-lng' ).val( '' ).trigger( 'change' );
			$w.trigger( 'cfs-gmap-clear' );
		} );

		// Lazy: set a map up the first time it is hovered or focused (works for
		// fields in repeater rows and custom blocks, which appear after load).
		onDoc( 'mouseenter focusin', '.cfs-gmap', function () {
			initMap( $( this ) );
		} );
		$( '.cfs-gmap' ).each( function () {
			var $w = $( this );
			if ( $w.is( ':visible' ) ) {
				initMap( $w );
			}
		} );
	}
} )( jQuery );
