<?php
/**
 * The live-validation REST endpoint.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Rest;

use DateTimeImmutable;
use ProductPublishGuard\Plugin;
use ProductPublishGuard\Support\Checklist_Service;
use Throwable;
use WC_Product;
use WP_Error;
use WP_Http;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /sit-wcpg/v1/products/<id>/validate` — validate a product against an unsaved draft.
 *
 * The route is **read-only**: it computes a `Validation_Result` and returns it. It writes
 * nothing, fires no state-changing hook and returns no product content, so a crafted body
 * cannot be used to change anything (coding-plan.md section 9.8).
 *
 * It is a `POST` despite being a read because the draft snapshot — a whole description
 * among other things — belongs in a body, not in a query string that servers log.
 *
 * Every draft field is whitelisted, typed, and either coerced or rejected by this class
 * before it reaches `Product_Context` (section 9.3). Identifier lists are capped **before**
 * any per-item work, so a ten-thousand-element `category_ids` costs no more than a
 * hundred-element one.
 *
 * @since 1.0.0
 */
final class Validate_Controller {

	/**
	 * REST namespace.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const REST_NAMESPACE = 'sit-wcpg/v1';

	/**
	 * Route pattern, relative to the namespace.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const ROUTE = '/products/(?P<id>[\d]+)/validate';

	/**
	 * The draft payload's whitelist.
	 *
	 * `rule` names both the check applied in `validate_draft()` and the coercion applied in
	 * `sanitize_draft()`; `cap` bounds an identifier list. Any key absent from this map is
	 * dropped, which is what makes the sanitized output a whitelist rather than a filtered
	 * copy of the input.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private const DRAFT_FIELDS = array(
		'title'             => array( 'rule' => 'text_raw' ),
		'content'           => array( 'rule' => 'text_raw' ),
		'excerpt'           => array( 'rule' => 'text_raw' ),
		'product_type'      => array( 'rule' => 'product_type' ),
		'regular_price'     => array( 'rule' => 'price' ),
		'sale_price'        => array( 'rule' => 'price' ),
		'sale_from'         => array( 'rule' => 'date' ),
		'sale_to'           => array( 'rule' => 'date' ),
		'sku'               => array( 'rule' => 'sku' ),
		'stock_status'      => array( 'rule' => 'stock_status' ),
		'manage_stock'      => array( 'rule' => 'bool' ),
		'stock_quantity'    => array( 'rule' => 'nullable_int' ),
		'featured_image_id' => array( 'rule' => 'id' ),
		'gallery_image_ids' => array(
			'rule' => 'ids',
			'cap'  => 100,
		),
		'category_ids'      => array(
			'rule' => 'ids',
			'cap'  => 100,
		),
		'tag_ids'           => array(
			'rule' => 'ids',
			'cap'  => 200,
		),
	);

	/**
	 * The JSON-schema type published for each coercion rule.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private const SCHEMA_TYPES = array(
		'text_raw'     => 'string',
		'product_type' => 'string',
		'price'        => 'string',
		'date'         => 'string',
		'sku'          => 'string',
		'stock_status' => 'string',
		'bool'         => 'boolean',
		'nullable_int' => array( 'integer', 'null' ),
		'id'           => 'integer',
		'ids'          => 'array',
	);

	/**
	 * Accepted input formats for the two sale-window dates.
	 *
	 * The editor's datepicker writes `Y-m-d`; WooCommerce stores a full timestamp. Both are
	 * normalized to `Y-m-d H:i:s`, because `Sale_Price_Rule` compares the two dates as
	 * strings and that is only a date comparison while the format is fixed.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private const DATE_FORMATS = array( 'Y-m-d H:i:s', 'Y-m-d' );

	/**
	 * Result source, resolved on first use.
	 *
	 * @since 1.0.0
	 * @var Checklist_Service|null
	 */
	private ?Checklist_Service $checklist;

	/**
	 * Construct the controller.
	 *
	 * The checklist service is resolved lazily, so a REST request for somebody else's
	 * endpoint never builds the rule registry just because this route exists.
	 *
	 * @since 1.0.0
	 *
	 * @param Checklist_Service|null $checklist Result source. Null resolves the plugin's own.
	 */
	public function __construct( ?Checklist_Service $checklist = null ) {
		$this->checklist = $checklist;
	}

	/**
	 * Hook the route into the REST API.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'validate_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_args(),
				),
			)
		);
	}

	/**
	 * The route's argument schema.
	 *
	 * The draft is one object argument with its own callbacks rather than sixteen top-level
	 * arguments: the client sends one snapshot, and core does not run per-property callbacks
	 * inside a nested object, so the enforcement has to live in this class either way.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function get_args(): array {
		return array(
			'id'    => array(
				'description'       => __( 'Product identifier.', 'sapphireit-publish-guard' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ): bool {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
			'draft' => array(
				'description'          => __( 'Unsaved editor values. Only the keys present override the stored product.', 'sapphireit-publish-guard' ),
				'type'                 => 'object',
				'required'             => false,
				'default'              => array(),
				'properties'           => self::draft_properties(),
				'additionalProperties' => false,
				'validate_callback'    => array( $this, 'validate_draft' ),
				'sanitize_callback'    => array( $this, 'sanitize_draft' ),
			),
		);
	}

	/**
	 * The published JSON schema for each draft field.
	 *
	 * Documentation only: supplying `validate_callback` and `sanitize_callback` tells core
	 * to skip schema-driven handling, so `validate_draft()` and `sanitize_draft()` are what
	 * actually enforce this.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function draft_properties(): array {
		$properties = array();

		foreach ( self::DRAFT_FIELDS as $field => $spec ) {
			$property = array( 'type' => self::SCHEMA_TYPES[ $spec['rule'] ] );

			if ( isset( $spec['cap'] ) ) {
				$property['items']    = array( 'type' => 'integer' );
				$property['maxItems'] = $spec['cap'];
			}

			$properties[ $field ] = $property;
		}

		return $properties;
	}

	/**
	 * Authorize the request.
	 *
	 * The order is deliberate (section 9.10): a missing product is a 404 before the
	 * capability is consulted. That discloses only whether an id is a product, which the
	 * catalogue already discloses, and it keeps "this id is wrong" distinguishable from
	 * "you may not edit this" for the merchant who hits it.
	 *
	 * `edit_post` rather than `edit_products`, so per-post ownership and
	 * `edit_others_products` are both respected — a contributor must not be able to check a
	 * product it cannot edit.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public function permissions_check( WP_REST_Request $request ) {
		$product_id = absint( $request['id'] );

		if ( ! $this->get_product( $product_id ) instanceof WC_Product ) {
			return self::not_found();
		}

		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return new WP_Error(
				'sit_wcpg_forbidden',
				__( 'You are not allowed to check this product.', 'sapphireit-publish-guard' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Validate the product against the sanitized draft and return the result.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_item( WP_REST_Request $request ) {
		$product_id = absint( $request['id'] );
		$draft      = $request['draft'];
		$draft      = is_array( $draft ) ? $draft : array();

		try {
			$result = $this->checklist()->validate_post( $product_id, $draft );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'SapphireIT Publish Guard: REST validation failed - ' . $e->getMessage() );
			}

			return new WP_Error(
				'sit_wcpg_validation_failed',
				__( 'The checklist could not be calculated.', 'sapphireit-publish-guard' ),
				array( 'status' => WP_Http::INTERNAL_SERVER_ERROR )
			);
		}

		if ( null === $result ) {
			return self::not_found();
		}

		return rest_ensure_response( $result->to_array() );
	}

	/**
	 * Reject a draft that cannot be coerced without guessing.
	 *
	 * Enum and price fields are rejected outright, so a client bug surfaces as a 400 rather
	 * than as a silently different checklist. Unknown keys are **not** rejected — they are
	 * dropped in `sanitize_draft()`, so a newer client sending a field this version does not
	 * know about still gets a correct answer for the fields it does.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed           $value   The raw `draft` argument.
	 * @param WP_REST_Request $request The request.
	 * @param string          $param   The argument name.
	 * @return true|WP_Error
	 */
	public function validate_draft( $value, WP_REST_Request $request, string $param ) {
		if ( ! is_array( $value ) ) {
			return self::invalid( $param, __( 'Expected an object.', 'sapphireit-publish-guard' ) );
		}

		foreach ( self::DRAFT_FIELDS as $field => $spec ) {
			if ( ! array_key_exists( $field, $value ) ) {
				continue;
			}

			$checked = self::check_field( $param . '.' . $field, $spec['rule'], $value[ $field ] );

			if ( $checked instanceof WP_Error ) {
				return $checked;
			}
		}

		return true;
	}

	/**
	 * Check one raw draft field.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name  Dotted argument name, for the error message.
	 * @param string $rule  Coercion rule from the whitelist.
	 * @param mixed  $value Raw value.
	 * @return true|WP_Error
	 */
	private static function check_field( string $name, string $rule, $value ) {
		if ( 'ids' === $rule ) {
			return ( is_array( $value ) || is_string( $value ) )
				? true
				: self::invalid( $name, __( 'Expected a list of identifiers.', 'sapphireit-publish-guard' ) );
		}

		if ( 'nullable_int' === $rule ) {
			return ( null === $value || is_scalar( $value ) )
				? true
				: self::invalid( $name, __( 'Expected a number or null.', 'sapphireit-publish-guard' ) );
		}

		if ( ! is_scalar( $value ) ) {
			return self::invalid( $name, __( 'Expected a single value.', 'sapphireit-publish-guard' ) );
		}

		if ( 'product_type' === $rule && ! in_array( (string) $value, self::product_types(), true ) ) {
			return self::invalid( $name, __( 'Not one of the registered product types.', 'sapphireit-publish-guard' ) );
		}

		if ( 'stock_status' === $rule && ! in_array( (string) $value, self::stock_statuses(), true ) ) {
			return self::invalid( $name, __( 'Not one of the registered stock statuses.', 'sapphireit-publish-guard' ) );
		}

		if ( 'price' === $rule ) {
			$normalized = self::normalize_price( $value );

			if ( '' !== $normalized && ! is_numeric( $normalized ) ) {
				return self::invalid( $name, __( 'Expected a price.', 'sapphireit-publish-guard' ) );
			}
		}

		return true;
	}

	/**
	 * Build the whitelisted override array handed to `Product_Context`.
	 *
	 * The output is constructed key by key from the whitelist and never filtered from the
	 * input, so an unknown key cannot survive whatever it is called.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw `draft` argument.
	 * @return array
	 */
	public function sanitize_draft( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$draft = array();

		foreach ( self::DRAFT_FIELDS as $field => $spec ) {
			if ( ! array_key_exists( $field, $value ) ) {
				continue;
			}

			$draft[ $field ] = self::coerce( $spec, $value[ $field ] );
		}

		return $draft;
	}

	/**
	 * Coerce one whitelisted draft field.
	 *
	 * @since 1.0.0
	 *
	 * @param array $spec  Whitelist entry.
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private static function coerce( array $spec, $value ) {
		switch ( $spec['rule'] ) {
			case 'text_raw':
				/*
				 * Deliberately unsanitized. These three fields are reduced to a character
				 * count by the length rules and are never stored, echoed or logged
				 * (section 9.3), so a sanitizer here would only corrupt the count it is
				 * meant to protect. Section 9.5 is what keeps them out of every message.
				 */
				return is_scalar( $value ) ? (string) $value : '';
			case 'sku':
				return sanitize_text_field( (string) $value );
			case 'product_type':
			case 'stock_status':
				return sanitize_key( (string) $value );
			case 'price':
				return self::normalize_price( $value );
			case 'date':
				return self::normalize_date( $value );
			case 'bool':
				return rest_sanitize_boolean( $value );
			case 'nullable_int':
				return ( null === $value || '' === $value ) ? null : (int) $value;
			case 'id':
				// Core writes -1 into the featured-image input when there is no image.
				return max( 0, (int) $value );
			case 'ids':
				return self::normalize_ids( $value, (int) $spec['cap'] );
			default:
				return '';
		}
	}

	/**
	 * Cap, then parse, a list of identifiers.
	 *
	 * The cap is applied to the raw list **before** any per-item work, so an oversized
	 * payload costs no more than a legitimate one (section 9.9).
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw list, either an array or a comma-separated string.
	 * @param int   $cap   Maximum number of entries to consider.
	 * @return array
	 */
	private static function normalize_ids( $value, int $cap ): array {
		if ( is_string( $value ) ) {
			$value = '' === $value ? array() : explode( ',', $value, $cap + 1 );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();

		foreach ( array_slice( $value, 0, $cap ) as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}

			$id = absint( $id );

			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Normalize a price to WooCommerce's decimal string.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function normalize_price( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		return function_exists( 'wc_format_decimal' ) ? (string) wc_format_decimal( $value ) : $value;
	}

	/**
	 * Normalize a sale-window date to `Y-m-d H:i:s`.
	 *
	 * Strict by format rather than `strtotime()`, which reads almost any string as some
	 * date. An unparseable value becomes an empty string — the same thing a cleared
	 * datepicker sends — rather than a 400, so a half-typed date never breaks the panel.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function normalize_date( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		foreach ( self::DATE_FORMATS as $format ) {
			// The leading `!` zeroes every field the format does not set, so a bare date
			// becomes midnight rather than the time this request happened to run.
			$date = DateTimeImmutable::createFromFormat( '!' . $format, $value );

			if ( $date instanceof DateTimeImmutable && $date->format( $format ) === $value ) {
				return $date->format( 'Y-m-d H:i:s' );
			}
		}

		return '';
	}

	/**
	 * The product types this store has registered.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function product_types(): array {
		return function_exists( 'wc_get_product_types' )
			? array_map( 'strval', array_keys( wc_get_product_types() ) )
			: array();
	}

	/**
	 * The stock statuses this store has registered.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function stock_statuses(): array {
		if ( function_exists( 'wc_get_product_stock_status_options' ) ) {
			return array_map( 'strval', array_keys( wc_get_product_stock_status_options() ) );
		}

		return array( 'instock', 'outofstock', 'onbackorder' );
	}

	/**
	 * The 404 for an id that is not a product.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error(
			'sit_wcpg_not_found',
			__( 'No product was found with that identifier.', 'sapphireit-publish-guard' ),
			array( 'status' => WP_Http::NOT_FOUND )
		);
	}

	/**
	 * A 400 for one named argument.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name   Dotted argument name.
	 * @param string $reason Translated reason, which never contains request data.
	 * @return WP_Error
	 */
	private static function invalid( string $name, string $reason ): WP_Error {
		return new WP_Error(
			'rest_invalid_param',
			sprintf(
				/* translators: 1: parameter name, 2: reason the value was rejected. */
				__( 'Invalid parameter: %1$s. %2$s', 'sapphireit-publish-guard' ),
				$name,
				$reason
			),
			array(
				'status' => WP_Http::BAD_REQUEST,
				'params' => array( $name ),
			)
		);
	}

	/**
	 * Resolve a post id to a product.
	 *
	 * @since 1.0.0
	 *
	 * @param int $product_id Product post identifier.
	 * @return WC_Product|null
	 */
	private function get_product( int $product_id ): ?WC_Product {
		if ( 0 === $product_id || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * Get the checklist service.
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
