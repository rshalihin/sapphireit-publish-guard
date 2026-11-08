<?php
/**
 * The REST route that records an answer to the review request.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Rest;

use ProductPublishGuard\Admin\Review_Notice;
use ProductPublishGuard\Settings\Settings_Page;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /sit-wcpg/v1/review-notice`, with `choice` one of `review`, `later` or `never`.
 *
 * The plugin's only state-changing route, and it can change nothing but the notice's own
 * option. Core's REST cookie authentication supplies the nonce check.
 *
 * @since 1.2.0
 */
final class Review_Notice_Controller {

	/**
	 * Route, relative to the namespace.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const ROUTE = '/review-notice';

	/**
	 * Hook the route in.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Validate_Controller::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'record' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'choice' => array(
						'type'              => 'string',
						'enum'              => Review_Notice::CHOICES,
						'required'          => true,
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
	}

	/**
	 * Only someone who can see the notice can answer it.
	 *
	 * @since 1.2.0
	 *
	 * @return true|WP_Error
	 */
	public function permissions_check() {
		if ( current_user_can( Settings_Page::CAPABILITY ) ) {
			return true;
		}

		return new WP_Error(
			'sit_wcpg_forbidden',
			__( 'You are not allowed to answer this request.', 'sapphireit-publish-guard' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Store the answer.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_REST_Request $request The validated request.
	 * @return WP_REST_Response
	 */
	public function record( WP_REST_Request $request ): WP_REST_Response {
		$state = ( new Review_Notice() )->record_choice( (string) $request->get_param( 'choice' ) );

		return new WP_REST_Response(
			array(
				'done'      => $state['done'],
				'ask_after' => $state['ask_after'],
			),
			200
		);
	}
}
