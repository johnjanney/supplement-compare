<?php
/**
 * Form detection (capsule / tablet / softgel / powder / liquid / sublingual
 * / gummy / other). Keyword search, order-sensitive — more specific
 * keywords are checked first so e.g. "softgel" doesn't accidentally match
 * as "gel".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Form_Rule {

	// Order matters: most specific first.
	const PATTERNS = array(
		'softgel'    => array( 'softgels?', 'soft-gels?', 'soft\s+gel' ),
		'sublingual' => array( 'sublinguals?', 'under-the-tongue', 'sub-lingual' ),
		'gummy'      => array( 'gummies', 'gummy' ),
		'capsule'    => array( 'capsules?', 'caps?\b' ),
		'tablet'     => array( 'tablets?', 'tabs?\b' ),
		'powder'     => array( 'powders?', 'powdered' ),
		'liquid'     => array( 'liquids?', 'tinctures?', 'drops?\b', 'syrups?' ),
	);

	/**
	 * @return string|null  one of PROJECTBRIEF.md §3.3 PRODUCT_FORMS, or null
	 */
	public static function extract( $text ) {
		if ( ! is_string( $text ) || $text === '' ) {
			return null;
		}
		foreach ( self::PATTERNS as $form => $patterns ) {
			foreach ( $patterns as $p ) {
				if ( preg_match( '/\b' . $p . '\b/i', $text ) ) {
					return $form;
				}
			}
		}
		return null;
	}
}
