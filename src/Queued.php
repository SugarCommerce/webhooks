<?php
/**
 * The bodies of deliveries in the queue, and changing them.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Jobs\Scheduler;

/**
 * Class Queued
 *
 * Finding the deliveries Action Scheduler holds that say something, and
 * rewriting what they say. Made by {@see Dispatcher::queued()}.
 *
 * A delivery is queued with its whole JSON body as one of its arguments,
 * because the body is signed as it is sent and must be the same bytes on
 * every retry. So the body -- a buyer's email address in it -- sits in
 * Action Scheduler's table until the delivery has run, and for a month
 * after that in the record of what ran. Erasing somebody under a privacy
 * request has to reach it.
 *
 * Action Scheduler cannot change an action's arguments. A delivery still
 * to be sent is queued again for the same moment with the new body, and
 * then the old one is deleted -- deleted, not cancelled, because a
 * cancelled action keeps its arguments. One already sent, or given up on,
 * is only deleted: it is a record of a request that has been made, and the
 * record goes rather than being made to say something else.
 *
 * Nothing here knows what is in a body. Whoever calls says what to look
 * for and how to change an event.
 *
 *   $webhooks->queued()->rewrite( $email, function ( array $event ): ?array {
 *       if ( ( $event['data']['email'] ?? '' ) !== $email ) {
 *           return null;                         // not about them: leave it
 *       }
 *       $event['data']['email'] = '';
 *       return $event;
 *   } );
 *
 * @since 1.0.0
 */
final class Queued {

	/**
	 * Deliveries read at a time.
	 */
	private const PAGE = 100;

	/**
	 * Which of a delivery's arguments is its body.
	 *
	 * The arguments are `[ endpoint id, type, body, attempt ]`, as the
	 * dispatcher queues them.
	 */
	private const BODY = 2;

	/**
	 * @since 1.0.0
	 *
	 * @param string $hook  The delivery action.
	 * @param string $group Its Action Scheduler group.
	 */
	public function __construct(
		private string $hook,
		private string $group,
	) {}

	/**
	 * Change every delivery whose body mentions something.
	 *
	 * `$change` is handed each event that mentions `$needle` -- `id`,
	 * `type`, `created`, `test` and `data`, decoded -- and returns it
	 * changed, or null to leave it alone. The needle only narrows the
	 * search: it is looked for in the stored text, and the callback is the
	 * judge of whether an event is about what it is looking for.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $needle Text the body must contain, as it is stored.
	 * @param callable $change fn( array $event ): ?array.
	 *
	 * @return int How many deliveries were changed or removed.
	 */
	public function rewrite( string $needle, callable $change ): int {
		if ( '' === $needle || ! self::available() ) {
			return 0;
		}

		$changed = 0;
		$offset  = 0;

		do {
			$actions = as_get_scheduled_actions(
				[
					'hook'     => $this->hook,
					'group'    => $this->group,
					'search'   => $needle,

					// Every status: a delivery that has run keeps its body
					// in the record as well.
					'status'   => '',
					'per_page' => self::PAGE,
					'offset'   => $offset,
					'orderby'  => 'date',
					'order'    => 'ASC',
				]
			);

			$kept = 0;

			foreach ( $actions as $id => $action ) {
				if ( $this->rewrite_one( (int) $id, $action, $change ) ) {
					++$changed;
				} else {
					++$kept;
				}
			}

			// What was changed no longer matches, so the next page starts
			// after the ones that were left as they were.
			$offset += $kept;
		} while ( count( $actions ) === self::PAGE );

		return $changed;
	}

	/**
	 * Change one delivery, if its event is one to change.
	 *
	 * @param int      $id     The action.
	 * @param object   $action Action Scheduler's action.
	 * @param callable $change What to do to the event.
	 *
	 * @return bool Whether it was changed or removed.
	 */
	private function rewrite_one( int $id, object $action, callable $change ): bool {
		$args  = array_values( (array) $action->get_args() );
		$event = json_decode( (string) ( $args[ self::BODY ] ?? '' ), true );

		if ( ! is_array( $event ) ) {
			return false;
		}

		$event = $change( $event );

		if ( ! is_array( $event ) ) {
			return false;
		}

		if ( ! $action->is_finished() ) {
			$args[ self::BODY ] = (string) wp_json_encode( $event );

			Scheduler::group( $this->group )->schedule( $this->hook, $args, self::when( $action ) );
		}

		try {
			\ActionScheduler::store()->delete_action( $id );
		} catch ( \Throwable $gone ) {
			// Run and cleaned up by another process meanwhile: gone either way.
			unset( $gone );
		}

		return true;
	}

	/**
	 * When a delivery still to be sent was going to be sent.
	 *
	 * @param object $action Action Scheduler's action.
	 *
	 * @return int A Unix time; now when it does not say.
	 */
	private static function when( object $action ): int {
		$date = $action->get_schedule()->get_date();

		return $date instanceof \DateTimeInterface ? max( Clock::time(), $date->getTimestamp() ) : Clock::time();
	}

	/**
	 * Whether there is a queue to look in.
	 *
	 * @return bool
	 */
	private static function available(): bool {
		return Scheduler::available() && function_exists( 'as_get_scheduled_actions' ) && class_exists( 'ActionScheduler' );
	}
}
