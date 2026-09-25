<?php
/**
 * Server-side publishing enforcement.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Publishing;

use ProductPublishGuard\Admin\Notices;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Save_Request_Reader;
use ProductPublishGuard\Engine\Validation_Result;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Settings\Settings;
use ProductPublishGuard\Support\Checklist_Service;
use Throwable;
use WC_Product;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a product that fails a required check from becoming `publish` or `future`.
 *
 * Three layers, because no single hook sees every path (coding-plan.md section 6.3):
 *
 * - **A** `wp_insert_post_data` — the classic editor, Quick Edit, Bulk Edit and any
 *   `wp_update_post()`, before the row is written.
 * - **B** `woocommerce_before_product_object_save` — WooCommerce CRUD writes (REST,
 *   importers, `$product->save()`), whose props are invisible to Layer A.
 * - **C** `future_to_publish` — the scheduled-publish cron, which writes the status with
 *   a raw query that reaches neither of the others.
 *
 * Enforcement applies to transitions **into** publish only; a live product is never
 * demoted (section 6.4). An internal exception fails open: a bug in this plugin must
 * never stop a merchant from publishing.
 *
 * @since 1.0.0
 */
final class Publish_Guard {

	/**
	 * Source name for a WooCommerce CRUD write outside a REST request.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SOURCE_CRUD = 'crud';

	/**
	 * Source name for the scheduled-publish backstop.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const SOURCE_SCHEDULED = 'scheduled';

	/**
	 * Statuses that count as an attempt to publish.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const PUBLISH_STATUSES = array( 'publish', 'future' );

	/**
	 * Stored statuses a refused publish returns to; anything else becomes `draft`.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const KEPT_STATUSES = array( 'draft', 'pending' );

	/**
	 * CRUD saves in flight, as product id (0 while creating) => nesting depth.
	 *
	 * Static because the flag describes the request: Layer A must see it whichever guard
	 * instance set it, including a second instance a test registers.
	 *
	 * @since 1.0.0
	 * @var array<int, int>
	 */
	private static array $crud_saves = array();

	/**
	 * The id each in-flight product object was saved under, by object id.
	 *
	 * A new product has id 0 before the save and a real id after it, so the key taken on
	 * the way in is remembered rather than recomputed.
	 *
	 * @since 1.0.0
	 * @var array<int, int>
	 */
	private static array $crud_keys = array();

	/**
	 * Posts Layer A has judged inside a `wp_insert_post()` call that is still running.
	 *
	 * @since 1.0.0
	 * @var array<int, true>
	 */
	private static array $layer_a_writes = array();

	/**
	 * Whether the backstop is itself writing, so its own update is not judged again.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private static bool $reverting = false;

	/**
	 * The merchant's configuration.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Where refused and overridden publishes are reported.
	 *
	 * @since 1.0.0
	 * @var Notices
	 */
	private Notices $notices;

	/**
	 * Result source, resolved on first use.
	 *
	 * @since 1.0.0
	 * @var Checklist_Service|null
	 */
	private ?Checklist_Service $checklist;

	/**
	 * Results of the publishes refused in this request, by post id.
	 *
	 * @since 1.0.0
	 * @var array<int, Validation_Result>
	 */
	private array $refused = array();

	/**
	 * Construct the guard.
	 *
	 * The checklist service is resolved lazily: most saves are not publish attempts on
	 * products, and those never build the rule registry.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings               $settings  The merchant's configuration.
	 * @param Notices                $notices   Feedback queue.
	 * @param Checklist_Service|null $checklist Result source; defaults to the plugin's.
	 */
	public function __construct( Settings $settings, Notices $notices, ?Checklist_Service $checklist = null ) {
		$this->settings  = $settings;
		$this->notices   = $notices;
		$this->checklist = $checklist;
	}

	/**
	 * Register the three layers.
	 *
	 * Registered on every request, not only in the admin: REST, importers and cron are
	 * exactly the paths a UI-only guard would miss.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_insert_post_data', array( $this, 'filter_insert_post_data' ), 10, 2 );
		add_action( 'wp_insert_post', array( $this, 'end_insert' ), PHP_INT_MAX );
		add_action( 'woocommerce_before_product_object_save', array( $this, 'guard_product_object_save' ), 10, 1 );
		add_action( 'woocommerce_after_product_object_save', array( $this, 'end_product_object_save' ), PHP_INT_MAX, 1 );
		add_action( 'future_to_publish', array( $this, 'guard_scheduled_publish' ), 5, 1 );
	}

	/**
	 * Remove the three layers. Used by tests that swap the guard for one with known rules.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_filter( 'wp_insert_post_data', array( $this, 'filter_insert_post_data' ), 10 );
		remove_action( 'wp_insert_post', array( $this, 'end_insert' ), PHP_INT_MAX );
		remove_action( 'woocommerce_before_product_object_save', array( $this, 'guard_product_object_save' ), 10 );
		remove_action( 'woocommerce_after_product_object_save', array( $this, 'end_product_object_save' ), PHP_INT_MAX );
		remove_action( 'future_to_publish', array( $this, 'guard_scheduled_publish' ), 5 );
	}

	/**
	 * Layer A: refuse a publish before the post row is written.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data    Slashed, sanitized post row about to be written.
	 * @param mixed $postarr Slashed post array the row was built from.
	 * @return mixed The row, with `post_status` downgraded when publishing is refused.
	 */
	public function filter_insert_post_data( $data, $postarr = array() ) {
		if ( ! is_array( $data ) || ! is_array( $postarr ) ) {
			return $data;
		}

		try {
			return $this->guard_insert( $data, $postarr );
		} catch ( Throwable $error ) {
			self::log_failure( 'wp_insert_post_data', $error );

			return $data;
		}
	}

	/**
	 * Close Layer A's window for a post once `wp_insert_post()` has finished with it.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $post_id The post that was written.
	 * @return void
	 */
	public function end_insert( $post_id ): void {
		unset( self::$layer_a_writes[ (int) $post_id ] );
	}

	/**
	 * Layer B: refuse a publish on a WooCommerce CRUD save.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $product The product about to be saved.
	 * @return void
	 */
	public function guard_product_object_save( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		// Flag the write before anything can return, so Layer A stands down while the
		// data store writes the row ahead of the meta it would otherwise judge.
		$key = (int) $product->get_id();

		self::$crud_keys[ spl_object_id( $product ) ] = $key;
		self::$crud_saves[ $key ]                     = ( self::$crud_saves[ $key ] ?? 0 ) + 1;

		try {
			$this->guard_crud( $product );
		} catch ( Throwable $error ) {
			self::log_failure( 'woocommerce_before_product_object_save', $error );
		}
	}

	/**
	 * Clear Layer B's in-progress flag once the data store has written.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $product The product that was saved.
	 * @return void
	 */
	public function end_product_object_save( $product ): void {
		if ( ! is_object( $product ) ) {
			return;
		}

		$object_id = spl_object_id( $product );

		if ( ! isset( self::$crud_keys[ $object_id ] ) ) {
			return;
		}

		$key = self::$crud_keys[ $object_id ];

		unset( self::$crud_keys[ $object_id ] );

		if ( isset( self::$crud_saves[ $key ] ) && --self::$crud_saves[ $key ] <= 0 ) {
			unset( self::$crud_saves[ $key ] );
		}
	}

	/**
	 * Layer C: return a scheduled product to draft if it fails when its time comes.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $post The post that has just become `publish`.
	 * @return void
	 */
	public function guard_scheduled_publish( $post ): void {
		if ( self::$reverting || ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}

		try {
			$this->guard_scheduled( $post );
		} catch ( Throwable $error ) {
			self::log_failure( 'future_to_publish', $error );
		}
	}

	/**
	 * Whether a user may publish past failing required checks.
	 *
	 * True only when the merchant has allowed it **and** the user can manage WooCommerce.
	 * No capability is registered and no role is modified.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id The user.
	 * @return bool
	 */
	public function can_override( int $user_id ): bool {
		$can = $user_id > 0
			&& $this->settings->allows_admin_override()
			&& user_can( $user_id, 'manage_woocommerce' );

		/**
		 * Filters whether a user may publish a product that fails required checks.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $can     Whether the user may override the guard.
		 * @param int  $user_id The user.
		 */
		return (bool) apply_filters( 'sit_wcpg_can_override_publish_guard', $can, $user_id );
	}

	/**
	 * Whether this request is inside the enforcement scope (section 6.3.3).
	 *
	 * Cron and WP-CLI are always outside it, and so is any request with no user: an
	 * import or a system job is never silently demoted.
	 *
	 * @since 1.0.0
	 *
	 * @param Product_Context $context The product being saved.
	 * @param string          $source  Where the save came from.
	 * @return bool
	 */
	public function should_enforce( Product_Context $context, string $source ): bool {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || get_current_user_id() <= 0 ) {
			$enforce = false;
		} elseif ( Settings::SCOPE_EDITOR === $this->settings->enforcement_scope() ) {
			$enforce = in_array( $source, Save_Request_Reader::admin_sources(), true );
		} else {
			$enforce = true;
		}

		return $this->filter_enforce( $enforce, $context, $source );
	}

	/**
	 * The result of a publish refused in this request.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post.
	 * @return Validation_Result|null Null when this request refused nothing for it.
	 */
	public function get_refused_result( int $post_id ): ?Validation_Result {
		return $this->refused[ $post_id ] ?? null;
	}

	/**
	 * Layer A's decision, without the fail-open wrapper.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data    Slashed post row.
	 * @param array $postarr Slashed post array.
	 * @return array
	 */
	private function guard_insert( array $data, array $postarr ): array {
		// An absent status (Bulk Edit's "No change") is no transition at all.
		$status = isset( $data['post_status'] ) && is_string( $data['post_status'] ) ? $data['post_status'] : '';

		if ( 'product' !== ( $data['post_type'] ?? '' ) || ! in_array( $status, self::PUBLISH_STATUSES, true ) ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) && is_scalar( $postarr['ID'] ) ? absint( $postarr['ID'] ) : 0;

		if ( isset( self::$crud_saves[ $post_id ] ) ) {
			return $data;
		}

		$stored_status = $post_id > 0 ? (string) get_post_status( $post_id ) : '';

		if ( 'publish' === $stored_status || self::is_autosave_or_revision( $post_id ) || ! $this->is_active() ) {
			return $data;
		}

		// Whatever this layer decides, the backstop must not second-guess it for this write.
		self::$layer_a_writes[ $post_id ] = true;

		$stored    = $post_id > 0 ? wc_get_product( $post_id ) : null;
		$stored    = $stored instanceof WC_Product ? $stored : null;
		$overrides = Save_Request_Reader::read( $postarr, $data, $post_id );

		if ( null === $stored && ! isset( $overrides['product_type'] ) ) {
			// WooCommerce reads a product with no type term as simple.
			$overrides['product_type'] = 'simple';
		}

		$context = Product_Context::from_save_request( $stored, $overrides );

		if ( ! $this->should_enforce( $context, Save_Request_Reader::detect_source( $postarr, $post_id ) ) ) {
			return $data;
		}

		$result  = $this->checklist()->validate_context( $context );
		$user_id = get_current_user_id();

		if ( array() === $result->get_required_failures() ) {
			return $data;
		}

		if ( $this->can_override( $user_id ) ) {
			$this->notices->queue( $user_id, $post_id, $result, Notices::KIND_OVERRIDE );

			return $data;
		}

		$data['post_status'] = in_array( $stored_status, self::KEPT_STATUSES, true ) ? $stored_status : 'draft';

		$this->refuse( $user_id, $post_id, $result );

		return $data;
	}

	/**
	 * Layer B's decision, without the fail-open wrapper.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Product $product The product about to be saved.
	 * @return void
	 */
	private function guard_crud( WC_Product $product ): void {
		if ( 'variation' === $product->get_type() || ! in_array( $product->get_status( 'edit' ), self::PUBLISH_STATUSES, true ) ) {
			return;
		}

		$product_id    = (int) $product->get_id();
		$stored_status = $product_id > 0 ? (string) get_post_status( $product_id ) : '';

		if ( 'publish' === $stored_status || ! $this->is_active() ) {
			return;
		}

		$context = Product_Context::from_product_with_overrides( $product, self::pending_terms( $product ) );
		$source  = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ? Save_Request_Reader::SOURCE_REST : self::SOURCE_CRUD;

		if ( ! $this->should_enforce( $context, $source ) ) {
			return;
		}

		$result  = $this->checklist()->validate_context( $context );
		$user_id = get_current_user_id();

		if ( array() === $result->get_required_failures() ) {
			return;
		}

		if ( $this->can_override( $user_id ) ) {
			$this->notices->queue( $user_id, $product_id, $result, Notices::KIND_OVERRIDE );

			return;
		}

		$product->set_status( in_array( $stored_status, self::KEPT_STATUSES, true ) ? $stored_status : 'draft' );

		$this->refuse( $user_id, $product_id, $result );
	}

	/**
	 * Layer C's decision, without the fail-open wrapper.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post The post that has just become `publish`.
	 * @return void
	 */
	private function guard_scheduled( WP_Post $post ): void {
		$post_id = (int) $post->ID;

		// An edit that went through wp_insert_post() was judged by Layer A already.
		if ( isset( self::$layer_a_writes[ $post_id ] ) || ! $this->is_active() ) {
			return;
		}

		$product = wc_get_product( $post_id );

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$context = Product_Context::from_product( $product );

		// Cron is this layer's whole case, so the scope's cron exclusion does not apply.
		if ( ! $this->filter_enforce( true, $context, self::SOURCE_SCHEDULED ) ) {
			return;
		}

		$result = $this->checklist()->validate_context( $context );
		$author = (int) $post->post_author;

		if ( array() === $result->get_required_failures() ) {
			return;
		}

		if ( $this->can_override( $author ) ) {
			$this->notices->queue( $author, $post_id, $result, Notices::KIND_OVERRIDE );

			return;
		}

		self::$reverting = true;

		try {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			);
		} finally {
			self::$reverting = false;
		}

		$this->checklist()->flush_post( $post_id );
		$this->refuse( $author, $post_id, $result );
	}

	/**
	 * Record a refusal and tell the user.
	 *
	 * @since 1.0.0
	 *
	 * @param int               $user_id The user to tell; 0 tells nobody.
	 * @param int               $post_id The product.
	 * @param Validation_Result $result  Why it was refused.
	 * @return void
	 */
	private function refuse( int $user_id, int $post_id, Validation_Result $result ): void {
		$this->refused[ $post_id ] = $result;

		$this->notices->queue( $user_id, $post_id, $result, Notices::KIND_BLOCKED );
	}

	/**
	 * Category and tag ids a CRUD save is about to write.
	 *
	 * The data store writes terms after Layer B's hook, so the term cache is stale here.
	 * Terms the save does not touch keep coming from it; terms it does are taken from the
	 * object, with the default category WooCommerce substitutes for an empty list.
	 *
	 * @since 1.0.0
	 *
	 * @param WC_Product $product The product about to be saved.
	 * @return array Overrides for `Product_Context`.
	 */
	private static function pending_terms( WC_Product $product ): array {
		$changes   = $product->get_changes();
		$is_new    = 0 === (int) $product->get_id();
		$overrides = array();

		if ( $is_new || array_key_exists( 'category_ids', $changes ) ) {
			$categories = $product->get_category_ids( 'edit' );
			$default    = absint( get_option( 'default_product_cat', 0 ) );

			$overrides['category_ids'] = ( empty( $categories ) && $default > 0 ) ? array( $default ) : $categories;
		}

		if ( $is_new || array_key_exists( 'tag_ids', $changes ) ) {
			$overrides['tag_ids'] = $product->get_tag_ids( 'edit' );
		}

		return $overrides;
	}

	/**
	 * Apply the integrator filter to an enforcement decision.
	 *
	 * @since 1.0.0
	 *
	 * @param bool            $enforce Decision so far.
	 * @param Product_Context $context The product being saved.
	 * @param string          $source  Where the save came from.
	 * @return bool
	 */
	private function filter_enforce( bool $enforce, Product_Context $context, string $source ): bool {
		/**
		 * Filters whether the publishing guard applies to this save.
		 *
		 * @since 1.0.0
		 *
		 * @param bool            $enforce Whether to enforce.
		 * @param Product_Context $context The product as it is about to be saved.
		 * @param string          $source  `classic`, `quick_edit`, `bulk_edit`, `rest`,
		 *                                 `programmatic`, `crud` or `scheduled`.
		 */
		return (bool) apply_filters( 'sit_wcpg_should_enforce', $enforce, $context, $source );
	}

	/**
	 * Whether the plugin and its publishing block are both switched on.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function is_active(): bool {
		return $this->settings->is_enabled() && $this->settings->blocks_publishing();
	}

	/**
	 * Whether this write is an autosave or a revision, which never publishes anything.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id The post being written.
	 * @return bool
	 */
	private static function is_autosave_or_revision( int $post_id ): bool {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return true;
		}

		return $post_id > 0 && ( false !== wp_is_post_revision( $post_id ) || false !== wp_is_post_autosave( $post_id ) );
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

	/**
	 * Record an internal failure, after which the guard has let the save through.
	 *
	 * @since 1.0.0
	 *
	 * @param string    $layer Hook the failure happened on.
	 * @param Throwable $error The throwable.
	 * @return void
	 */
	private static function log_failure( string $layer, Throwable $error ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// Developer-facing diagnostics only; the merchant's save is never blocked by this.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'Product Publish Guard: %1$s failed open after %2$s: %3$s',
				$layer,
				get_class( $error ),
				$error->getMessage()
			)
		);
	}
}
