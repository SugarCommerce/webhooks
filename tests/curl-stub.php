<?php
/**
 * curl_setopt(), as SafeUrl's namespace sees it.
 *
 * An unqualified call resolves to a function in the caller's namespace
 * before the global one, so this records the pin SafeUrl gives curl --
 * which a real handle cannot be asked for afterwards.
 *
 * @package SugarCommerce\Webhooks
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

/**
 * Record CURLOPT_RESOLVE; pass anything else through.
 *
 * @param mixed $handle The handle.
 * @param int   $option The option.
 * @param mixed $value  Its value.
 *
 * @return bool
 */
function curl_setopt( $handle, int $option, $value ): bool {
	if ( CURLOPT_RESOLVE === $option ) {
		$GLOBALS['wh_pin'] = $value;

		return true;
	}

	return \curl_setopt( $handle, $option, $value );
}
