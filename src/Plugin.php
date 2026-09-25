<?php
/**
 * Plugin service locator and hook wiring.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard;

use ProductPublishGuard\Admin\Assets;
use ProductPublishGuard\Admin\Editor_Meta_Box;
use ProductPublishGuard\Admin\Notices;
use ProductPublishGuard\Admin\Product_List_Column;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Validator;
use ProductPublishGuard\Publishing\Publish_Guard;
use ProductPublishGuard\Rest\Validate_Controller;
use ProductPublishGuard\Rules\Rules_Provider;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Settings\Settings_Page;
use ProductPublishGuard\Support\Checklist_Service;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's single entry point once requirements have passed.
 *
 * Holds the four shared services and constructs each on first use, so a request that
 * never touches the checklist (most front-end requests) never builds the rule registry.
 *
 * @since 1.0.0
 */
final class Plugin {

	/**
	 * The singleton instance.
	 *
	 * @since 1.0.0
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Lazily constructed settings facade.
	 *
	 * @since 1.0.0
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Lazily constructed, populated rule registry.
	 *
	 * @since 1.0.0
	 * @var Rule_Registry|null
	 */
	private ?Rule_Registry $registry = null;

	/**
	 * Lazily constructed validator.
	 *
	 * @since 1.0.0
	 * @var Validator|null
	 */
	private ?Validator $validator = null;

	/**
	 * Lazily constructed checklist service.
	 *
	 * @since 1.0.0
	 * @var Checklist_Service|null
	 */
	private ?Checklist_Service $checklist = null;

	/**
	 * Lazily constructed notice queue.
	 *
	 * @since 1.0.0
	 * @var Notices|null
	 */
	private ?Notices $notices = null;

	/**
	 * Lazily constructed publishing guard.
	 *
	 * @since 1.0.0
	 * @var Publish_Guard|null
	 */
	private ?Publish_Guard $publish_guard = null;

	/**
	 * Private constructor: use instance().
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

	/**
	 * Prevent cloning of the singleton.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the plugin's hooks.
	 *
	 * Idempotent: calling it twice registers nothing twice.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );

		/*
		 * Not gated on is_admin(): a REST request is not an admin request. All this does
		 * is add one `rest_api_init` callback, which no other kind of request ever fires.
		 */
		( new Validate_Controller() )->register();

		/*
		 * Not gated on is_admin() either: REST, importers and cron are exactly the paths a
		 * UI-only guard would miss (section 6.3). Registering adds hooks and nothing else.
		 */
		$this->publish_guard()->register();

		if ( is_admin() ) {
			/*
			 * Constructing these is cheap: they only add hooks. Everything expensive —
			 * the rule registry, the checklist service, a validation run — is resolved
			 * inside the callbacks, which fire on three screens and nowhere else. An
			 * ordinary admin request therefore never builds the rule list.
			 */
			( new Settings_Page( $this->settings() ) )->register();
			( new Assets( $this->settings() ) )->register();
			( new Editor_Meta_Box( $this->settings() ) )->register();
			( new Product_List_Column( $this->settings() ) )->register();
			$this->notices()->register();
		}
	}

	/**
	 * Load the plugin's translations.
	 *
	 * Hooked to `init` and never earlier: WordPress 6.7 emits a `_doing_it_wrong()`
	 * notice for translations loaded before `after_setup_theme`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'product-publish-guard',
			false,
			dirname( plugin_basename( SIT_WCPG_FILE ) ) . '/languages'
		);
	}

	/**
	 * Get the settings facade.
	 *
	 * @since 1.0.0
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		if ( null === $this->settings ) {
			$this->settings = new Settings();
		}

		return $this->settings;
	}

	/**
	 * Get the populated rule registry.
	 *
	 * Population fires `sit_wcpg_register_rules`, so third-party rules are available to
	 * every caller of this method.
	 *
	 * @since 1.0.0
	 *
	 * @return Rule_Registry
	 */
	public function registry(): Rule_Registry {
		if ( null === $this->registry ) {
			$this->registry = new Rule_Registry();
			Rules_Provider::populate( $this->registry );
		}

		return $this->registry;
	}

	/**
	 * Get the validator.
	 *
	 * @since 1.0.0
	 *
	 * @return Validator
	 */
	public function validator(): Validator {
		if ( null === $this->validator ) {
			$this->validator = new Validator( $this->registry(), $this->settings() );
		}

		return $this->validator;
	}

	/**
	 * Get the checklist service.
	 *
	 * @since 1.0.0
	 *
	 * @return Checklist_Service
	 */
	public function checklist(): Checklist_Service {
		if ( null === $this->checklist ) {
			$this->checklist = new Checklist_Service( $this->validator(), $this->settings() );
		}

		return $this->checklist;
	}

	/**
	 * Get the notice queue.
	 *
	 * @since 1.0.0
	 *
	 * @return Notices
	 */
	public function notices(): Notices {
		if ( null === $this->notices ) {
			$this->notices = new Notices();
		}

		return $this->notices;
	}

	/**
	 * Get the publishing guard.
	 *
	 * @since 1.0.0
	 *
	 * @return Publish_Guard
	 */
	public function publish_guard(): Publish_Guard {
		if ( null === $this->publish_guard ) {
			$this->publish_guard = new Publish_Guard( $this->settings(), $this->notices() );
		}

		return $this->publish_guard;
	}
}
