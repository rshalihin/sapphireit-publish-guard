<?php
/**
 * Locks the public rule API against accidental change.
 *
 * @package ProductPublishGuard
 */

namespace ProductPublishGuard\Tests\Unit;

use ProductPublishGuard\Engine\Abstract_Rule;
use ProductPublishGuard\Engine\Product_Context;
use ProductPublishGuard\Engine\Rule_Interface;
use ProductPublishGuard\Engine\Rule_Registry;
use ProductPublishGuard\Engine\Rule_Result;
use ProductPublishGuard\Engine\Save_Path;
use ProductPublishGuard\Engine\Severity;
use ProductPublishGuard\Engine\Status;
use ProductPublishGuard\Engine\Type_Family;
use ProductPublishGuard\Engine\Validation_Result;
use ProductPublishGuard\Settings\Settings;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionParameter;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Compares every member tagged `@api` with `tests/fixtures/rule-api-v2.json`.
 *
 * Third-party and Pro rules are written against this surface. A removal or a signature
 * change has to bump `Rule_Registry::API_VERSION`; an addition only has to update the
 * fixture and the README. Either way, it cannot happen by accident.
 *
 * @since 1.2.0
 */
final class Rule_Api_Contract_Test extends TestCase {

	/**
	 * The types the contract covers (coding-plan.md section 3.4).
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	private const TYPES = array(
		Rule_Interface::class,
		Abstract_Rule::class,
		Product_Context::class,
		Rule_Result::class,
		Validation_Result::class,
		Rule_Registry::class,
		Severity::class,
		Status::class,
		Save_Path::class,
		Type_Family::class,
		Settings::class,
	);

	/**
	 * The contract fixture.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	private static function fixture(): array {
		$json = file_get_contents( SIT_WCPG_PATH . 'tests/fixtures/rule-api-v2.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local fixture.

		return json_decode( (string) $json, true );
	}

	/**
	 * Whether a docblock carries an `@api` tag of its own, not just the word in prose.
	 *
	 * @since 1.2.0
	 *
	 * @param string|false $doc The docblock.
	 * @return bool
	 */
	private static function is_tagged( $doc ): bool {
		return false !== $doc && 1 === preg_match( '/^\s*\*\s*@api\b/m', $doc );
	}

	/**
	 * A parameter's default value, as the fixture spells it.
	 *
	 * @since 1.2.0
	 *
	 * @param ReflectionParameter $parameter The parameter.
	 * @return string
	 */
	private static function render_default( ReflectionParameter $parameter ): string {
		if ( $parameter->isDefaultValueConstant() ) {
			return (string) $parameter->getDefaultValueConstantName();
		}

		$value = $parameter->getDefaultValue();

		if ( null === $value ) {
			return 'null';
		}

		return array() === $value ? 'array()' : var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Rendering a scalar default for comparison.
	}

	/**
	 * A method's signature, as `(<type> $<name>[ = <default>], …): <return>`.
	 *
	 * @since 1.2.0
	 *
	 * @param ReflectionMethod $method The method.
	 * @return string
	 */
	private static function render_signature( ReflectionMethod $method ): string {
		$parameters = array();

		foreach ( $method->getParameters() as $parameter ) {
			$rendered = trim( (string) $parameter->getType() . ' $' . $parameter->getName() );

			if ( $parameter->isDefaultValueAvailable() ) {
				$rendered .= ' = ' . self::render_default( $parameter );
			}

			$parameters[] = $rendered;
		}

		return '(' . implode( ', ', $parameters ) . ')' . ( $method->hasReturnType() ? ': ' . (string) $method->getReturnType() : '' );
	}

	/**
	 * Every member of a type that is tagged `@api`, as the fixture spells it.
	 *
	 * Public so the fixture can be regenerated from it and reviewed by hand.
	 *
	 * @since 1.2.0
	 *
	 * @param string $type Fully qualified type name.
	 * @return array{constants: array<string, mixed>, methods: array<string, string>}
	 */
	public static function surface( string $type ): array {
		$reflection = new ReflectionClass( $type );
		$surface    = array(
			'constants' => array(),
			'methods'   => array(),
		);

		foreach ( $reflection->getReflectionConstants( ReflectionClassConstant::IS_PUBLIC ) as $constant ) {
			if ( $constant->getDeclaringClass()->getName() === $type && self::is_tagged( $constant->getDocComment() ) ) {
				$surface['constants'][ $constant->getName() ] = $constant->getValue();
			}
		}

		foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED ) as $method ) {
			if ( $method->getDeclaringClass()->getName() === $type && self::is_tagged( $method->getDocComment() ) ) {
				$surface['methods'][ $method->getName() ] = self::render_signature( $method );
			}
		}

		return $surface;
	}

	/**
	 * The fixture's types, as a data provider.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function data_types(): array {
		$types = array();

		foreach ( array_keys( self::fixture()['types'] ) as $type ) {
			$types[ $type ] = array( $type );
		}

		return $types;
	}

	/**
	 * The version the code reports is the version the fixture describes.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_api_version_matches_fixture(): void {
		$this->assertSame( self::fixture()['api_version'], Rule_Registry::API_VERSION );
	}

	/**
	 * No type can leave the contract by dropping out of the fixture.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_every_contract_type_is_in_the_fixture(): void {
		$listed = array_keys( self::fixture()['types'] );

		foreach ( self::TYPES as $type ) {
			$this->assertContains( $type, $listed, $type . ' is missing from rule-api-v2.json.' );
		}
	}

	/**
	 * The code's `@api` members are exactly the fixture's, with the same signatures.
	 *
	 * @since 1.2.0
	 *
	 * @dataProvider data_types
	 *
	 * @param string $type Fully qualified type name.
	 * @return void
	 */
	public function test_public_surface_matches_fixture( string $type ): void {
		$expected = self::fixture()['types'][ $type ];
		$actual   = self::surface( $type );

		foreach ( array( 'constants', 'methods' ) as $kind ) {
			foreach ( $expected[ $kind ] as $name => $value ) {
				$this->assertArrayHasKey( $name, $actual[ $kind ], "{$type}::{$name} was removed from the API — bump API_VERSION." );
				$this->assertSame( $value, $actual[ $kind ][ $name ], "{$type}::{$name} changed — bump API_VERSION." );
			}

			foreach ( array_keys( $actual[ $kind ] ) as $name ) {
				$this->assertArrayHasKey( $name, $expected[ $kind ], "{$type}::{$name} is a new @api member — add it to rule-api-v2.json and README." );
			}
		}
	}

	/**
	 * Every method the fixture promises says so where its author will see it.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_every_api_member_is_tagged(): void {
		foreach ( self::fixture()['types'] as $type => $members ) {
			foreach ( array_keys( $members['methods'] ) as $name ) {
				$this->assertTrue( self::is_tagged( ( new ReflectionMethod( $type, $name ) )->getDocComment() ), "{$type}::{$name} has no @api tag." );
			}

			foreach ( array_keys( $members['constants'] ) as $name ) {
				$this->assertTrue( self::is_tagged( ( new ReflectionClassConstant( $type, $name ) )->getDocComment() ), "{$type}::{$name} has no @api tag." );
			}
		}
	}
}
