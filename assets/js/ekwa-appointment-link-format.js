/**
 * Ekwa Appointment Link Inline Format.
 *
 * The block-level "Link Source" control (ekwa-link-source-control.js) lets
 * ekwa/div, ekwa/link, ekwa/button etc. point at the Appointment URL from
 * Ekwa Settings and stay correct when that setting changes — the block only
 * stores linkType:"appointment" and the real URL is resolved fresh on every
 * render (see ekwa_resolve_block_link_url()).
 *
 * Plain RichText links (the chain icon in the paragraph/heading toolbar)
 * have no such mechanism — clicking it writes a literal href into the saved
 * content, so it goes stale the moment the appointment URL changes. This
 * format is the RichText equivalent of the block-level control: it marks
 * the wrapped text as an appointment link instead of writing a real URL,
 * and inc/ekwa-blocks.php (ekwa_resolve_appointment_link_format) rewrites
 * the href to the live appointment URL on every render.
 *
 * The href written here at save time is only an editor-preview convenience
 * (so links look right immediately) — it's discarded and recomputed on the
 * front end.
 *
 * Usage: select text, click the calendar toolbar button. Click again
 * (caret inside, or the same text selected) to remove it.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.richText || ! wp.richText.registerFormatType ) {
		return;
	}

	var registerFormatType    = wp.richText.registerFormatType;
	var applyFormat           = wp.richText.applyFormat;
	var removeFormat          = wp.richText.removeFormat;
	var RichTextToolbarButton = wp.blockEditor.RichTextToolbarButton;
	var el                    = wp.element.createElement;
	var __                    = wp.i18n.__;

	var FORMAT_NAME = 'ekwa/appointment-link';

	registerFormatType( FORMAT_NAME, {
		title:     __( 'Appointment Link', 'ekwa' ),
		tagName:   'a',
		// Scoped to our own marker class so this format only ever claims
		// anchors it created itself — core's link format (also tagName "a",
		// className null) keeps handling every other link untouched.
		className: 'ekwa-appointment-link',
		attributes: {
			href:         'href',
			dataLinkType: 'data-ekwa-link-type',
		},

		edit: function ( formatProps ) {
			var isActive     = formatProps.isActive;
			var value        = formatProps.value;
			var onChange     = formatProps.onChange;
			var hasSelection = value.start !== value.end;

			function onClick() {
				if ( isActive ) {
					onChange( removeFormat( value, FORMAT_NAME ) );
					return;
				}
				if ( ! hasSelection ) {
					return;
				}

				var apptUrl = ( window.ekwaBlockData && window.ekwaBlockData.appointmentUrl ) || '';

				onChange( applyFormat( value, {
					type: FORMAT_NAME,
					attributes: {
						href:         apptUrl || '#',
						dataLinkType: 'appointment',
					},
				} ) );
			}

			return el( RichTextToolbarButton, {
				icon:     'calendar-alt',
				title:    isActive
					? __( 'Remove appointment link', 'ekwa' )
					: __( 'Insert appointment link', 'ekwa' ),
				onClick:  onClick,
				isActive: isActive,
				disabled: ! isActive && ! hasSelection,
			} );
		},
	} );
} )( window.wp );
