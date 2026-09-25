<?php
/**
 * Conditional admin asset loading.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Engine\Validation_Result;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Rest\Validate_Controller;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's only enqueue point, implementing the matrix in coding-plan.md section 11.1.
 *
 * | Screen                              | JS           | CSS         | Inline payload  |
 * | product editor (`post.php`/`post-new.php`) | `sit-wcpg-editor` | editor.css | `sitWcpgEditorData` |
 * | products list (`edit.php`)          | none         | admin.css   | none            |
 * | plugin settings page                | none         | admin.css   | none            |
 * | everything else                     | none         | none        | none            |
 *
 * The screen is decided before the settings are read, because reading a setting builds
 * the rule registry and the overwhelming majority of admin requests are none of these
 * three screens.
 *
 * Every build artefact is checked with `file_exists()` first. A plugin installed from a
 * checkout that has not been built still renders a correct, static checklist from the
 * meta box — it simply never upgrades to the live panel.
 *
 * @since 1.0.0
 */
final class Assets {

	/**
	 * Handle of the editor script and of its stylesheet.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const EDITOR_HANDLE = 'sit-wcpg-editor';

	/**
	 * Handle of the shared admin stylesheet.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const ADMIN_HANDLE = 'sit-wcpg-admin';

	/**
	 * The global the editor bundle reads its bootstrap payload from.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const PAYLOAD_GLOBAL = 'sitWcpgEditorData';

	/**
	 * The merchant's configuration.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The checklist source, resolved on first use.
	 *
	 * @since 1.0.0
	 * @var Checklist_Service|null
	 */
	private ?Checklist_Service $checklist;

	/**
	 * Construct the enqueuer.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings               $settings  The merchant's configuration.
	 * @param Checklist_Service|null $checklist Result source. Null resolves the plugin's own.
	 */
	public function __construct( Settings $settings, ?Checklist_Service $checklist = null ) {
		$this->settings  = $settings;
		$this->checklist = $checklist;
	}

	/**
	 * Hook the enqueuer into the admin.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue whatever this screen needs, which is usually nothing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Hook suffix of the current admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		$hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';

		if ( Screen::is_product_edit_screen( $hook_suffix ) ) {
			if ( ! $this->settings->is_enabled() ) {
				return;
			}

			$this->enqueue_editor();

			return;
		}

		if ( Screen::is_product_list_screen( $hook_suffix ) || Screen::is_settings_screen( $hook_suffix ) ) {
			$this->enqueue_style( self::ADMIN_HANDLE, 'admin.css', SIT_WCPG_VERSION );
		}
	}

	/**
	 * Enqueue the React panel and its bootstrap payload.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function enqueue_editor(): void {
		$product_id = Screen::current_product_id();

		if ( 0 === $product_id ) {
			return;
		}

		$asset = $this->read_asset_file( 'editor' );

		/*
		 * The panel renders `@wordpress/components` (Button, Notice, Spinner), whose
		 * stylesheet the classic product editor does not load on its own.
		 */
		$this->enqueue_style( self::EDITOR_HANDLE, 'editor.css', $asset['version'], array( 'wp-components' ) );

		if ( ! file_exists( SIT_WCPG_PATH . 'build/editor.js' ) ) {
			return;
		}

		wp_enqueue_script(
			self::EDITOR_HANDLE,
			SIT_WCPG_URL . 'build/editor.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( self::EDITOR_HANDLE, 'product-publish-guard', SIT_WCPG_PATH . 'languages' );

		/*
		 * `wp_json_encode()` inside `wp_add_inline_script()`, never string concatenation
		 * into a <script> block: the encoder escapes the sequences that would otherwise
		 * end the script element.
		 */
		wp_add_inline_script(
			self::EDITOR_HANDLE,
			'window.' . self::PAYLOAD_GLOBAL . ' = ' . wp_json_encode( $this->payload( $product_id ) ) . ';',
			'before'
		);
	}

	/**
	 * The bootstrap payload, which is why the panel needs no request to paint.
	 *
	 * @since 1.0.0
	 *
	 * @param int $product_id The product being edited.
	 * @return array
	 */
	private function payload( int $product_id ): array {
		$result = $this->checklist()->validate_post( $product_id );

		return array(
			'productId'  => $product_id,

			/*
			 * The saved status: enforcement applies only to transitions into publish, so
			 * the publish warning is pointless on a product that is already live.
			 */
			'postStatus' => (string) get_post_status( $product_id ),
			'result'     => $result instanceof Validation_Result ? $result->to_array() : null,
			'settings'   => array(
				'blocksPublishing' => $this->settings->blocks_publishing(),

				/*
				 * Advisory only, for the wording of the client-side notice. The authority
				 * on whether this user may publish past a failure is
				 * `Publish_Guard::can_override()`, which runs server-side on every save.
				 */
				'canOverride'      => $this->settings->allows_admin_override() && current_user_can( 'manage_woocommerce' ),
			),
			'restPath'   => '/' . Validate_Controller::REST_NAMESPACE . '/products/' . $product_id . '/validate',
			'groups'     => Editor_Meta_Box::group_labels(),
		);
	}

	/**
	 * Enqueue one built stylesheet if it was built.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $handle  Stylesheet handle.
	 * @param string   $file    File name inside `build/`.
	 * @param string   $version Version string for cache busting.
	 * @param string[] $deps    Stylesheet handles this one depends on.
	 * @return void
	 */
	private function enqueue_style( string $handle, string $file, string $version, array $deps = array() ): void {
		if ( ! file_exists( SIT_WCPG_PATH . 'build/' . $file ) ) {
			return;
		}

		wp_enqueue_style( $handle, SIT_WCPG_URL . 'build/' . $file, $deps, $version );
	}

	/**
	 * Dependencies and version for a build entry, as written by `@wordpress/scripts`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $entry Build entry name.
	 * @return array{dependencies: array, version: string}
	 */
	private function read_asset_file( string $entry ): array {
		$defaults = array(
			'dependencies' => array(),
			'version'      => SIT_WCPG_VERSION,
		);

		$path = SIT_WCPG_PATH . 'build/' . $entry . '.asset.php';

		if ( ! file_exists( $path ) ) {
			return $defaults;
		}

		$asset = require $path;

		if ( ! is_array( $asset ) ) {
			return $defaults;
		}

		return array(
			'dependencies' => isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] )
				? $asset['dependencies']
				: $defaults['dependencies'],
			'version'      => isset( $asset['version'] ) && is_string( $asset['version'] )
				? $asset['version']
				: $defaults['version'],
		);
	}

	/**
	 * The checklist service, resolved on first use.
	 *
	 * @since 1.0.0
	 *
	 * @return Checklist_Service
	 */
	private function checklist(): Checklist_Service {
		if ( null === $this->checklist ) {
			$this->checklist = Plugin::instance()->checklist();
		}

		return $this->checklist;
	}
}
