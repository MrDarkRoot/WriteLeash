<?php namespace BulkPriceEditor\PriceModifiers;

/**
 * Evaluates the price expressions used by the "Update by formula" modifier.
 *
 * An expression is either a plain number or arithmetic ( + - * /,
 * parentheses and the min()/max() functions ) over numbers and {variable}
 * placeholders, e.g. "{regular_price} * 0.8" or
 * "max({regular_price} * 0.7, 9.99)". Evaluation is done by a small
 * parser — never by eval() — and anything invalid returns null
 * ("leave the price alone").
 */
class PriceFormula {

	const MAX_LENGTH = 200;

	/**
	 * @param  string  $expression
	 * @param  array   $variables  name => numeric value; a referenced variable
	 *                             with a non-numeric value (e.g. a product
	 *                             without a sale price) makes the whole
	 *                             expression inapplicable.
	 *
	 * @return float|null null when the expression does not apply or is invalid
	 */
	public static function evaluate( string $expression, array $variables = array() ): ?float {

		$expression = trim( $expression );

		if ( '' === $expression || strlen( $expression ) > self::MAX_LENGTH ) {
			return null;
		}

		if ( is_numeric( $expression ) ) {
			return (float) $expression;
		}

		$missingVariable = false;

		$expression = preg_replace_callback( '/\{\s*([a-z_]+)\s*\}/i',
			function ( $matches ) use ( $variables, &$missingVariable ) {

				$name = strtolower( $matches[1] );

				if ( ! isset( $variables[ $name ] ) || ! is_numeric( $variables[ $name ] ) ) {
					$missingVariable = true;

					return '0';
				}

				return '(' . (float) $variables[ $name ] . ')';
			}, $expression );

		if ( $missingVariable ) {
			return null;
		}

		if ( ! preg_match( '/^(?:[0-9+\-*\/().,\s]|min|max)+$/i', $expression ) ) {
			return null;
		}

		return self::evaluateArithmetic( $expression );
	}

	protected static function evaluateArithmetic( string $expression ): ?float {

		$expression = strtolower( str_replace( ' ', '', $expression ) );

		preg_match_all( '/\d*\.?\d+|min|max|[+\-*\/(),]/', $expression, $matches );

		$tokens = $matches[0];

		// Every character must belong to a token, otherwise the input is malformed.
		if ( implode( '', $tokens ) !== $expression ) {
			return null;
		}

		$position = 0;

		$value = self::parseExpression( $tokens, $position );

		if ( is_null( $value ) || $position !== count( $tokens ) ) {
			return null;
		}

		return $value;
	}

	protected static function parseExpression( array $tokens, int &$position ): ?float {

		$value = self::parseTerm( $tokens, $position );

		if ( is_null( $value ) ) {
			return null;
		}

		while ( isset( $tokens[ $position ] ) && in_array( $tokens[ $position ], array( '+', '-' ), true ) ) {

			$operator = $tokens[ $position ++ ];

			$right = self::parseTerm( $tokens, $position );

			if ( is_null( $right ) ) {
				return null;
			}

			$value = '+' === $operator ? $value + $right : $value - $right;
		}

		return $value;
	}

	protected static function parseTerm( array $tokens, int &$position ): ?float {

		$value = self::parseFactor( $tokens, $position );

		if ( is_null( $value ) ) {
			return null;
		}

		while ( isset( $tokens[ $position ] ) && in_array( $tokens[ $position ], array( '*', '/' ), true ) ) {

			$operator = $tokens[ $position ++ ];

			$right = self::parseFactor( $tokens, $position );

			if ( is_null( $right ) ) {
				return null;
			}

			if ( '/' === $operator ) {
				if ( abs( $right ) < 1e-9 ) {
					return null;
				}

				$value /= $right;
			} else {
				$value *= $right;
			}
		}

		return $value;
	}

	protected static function parseFactor( array $tokens, int &$position ): ?float {

		if ( ! isset( $tokens[ $position ] ) ) {
			return null;
		}

		$token = $tokens[ $position ];

		if ( 'min' === $token || 'max' === $token ) {
			$position ++;

			$arguments = self::parseFunctionArguments( $tokens, $position );

			if ( is_null( $arguments ) ) {
				return null;
			}

			return 'min' === $token ? min( $arguments ) : max( $arguments );
		}

		if ( '-' === $token || '+' === $token ) {
			$position ++;

			$value = self::parseFactor( $tokens, $position );

			if ( is_null( $value ) ) {
				return null;
			}

			return '-' === $token ? - $value : $value;
		}

		if ( '(' === $token ) {
			$position ++;

			$value = self::parseExpression( $tokens, $position );

			if ( is_null( $value ) || ! isset( $tokens[ $position ] ) || ')' !== $tokens[ $position ] ) {
				return null;
			}

			$position ++;

			return $value;
		}

		if ( is_numeric( $token ) ) {
			$position ++;

			return (float) $token;
		}

		return null;
	}

	/**
	 * Parses "( expr [, expr ...] )" and returns the argument values.
	 */
	protected static function parseFunctionArguments( array $tokens, int &$position ): ?array {

		if ( ! isset( $tokens[ $position ] ) || '(' !== $tokens[ $position ] ) {
			return null;
		}

		$position ++;

		$arguments = array();

		$value = self::parseExpression( $tokens, $position );

		if ( is_null( $value ) ) {
			return null;
		}

		$arguments[] = $value;

		while ( isset( $tokens[ $position ] ) && ',' === $tokens[ $position ] ) {
			$position ++;

			$value = self::parseExpression( $tokens, $position );

			if ( is_null( $value ) ) {
				return null;
			}

			$arguments[] = $value;
		}

		if ( ! isset( $tokens[ $position ] ) || ')' !== $tokens[ $position ] ) {
			return null;
		}

		$position ++;

		return $arguments;
	}
}
