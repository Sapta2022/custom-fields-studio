/**
 * Editor side of Fieldloom blocks.
 *
 * Every block registered with cfstudio_register_block() is registered here too.
 * The block's field values are a form-encoded string kept in the `form`
 * attribute; the field form itself is HTML fetched from the server (so every
 * field type, repeaters included, works exactly as in a meta box).
 */
( function ( wp, $ ) {
	'use strict';

	if ( ! wp || ! window.cfstudioBlocks ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var i18n = window.cfstudioBlocks.i18n;

	function serialize( root ) {
		return $( root ).find( ':input[name^="cfstudio["]' ).serialize();
	}

	/*
	 * When the editor canvas is an iframe, give it the field handlers and the
	 * stylesheets the form needs (the iframe starts with neither).
	 */
	function prepareDocument( doc ) {
		if ( ! doc || doc === document ) {
			return;
		}
		if ( window.cfstudioBindDocument ) {
			window.cfstudioBindDocument( doc );
		}
		if ( doc.__cfstudioStyled ) {
			return;
		}
		doc.__cfstudioStyled = true;
		$( 'link[rel="stylesheet"]' ).each( function () {
			var id = this.id || '';
			if ( /^(cfs-admin|buttons|forms|dashicons|wp-color-picker|media-views)-css$/.test( id ) ) {
				var link = doc.createElement( 'link' );
				link.rel = 'stylesheet';
				link.href = this.href;
				doc.head.appendChild( link );
			}
		} );
	}

	function FormPane( props ) {
		var ref = useRef( null );
		var last = useRef( props.form );
		var state = useState( 'loading' );
		var status = state[ 0 ];
		var setStatus = state[ 1 ];

		useEffect( function () {
			var cancelled = false;
			var timer = null;
			var observer = null;
			var $root = null;

			function push() {
				if ( ! ref.current ) {
					return;
				}
				var s = serialize( ref.current );
				if ( s !== last.current ) {
					last.current = s;
					props.onChange( s );
				}
			}

			function schedule() {
				clearTimeout( timer );
				timer = setTimeout( push, 300 );
			}

			wp.apiFetch( {
				path: '/cfstudio/v1/block-form',
				method: 'POST',
				data: { block: props.block, form: props.form }
			} ).then( function ( res ) {
				if ( cancelled || ! ref.current ) {
					return;
				}
				if ( ! res.groups ) {
					setStatus( 'empty' );
					return;
				}
				ref.current.innerHTML = res.html;
				prepareDocument( ref.current.ownerDocument );
				$root = $( ref.current );
				if ( window.cfstudioInit ) {
					window.cfstudioInit( $root );
				}
				$root.on( 'input change click', schedule );
				observer = new MutationObserver( schedule );
				observer.observe( ref.current, { childList: true, subtree: true } );
				setStatus( 'ready' );
			} );

			return function () {
				cancelled = true;
				clearTimeout( timer );
				if ( observer ) {
					observer.disconnect();
				}
				if ( $root ) {
					$root.off( 'input change click', schedule );
				}
			};
		}, [] );

		return el(
			'div',
			{ className: 'cfs-block-form' },
			status === 'loading' ? el( 'p', { className: 'cfs-block-loading' }, i18n.loading ) : null,
			status === 'empty' ? el( 'p', { className: 'cfs-block-empty' }, i18n.noFields ) : null,
			el( 'div', { ref: ref } )
		);
	}

	window.cfstudioBlocks.blocks.forEach( function ( def ) {
		wp.blocks.registerBlockType( def.name, {
			apiVersion: 2,
			title: def.title,
			description: def.description,
			category: def.category,
			icon: def.icon,
			keywords: def.keywords,
			supports: Object.assign( { html: false }, def.supports ),
			attributes: {
				form: { type: 'string', default: '' },
				align: { type: 'string' }
			},
			edit: function ( props ) {
				var s = useState( ! props.attributes.form );
				var editing = s[ 0 ];
				var setEditing = s[ 1 ];
				var blockProps = wp.blockEditor.useBlockProps( { className: 'cfs-block-editor' } );

				return el(
					Fragment,
					null,
					el(
						wp.blockEditor.BlockControls,
						null,
						el(
							wp.components.ToolbarGroup,
							null,
							el( wp.components.ToolbarButton, {
								icon: editing ? 'visibility' : 'edit',
								label: editing ? i18n.preview : i18n.editFields,
								onClick: function () {
									setEditing( ! editing );
								}
							} )
						)
					),
					el(
						'div',
						blockProps,
						editing
							? el(
									'div',
									{ className: 'cfs-block-editor-panel' },
									el( 'strong', { className: 'cfs-block-editor-title' }, def.title ),
									el( FormPane, {
										block: def.name,
										form: props.attributes.form,
										onChange: function ( form ) {
											props.setAttributes( { form: form } );
										}
									} )
							  )
							: el( wp.serverSideRender, { block: def.name, attributes: { form: props.attributes.form, align: props.attributes.align } } )
					)
				);
			},
			save: function () {
				return null;
			}
		} );
	} );
} )( window.wp, window.jQuery );
