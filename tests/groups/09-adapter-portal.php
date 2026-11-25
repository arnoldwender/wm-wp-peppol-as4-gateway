<?php
/**
 * Hub adapter (D17) and public status portal (D30, D32, D33, portal claims).
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

use WMCyber\Peppol\Adapters\Peppol_Spoke_Adapter;
use WMCyber\Peppol\Frontend\InvoicePortal;

pp_group( 'HUB ADAPTER · health, telemetry and self-test measure the install', static function (): void {
	pp_reset();
	$adapter = new Peppol_Spoke_Adapter();

	$GLOBALS['wpdb'] = new Test_WPDB();
	pp_assert( 'ERROR' === $adapter->get_health_status(), 'without tables: ERROR (was: HEALTHY on every install)' );
	pp_reset();
	pp_assert( 'NEEDS_CONFIG' === $adapter->get_health_status(), 'tables without seller data: NEEDS_CONFIG' );
	pp_seller_options();
	pp_assert( 'HEALTHY' === $adapter->get_health_status(), 'seller data, no dispatch: HEALTHY' );
	update_option( 'wm_peppol_kosit_url', 'https://kosit.example.test' );
	update_option( 'wm_peppol_phase4_url', 'https://phase4.example.test' );
	pp_assert( 'DEGRADED' === $adapter->get_health_status(), 'dispatch configured without receipt secret: DEGRADED' );
	update_option( 'wm_peppol_receipt_secret', 'secret' );
	pp_assert( 'HEALTHY' === $adapter->get_health_status(), 'with the secret: HEALTHY' );

	$metrics = $adapter->get_telemetry_metrics();
	pp_assert( true === $metrics['tables_present'] && 0 === $metrics['stored_invoices'] && true === $metrics['dispatch_configured'] && 'not_implemented' === $metrics['smp_discovery'], 'telemetry reports measured values' );
	$flat = (string) json_encode( [ $metrics, $adapter->get_sbom_data(), $adapter->get_description() ], JSON_UNESCAPED_UNICODE );
	foreach ( [ 'COMPLIANT', 'READY', 'GoBD', '147 AO', 'WS-Security', 'konform' ] as $claim ) {
		pp_assert( ! str_contains( $flat, $claim ), "no \"{$claim}\" in telemetry, SBOM or description" );
	}

	pp_reset();
	pp_assert( false === $adapter->execute_action( 'self_test' )['success'], 'the self-test without seller data fails (was: "EN 16931 konform" without tables)' );
	pp_seller_options();
	$test = $adapter->execute_action( 'self_test' );
	pp_assert( true === $test['success'] && ! str_contains( $test['message'], 'konform' ) && str_contains( $test['message'], 'nicht gesendet' ), 'with seller data the self-test builds and checks a document and says what it did not do' . ( $test['success'] ? '' : ': ' . $test['message'] ) );
	pp_assert( [] === $GLOBALS['wpdb']->rows( 'wp_wm_peppol_invoices' ), 'the self-test stores nothing' );
	pp_assert( false === $adapter->execute_action( 'drop_everything' )['success'], 'an unknown action is refused' );
} );

pp_group( 'PORTAL · own ids per instance, no inline colours, no download or signature claim, unescaped script strings', static function (): void {
	pp_reset();
	$GLOBALS['wp_test']['translations']['Rechnung gefunden'] = 'Rechnung & Beleg gefunden';
	$portal = new InvoicePortal();
	$html   = $portal->render_portal() . $portal->render_portal();

	preg_match_all( '/\sid="([^"]+)"/', $html, $ids );
	pp_assert( count( $ids[1] ) >= 8 && count( $ids[1] ) === count( array_unique( $ids[1] ) ), 'two portals on one page have no duplicate id (was: six duplicates, the second form without handler)' );
	preg_match_all( '/<label for="([^"]+)"/', $html, $fors );
	pp_assert( 4 === count( $fors[1] ) && [] === array_diff( $fors[1], $ids[1] ), 'every label points at an input that exists' );
	pp_assert( ! str_contains( $html, 'style=' ) && 0 === preg_match_all( '/#[0-9a-fA-F]{6}\b/', $html ) && 0 === preg_match_all( '/[\x{1F300}-\x{1FAFF}]/u', $html ), 'no inline style, hex colour or emoji (was: 44 hex colours and an emoji in the button)' );
	foreach ( [ 'UBL-Download', 'laden Sie', 'kryptografisch', 'EN 16931 & PEPPOL' ] as $claim ) {
		pp_assert( ! str_contains( $html, $claim ), "no claim \"{$claim}\"" );
	}

	preg_match( '/data-wm-peppol-portal="([^"]+)"/', $html, $attribute );
	$config = json_decode( html_entity_decode( $attribute[1] ?? '', ENT_QUOTES ), true );
	pp_assert( 'Rechnung & Beleg gefunden' === ( $config['i18n']['found'] ?? null ) && str_ends_with( (string) ( $config['endpoint'] ?? '' ), 'wm-peppol/v1/invoice-status' ), 'script strings arrive unescaped (was: esc_html__() and textContent would show "&amp;")' );
	pp_assert( in_array( 'wm-peppol-portal', $GLOBALS['wp_test']['styles'], true ) && in_array( 'wm-peppol-portal', $GLOBALS['wp_test']['scripts'], true ), 'the portal enqueues its own sheet and script' );

	$root = dirname( __DIR__, 2 );
	$js   = (string) file_get_contents( $root . '/assets/js/wm-peppol-portal.js' );
	pp_assert( str_contains( $js, 'textContent' ) && ! str_contains( $js, 'innerHTML' ) && str_contains( $js, "querySelectorAll( '[data-wm-peppol-portal]' )" ), 'the script binds every portal and writes text only' );

	$css         = (string) file_get_contents( $root . '/assets/css/wm-peppol-portal.css' );
	$hex_outside = 0;
	foreach ( preg_split( '/\R/', $css ) ?: [] as $line ) {
		if ( ! preg_match( '/--[a-z0-9-]+\s*:/', $line ) && preg_match( '/#[0-9a-fA-F]{3,6}\b/', $line ) ) {
			++$hex_outside;
		}
	}
	pp_assert( 0 === $hex_outside && ! preg_match( '/outline\s*:\s*(none|0)\b/', $css ), 'colours only on token lines, no outline removed' );

	$tokens = pp_css_tokens( $css );
	$pairs  = [
		[ '--wm-peppol-text', '--wm-peppol-bg' ],
		[ '--wm-peppol-muted', '--wm-peppol-bg' ],
		[ '--wm-peppol-label', '--wm-peppol-bg' ],
		[ '--wm-peppol-value', '--wm-peppol-bg' ],
		[ '--wm-peppol-input-text', '--wm-peppol-input-bg' ],
		[ '--wm-peppol-muted', '--wm-peppol-input-bg' ],
		[ '--wm-peppol-button-text', '--wm-peppol-accent' ],
		[ '--wm-peppol-button-text', '--wm-peppol-accent-end' ],
		[ '--wm-peppol-accent', '--wm-peppol-bg' ],
		[ '--wm-peppol-warning', '--wm-peppol-bg' ],
		[ '--wm-peppol-danger', '--wm-peppol-bg' ],
	];
	foreach ( $pairs as [ $fg, $bg ] ) {
		$ratio = isset( $tokens[ $fg ], $tokens[ $bg ] ) ? pp_contrast( $tokens[ $fg ], $tokens[ $bg ] ) : 0.0;
		pp_assert( $ratio >= 4.5, sprintf( '%s on %s measures %.2f:1', $fg, $bg, $ratio ) );
	}
} );
