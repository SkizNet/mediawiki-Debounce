<?php

use MediaWiki\MediaWikiServices;

class Debounce {
	/**
	 * Check if an email address is valid for transactional emails.
	 *
	 * @param string $addr Email address
	 * @param bool &$result Result to use if stopping hook execution
	 * @return bool True to continue hook execution, false to stop it
	 * @throws MWException If extension is not configured
	 */
	public static function isValidEmailAddr( $addr, &$result ) {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$apiKey = $config->get( 'DebounceApiKey' );
		if ( !$apiKey ) {
			throw new MWException( 'debounce-unconfigured' );
		}

		// check for cached result
		$cache = ObjectCache::getLocalClusterInstance();
		$cacheKey = 'email_' . hash_hmac( 'sha256', $addr, $config->get( 'SecretKey' ) );
		$cachedData = $cache->get( $cacheKey );

		if ( is_int( $cachedData ) ) {
			// We want to return true if the cached result says the email is valid,
			// so that other hooks and MW internal logic can also validate it.
			// We want to return false if the cached result says the email is invalid
			// so that MW core honors it (overall hook returning true ignores $result).
			$result = (bool)$cachedData;
			return $result;
		}

		$http = $services->getHttpRequestFactory();
		$res = $http->get( 'https://api.debounce.io/v1/?' . wfArrayToCgi( [
			'api' => $apiKey,
			'email' => $addr
		] ) );

		// on API failure, soft fail (allow registration to proceed)
		$data = json_decode( $res );
		if ( $data->success ) {
			$result = (bool)$data->debounce->send_transactional;
			// cache result for 1 week; store as int because cache->get returns
			// bool false on key not found and we need to distinguish between not found
			// and a cached negative result.
			$cache->set( $cacheKey, (int)$result, 60 * 60 * 24 * 7 );
			return $result;
		}

		return true;
	}
}
