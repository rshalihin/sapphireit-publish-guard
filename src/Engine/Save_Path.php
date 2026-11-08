<?php
/**
 * How a product is being saved.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * The canonical names of the routes a product save can arrive through.
 *
 * These are the strings `sit_wcpg_should_enforce` has received since 1.0.0, so a rule that
 * branches on `Product_Context::get_save_path()` and a filter that branches on `$source`
 * speak the same language.
 *
 * @since 1.2.0
 * @api
 */
final class Save_Path {

	/**
	 * The classic product editor (`post.php`).
	 *
	 * @since 1.2.0
	 * @api
	 * @var string
	 */
	public const CLASSIC = 'classic';

	/**
	 * Quick Edit on the products list.
	 *
	 * @since 1.2.0
	 * @api
	 * @var string
	 */
	public const QUICK_EDIT = 'quick_edit';

	/**
	 * Bulk Edit on the products list.
	 *
	 * @since 1.2.0
	 * @api
	 * @var string
	 */
	public const BULK_EDIT = 'bulk_edit';

	/**
	 * A REST request.
	 *
	 * @since 1.2.0
	 * @api
	 * @var string
	 */
	public const REST = 'rest';

	/**
	 * A `wp_insert_post()` or `wp_update_post()` call from other code.
	 *
	 * @since 1.2.0
	 * @api
	 * @var string
	 */
	public const PROGRAMMATIC = 'programmatic';

	/**
	 * A WooCommerce CRUD write outside a REST request.
	 *
	 * @since 1.2.0
	 * @api
	 * @var string
	 */
	public const CRUD = 'crud';

	/**
	 * A scheduled product coming due.
	 *
	 * @since 1.2.0
	 * @api
	 * @var string
	 */
	public const SCHEDULED = 'scheduled';

	/**
	 * A WP-CLI command.
	 *
	 * @since 1.2.0
	 * @api
	 * @var string
	 */
	public const CLI = 'cli';

	/**
	 * No save in progress: a checklist read, or a context nobody stamped.
	 *
	 * @since 1.2.0
	 * @api
	 * @var string
	 */
	public const NONE = 'none';

	/**
	 * Not instantiable.
	 *
	 * @since 1.2.0
	 */
	private function __construct() {}

	/**
	 * Every path, in documented order.
	 *
	 * @since 1.2.0
	 * @api
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::CLASSIC,
			self::QUICK_EDIT,
			self::BULK_EDIT,
			self::REST,
			self::PROGRAMMATIC,
			self::CRUD,
			self::SCHEDULED,
			self::CLI,
			self::NONE,
		);
	}

	/**
	 * Whether a value is one of the paths.
	 *
	 * @since 1.2.0
	 * @api
	 *
	 * @param string $path Candidate path.
	 * @return bool
	 */
	public static function is_valid( string $path ): bool {
		return in_array( $path, self::all(), true );
	}

	/**
	 * The admin form paths, which are what the `editor` enforcement scope covers.
	 *
	 * @since 1.2.0
	 * @api
	 *
	 * @return string[]
	 */
	public static function admin_paths(): array {
		return array( self::CLASSIC, self::QUICK_EDIT, self::BULK_EDIT );
	}
}
