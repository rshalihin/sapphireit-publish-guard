<?php
/**
 * The `wp sit-wcpg` commands.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Run the product publish gate from the command line.
 *
 * ## EXAMPLES
 *
 *     # List published products that fail a required check.
 *     $ wp sit-wcpg check --status=publish --fail-only
 *
 *     # Update the products list filter without Action Scheduler.
 *     $ wp sit-wcpg reindex
 *
 * @since 1.2.0
 */
final class Gate_Command {

	/**
	 * The name the commands are registered under.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const COMMAND = 'sit-wcpg';

	/**
	 * What the commands actually do.
	 *
	 * @since 1.2.0
	 * @var Gate_Runner
	 */
	private Gate_Runner $runner;

	/**
	 * Construct the commands.
	 *
	 * @since 1.2.0
	 *
	 * @param Gate_Runner $runner What the commands actually do.
	 */
	public function __construct( Gate_Runner $runner ) {
		$this->runner = $runner;
	}

	/**
	 * Check products against the required checks. Changes nothing.
	 *
	 * Output holds product ids, statuses and rule ids, never product text.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Product ids to check. Without any, the whole catalog is checked.
	 *
	 * [--status=<status>]
	 * : Only walk products with this post status. Ignored when ids are given.
	 * ---
	 * default: any
	 * options:
	 *   - any
	 *   - publish
	 *   - draft
	 *   - pending
	 *   - future
	 *   - private
	 * ---
	 *
	 * [--fail-only]
	 * : List only the products that fail a required check.
	 *
	 * [--format=<format>]
	 * : How to print the result.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - count
	 *   - ids
	 * ---
	 *
	 * [--strict]
	 * : Exit with status 1 when any product fails a required check.
	 *
	 * ## EXAMPLES
	 *
	 *     # List published products that fail a required check.
	 *     $ wp sit-wcpg check --status=publish --fail-only
	 *
	 *     # Print the whole catalog as JSON.
	 *     $ wp sit-wcpg check --format=json
	 *
	 *     # Fail a deployment when any product would be refused.
	 *     $ wp sit-wcpg check --status=publish --strict --format=count
	 *
	 * @since 1.2.0
	 *
	 * @param array $args       Product ids.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function check( $args, $assoc_args ): void {
		$this->require_checklist();

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$rows   = iterator_to_array(
			$this->runner->check(
				array_map( 'intval', (array) $args ),
				(string) ( $assoc_args['status'] ?? 'any' ),
				(bool) \WP_CLI\Utils\get_flag_value( (array) $assoc_args, 'fail-only', false )
			),
			false
		);

		\WP_CLI\Utils\format_items(
			$format,
			'ids' === $format ? array_column( $rows, 'id' ) : $rows,
			array( 'id', 'status', 'required_failures', 'warnings' )
		);

		$summary = $this->runner->summary();

		if ( 'table' === $format ) {
			\WP_CLI::log(
				sprintf(
					/* translators: 1: products checked, 2: products that would fail, 3: products with warnings. */
					__( 'Checked %1$d products: %2$d would fail, %3$d have warnings.', 'sapphireit-publish-guard' ),
					$summary['checked'],
					$summary['failed'],
					$summary['warnings']
				)
			);
		}

		if ( $summary['failed'] > 0 && \WP_CLI\Utils\get_flag_value( (array) $assoc_args, 'strict', false ) ) {
			\WP_CLI::error(
				sprintf(
					/* translators: %d: number of products. */
					__( '%d products fail a required check.', 'sapphireit-publish-guard' ),
					$summary['failed']
				)
			);
		}
	}

	/**
	 * Recheck every product now and update the products list filter.
	 *
	 * Runs in this process, so it works without Action Scheduler.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp sit-wcpg reindex
	 *
	 * @since 1.2.0
	 *
	 * @param array $args       Unused.
	 * @param array $assoc_args Unused.
	 * @return void
	 */
	public function reindex( $args, $assoc_args ): void {
		unset( $args, $assoc_args );

		$this->require_checklist();

		$bar   = \WP_CLI\Utils\make_progress_bar( __( 'Rechecking products', 'sapphireit-publish-guard' ), Gate_Runner::catalog_size() );
		$count = $this->runner->reindex(
			static function () use ( $bar ): void {
				$bar->tick();
			}
		);

		$bar->finish();

		\WP_CLI::success(
			sprintf(
				/* translators: %d: number of products. */
				__( 'Rechecked %d products against the current settings.', 'sapphireit-publish-guard' ),
				$count
			)
		);
	}

	/**
	 * Stop when the checklist is switched off.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function require_checklist(): void {
		if ( ! $this->runner->is_checklist_enabled() ) {
			\WP_CLI::error( __( 'The product checklist is switched off. Turn it on before running this command.', 'sapphireit-publish-guard' ) );
		}
	}
}
