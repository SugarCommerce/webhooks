<?php
/**
 * What one webhook delivery attempt came to.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

/**
 * Class Attempt
 *
 * Whether it arrived, and enough to report and reschedule when it did not:
 * the receiver's status (0 when none came back), an error in words a seller
 * can read, and how long the receiver asked to be left — its Retry-After,
 * in seconds or as an HTTP date, believed in either form.
 *
 * @since 1.4.0
 */
final readonly class Attempt {

	/**
	 * @since 1.4.0
	 *
	 * @param bool     $sent        Whether the receiver accepted it (2xx).
	 * @param int      $status      The receiver's status, 0 for none.
	 * @param string   $error       Why it did not arrive, '' when it did.
	 * @param int|null $retry_after Seconds the receiver asked to wait, if it said.
	 */
	public function __construct(
		public bool $sent,
		public int $status,
		public string $error = '',
		public ?int $retry_after = null,
	) {}
}
