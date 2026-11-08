<?php
/**
 * The notice that asks for a WordPress.org review.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Admin;

use ProductPublishGuard\Settings\Settings_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Asks once, after thirty days, and only after the gate has actually stopped something.
 *
 * The clock starts on the first visit to one of the plugin's own screens rather than on
 * activation, because an update from an earlier version never fires the activation hook.
 * A refusal can happen over REST or from cron, so that listener is on every request. The
 * answer is stored per site: one store, one review.
 *
 * A choice is recorded through `Rest\Review_Notice_Controller`, so the notice adds no
 * admin-post or admin-ajax handler.
 *
 * @since 1.2.0
 */
final class Review_Notice {

	/**
	 * Option holding when to ask and whether to stop. Not autoloaded.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const OPTION = 'sit_wcpg_review_notice';

	/**
	 * Where "Leave a review" goes.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public const REVIEW_URL = Settings_Footer::REVIEW_URL;

	/**
	 * How long after first use to ask.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const FIRST_DELAY = 30 * DAY_IN_SECONDS;

	/**
	 * How long "Maybe later" waits.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public const SNOOZE = 14 * DAY_IN_SECONDS;

	/**
	 * Choice: the merchant went to leave a review.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const CHOICE_REVIEW = 'review';

	/**
	 * Choice: ask again later.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const CHOICE_LATER = 'later';

	/**
	 * Choice: never ask again.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const CHOICE_NEVER = 'never';

	/**
	 * Every accepted choice.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	public const CHOICES = array( self::CHOICE_REVIEW, self::CHOICE_LATER, self::CHOICE_NEVER );

	/**
	 * Register hooks.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'sit_wcpg_publish_blocked', array( $this, 'record_block' ), 10, 0 );

		if ( is_admin() ) {
			add_action( 'current_screen', array( $this, 'start_clock' ), 10, 1 );
			add_action( 'admin_notices', array( $this, 'render' ), 10, 0 );
		}
	}

	/**
	 * Note the first time the gate stopped a publish. Writes once, ever.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function record_block(): void {
		$state = self::state();

		if ( 0 === $state['blocked_at'] ) {
			$state['blocked_at'] = time();

			self::save( $state );
		}
	}

	/**
	 * Start the clock on the first visit to one of the listed screens.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $screen The screen, already the current one when `current_screen` fires.
	 * @return void
	 */
	public function start_clock( $screen ): void {
		unset( $screen );

		if ( ! self::is_listed_screen() ) {
			return;
		}

		$state = self::state();

		if ( 0 === $state['first_seen'] ) {
			$state['first_seen'] = time();

			self::save( $state );
		}
	}

	/**
	 * Whether this is a screen the notice can appear on.
	 *
	 * @since 1.2.0
	 *
	 * @param string $hook_suffix Hook suffix from `admin_enqueue_scripts`, or '' to ask the screen.
	 * @return bool
	 */
	public static function is_listed_screen( string $hook_suffix = '' ): bool {
		return Screen::is_settings_screen( $hook_suffix )
			|| Screen::is_product_list_screen( $hook_suffix )
			|| Screen::is_catalog_screen( $hook_suffix );
	}

	/**
	 * The stored state, normalized.
	 *
	 * @since 1.2.0
	 *
	 * @return array{first_seen: int, blocked_at: int, ask_after: int, done: bool}
	 */
	public static function state(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'first_seen' => self::timestamp( $stored['first_seen'] ?? 0 ),
			'blocked_at' => self::timestamp( $stored['blocked_at'] ?? 0 ),
			'ask_after'  => self::timestamp( $stored['ask_after'] ?? 0 ),
			'done'       => ! empty( $stored['done'] ),
		);
	}

	/**
	 * Whether it is time to ask.
	 *
	 * @since 1.2.0
	 *
	 * @param int|null $now Unix time. Null means now.
	 * @return bool
	 */
	public static function is_due( ?int $now = null ): bool {
		$now   = $now ?? time();
		$state = self::state();

		return ! $state['done']
			&& $state['blocked_at'] > 0
			&& $state['first_seen'] > 0
			&& $now >= $state['first_seen'] + self::FIRST_DELAY
			&& $now >= $state['ask_after'];
	}

	/**
	 * Store the merchant's answer.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $choice One of `CHOICES`. Anything else changes nothing.
	 * @param int|null $now    Unix time. Null means now.
	 * @return array{first_seen: int, blocked_at: int, ask_after: int, done: bool} The new state.
	 */
	public function record_choice( string $choice, ?int $now = null ): array {
		$state = self::state();

		switch ( $choice ) {
			case self::CHOICE_REVIEW:
			case self::CHOICE_NEVER:
				$state['done'] = true;
				break;

			case self::CHOICE_LATER:
				$state['ask_after'] = ( $now ?? time() ) + self::SNOOZE;
				break;

			default:
				return $state;
		}

		self::save( $state );

		return $state;
	}

	/**
	 * Print the notice on the listed screens once it is due.
	 *
	 * Not `is-dismissible`: core's X only hides a notice until the next page load, which
	 * would make it look broken. "Never ask again" is the permanent dismissal.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! self::is_listed_screen() || ! current_user_can( Settings_Page::CAPABILITY ) || ! self::is_due() ) {
			return;
		}

		printf(
			'<div class="notice notice-info sit-wcpg-brand-notice sit-wcpg-review-notice" data-sit-wcpg-review>'
				. '<img class="sit-wcpg-brand-notice__logo" src="%1$s" width="64" height="64" alt="" />'
				. '<div class="sit-wcpg-brand-notice__body">'
				. '<p>%2$s</p>'
				. '<p class="sit-wcpg-brand-notice__actions">'
				. '<a class="button button-primary" href="%3$s" target="_blank" rel="noopener noreferrer" data-choice="%4$s">%5$s</a>'
				. '<button type="button" class="button-link" data-choice="%6$s">%7$s</button>'
				. '<button type="button" class="button-link" data-choice="%8$s">%9$s</button>'
				. '</p></div></div>',
			esc_url( SIT_WCPG_URL . Activation_Notice::LOGO ),
			esc_html__( 'Publish Guard has been stopping incomplete products from going live on this store. If it has helped, a short review on WordPress.org helps other store owners find it.', 'sapphireit-publish-guard' ),
			esc_url( self::REVIEW_URL ),
			esc_attr( self::CHOICE_REVIEW ),
			esc_html__( 'Leave a review', 'sapphireit-publish-guard' ),
			esc_attr( self::CHOICE_LATER ),
			esc_html__( 'Maybe later', 'sapphireit-publish-guard' ),
			esc_attr( self::CHOICE_NEVER ),
			esc_html__( 'Never ask again', 'sapphireit-publish-guard' )
		);
	}

	/**
	 * A stored time as a non-negative integer.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $value Stored value.
	 * @return int
	 */
	private static function timestamp( $value ): int {
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Write the state. Not autoloaded: only three screens and a refusal ever read it.
	 *
	 * @since 1.2.0
	 *
	 * @param array $state Normalized state.
	 * @return void
	 */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}
}
