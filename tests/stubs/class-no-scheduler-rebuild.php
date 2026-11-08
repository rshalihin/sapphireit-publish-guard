<?php
/**
 * A rebuild on a site where Action Scheduler is missing.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Stubs;

use ProductPublishGuard\Support\Readiness_Rebuild;

/**
 * WooCommerce bundles Action Scheduler, so its absence can only be simulated.
 *
 * @since 1.2.0
 */
final class No_Scheduler_Rebuild extends Readiness_Rebuild {

	/**
	 * Action Scheduler is not there.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public static function scheduler_available(): bool {
		return false;
	}
}
