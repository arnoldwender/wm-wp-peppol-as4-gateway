( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, ToggleControl } = wp.components;
	const { createElement: el, Fragment } = wp.element;
	const { __ } = wp.i18n;
	const ServerSideRender = wp.serverSideRender;

	registerBlockType( 'wm/peppol-invoice-portal', {
		edit: function EditInvoicePortalBlock( props ) {
			const { attributes, setAttributes } = props;
			const blockProps = useBlockProps();

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __( 'Portal-Darstellung', 'wm-peppol-as4-gateway' ),
							initialOpen: true,
						},
						el( ToggleControl, {
							label: __( 'Überschrift anzeigen', 'wm-peppol-as4-gateway' ),
							checked: false !== attributes.showHeading,
							onChange: function ( next ) {
								setAttributes( { showHeading: Boolean( next ) } );
							},
						} ),
						el( ToggleControl, {
							label: __( 'Einleitungstext anzeigen', 'wm-peppol-as4-gateway' ),
							checked: false !== attributes.showLead,
							onChange: function ( next ) {
								setAttributes( { showLead: Boolean( next ) } );
							},
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block: 'wm/peppol-invoice-portal',
						attributes: attributes,
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
