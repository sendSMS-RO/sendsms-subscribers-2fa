/**
 * SendSMS Dashboard — Unsubscribe block (edit-time UI).
 *
 * Dynamic block: server-rendered via PHP. The editor shows a live preview
 * via wp.serverSideRender and a sidebar inspector for the title attribute.
 * No build step — relies on the wp.* globals registered by WordPress core.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks ) {
		return;
	}

	var blockEditor       = wp.blockEditor || wp.editor;
	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var ServerSideRender  = wp.serverSideRender || wp.editor.ServerSideRender;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps     = blockEditor.useBlockProps;
	var PanelBody         = wp.components.PanelBody;
	var TextControl       = wp.components.TextControl;
	var Disabled          = wp.components.Disabled;
	var __                = wp.i18n.__;

	registerBlockType( 'sendsms-dashboard/unsubscribe', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps    = useBlockProps ? useBlockProps() : {};

			return el(
				Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{
							title:       __( 'Settings', 'sendsms-dashboard' ),
							initialOpen: true,
						},
						el( TextControl, {
							label:    __( 'Title', 'sendsms-dashboard' ),
							value:    attributes.title || '',
							onChange: function ( value ) { setAttributes( { title: value } ); },
						} )
					)
				),
				el(
					'div',
					blockProps,
					el(
						Disabled,
						{},
						el( ServerSideRender, {
							block:      'sendsms-dashboard/unsubscribe',
							attributes: attributes,
						} )
					)
				)
			);
		},

		// Dynamic block — rendered by PHP at view time.
		save: function () { return null; },
	} );
}( window.wp ) );
