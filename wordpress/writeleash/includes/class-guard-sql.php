<?php
namespace WriteLeash;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deterministic lexical classifier for SQL reserved to the Guard.
 *
 * The wpdb 'query' filter is a string boundary, so a fragile anchored regex is
 * not enough: backtick quoting, comments and executable comments must be
 * recognized. This is intentionally a small tokenizer for the reserved subset,
 * not a SQL parser.
 *
 * classify() returns:
 * - CLEAR: the statement does not look like Guard-reserved SQL;
 * - RESERVED: a matched transaction/lifecycle form or any opaque CALL;
 * - AMBIGUOUS: the statement cannot be safely classified (executable comments,
 *   unterminated tokens, multiple statements). The
 *   monitor treats AMBIGUOUS as reserved and fails closed.
 */
final class Guard_Sql {
	const CLEAR     = 'clear';
	const RESERVED  = 'reserved';
	const AMBIGUOUS = 'ambiguous';

	public static function classify( string $sql ): string {
		$tokens = self::tokenize( $sql );
		if ( null === $tokens ) {
			return self::AMBIGUOUS;
		}
		foreach ( $tokens as $index => $token ) {
			if ( 'symbol' === $token['type'] && ';' === $token['value'] && self::has_significant_after( $tokens, $index ) ) {
				return self::AMBIGUOUS;
			}
		}
		if ( array() === $tokens ) {
			return self::CLEAR;
		}

		// Any reference to a managed runtime session variable is reserved, whether it
		// reads or writes the variable (for example SELECT @writeleash_v01_denied := 0).
		// The pre-rebrand @commitcap_v01_* prefix stays defensively reserved as
		// legacy managed-looking state. That is classification only: it is never
		// compatibility support, a migration input or a fallback authority.
		foreach ( $tokens as $index => $token ) {
			if ( 'symbol' === $token['type'] && '@' === $token['value'] ) {
				$next = isset( $tokens[ $index + 1 ] ) ? $tokens[ $index + 1 ] : null;
				if ( null !== $next && self::is_identifier_token( $next ) ) {
					$value = strtolower( $next['value'] );
					if ( 0 === strpos( $value, 'writeleash_v01_' ) || 0 === strpos( $value, 'commitcap_v01_' ) ) {
						return self::RESERVED;
					}
				}
			}
		}

		$first = $tokens[0];
		if ( ! self::is_word_token( $first ) ) {
			return self::CLEAR;
		}
		switch ( strtoupper( $first['value'] ) ) {
			case 'CALL':
				// A foreign procedure may call CLOSE/OPEN or COMMIT inside
				// the server. Its body is opaque to the wpdb query filter.
				return self::RESERVED;
			case 'COMMIT':
			case 'ROLLBACK':
			case 'BEGIN':
			case 'SAVEPOINT':
			case 'PREPARE':
			case 'EXECUTE':
			case 'DEALLOCATE':
				return self::RESERVED;
			case 'START':
				return self::second_is( $tokens, 'TRANSACTION' ) ? self::RESERVED : self::CLEAR;
			case 'RELEASE':
				return self::second_is( $tokens, 'SAVEPOINT' ) ? self::RESERVED : self::CLEAR;
			case 'SET':
				return self::classify_set( array_slice( $tokens, 1 ) );
		}
		return self::CLEAR;
	}

	private static function classify_set( array $tokens ): string {
		$depth     = 0;
		$target    = array();
		$at_target = true;
		foreach ( $tokens as $token ) {
			if ( 'symbol' === $token['type'] ) {
				if ( '(' === $token['value'] ) {
					++$depth;
				} elseif ( ')' === $token['value'] ) {
					$depth = max( 0, $depth - 1 );
				} elseif ( 0 === $depth && ',' === $token['value'] ) {
					if ( self::set_target_reserved( $target ) ) {
						return self::RESERVED;
					}
					$target    = array();
					$at_target = true;
					continue;
				} elseif ( 0 === $depth && ( '=' === $token['value'] || ':=' === $token['value'] ) ) {
					if ( self::set_target_reserved( $target ) ) {
						return self::RESERVED;
					}
					$target    = array();
					$at_target = false;
					continue;
				}
			}
			if ( $at_target ) {
				$target[] = $token;
			}
		}
		return self::set_target_reserved( $target ) ? self::RESERVED : self::CLEAR;
	}

	private static function set_target_reserved( array $target ): bool {
		foreach ( $target as $index => $token ) {
			if ( ! self::is_word_token( $token ) || 'autocommit' !== strtolower( $token['value'] ) ) {
				continue;
			}
			$previous = isset( $target[ $index - 1 ] ) ? $target[ $index - 1 ] : null;
			if ( null !== $previous && 'symbol' === $previous['type'] && '@' === $previous['value'] ) {
				continue; // user variable named @autocommit, not the system setting
			}
			return true;
		}
		return false;
	}

	private static function second_is( array $tokens, string $keyword ): bool {
		return isset( $tokens[1] ) && self::is_word_token( $tokens[1] ) &&
			strtoupper( $tokens[1]['value'] ) === $keyword;
	}

	private static function has_significant_after( array $tokens, int $index ): bool {
		$count = count( $tokens );
		for ( $i = $index + 1; $i < $count; ++$i ) {
			$token = $tokens[ $i ];
			if ( 'symbol' === $token['type'] && ';' === $token['value'] ) {
				continue;
			}
			return true;
		}
		return false;
	}

	private static function is_word_token( array $token ): bool {
		return 'word' === $token['type'];
	}

	private static function is_identifier_token( array $token ): bool {
		return 'word' === $token['type'] || 'ident' === $token['type'];
	}

	/**
	 * Returns tokens as array( type => 'word'|'ident'|'string'|'number'|'symbol',
	 * value => string ) or null when the statement cannot be safely tokenized.
	 */
	private static function tokenize( string $sql ): ?array {
		$tokens = array();
		$length = strlen( $sql );
		$i      = 0;
		while ( $i < $length ) {
			$char = $sql[ $i ];
			if ( ctype_space( $char ) ) {
				++$i;
				continue;
			}
			if ( '#' === $char ) {
				$i = self::skip_line( $sql, $i + 1 );
				continue;
			}
			if ( '-' === $char && $i + 1 < $length && '-' === $sql[ $i + 1 ] &&
				( $i + 2 >= $length || ctype_space( $sql[ $i + 2 ] ) ) ) {
				$i = self::skip_line( $sql, $i + 2 );
				continue;
			}
			if ( '/' === $char && $i + 1 < $length && '*' === $sql[ $i + 1 ] ) {
				if ( $i + 2 < $length && ( '!' === $sql[ $i + 2 ] ||
					( $i + 3 < $length && ( 'M' === $sql[ $i + 2 ] || 'm' === $sql[ $i + 2 ] ) && '!' === $sql[ $i + 3 ] ) ) ) {
					// MySQL /*! ... */ and MariaDB /*M! ... */ (including
					// versioned forms) can execute SQL; never strip either.
					return null;
				}
				$end = strpos( $sql, '*/', $i + 2 );
				if ( false === $end ) {
					return null;
				}
				$i = $end + 2;
				continue;
			}
			if ( '`' === $char ) {
				$identifier = self::read_backtick( $sql, $i );
				if ( null === $identifier ) {
					return null;
				}
				$tokens[] = array( 'type' => 'ident', 'value' => $identifier['value'] );
				$i        = $identifier['next'];
				continue;
			}
			if ( "'" === $char || '"' === $char ) {
				$next = self::read_string( $sql, $i, $char );
				if ( null === $next ) {
					return null;
				}
				$tokens[] = array( 'type' => 'string', 'value' => '' );
				$i        = $next;
				continue;
			}
			if ( ctype_alpha( $char ) || '_' === $char || '$' === $char || ord( $char ) >= 0x80 ) {
				$start = $i;
				while ( $i < $length && self::is_identifier_char( $sql[ $i ] ) ) {
					++$i;
				}
				$tokens[] = array( 'type' => 'word', 'value' => substr( $sql, $start, $i - $start ) );
				continue;
			}
			if ( ctype_digit( $char ) ) {
				$start = $i;
				while ( $i < $length && ( ctype_alnum( $sql[ $i ] ) || '.' === $sql[ $i ] ) ) {
					++$i;
				}
				$tokens[] = array( 'type' => 'number', 'value' => substr( $sql, $start, $i - $start ) );
				continue;
			}
			if ( ':' === $char && $i + 1 < $length && '=' === $sql[ $i + 1 ] ) {
				$tokens[] = array( 'type' => 'symbol', 'value' => ':=' );
				$i       += 2;
				continue;
			}
			if ( '@' === $char ) {
				if ( $i + 1 < $length && '@' === $sql[ $i + 1 ] ) {
					$tokens[] = array( 'type' => 'symbol', 'value' => '@@' );
					$i       += 2;
					continue;
				}
				$tokens[] = array( 'type' => 'symbol', 'value' => '@' );
				++$i;
				continue;
			}
			$tokens[] = array( 'type' => 'symbol', 'value' => $char );
			++$i;
		}
		return $tokens;
	}

	private static function is_identifier_char( string $char ): bool {
		return ctype_alnum( $char ) || '_' === $char || '$' === $char || ord( $char ) >= 0x80;
	}

	private static function skip_line( string $sql, int $i ): int {
		$newline = strpos( $sql, "\n", $i );
		return false === $newline ? strlen( $sql ) : $newline + 1;
	}

	private static function read_backtick( string $sql, int $i ): ?array {
		$length = strlen( $sql );
		$value  = '';
		++$i;
		while ( $i < $length ) {
			$char = $sql[ $i ];
			if ( '`' === $char ) {
				if ( $i + 1 < $length && '`' === $sql[ $i + 1 ] ) {
					$value .= '`';
					$i     += 2;
					continue;
				}
				return array( 'value' => $value, 'next' => $i + 1 );
			}
			$value .= $char;
			++$i;
		}
		return null;
	}

	private static function read_string( string $sql, int $i, string $quote ): ?int {
		$length = strlen( $sql );
		++$i;
		while ( $i < $length ) {
			$char = $sql[ $i ];
			if ( '\\' === $char ) {
				$i += 2;
				continue;
			}
			if ( $char === $quote ) {
				if ( $i + 1 < $length && $sql[ $i + 1 ] === $quote ) {
					$i += 2;
					continue;
				}
				return $i + 1;
			}
			++$i;
		}
		return null;
	}
}
