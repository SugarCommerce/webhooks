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
}
