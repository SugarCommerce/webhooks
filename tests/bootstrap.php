<?php
/**
 * PHPUnit bootstrap.
 *
 * Signature, Verification and Backoff call no WordPress function. The
 * rest does, and the functions it calls are stubbed here over globals a
 * test can read and set: hooks, options, the requests that were made and
 * the answers they get, and Action Scheduler's queue -- unless the run is
 * the one without it (`composer test` makes both), where none of its
 * functions exist. `$wpdb` is an in-memory SQLite database, so the
 * receiver's inbox claims are real SQL answering real affected-row counts.
 *
 * @package SugarCommerce\Webhooks
 */

declare( strict_types=1 );

// phpcs:disable

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/curl-stub.php';

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public mixed $data = '' ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		public function __construct( private string $body = '', private array $headers = [] ) {}
		public function get_body() { return $this->body; }

		/**
		 * As core: header names are matched without case, `-` and `_` alike.
		 */
		public function get_header( $key ) {
			$canonical = static fn( string $name ): string => str_replace( '-', '_', strtolower( $name ) );

			foreach ( $this->headers as $name => $value ) {
				if ( $canonical( $name ) === $canonical( (string) $key ) ) {
					return $value;
				}
			}

			return null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public function __construct( public $data = null, public int $status = 200 ) {}
		public function get_data() { return $this->data; }
		public function get_status() { return $this->status; }
	}
}

/**
 * `$wpdb` on SQLite: the handful of methods the inbox calls, with the same
 * answers -- affected rows from query(), false on an error.
 */
class WH_Test_Wpdb {

	public string $prefix = 'wp_';
	public PDO $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	}

	public function prepare( string $query, ...$args ) {
		$index = 0;

		return preg_replace_callback(
			'/%(%|d|s)/',
			function ( array $match ) use ( &$args, &$index ) {
				if ( '%' === $match[1] ) {
					return '%';
				}

				$value = $args[ $index++ ] ?? null;

				return 'd' === $match[1] ? (string) (int) $value : $this->pdo->quote( (string) $value );
			},
			$query
		);
	}

	public function query( string $sql ) {
		try {
			return $this->pdo->exec( str_replace( 'INSERT IGNORE', 'INSERT OR IGNORE', $sql ) );
		} catch ( PDOException $e ) {
			return false;
		}
	}

	public function get_var( string $sql ) {
		$value = $this->pdo->query( $sql )->fetchColumn();

		return false === $value ? null : $value;
	}

	public function get_row( string $sql, $output = 'OBJECT' ) {
		$row = $this->pdo->query( $sql )->fetch( PDO::FETCH_ASSOC );

		return false === $row ? null : ( ARRAY_A === $output ? $row : (object) $row );
	}

	public function update( string $table, array $data, array $where ) {
		$set    = [];
		$values = [];

		foreach ( $data as $column => $value ) {
			$set[]    = "{$column} = ?";
			$values[] = $value;
		}

		$conditions = [];

		foreach ( $where as $column => $value ) {
			$conditions[] = "{$column} = ?";
			$values[]     = $value;
		}

		$statement = $this->pdo->prepare( "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $conditions ) );
		$statement->execute( $values );

		return $statement->rowCount();
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['wh_hooks'][ $hook ][] = $callback;

	return true;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	return add_filter( $hook, $callback, $priority, $args );
}

function remove_action( $hook, $callback, $priority = 10 ) {
	foreach ( $GLOBALS['wh_hooks'][ $hook ] ?? [] as $index => $attached ) {
		if ( $attached === $callback ) {
			unset( $GLOBALS['wh_hooks'][ $hook ][ $index ] );
		}
	}

	return true;
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['wh_hooks'][ $hook ] ?? [] as $callback ) {
		$value = $callback( $value, ...$args );
	}

	return $value;
}

function do_action( $hook, ...$args ) {
	$GLOBALS['wh_fired'][ $hook ][] = $args;

	foreach ( $GLOBALS['wh_hooks'][ $hook ] ?? [] as $callback ) {
		$callback( ...$args );
	}
}

function did_action( $hook ) {
	return 1;
}

function doing_action( $hook = null ) {
	return false;
}

function get_option( $name, $fallback = false ) {
	return array_key_exists( $name, $GLOBALS['wh_options'] ?? [] ) ? $GLOBALS['wh_options'][ $name ] : $fallback;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['wh_options'][ $name ] = $value;

	return true;
}

function get_bloginfo( $show = '' ) {
	return 'version' === $show ? '7.1' : 'Test';
}

function home_url( $path = '' ) {
	return 'https://shop.example' . $path;
}

function esc_url_raw( $url, $protocols = null ) {
	$url    = trim( (string) $url );
	$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );

	return in_array( $scheme, (array) ( $protocols ?? [ 'http', 'https' ] ), true ) ? $url : '';
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $text ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $text ) ) );
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}

/*
 * The HTTP API. Each request is recorded, with the pin a curl transport
 * would have been given; each answer is the next one a test queued, or a
 * 200. A queued answer may be a WP_Error.
 */
function wp_safe_remote_post( $url, $args = [] ) {
	$GLOBALS['wh_pin'] = null;

	if ( function_exists( 'curl_init' ) ) {
		$handle = curl_init();
		do_action( 'http_api_curl', $handle, $args, $url );
	}

	$GLOBALS['wh_requests'][] = [
		'url'  => $url,
		'args' => $args,
		'pin'  => $GLOBALS['wh_pin'],
	];

	return array_shift( $GLOBALS['wh_answers'] ) ?? wh_answer( 200 );
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : '';
}

function wp_remote_retrieve_header( $response, $header ) {
	return is_array( $response ) ? (string) ( array_change_key_case( $response['headers'] ?? [] )[ strtolower( $header ) ] ?? '' ) : '';
}

/**
 * An answer, as the HTTP API returns one.
 *
 * @param int                   $code    Status.
 * @param array<string, string> $headers Headers.
 *
 * @return array<string, mixed>
 */
function wh_answer( int $code, array $headers = [] ): array {
	return [
		'response' => [ 'code' => $code ],
		'headers'  => $headers,
		'body'     => '',
	];
}

if ( ! getenv( 'WITHOUT_ACTION_SCHEDULER' ) ) {
	function as_schedule_single_action( $timestamp, $hook, $args = [], $group = '', $unique = false, $priority = 10 ) {
		$GLOBALS['as_actions'][] = [
			'hook'      => $hook,
			'args'      => $args,
			'group'     => $group,
			'timestamp' => (int) $timestamp,
			'status'    => 'pending',
		];

		return count( $GLOBALS['as_actions'] );
	}

	function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
		foreach ( wh_queued() as $action ) {
			if ( $action['hook'] === $hook && ( null === $args || $action['args'] === $args ) && ( '' === $group || $action['group'] === $group ) ) {
				return true;
			}
		}

		return false;
	}

	/*
	 * Reading actions back, for Queued: the id is the place in the list
	 * plus one, and a deleted action is null in its place.
	 */
	function as_get_scheduled_actions( $args = [], $format = 'OBJECT' ) {
		$found = [];

		foreach ( $GLOBALS['as_actions'] ?? [] as $index => $action ) {
			if ( null === $action || ( isset( $args['hook'] ) && $action['hook'] !== $args['hook'] ) ) {
				continue;
			}

			if ( ! empty( $args['group'] ) && $action['group'] !== $args['group'] ) {
				continue;
			}

			if ( ! empty( $args['status'] ) && $action['status'] !== $args['status'] ) {
				continue;
			}

			if ( ! empty( $args['search'] ) && false === stripos( (string) json_encode( $action['args'] ), (string) $args['search'] ) ) {
				continue;
			}

			$found[ $index + 1 ] = new WH_Test_Action( $action );
		}

		return array_slice( $found, (int) ( $args['offset'] ?? 0 ), (int) ( $args['per_page'] ?? 5 ), true );
	}

	/**
	 * Action Scheduler's store, for deleting.
	 */
	class ActionScheduler {
		public static function store() {
			return new class() {
				public function delete_action( $id ) {
					$GLOBALS['as_actions'][ $id - 1 ] = null;
				}
			};
		}
	}

	/**
	 * A recorded action, answering as Action Scheduler's do.
	 */
	final class WH_Test_Action {
		public function __construct( private array $action ) {}
		public function get_args() { return $this->action['args']; }
		public function is_finished() { return 'pending' !== $this->action['status']; }

		public function get_schedule() {
			return new class( $this->action['timestamp'] ) {
				public function __construct( private int $timestamp ) {}
				public function get_date() { return new DateTimeImmutable( '@' . $this->timestamp ); }
			};
		}
	}
}

/**
 * The actions still in the queue, deleted ones left out.
 *
 * @return array<int, array<string, mixed>>
 */
function wh_queued(): array {
	return array_values( array_filter( $GLOBALS['as_actions'] ?? [] ) );
}

/**
 * Forget everything a previous test set up.
 *
 * @return void
 */
function wh_reset(): void {
	$GLOBALS['wh_hooks']    = [];
	$GLOBALS['wh_fired']    = [];
	$GLOBALS['wh_options']  = [];
	$GLOBALS['wh_requests'] = [];
	$GLOBALS['wh_answers']  = [];
	$GLOBALS['wh_pin']      = null;
	$GLOBALS['as_actions']  = [];
	$GLOBALS['wpdb']        = new WH_Test_Wpdb();

	\SugarCommerce\Dates\Clock::reset();

	( new ReflectionProperty( 'SugarCommerce\\Jobs\\Scheduler', 'groups' ) )->setValue( null, [] );
}

wh_reset();
