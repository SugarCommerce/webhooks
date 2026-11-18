<?php
/**
 * Where webhooks go.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

use SugarCommerce\Security\Random;

/**
 * Class Endpoints
 *
 * The list a site owner keeps on a settings screen: an address, a signing
 * secret, the kinds of event it wants, and a switch. Stored wherever the
 * plugin keeps its settings -- this reads and writes through two callables,
 * or one option with {@see self::option()}.
 *
 * A stored endpoint:
 *
 *   [
 *       'id'     => 'we_…',    // minted once, kept for ever
 *       'url'    => 'https://…',
 *       'secret' => 'whsec_…', // minted when left empty
 *       'events' => [],        // none ticked is every kind
 *       'active' => 1,
 *   ]
 *
 * Switching off for failing is this same `active` turned off, by
 * {@see Breaker}; why and when are kept beside it, not on the row.
 *
 * @since 1.0.0
 */
final class Endpoints {

	/**
	 * Reads the stored list: fn(): mixed.
	 *
	 * @var callable
	 */
	private $read;

	/**
	 * Writes it back: fn( array $rows ): void. Null when it is read-only.
	 *
	 * @var callable|null
	 */
	private $write;

	/**
	 * @since 1.0.0
	 *
	 * @param callable      $read          fn(): mixed, the stored list.
	 * @param callable|null $write         fn( array $rows ): void. Needed to
	 *                                     switch a dead endpoint off.
	 * @param Events|null   $events        The catalogue. Kinds not in it
	 *                                     are dropped from an endpoint.
	 * @param string        $id_prefix     Before a minted id.
	 * @param string        $secret_prefix Before a minted secret.
	 * @param string        $option        The option they are kept in, when
	 *                                     it is one of their own. Use
	 *                                     self::option().
	 */
	public function __construct(
		callable $read,
		?callable $write = null,
		private ?Events $events = null,
		private string $id_prefix = 'we_',
		private string $secret_prefix = 'whsec_',
		private string $option = '',
	) {
		$this->read  = $read;
		$this->write = $write;
	}

	/**
	 * Endpoints kept in one option of their own.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $name          The option.
	 * @param Events|null $events        The catalogue.
	 * @param string      $id_prefix     Before a minted id.
	 * @param string      $secret_prefix Before a minted secret.
	 *
	 * @return self
	 */
	public static function option( string $name, ?Events $events = null, string $id_prefix = 'we_', string $secret_prefix = 'whsec_' ): self {
		return new self(
			static fn() => get_option( $name, [] ),
			static fn( array $rows ) => update_option( $name, $rows, false ),
			$events,
			$id_prefix,
			$secret_prefix,
			$name
		);
	}

	/**
	 * The catalogue the endpoints pick from.
	 *
	 * @since 1.0.0
	 *
	 * @return Events|null
	 */
	public function events(): ?Events {
		return $this->events;
	}

	/**
	 * Every stored endpoint, as stored.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$rows = ( $this->read )();

		return array_values( array_filter( is_array( $rows ) ? $rows : [], 'is_array' ) );
	}

	/**
	 * The endpoints that are switched on and have somewhere to post to.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $switched_off Whether to include the ones switched off,
	 *                           for a test.
	 *
	 * @return array<int, array{id: string, url: string, secret: string, events: array<int, string>}>
	 */
	public function active( bool $switched_off = false ): array {
		$out = [];

		foreach ( $this->all() as $row ) {
			$url = esc_url_raw( (string) ( $row['url'] ?? '' ), [ 'https', 'http' ] );

			if ( '' === $url || ( empty( $row['active'] ) && ! $switched_off ) || '' === (string) ( $row['id'] ?? '' ) ) {
				continue;
			}

			$out[] = [
				'id'     => (string) $row['id'],
				'url'    => $url,
				'secret' => (string) ( $row['secret'] ?? '' ),

				// None chosen means all of them, which is what somebody who
				// added an endpoint and ticked nothing was expecting.
				'events' => $this->known( $row['events'] ?? [] ),
			];
		}

		return $out;
	}

	/**
	 * One switched-on endpoint, by id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id           The endpoint.
	 * @param bool   $switched_off Whether a switched-off one will do.
	 *
	 * @return array{id: string, url: string, secret: string, events: array<int, string>}|null
	 */
	public function find( string $id, bool $switched_off = false ): ?array {
		foreach ( $this->active( $switched_off ) as $endpoint ) {
			if ( $endpoint['id'] === $id ) {
				return $endpoint;
			}
		}

		return null;
	}

	/**
	 * The switched-on endpoints that want a kind of event.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type The event type.
	 *
	 * @return array<int, array{id: string, url: string, secret: string, events: array<int, string>}>
	 */
	public function wanting( string $type ): array {
		return array_values(
			array_filter(
				$this->active(),
				static fn( array $endpoint ): bool => [] === $endpoint['events'] || in_array( $type, $endpoint['events'], true )
			)
		);
	}

	/**
	 * Give each endpoint an id and a secret when it is saved.
	 *
	 * The sanitize callback for the setting. The id is what a queued
	 * delivery remembers its endpoint by, so it is minted once and kept: one
	 * that followed the URL would lose every delivery in flight when
	 * somebody corrected a typo.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $rows What the settings screen posted.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function sanitize( $rows ): array {
		$out = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			// The shape only: an http(s) address with a host. Whether it is
			// safe to post to -- not somewhere inside the server's own
			// network -- is checked when the request is sent, which is the
			// moment it matters. Checking here as well would make saving a
			// setting depend on a DNS lookup.
			$url = esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ), [ 'https', 'http' ] );

			if ( ! self::is_web_address( $url ) ) {
				continue;
			}

			$id     = sanitize_key( (string) ( $row['id'] ?? '' ) );
			$secret = trim( sanitize_text_field( (string) ( $row['secret'] ?? '' ) ) );

			$out[] = [
				'id'     => '' !== $id ? $id : $this->id_prefix . substr( Random::hex( 16 ), 0, 16 ),
				'url'    => $url,

				// Minted when left empty, so no endpoint is ever unsigned by
				// accident. It is shown in the field afterwards to be copied.
				'secret' => '' !== $secret ? $secret : $this->secret_prefix . Random::alphanumeric( 32 ),
				'events' => $this->known( $row['events'] ?? [] ),
				'active' => empty( $row['active'] ) ? 0 : 1,
			];
		}

		return $out;
	}

	/**
	 * Change one stored endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $id     The endpoint.
	 * @param callable $change fn( array $row ): array.
	 *
	 * @return bool False when there is nowhere to write, or no such endpoint.
	 */
	public function update( string $id, callable $change ): bool {
		if ( null === $this->write ) {
			return false;
		}

		$rows  = $this->all();
		$found = false;

		foreach ( $rows as $index => $row ) {
			if ( (string) ( $row['id'] ?? '' ) === $id ) {
				$rows[ $index ] = (array) $change( $row );
				$found          = true;
			}
		}

		if ( $found ) {
			( $this->write )( $rows );
		}

		return $found;
	}

	/**
	 * Turn an endpoint's Active toggle on or off.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id     The endpoint.
	 * @param bool   $active On or off.
	 *
	 * @return bool False when there is nowhere to write, or no such endpoint.
	 */
	public function set_active( string $id, bool $active ): bool {
		return $this->update(
			$id,
			static function ( array $row ) use ( $active ): array {
				$row['active'] = $active ? 1 : 0;

				return $row;
			}
		);
	}

	/**
	 * The option the endpoints are kept in, when they have one of their own.
	 *
	 * @since 1.0.0
	 *
	 * @return string Empty when they are read and written by callables.
	 */
	public function option_name(): string {
		return $this->option;
	}

	/**
	 * Whether a string has the shape of somewhere to post to.
	 *
	 * An http(s) address with a host that has a dot in it. The shape only:
	 * see self::sanitize() for why nothing is looked up.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url An address, already cleaned.
	 *
	 * @return bool
	 */
	public static function is_web_address( string $url ): bool {
		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || ! str_contains( $host, '.' ) ) {
			return false;
		}

		return false !== filter_var( $url, FILTER_VALIDATE_URL );
	}

	/**
	 * The kinds of event an endpoint asked for that are in the catalogue.
	 *
	 * @param mixed $events What is stored or posted.
	 *
	 * @return array<int, string>
	 */
	private function known( $events ): array {
		$events = array_values( array_map( 'strval', array_filter( (array) $events, 'is_scalar' ) ) );

		if ( null === $this->events ) {
			return $events;
		}

		return array_values( array_intersect( $events, $this->events->types() ) );
	}
}
