/**
 * Remember whether a disclosure was left open, per user, in `localStorage`.
 *
 * A convenience only: storage can be missing, blocked or full (a private window, site
 * data blocked, a quota), so every access is guarded and a failure simply means the
 * disclosure starts closed.
 */

/**
 * Prefix of every key this panel stores (naming contract: `sit-wcpg-`).
 */
const KEY_PREFIX = 'sit-wcpg-';

/**
 * The storage key for one disclosure, scoped to the signed-in user where WordPress
 * says who that is (`window.userSettings.uid`, printed on every admin screen).
 *
 * @param {string} name Disclosure name, e.g. `checklist-passed-open`.
 * @return {string} Storage key.
 */
export function storageKey( name ) {
	const uid =
		typeof window !== 'undefined' &&
		window.userSettings &&
		window.userSettings.uid
			? String( window.userSettings.uid )
			: '';

	return KEY_PREFIX + name + ( uid ? '-' + uid : '' );
}

/**
 * Whether the disclosure was left open.
 *
 * @param {string} name Disclosure name.
 * @return {boolean} True when it was left open; false when closed, unknown or unreadable.
 */
export function readOpen( name ) {
	try {
		return '1' === window.localStorage.getItem( storageKey( name ) );
	} catch {
		return false;
	}
}

/**
 * Remember the disclosure's state. Silently does nothing when storage is unavailable.
 *
 * @param {string}  name Disclosure name.
 * @param {boolean} open Whether it is open.
 */
export function writeOpen( name, open ) {
	try {
		window.localStorage.setItem( storageKey( name ), open ? '1' : '0' );
	} catch {
		// Storage is blocked or full: the state just is not remembered.
	}
}
