<?php
/**
 * How each webhook endpoint has been answering.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

/**
 * Class EndpointHealth
 *
 * Failures in a row, per endpoint, and the moment there have been enough
 * of them to stop.
 *
 * An endpoint that has gone -- a server switched off, a domain that
 * lapsed -- fails every delivery, and every retry of every delivery, for
 * as long as the site runs. Each one is a request that waits for a
 * timeout, and a queue that never empties; posting for ever to a host that
 * has been gone since spring is also how a sender ends up on a blocklist.
 * So an endpoint is switched off once it has failed `$limit` times in a
 * row **and** has been failing for at least `$period` seconds. Both,
 * because either alone is wrong: failures are counted per delivery, so
 * twenty queued events meeting one busy minute of a restart are twenty
 * failures in a minute, and a day can hold only two failures for a site
 * that sells twice a day.
 *
 * A success, at any point, starts the count again. Nothing here decides
 * what switching off means or who is told -- {@see Breaker} does, with
 * what failed() returns.
 *
 * The record is kept wherever the plugin likes: one option with
 * {@see self::option()}, or a `read`/`write` pair over anything else. It
 * is read and written per failure, so two deliveries failing at the same
 * moment can each write over the other's count, which undercounts by one
 * -- in the direction of switching off later, never sooner, and a dead
 * endpoint fails again soon enough to catch up.
 *
 * @since 1.0.0
 */
final class EndpointHealth {

	/**
	 * Reads every record: fn(): mixed.
	 *
	 * @var callable
	 */
	private $read;

	/**
	 * Writes them back: fn( array $records ): void.
	 *
	 * @var callable
	 */
	private $write;

	/**
	 * Watch endpoints, keeping the record through two callables.
	 *
	 * @since 1.0.0
	 *
	 * @param callable $read   fn(): mixed, id => record.
	 * @param callable $write  fn( array $records ): void.
	 * @param int      $limit  Failures in a row that can switch one off; 0
	 *                         never does.
	 * @param int      $period Seconds it must have been failing for, too.
	 */
	public function __construct(
		callable $read,
		callable $write,
		private int $limit,
		private int $period,
	) {
		$this->read  = $read;
		$this->write = $write;
	}

	/**
	 * Watch endpoints, keeping the record in an option of its own.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name   The option.
	 * @param int    $limit  Failures in a row; 0 never switches off.
	 * @param int    $period Seconds it must have been failing for, too.
	 *
	 * @return self
	 */
	public static function option( string $name, int $limit, int $period ): self {
		return new self(
			static fn() => get_option( $name, [] ),
			static fn( array $records ) => update_option( $name, $records, false ),
			$limit,
			$period
		);
	}

	/**
	 * Count a failure.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id     The endpoint.
	 * @param int    $status The HTTP status, or 0 when nothing answered.
	 * @param string $error  What went wrong, when nothing answered.
	 * @param int    $now    When.
	 *
	 * @return array<string, mixed>|null The record, when this failure is
	 *                                   the one that switches it off; null
	 *                                   otherwise, and for one already off.
	 */
	public function failed( string $id, int $status, string $error, int $now ): ?array {
		$all    = $this->all();
		$record = $all[ $id ] ?? [];

		$record = [
			'failures' => (int) ( $record['failures'] ?? 0 ) + 1,
			'since'    => (int) ( $record['since'] ?? $now ),
			'last'     => $now,
			'status'   => $status,
			'error'    => $error,
			'off_at'   => (int) ( $record['off_at'] ?? 0 ),
		];

		$newly = 0 === $record['off_at']
			&& $this->limit > 0
			&& $record['failures'] >= $this->limit
			&& $now - $record['since'] >= $this->period;

		if ( $newly ) {
			$record['off_at'] = $now;
		}

		$all[ $id ] = $record;

		( $this->write )( $all );

		return $newly ? $record : null;
	}

	/**
	 * Forget an endpoint's failures: it answered, or somebody switched it
	 * back on.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id The endpoint.
	 *
	 * @return void
	 */
	public function forget( string $id ): void {
		$all = $this->all();

		if ( ! isset( $all[ $id ] ) ) {
			return;
		}

		unset( $all[ $id ] );

		( $this->write )( $all );
	}

	/**
	 * An endpoint's record.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id The endpoint.
	 *
	 * @return array<string, mixed>|null `failures`, `since`, `last`,
	 *                                   `status`, `error` and `off_at` (0
	 *                                   while it is still on); null when it
	 *                                   has not failed since it last
	 *                                   answered.
	 */
	public function get( string $id ): ?array {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * The endpoints switched off, and why.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> Id => record.
	 */
	public function switched_off(): array {
		return array_filter( $this->all(), static fn( array $record ): bool => (int) ( $record['off_at'] ?? 0 ) > 0 );
	}

	/**
	 * Every record.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function all(): array {
		$all = ( $this->read )();

		return is_array( $all ) ? array_filter( $all, 'is_array' ) : [];
	}
}
