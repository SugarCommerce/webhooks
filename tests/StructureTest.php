<?php
/**
 * How big a source file may grow.
 *
 * @package SugarCommerce\Webhooks
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * No class or trait in src/ past 1,000 lines.
 *
 * A class that does several jobs becomes a slim public class and a trait
 * per job under src/Traits/<Class>/ well before that. Files past 500 lines
 * are listed on STDERR as the ones to look at next; they do not fail.
 */
final class StructureTest extends TestCase {

	/**
	 * Never longer than this.
	 *
	 * @var int
	 */
	private const int LIMIT = 1000;

	/**
	 * Longer than this is worth a look.
	 *
	 * @var int
	 */
	private const int WARN = 500;

	/**
	 * Every PHP file under src/, with its line count.
	 *
	 * @return array<string, int> Path relative to the package => lines.
	 */
	private static function sizes(): array {
		$root  = dirname( __DIR__ );
		$sizes = [];
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$path           = substr( $file->getPathname(), strlen( $root ) + 1 );
			$sizes[ $path ] = count( (array) file( $file->getPathname() ) );
		}

		ksort( $sizes );

		return $sizes;
	}

	/**
	 * Nothing past the limit; the long ones named.
	 *
	 * @return void
	 */
	public function test_no_class_or_trait_is_over_a_thousand_lines(): void {
		$sizes = self::sizes();
		$over  = array_filter( $sizes, static fn( int $lines ): bool => $lines > self::LIMIT );
		$long  = array_filter( $sizes, static fn( int $lines ): bool => $lines > self::WARN && $lines <= self::LIMIT );

		foreach ( $long as $path => $lines ) {
			fwrite( STDERR, sprintf( "\nStructure: %s is %d lines (over %d): split it by job before it grows.", $path, $lines, self::WARN ) );
		}

		$this->assertNotEmpty( $sizes, 'No source files were found.' );
		$this->assertSame( [], $over, 'Over ' . self::LIMIT . ' lines: split into a slim class and a trait per job.' );
	}

	/**
	 * Another package is named so the build can rename it.
	 *
	 * Strauss rewrites the namespaces of the packages a plugin bundles and
	 * nothing else. A string naming `SugarCommerce\…`, or a `use` of a package
	 * this one only suggests, is left unprefixed when that package is not
	 * bundled -- and with SugarCart active it then finds SugarCart's copy,
	 * at SugarCart's version, from inside another plugin. A package this one
	 * requires is named with `::class`; one it can do without through
	 * sugarcommerce/plugin's `Build::sibling()`.
	 *
	 * @return void
	 */
	public function test_other_packages_are_named_so_a_build_renames_them(): void {
		$root     = dirname( __DIR__ );
		$composer = (array) json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
		$own      = explode( '\\', (string) array_key_first( (array) ( $composer['autoload']['psr-4'] ?? [] ) ) )[1] ?? '';
		$allowed  = [ strtolower( $own ) => true ];

		foreach ( array_keys( (array) ( $composer['require'] ?? [] ) ) as $package ) {
			if ( str_starts_with( $package, 'sugarcommerce/' ) ) {
				$allowed[ str_replace( '-', '', substr( $package, 14 ) ) ] = true;
			}
		}

		$wrong = [];
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			$where = substr( $file->getPathname(), strlen( $root ) + 1 );

			foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) {
				if ( ! is_array( $token ) ) {
					continue;
				}

				if ( T_CONSTANT_ENCAPSED_STRING === $token[0] && 1 === preg_match( '/^.\\\\{0,2}SugarCommerce\\\\{1,2}[A-Za-z]/', $token[1] ) ) {
					$wrong[] = $where . ':' . $token[2] . ' names a class in a string: ' . $token[1];
					continue;
				}

				if ( in_array( $token[0], [ T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ], true )
					&& 1 === preg_match( '/^\\\\?SugarCommerce\\\\([A-Za-z0-9]+)/', $token[1], $match )
					&& ! isset( $allowed[ strtolower( $match[1] ) ] ) ) {
					$wrong[] = $where . ':' . $token[2] . ' names ' . $token[1] . ', a package composer.json does not require: ask Build::sibling()';
				}
			}
		}

		sort( $wrong );

		$this->assertSame( [], $wrong );
	}
}
