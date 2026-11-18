<?php
/**
 * The kinds of event a site sends.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

/**
 * Class Events
 *
 * The catalogue an endpoint picks from: `order.completed` and what the
 * settings screen calls it, and a line saying when it is sent.
 *
 * Given as a callable, so the labels are translated when they are read --
 * on `init` or later -- and not when the plugin boots, which is before the
 * translations are loaded. Passed through a filter, so an add-on can add
 * its own kinds and then send them; an event that is not in the catalogue
 * is not sent.
 *
 * An entry is a label, or `[ 'label' => …, 'description' => … ]`.
 * Whatever the filter is handed comes back in the same shape, so add-ons
 * written against a list of labels keep working.
 *
 *   $events = new Events( fn() => [
 *       'order.completed' => __( 'Order completed', 'myplugin' ),
 *       'order.refunded'  => [
 *           'label'       => __( 'Order refunded', 'myplugin' ),
 *           'description' => __( 'Sent for a full or part refund.', 'myplugin' ),
 *       ],
 *   ], 'myplugin_webhook_events' );
 *
 * @since 1.0.0
 */
final class Events {

	/**
	 * The built-in catalogue, or what makes it.
	 *
	 * @var callable|array
	 */
	private $events;

	/**
	 * @since 1.0.0
	 *
	 * @param callable|array $events Type => label or `label`/`description`,
	 *                               or a callable answering that.
	 * @param string         $filter The filter add-ons extend it through.
	 *                               None when empty.
	 */
	public function __construct(
		callable|array $events,
		private string $filter = '',
	) {
		$this->events = $events;
	}

	/**
	 * Every kind, with its label and description.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public function all(): array {
		$events = is_callable( $this->events ) ? ( $this->events )() : $this->events;
		$events = is_array( $events ) ? $events : [];

		if ( '' !== $this->filter ) {
			/**
			 * Filter the kinds of event an endpoint can be sent.
			 *
			 * Named by whoever made the catalogue. Add a kind here, send it
			 * with the dispatcher, and it is offered on every endpoint.
			 *
			 * @param array<string, string|array> $events Type => label, or
			 *                                            label/description.
			 */
			$events = apply_filters( $this->filter, $events );
		}

		$out = [];

		foreach ( is_array( $events ) ? $events : [] as $type => $event ) {
			$type = (string) $type;

			if ( '' === $type ) {
				continue;
			}

			$label       = is_array( $event ) ? ( $event['label'] ?? $type ) : $event;
			$description = is_array( $event ) ? ( $event['description'] ?? '' ) : '';

			$out[ $type ] = [
				'label'       => is_scalar( $label ) ? (string) $label : $type,
				'description' => is_scalar( $description ) ? (string) $description : '',
			];
		}

		return $out;
	}

	/**
	 * Every kind and what to call it, for a list of checkboxes.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public function labels(): array {
		return array_map( static fn( array $event ): string => $event['label'], $this->all() );
	}

	/**
	 * Every kind's type.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function types(): array {
		return array_keys( $this->all() );
	}

	/**
	 * Whether a kind is in the catalogue.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type The event type.
	 *
	 * @return bool
	 */
	public function has( string $type ): bool {
		return isset( $this->all()[ $type ] );
	}
}
