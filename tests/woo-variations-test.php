<?php
/**
 * Standalone tests for WooCommerce variant resolution (v1.43.1). The Store
 * API returns variation objects with an empty `attributes` array; the
 * selections live on the parent's `variations` list (slug values) and in
 * the variation's `variation` string. No WordPress needed:
 *
 *     php tests/woo-variations-test.php
 *
 * The fixture mirrors the real response shape with invented names
 * (CLAUDE.md: no real brands or domains in git).
 */

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/plugin/includes/extractor/class-extractor-woo.php';

$failures = 0;
$passes   = 0;
function check( $label, $expected, $actual ) {
	global $failures, $passes;
	if ( $expected === $actual ) {
		++$passes;
		return;
	}
	++$failures;
	echo "FAIL: {$label}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

$parent = array(
	'id'         => 100,
	'permalink'  => 'https://example.com/shop/example-peptide/',
	'attributes' => array(
		array(
			'id' => 1, 'name' => 'Choose an option', 'taxonomy' => 'pa_choose-an-option', 'has_variations' => true,
			'terms' => array(
				array( 'id' => 220, 'name' => '10mg Kit (100mg)', 'slug' => '10mg-kit-100mg' ),
				array( 'id' => 271, 'name' => '10mg vial', 'slug' => '10mg-vial' ),
			),
		),
		array(
			'id' => 0, 'name' => 'MG', 'taxonomy' => null, 'has_variations' => true,
			'terms' => array( array( 'id' => 0, 'name' => '5', 'slug' => '5' ) ),
		),
	),
	'variations' => array(
		array( 'id' => 101, 'attributes' => array( array( 'name' => 'Choose an option', 'value' => '10mg-vial' ) ) ),
		array( 'id' => 102, 'attributes' => array( array( 'name' => 'Choose an option', 'value' => '10mg-kit-100mg' ) ) ),
		array( 'id' => 103, 'attributes' => array( array( 'name' => 'MG', 'value' => '5' ) ) ),
	),
);

$r     = new ReflectionClass( 'Supcomp_Extractor_Woo' );
$call  = static function ( $name, ...$args ) use ( $r ) {
	$m = $r->getMethod( $name );
	$m->setAccessible( true );
	return $m->invoke( null, ...$args );
};
$title = static function ( array $attrs ) use ( $call ) {
	return $call( 'variant_title_from_attrs', array_map( static function ( $a ) {
		return array( 'name' => $a['name'], 'value' => $a['label'] );
	}, $attrs ) );
};

$map = $call( 'parent_variation_map', $parent );
check( 'map built from parent variations', array( '101', '102', '103' ), array_map( 'strval', array_keys( $map ) ) );

$a = $call( 'resolve_variation_attrs', $parent, array( 'id' => 102, 'attributes' => array() ), $map );
check( 'slug resolved to term name', '10mg Kit (100mg)', $title( $a ) );
check(
	'variant URL uses the slug',
	'https://example.com/shop/example-peptide/?attribute_pa_choose-an-option=10mg-kit-100mg',
	$call( 'variation_url', $parent['permalink'], $parent, array( 'attributes' => $a ) )
);

$a = $call( 'resolve_variation_attrs', $parent, array( 'id' => 103, 'attributes' => array() ), $map );
check( 'unit-named attribute carries its unit', '5 MG', $title( $a ) );

// No parent map: fall back to the variation's own "variation" string.
$a = $call( 'resolve_variation_attrs', $parent, array( 'id' => 101, 'variation' => 'Choose an option: 10mg vial', 'attributes' => array() ), array() );
check( 'variation string fallback', '10mg vial', $title( $a ) );
check( 'fallback recovers the slug', '10mg-vial', $a[0]['value'] );

$a = $call( 'resolve_variation_attrs', $parent, array( 'id' => 104, 'variation' => 'Choose an option: 10mg vial, MG: 5', 'attributes' => array() ), array() );
check( 'multi-attribute string', '10mg vial / 5 MG', $title( $a ) );

// Legacy endpoint: attributes on the variation itself.
$a = $call( 'resolve_variation_attrs', $parent, array( 'id' => 105, 'attributes' => array( array( 'name' => 'Choose an option', 'value' => '10mg-vial' ) ) ), array() );
check( 'legacy attributes still work', '10mg vial', $title( $a ) );

$a = $call( 'resolve_variation_attrs', $parent, array( 'id' => 106, 'attributes' => array() ), $map );
check( 'nothing known → no attributes', array(), $a );

echo "\n{$passes} passed, {$failures} failed\n";
exit( $failures ? 1 : 0 );
