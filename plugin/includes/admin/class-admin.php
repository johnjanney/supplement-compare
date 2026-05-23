<?php
/**
 * Admin menu registration.
 *
 * Top-level "Supplement Compare" menu lands on the Pending Queue — the
 * operator's main daily workflow. Capability check (`manage_options`) is
 * enforced both here (by add_menu_page / add_submenu_page) and again
 * inside each screen's render() as defense in depth.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Supcomp_Admin {

	const CAPABILITY = 'manage_options';

	public static function register_menu() {
		add_menu_page(
			__( 'Supplement Compare', 'supplement-compare' ),
			__( 'Supplement Compare', 'supplement-compare' ),
			self::CAPABILITY,
			'supcomp-pending',
			array( 'Supcomp_Pending_Queue_Screen', 'render' ),
			'dashicons-list-view',
			30
		);

		$submenus = array(
			array( 'supcomp-pending',        __( 'Pending Queue', 'supplement-compare' ),       array( 'Supcomp_Pending_Queue_Screen', 'render' ) ),
			array( 'supcomp-active',         __( 'Active Offers', 'supplement-compare' ),       array( 'Supcomp_Active_Offers_Screen', 'render' ) ),
			array( 'supcomp-import',         __( 'Import', 'supplement-compare' ),              array( 'Supcomp_Import_Screen', 'render' ) ),
			array( 'supcomp-extract-sites',  __( 'Extractor Sites', 'supplement-compare' ),     array( 'Supcomp_Extract_Sites_Screen', 'render' ) ),
			array( 'supcomp-extract-runs',   __( 'Extractor Runs', 'supplement-compare' ),      array( 'Supcomp_Extract_Runs_Screen', 'render' ) ),
			array( 'supcomp-merchants',      __( 'Merchants', 'supplement-compare' ),           array( 'Supcomp_Merchants_Screen', 'render' ) ),
			array( 'supcomp-ingredients',    __( 'Ingredients', 'supplement-compare' ),         array( 'Supcomp_Ingredients_Screen', 'render' ) ),
			array( 'supcomp-canonical',      __( 'Canonical Products', 'supplement-compare' ),  array( 'Supcomp_Canonical_Products_Screen', 'render' ) ),
			array( 'supcomp-clicks',         __( 'Clicks', 'supplement-compare' ),              array( 'Supcomp_Clicks_Screen', 'render' ) ),
			array( 'supcomp-cleanup',        __( 'Cleanup', 'supplement-compare' ),             array( 'Supcomp_Cleanup_Screen', 'render' ) ),
			array( 'supcomp-settings',       __( 'Settings', 'supplement-compare' ),            array( 'Supcomp_Settings', 'render' ) ),
		);

		foreach ( $submenus as $row ) {
			list( $slug, $title, $callback ) = $row;
			add_submenu_page(
				'supcomp-pending',
				$title,
				$title,
				self::CAPABILITY,
				$slug,
				$callback
			);
		}

		// Hidden submenu — registered so WP recognizes the page slug, but no
		// menu entry. Reached via "Delete" links on entity admin screens.
		add_submenu_page(
			null,
			__( 'Confirm deletion', 'supplement-compare' ),
			__( 'Confirm deletion', 'supplement-compare' ),
			self::CAPABILITY,
			'supcomp-delete',
			array( 'Supcomp_Deletion_Admin', 'render' )
		);
	}

	/**
	 * Shared placeholder body used by Phase 1 screens that don't have real
	 * functionality yet. Centralizes the capability check + heading layout.
	 */
	public static function render_placeholder( $heading, $phase_note ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'supplement-compare' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $heading ); ?></h1>
			<p><?php echo esc_html( $phase_note ); ?></p>
			<p>
				<em><?php esc_html_e( 'Plugin version:', 'supplement-compare' ); ?></em>
				<code><?php echo esc_html( SUPPLEMENT_COMPARE_VERSION ); ?></code>
			</p>
		</div>
		<?php
	}
}
