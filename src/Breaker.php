<?php
/**
 * Switching off a webhook endpoint that has stopped answering.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

use SugarCommerce\Dates\Clock;

/**
 * Class Breaker
 *
 * The site's side of {@see EndpointHealth}: how many failures over how
 * long, what switching off does, and how an endpoint comes back. Made by
 * {@see Dispatcher::breaker()}.
 *
 * Switching off is the endpoint's own Active toggle turned off, so the
 * settings screen shows it off and the dispatcher stops queueing for it --
 * there is no second idea of "off" for anything to disagree with. Why and
 * when are kept in the health record beside it, for a notice, for Site
 * Health and for whoever listens to `{prefix}_webhook_switched_off`.
 *
 * It comes back two ways. Somebody turns Active on again and saves; or
 * somebody sends it a test ({@see Dispatcher::test()}) and the test is
 * answered, which turns Active on for them. Either way the failures are
 * forgotten, so it does not go straight back off on the next one.
 *
 * @since 1.0.0
 */
final class Breaker {

	/**
	 * Failures in a row before an endpoint can be switched off.
	 *
	 * @since 1.0.0
	 */
	public const int LIMIT = 15;

	/**
	 * How long it must have been failing for as well.
	 *
	 * A day: long enough that a server being moved or restarted overnight
	 * is not switched off, short enough that a dead one is not posted to
	 * for a week.
	 *
	 * @since 1.0.0
	 */
	public const int PERIOD = 86400;

	/**
	 * Where the record of failures is kept: an option name, or
	 * `read`/`write` callables.
	 *
	 * @var string|array{read: callable, write: callable}
	 */
	private string|array $store;

	/**
	 * @since 1.0.0
	 *
	 * @param Endpoints    $endpoints Where the Active toggle is.
	 * @param string       $prefix    Hooks are named from it.
	 * @param string|array $store     Where the record of failures is kept:
	 *                                an option, or `read`/`write`
	 *                                callables.
	 * @param int          $limit     Failures in a row; 0 never switches
	 *                                off.
	 * @param int          $period    Seconds it must have been failing for.
	 *
	 * @throws \InvalidArgumentException When the store is neither.
	 */
	public function __construct(
		private Endpoints $endpoints,
		private string $prefix,
		string|array $store,
		private int $limit = self::LIMIT,
		private int $period = self::PERIOD,
	) {
		if ( is_array( $store ) && ! ( is_callable( $store['read'] ?? null ) && is_callable( $store['write'] ?? null ) ) ) {
			throw new \InvalidArgumentException( 'A health store needs both a read and a write callable.' );
		}

		$this->store = $store;
	}

	/**
	 * The record, with the limit and period as filtered now.
	 *
	 * @since 1.0.0
	 *
	 * @return EndpointHealth
	 */
	public function health(): EndpointHealth {
		/**
		 * Filter how many outgoing webhooks in a row an endpoint may fail
		 * before it is switched off.
		 *
		 * Named `{prefix}_webhook_failure_limit`. It must also have been
		 * failing for the period. Return 0 never to switch one off.
		 *
		 * @param int $limit Fifteen, unless configured.
		 */
		$limit = max( 0, (int) apply_filters( $this->prefix . '_webhook_failure_limit', $this->limit ) );

		/**
		 * Filter how long an endpoint must have been failing, from its
		 * first failure in a row to its latest, before it is switched off.
		 *
		 * Named `{prefix}_webhook_failure_period`.
		 *
		 * @param int $period Seconds: a day, unless configured.
		 */
		$period = max( 0, (int) apply_filters( $this->prefix . '_webhook_failure_period', $this->period ) );

		return is_string( $this->store )
			? EndpointHealth::option( $this->store, $limit, $period )
			: new EndpointHealth( $this->store['read'], $this->store['write'], $limit, $period );
	}

	/**
	 * A delivery was not accepted.
	 *
	 * Every failed attempt counts, retries included: an endpoint that has
	 * gone fails all of them.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $endpoint The endpoint.
	 * @param int                  $status   The HTTP status, or 0.
	 * @param string               $error    What went wrong, when nothing
	 *                                       answered.
	 *
	 * @return bool Whether this switched it off.
	 */
	public function failed( array $endpoint, int $status, string $error = '' ): bool {
		$id     = (string) ( $endpoint['id'] ?? '' );
		$record = '' === $id ? null : $this->health()->failed( $id, $status, $error, Clock::time() );

		if ( null === $record ) {
			return false;
		}

		$this->endpoints->set_active( $id, false );

		/**
		 * Fires when an outgoing webhook endpoint is switched off for failing.
		 *
		 * Named `{prefix}_webhook_switched_off`.
		 *
		 * @param array<string, mixed> $endpoint The endpoint.
		 * @param array<string, mixed> $record   `failures`, `since`, `last`,
		 *                                       `status`, `error`, `off_at`.
		 */
		do_action( $this->prefix . '_webhook_switched_off', $endpoint, $record );

		return true;
	}

	/**
	 * A delivery was accepted.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id The endpoint.
	 *
	 * @return void
	 */
	public function succeeded( string $id ): void {
		$this->health()->forget( $id );
	}

	/**
	 * A test was answered: switch it back on, if it was off.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id The endpoint.
	 *
	 * @return void
	 */
	public function recovered( string $id ): void {
		$was_off = null !== $this->off( $id );

		$this->health()->forget( $id );

		if ( $was_off ) {
			$this->endpoints->set_active( $id, true );
		}
	}

	/**
	 * Why and when an endpoint was switched off.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id The endpoint.
	 *
	 * @return array<string, mixed>|null Null when it was not.
	 */
	public function off( string $id ): ?array {
		$record = $this->health()->get( $id );

		return null !== $record && (int) ( $record['off_at'] ?? 0 ) > 0 ? $record : null;
	}

	/**
	 * The endpoints switched off for failing, with their addresses.
	 *
	 * Only the ones still off: one turned back on by hand is forgotten when
	 * the settings are saved.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>> Each the record, with `id`
	 *                                          and `url`.
	 */
	public function switched_off(): array {
		$off = $this->health()->switched_off();
		$out = [];

		foreach ( $this->endpoints->all() as $row ) {
			$id = (string) ( $row['id'] ?? '' );

			if ( isset( $off[ $id ] ) && empty( $row['active'] ) ) {
				$out[] = $off[ $id ] + [
					'id'  => $id,
					'url' => (string) ( $row['url'] ?? '' ),
				];
			}
		}

		return $out;
	}

	/**
	 * Forget the failures of an endpoint somebody switched back on, or
	 * removed.
	 *
	 * For whatever hook fires when the endpoints are saved, handed the list
	 * as it was and as it is now.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $before The endpoints as they were.
	 * @param mixed $after  The endpoints as saved.
	 *
	 * @return void
	 */
	public function saved( $before, $after ): void {
		$was = self::by_id( is_array( $before ) ? $before : [] );
		$now = self::by_id( is_array( $after ) ? $after : [] );

		foreach ( $was as $id => $row ) {
			$gone      = ! isset( $now[ $id ] );
			$turned_on = ! $gone && empty( $row['active'] ) && ! empty( $now[ $id ]['active'] );

			if ( $gone || $turned_on ) {
				$this->health()->forget( $id );
			}
		}
	}

	/**
	 * Endpoints by id.
	 *
	 * @param array<int, mixed> $rows The rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function by_id( array $rows ): array {
		$out = [];

		foreach ( $rows as $row ) {
			if ( is_array( $row ) && '' !== (string) ( $row['id'] ?? '' ) ) {
				$out[ (string) $row['id'] ] = $row;
			}
		}

		return $out;
	}
}
