<?php
/**
 * Endpoint store and event catalogue tests.
 *
 * @package SugarCommerce\Webhooks
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Tests;

use SugarCommerce\Webhooks\Endpoints;
use SugarCommerce\Webhooks\Events;
use PHPUnit\Framework\TestCase;

/**
 * What a settings screen saves, and what the dispatcher reads back.
 */
final class EndpointsTest extends TestCase {

	/**
	 * Empty everything.
	 */
	protected function setUp(): void {
		wh_reset();
	}

	/**
	 * A catalogue of two.
	 *
	 * @return Events
	 */
	private function events(): Events {
		return new Events(
			array(
				'order.completed' => 'Order completed',
				'order.refunded'  => array(
					'label'       => 'Order refunded',
					'description' => 'Sent for a full or part refund.',
				),
			),
			'shop_webhook_events'
		);
	}

	/**
	 * A store over one option.
	 *
	 * @return Endpoints
	 */
	private function store(): Endpoints {
		return Endpoints::option( 'shop_webhook_endpoints', $this->events() );
	}

	/**
	 * A new endpoint gets an id and a secret, and the row has only its five keys.
	 */
	public function test_a_new_endpoint_is_given_an_id_and_a_secret(): void {
		$rows = $this->store()->sanitize(
			array(
				array(
					'url'    => ' https://hooks.example/in ',
					'events' => array( 'order.completed' ),
					'active' => '1',
				),
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'id', 'url', 'secret', 'events', 'active' ), array_keys( $rows[0] ) );
		$this->assertMatchesRegularExpression( '/^we_[a-z0-9]{16}$/', $rows[0]['id'] );
		$this->assertMatchesRegularExpression( '/^whsec_[A-Za-z0-9]{32}$/', $rows[0]['secret'] );
		$this->assertSame( 'https://hooks.example/in', $rows[0]['url'] );
		$this->assertSame( array( 'order.completed' ), $rows[0]['events'] );
		$this->assertSame( 1, $rows[0]['active'] );
	}

	/**
	 * An id and a secret already there are kept: deliveries in flight know it by its id.
	 */
	public function test_an_id_and_a_secret_are_kept(): void {
		$rows = $this->store()->sanitize(
			array(
				array(
					'id'     => 'we_kept',
					'url'    => 'https://hooks.example/new-path',
					'secret' => 'mine',
				),
			)
		);

		$this->assertSame( 'we_kept', $rows[0]['id'] );
		$this->assertSame( 'mine', $rows[0]['secret'] );
		$this->assertSame( 0, $rows[0]['active'] );
	}

	/**
	 * Only somewhere that has the shape of a web address is kept.
	 */
	public function test_addresses_without_the_shape_are_dropped(): void {
		$rows = $this->store()->sanitize(
			array(
				array( 'url' => 'ftp://hooks.example/' ),
				array( 'url' => 'https://localhost/' ),
				array( 'url' => 'not a url' ),
				array( 'url' => '' ),
				'not a row',
				array( 'url' => 'http://hooks.example/' ),
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'http://hooks.example/', $rows[0]['url'] );
	}

	/**
	 * Kinds nobody catalogued are dropped.
	 */
	public function test_unknown_kinds_are_dropped(): void {
		$rows = $this->store()->sanitize(
			array(
				array(
					'url'    => 'https://hooks.example/',
					'events' => array( 'order.completed', 'order.exploded' ),
				),
			)
		);

		$this->assertSame( array( 'order.completed' ), $rows[0]['events'] );
	}

	/**
	 * Only switched-on endpoints with an id and an address are posted to.
	 */
	public function test_active_is_what_is_switched_on(): void {
		update_option(
			'shop_webhook_endpoints',
			array(
				array( 'id' => 'we_on', 'url' => 'https://hooks.example/a', 'active' => 1, 'events' => array( 'order.exploded', 'order.refunded' ) ),
				array( 'id' => 'we_off', 'url' => 'https://hooks.example/b', 'active' => 0 ),
				array( 'id' => '', 'url' => 'https://hooks.example/c', 'active' => 1 ),
				array( 'id' => 'we_nowhere', 'url' => 'javascript:alert(1)', 'active' => 1 ),
			)
		);

		$store = $this->store();

		$this->assertSame( array( 'we_on' ), array_column( $store->active(), 'id' ) );
		$this->assertSame( array( 'order.refunded' ), $store->active()[0]['events'] );
		$this->assertSame( '', $store->active()[0]['secret'] );
		$this->assertSame( array( 'we_on', 'we_off' ), array_column( $store->active( true ), 'id' ) );
		$this->assertNull( $store->find( 'we_off' ) );
		$this->assertNotNull( $store->find( 'we_off', true ) );
	}

	/**
	 * Ticking nothing is every kind.
	 */
	public function test_no_kinds_ticked_is_every_kind(): void {
		update_option(
			'shop_webhook_endpoints',
			array(
				array( 'id' => 'we_all', 'url' => 'https://hooks.example/a', 'active' => 1, 'events' => array() ),
				array( 'id' => 'we_one', 'url' => 'https://hooks.example/b', 'active' => 1, 'events' => array( 'order.refunded' ) ),
			)
		);

		$this->assertSame( array( 'we_all' ), array_column( $this->store()->wanting( 'order.completed' ), 'id' ) );
		$this->assertSame( array( 'we_all', 'we_one' ), array_column( $this->store()->wanting( 'order.refunded' ), 'id' ) );
	}

	/**
	 * The Active toggle is written back, and nothing else changes.
	 */
	public function test_set_active_writes_the_toggle(): void {
		$row = array( 'id' => 'we_on', 'url' => 'https://hooks.example/a', 'secret' => 's', 'events' => array(), 'active' => 1 );

		update_option( 'shop_webhook_endpoints', array( $row ) );

		$this->assertTrue( $this->store()->set_active( 'we_on', false ) );
		$this->assertSame( array( array_merge( $row, array( 'active' => 0 ) ) ), get_option( 'shop_webhook_endpoints' ) );
		$this->assertFalse( $this->store()->set_active( 'we_nobody', true ) );
	}

	/**
	 * Read-only, nothing is written.
	 */
	public function test_a_store_without_a_writer_writes_nothing(): void {
		$store = new Endpoints( static fn(): array => array( array( 'id' => 'we_on', 'url' => 'https://hooks.example/', 'active' => 1 ) ) );

		$this->assertFalse( $store->set_active( 'we_on', false ) );
		$this->assertSame( '', $store->option_name() );
	}

	/**
	 * Labels and descriptions, whichever way they were given.
	 */
	public function test_the_catalogue_has_labels_and_descriptions(): void {
		$events = $this->events();

		$this->assertSame(
			array(
				'order.completed' => array(
					'label'       => 'Order completed',
					'description' => '',
				),
				'order.refunded'  => array(
					'label'       => 'Order refunded',
					'description' => 'Sent for a full or part refund.',
				),
			),
			$events->all()
		);
		$this->assertSame(
			array(
				'order.completed' => 'Order completed',
				'order.refunded'  => 'Order refunded',
			),
			$events->labels()
		);
	}

	/**
	 * The filter is handed what was given, and can add either shape.
	 */
	public function test_the_catalogue_is_filtered(): void {
		add_filter(
			'shop_webhook_events',
			static function ( array $events ): array {
				$events['catalogue.changed'] = 'Products changed';
				$events['member.joined']     = array( 'label' => 'Member joined' );
				$events['']                  = 'Nothing';

				return $events;
			}
		);

		$events = $this->events();

		$this->assertTrue( $events->has( 'catalogue.changed' ) );
		$this->assertSame( 'Member joined', $events->labels()['member.joined'] );
		$this->assertFalse( $events->has( '' ) );
		$this->assertFalse( $events->has( 'order.exploded' ) );
	}

	/**
	 * Made when read, so the labels are translated by then.
	 */
	public function test_the_catalogue_can_be_made_late(): void {
		$made   = 0;
		$events = new Events(
			static function () use ( &$made ): array {
				++$made;

				return array( 'a.b' => 'A' );
			}
		);

		$this->assertSame( 0, $made );
		$this->assertSame( array( 'a.b' ), $events->types() );
		$this->assertSame( 1, $made );
	}
}
