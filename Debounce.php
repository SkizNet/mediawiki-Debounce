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
		$free = $config->get( 'DebounceFree' );
		$private = $config->get( 'DebouncePrivate' );
		if ( !$free && !$apiKey ) {
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
		if ( $free ) {
			$res = $http->get( 'https://disposable.debounce.io/?' . wfArrayToCgi( [
				'email' => $addr
			] ) );
		} else {
			if ( $private ) {
				// obscure email address before sending to debounce;
				// effectively only domain portion is sent
				$parts = explode( '@', $addr );
				$parts[0] = 'example';
				$addr = implode( '@', $parts );
			}

			$res = $http->get( 'https://api.debounce.io/v1/?' . wfArrayToCgi( [
				'api' => $apiKey,
				'email' => $addr
			] ) );
		}

		// on API failure, soft fail (allow registration to proceed)
		$data = json_decode( $res, true );
		$result = null;
		if ( $free && isset( $data['disposable'] ) ) {
			// we want a true $result to mean "this email is valid"
			// which means it is *not* disposable
			$result = $data['disposable'] === 'false';
		} elseif ( isset( $data['success'] ) && $data['success'] === '1' ) {
			$result = $data['debounce']['send_transactional'] === '1';
		}

		// cache result for 1 week; store as int because cache->get returns
		// bool false on key not found and we need to distinguish between not found
		// and a cached negative result.
		if ( $result !== null ) {
			$cache->set( $cacheKey, (int)$result, 60 * 60 * 24 * 7 );
			return $result;
		}

		return true;
	}
}
