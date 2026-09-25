/**
 * Entry: mounts the live checklist over the server-rendered one.
 *
 * The meta box already holds a complete, escaped checklist (the no-JS fallback), and
 * `window.sitWcpgEditorData` holds the same result as data — so the panel paints with
 * zero requests and React simply takes over the node.
 */

import domReady from '@wordpress/dom-ready';
import { createElement, createRoot } from '@wordpress/element';

import { ChecklistApp } from './components/ChecklistApp';

import '../../scss/editor.scss';

/**
 * Mount-node id and payload global. Both are defined once in PHP
 * (`Editor_Meta_Box::MOUNT_ID`, `Assets::PAYLOAD_GLOBAL`); these must match them, and
 * `tests/Unit/Naming_Contract_Test.php` asserts that they do.
 */
const MOUNT_ID = 'sit-wcpg-checklist-root';
const PAYLOAD_GLOBAL = 'sitWcpgEditorData';

domReady( () => {
	const node = document.getElementById( MOUNT_ID );
	const data = window[ PAYLOAD_GLOBAL ];

	/*
	 * Without a result there is nothing to take over: the meta box is showing its
	 * "not saved yet" message, and the validate route would answer 404 for the same
	 * product. Leave the server's markup alone.
	 */
	if ( ! node || ! data || ! data.result || ! data.restPath ) {
		return;
	}

	createRoot( node ).render( <ChecklistApp data={ data } /> );
} );
