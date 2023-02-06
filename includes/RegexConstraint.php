<?php

namespace MediaWiki\Extension\DiscordNotifications;

use MediaWiki\Logger\LoggerFactory;
use StringUtils;

class RegexConstraint {
	/**
	 * @param string $regex invalid regex to log for
	 * @param string $name name of regex caller (config or message key) to log for
	 */
	private static function warnInvalidRegex( $regex, $name ) {
		LoggerFactory::getInstance( 'DiscordNotifications' )->warning(
			'{name} contains invalid regex',
			[
				'name' => $name,
				'regex' => $regex,
			]
		);
	}

	/**
	 * @param array &$regexes
	 * @param string $name name of regex caller (config or message key) for logging
	 * @param string $start
	 * @param string $end
	 * @return void
	 */
	private static function filterInvalidRegexes( &$regexes, $name = '', $start = '', $end = '' ) {
		$regexes = array_filter( $regexes, static function ( $regex ) use ( $name, $start, $end ) {
			if ( !StringUtils::isValidPCRERegex( $start . $regex . $end ) ) {
				if ( $name ) {
					self::warnInvalidRegex( $regex, $name );
				}

				return false;
			}

			return true;
		} );
	}

	/**
	 * @param array $regexes array of regexes to use for making into a string
	 * @param string $start prepend to the beginning of the regex
	 * @param string $end append to the end of the regex
	 * @param string $name name of regex caller (config or message key) for logging
	 * @return string
	 */
	public static function regexFromArray( $regexes, $start, $end, $name = '' ) {
		if ( empty( $regexes ) ) {
			return '';
		}

		self::filterInvalidRegexes( $regexes, $name, $start, $end );

		if ( !empty( $regexes ) ) {
			$regex = $start . implode( '|', $regexes ) . $end;

			if ( StringUtils::isValidPCRERegex( $regex ) ) {
				return $regex;
			}

			if ( $name ) {
				self::warnInvalidRegex( $regex, $name );
			}
		}

		return '';
	}

	/**
	 * @param array|string $regex
	 * @param string $start
	 * @param string $end
	 * @param string $name name of regex caller (config or message key) for logging
	 * @return string
	 */
	public static function regexFromArrayOrString( $regex, $start = '', $end = '', $name = '' ) {
		if ( is_array( $regex ) ) {
			return self::regexFromArray( $regex, $start, $end, $name );
		} else {
			if ( StringUtils::isValidPCRERegex( $regex ) ) {
				return $regex;
			}

			if ( $name ) {
				self::warnInvalidRegex( $regex, $name );
			}
		}

		return '';
	}
}
