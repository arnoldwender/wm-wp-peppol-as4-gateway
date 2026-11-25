<?php
/**
 * Boot and removed code (D24, D27), strict types and guards (D29), catalogs (D38), documentation that describes
 * the code, and the CI gates.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

use WMCyber\Peppol\Adapters\Peppol_Spoke_Adapter;

pp_group( 'BOOT · the running plugin registers its hooks and writes nothing on a request', static function (): void {
	pp_reset();
	$root   = dirname( __DIR__, 2 );
	$plugin = pp_code( 'includes/Core/Plugin.php' );
	pp_assert( ! str_contains( $plugin, 'DirectoryProtector' ) && ! str_contains( $plugin, 'RequestShield' ), 'boot neither creates uploads/wm-peppol/invoices nor logs the address of flagged request parameters' );
	foreach ( [ 'includes/Security', 'includes/class-peppol-cli.php', 'includes/class-peppol-spoke-adapter.php', 'includes/class-peppol-ubl-builder.php' ] as $gone ) {
		pp_assert( ! file_exists( "{$root}/{$gone}" ), "removed, it had no caller: {$gone}" );
	}

	$ready_plugin = null;
	add_action( 'wm_peppol_ready', static function ( $instance ) use ( &$ready_plugin ): void {
		$ready_plugin = $instance;
	} );
	$before = glob( WP_CONTENT_DIR . '/uploads/*' ) ?: [];
	\WMCyber\Peppol\Core\Plugin::instance()->boot();
	do_action( 'rest_api_init' );
	do_action( 'init' );
	pp_assert( $ready_plugin instanceof \WMCyber\Peppol\Core\Plugin, 'wm_peppol_ready fires with the active plugin instance' );
	pp_assert( ( glob( WP_CONTENT_DIR . '/uploads/*' ) ?: [] ) === $before, 'boot writes nothing under uploads' );
	pp_assert( isset( $GLOBALS['wp_test']['routes']['/wm-peppol/v1/invoice-status'], $GLOBALS['wp_test']['shortcodes']['wm_peppol_invoice_portal'], $GLOBALS['wp_test']['blocks']['wm/peppol-invoice-portal'] ), 'REST routes, portal shortcode and block are registered' );
	pp_assert( 'wm-peppol-blocks-editor' === ( $GLOBALS['wp_test']['blocks']['wm/peppol-invoice-portal']['editorScript'] ?? '' ) && isset( $GLOBALS['wp_test']['script_translations']['wm-peppol-blocks-editor'] ) && is_file( "{$root}/build/index.js" ) && is_file( "{$root}/build/index.asset.php" ), 'wm/peppol-invoice-portal registers compiled editorScript and script translations' );
	pp_assert( isset( $GLOBALS['wp_test']['block_bindings']['wm-peppol/status'], $GLOBALS['wp_test']['gb_tags']['wm_peppol_status'] ), 'WP 6.5+ Block Bindings source wm-peppol/status and GenerateBlocks tag wm_peppol_status are registered' );
	$bound_cb = $GLOBALS['wp_test']['block_bindings']['wm-peppol/status']['get_value_callback'];
	pp_assert( '0 gespeicherte Rechnungen, 0 Empfangsquittungen' === $bound_cb( [ 'key' => 'summary' ] ) && '0' === apply_filters( 'generateblocks_dynamic_tag_replacement', 'x', [ 'tag' => 'wm_peppol_status', 'options' => [ 'key' => 'invoices' ] ] ) && null === apply_filters( 'generateblocks_dynamic_tag_replacement', null, [ 'tag' => 'other_tag' ] ), 'dynamic bindings return measured gateway counts' );
	add_filter( 'wm_peppol_ubl_document', static function ( array $doc ): array {
		$doc['filtered_marker'] = true;
		return $doc;
	} );
	$filtered_doc = \WMCyber\Peppol\Core\Plugin::instance()->get_ubl_generator()->build( pp_invoice() );
	pp_assert( true === ( $filtered_doc['filtered_marker'] ?? false ), 'wm_peppol_ubl_document filters generated UBL results' );
	unset( $GLOBALS['wp_test']['actions']['wm_peppol_ubl_document'] );
	pp_assert( is_file( "{$root}/.wordpress-org/blueprints/blueprint.json" ) && is_file( "{$root}/bom.json" ), 'WordPress Playground blueprint.json and CycloneDX 1.6 bom.json exist' );
	pp_assert( ! empty( $GLOBALS['wp_test']['actions']['woocommerce_order_status_completed'] ) && ! empty( $GLOBALS['wp_test']['actions']['wm_peppol_dispatch_invoice'] ), 'the order flow and the dispatch event are hooked' );

	wm_peppol_as4_gateway_register_spoke_module();
	$modules = apply_filters( 'wm_register_suite_module', [] );
	pp_assert( 1 === count( $modules ) && $modules[0] instanceof Peppol_Spoke_Adapter, 'the hub gets the adapter through wm_register_suite_module' );

	\WMCyber\Peppol\Database\Schema::deactivate();
	pp_assert( in_array( 'wm_peppol_dispatch_invoice', $GLOBALS['wp_test']['cleared'], true ), 'deactivation clears scheduled dispatches' );

	$died = '';
	try {
		\WMCyber\Peppol\Database\Schema::enforce_prerequisites( static fn( string $ext ): bool => 'openssl' !== $ext );
	} catch ( Test_Die $e ) {
		$died = $e->getMessage();
	}
	pp_assert( str_contains( $died, 'openssl' ) && in_array( plugin_basename( WM_PEPPOL_FILE ), $GLOBALS['wp_test']['deactivated'], true ), 'missing required PHP extension deactivates cleanly with wp_die naming the missing extension' );
} );

pp_group( 'MULTISITE · wp_initialize_site, network activation and option autoload=false', static function (): void {
	pp_reset();
	global $wpdb;

	$init_hooks = $GLOBALS['pp_boot_hooks']['actions']['wp_initialize_site'] ?? [];
	pp_assert( in_array( 'wm_peppol_as4_gateway_initialize_site', $init_hooks, true ), 'entry file hooks wp_initialize_site to wm_peppol_as4_gateway_initialize_site' );
	foreach ( $init_hooks as $cb ) {
		add_action( 'wp_initialize_site', $cb, 10, 1 );
	}

	// 1. Not multisite: wp_initialize_site does not switch blogs or create tables on site 2.
	$GLOBALS['wp_test']['is_multisite']           = false;
	$GLOBALS['wp_test']['network_active_plugins'] = [ plugin_basename( WM_PEPPOL_FILE ) ];
	do_action( 'wp_initialize_site', (object) [ 'blog_id' => 2 ] );
	pp_assert( [] === $GLOBALS['wp_test']['switch_log'] && null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', 'wp_2_wm_peppol_invoices' ) ), 'wp_initialize_site does nothing outside multisite' );

	// 2. Multisite without network activation: new site does not get tables automatically.
	$GLOBALS['wp_test']['is_multisite']           = true;
	$GLOBALS['wp_test']['network_active_plugins'] = [];
	do_action( 'wp_initialize_site', (object) [ 'blog_id' => 2 ] );
	pp_assert( [] === $GLOBALS['wp_test']['switch_log'] && null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', 'wp_2_wm_peppol_invoices' ) ), 'wp_initialize_site does nothing when the plugin is not network-activated' );

	// 3. Multisite + network-activated: invalid site objects are ignored; valid site gets all 3 tables and restores blog context.
	$GLOBALS['wp_test']['network_active_plugins'] = [ plugin_basename( WM_PEPPOL_FILE ) ];
	do_action( 'wp_initialize_site', (object) [ 'blog_id' => 0 ] );
	pp_assert( [] === $GLOBALS['wp_test']['switch_log'], 'wp_initialize_site ignores a site without positive blog_id' );

	do_action( 'wp_initialize_site', (object) [ 'blog_id' => 2 ] );
	$site2_tables = [
		$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', 'wp_2_wm_peppol_invoices' ) ),
		$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', 'wp_2_wm_peppol_transmissions' ) ),
		$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', 'wp_2_wm_peppol_audit_log' ) ),
	];
	pp_assert(
		[ 'wp_2_wm_peppol_invoices', 'wp_2_wm_peppol_transmissions', 'wp_2_wm_peppol_audit_log' ] === $site2_tables
		&& [ [ 'switch', 2 ], [ 'restore' ] ] === $GLOBALS['wp_test']['switch_log']
		&& 'wp_' === $wpdb->prefix
		&& 1 === $GLOBALS['wp_test']['blog_id']
		&& [] === $GLOBALS['wp_test']['blog_stack']
		&& \WMCyber\Peppol\Database\Schema::VERSION === ( $GLOBALS['wp_test']['blog_options'][2][ \WMCyber\Peppol\Database\Schema::VERSION_OPTION ] ?? '' ),
		'wp_initialize_site provisions all three tables and schema version on the new multisite blog and restores the original blog'
	);

	// 4. Network activation ($network_wide = true) provisions all existing sites and network deactivation clears cron on all sites.
	$GLOBALS['wp_test']                 = wm_test_state();
	$GLOBALS['wpdb']                    = new Test_WPDB();
	$wpdb                               = $GLOBALS['wpdb'];
	$GLOBALS['wp_test']['is_multisite'] = true;
	$GLOBALS['wp_test']['sites']        = [ 1, 2, 3 ];
	\WMCyber\Peppol\Database\Schema::activate( true );
	$all_sites_ok = true;
	foreach ( [ 'wp_', 'wp_2_', 'wp_3_' ] as $prefix ) {
		foreach ( [ 'wm_peppol_invoices', 'wm_peppol_transmissions', 'wm_peppol_audit_log' ] as $tbl ) {
			if ( ( $prefix . $tbl ) !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix . $tbl ) ) ) {
				$all_sites_ok = false;
			}
		}
	}
	pp_assert( $all_sites_ok && 'wp_' === $wpdb->prefix && [] === $GLOBALS['wp_test']['blog_stack'], 'network-wide activation creates all three tables on every site in the network and restores blog context' );

	\WMCyber\Peppol\Database\Schema::deactivate( true );
	pp_assert( 3 === count( $GLOBALS['wp_test']['cleared'] ) && 'wp_' === $wpdb->prefix && [] === $GLOBALS['wp_test']['blog_stack'], 'network-wide deactivation clears scheduled dispatches on every site' );

	// 5. Option autoload=false on schema version and legacy database options.
	pp_reset();
	pp_assert( false === ( $GLOBALS['wp_test']['option_autoloads'][ \WMCyber\Peppol\Database\Schema::VERSION_OPTION ] ?? null ), 'Schema::activate sets wm_peppol_schema_version with autoload=false' );
	$GLOBALS['wpdb'] = new Test_WPDB();
	WM_Peppol_Database::create_tables();
	WM_Peppol_Database::record_invoice( [ 'invoice_number' => 'AUTOLOAD-1', 'gross_amount' => 119.00, 'payload_hash' => hash( 'sha256', 'a' ) ] );
	pp_assert(
		false === ( $GLOBALS['wp_test']['option_autoloads']['wm_peppol_db_version'] ?? null )
		&& false === ( $GLOBALS['wp_test']['option_autoloads'][ WM_Peppol_Database::TIP_OPTION ] ?? null ),
		'WM_Peppol_Database sets wm_peppol_db_version and wm_peppol_ledger_tip with autoload=false'
	);
} );

pp_group( 'FILES · every shipped PHP file has strict types and a direct-access guard', static function (): void {
	$root      = dirname( __DIR__, 2 );
	$files     = pp_shipped_php();
	$no_strict = [];
	$no_guard  = [];
	$emoji     = 0;
	foreach ( $files as $file ) {
		$code = (string) file_get_contents( "{$root}/{$file}" );
		if ( ! str_contains( $code, 'declare(strict_types=1);' ) ) {
			$no_strict[] = $file;
		}
		if ( ! preg_match( "/defined\\(\\s*'(ABSPATH|WP_UNINSTALL_PLUGIN)'\\s*\\)/", $code ) ) {
			$no_guard[] = $file;
		}
		$emoji += preg_match_all( '/[\x{1F300}-\x{1FAFF}]/u', $code );
	}
	pp_assert( count( $files ) >= 20, 'the file list is not empty (' . count( $files ) . ' files)' );
	pp_assert( [] === $no_strict, 'strict types in every shipped file (was: missing in five)' . ( $no_strict ? ': ' . implode( ', ', $no_strict ) : '' ) );
	pp_assert( [] === $no_guard, 'a direct-access guard in every shipped file (was: missing in three)' . ( $no_guard ? ': ' . implode( ', ', $no_guard ) : '' ) );
	pp_assert( 0 === $emoji, 'no emoji in shipped PHP' );

	// Plugin Check fails the distribution view on any hidden file (`hidden_files`, an ERROR). Measured
	// 2026-09-17: .license-allowlist.yml reached the ZIP because .distignore did not name it. Every
	// dot-entry at the root must be excluded by name — the rule, not just the one file that slipped.
	$ignored   = array_map( 'trim', (array) file( "{$root}/.distignore", FILE_IGNORE_NEW_LINES ) );
	$dot_files = array_values( array_filter( (array) scandir( $root ), static fn( $f ): bool => is_string( $f ) && '.' === ( $f[0] ?? '' ) && ! in_array( $f, [ '.', '..' ], true ) ) );
	$shipped   = array_values( array_filter( $dot_files, static fn( string $f ): bool => ! in_array( $f, $ignored, true ) && ! in_array( "{$f}/", $ignored, true ) ) );
	pp_assert( [] === $shipped, 'no hidden file at the root reaches the package (Plugin Check hidden_files)' . ( $shipped ? ': ' . implode( ', ', $shipped ) : '' ) );
} );

pp_group( 'CATALOGS · built from the code, readable by WordPress, no double-encoded strings', static function (): void {
	$root = dirname( __DIR__, 2 );
	$pot  = "{$root}/languages/wm-peppol-as4-gateway.pot";
	$ids  = pp_po_msgids( $pot );
	foreach ( [ 'Peppol Gateway', 'Rechnung erzeugen', 'Die Straße des Verkäufers fehlt.', 'Rechnungsstatus abfragen' ] as $id ) {
		pp_assert( in_array( $id, $ids, true ), "the template holds \"{$id}\"" );
	}
	pp_assert( [] === array_filter( $ids, static fn( string $id ): bool => str_contains( $id, 'Factura' ) || str_contains( $id, 'Glosario' ) ), 'no msgid of the former Spanish admin page is left' );
	pp_assert( ! str_contains( (string) file_get_contents( $pot ), '.po:' ), 'no template entry points at a catalog instead of code (was: 52 such entries)' );

	$catalogs   = glob( "{$root}/languages/wm-peppol-as4-gateway-*.po" ) ?: [];
	$mojibake   = [];
	$unreadable = [];
	foreach ( $catalogs as $po ) {
		if ( preg_match( '/Ã[\x{0080}-\x{00BF}\x{0152}\x{0153}\x{0178}\x{201A}-\x{201E}]/u', (string) file_get_contents( $po ) ) ) {
			$mojibake[] = basename( $po );
		}
		$mo = substr( $po, 0, -3 ) . '.mo';
		if ( ! is_file( $mo ) || ! pp_mo_readable( $mo ) ) {
			$unreadable[] = basename( $mo );
		}
	}
	pp_assert( count( $catalogs ) >= 20 && [] === $unreadable, 'every compiled catalog passes the checks WordPress makes before loading it (was: de_DE rejected, 0 entries)' . ( $unreadable ? ': ' . implode( ', ', $unreadable ) : '' ) );
	pp_assert( [] === $mojibake, 'no double-encoded UTF-8 in the catalogs (was: 2195 strings)' . ( $mojibake ? ': ' . implode( ', ', $mojibake ) : '' ) );
} );

pp_group( 'DOCS AND CI · the documents describe the code; CI runs every gate', static function (): void {
	$root = dirname( __DIR__, 2 );
	$docs = '';
	foreach ( [ 'README.md', 'readme.txt', 'CLAUDE.md', 'docs/ARCHITECTURE.md', 'docs/DEVELOPER-GUIDE.md', 'docs/LEGAL-COMPLIANCE.md' ] as $file ) {
		$docs .= "\n" . (string) file_get_contents( "{$root}/{$file}" );
	}
	$claims = [
		'signs message envelopes',
		'WS-Security 1.1 (XMLDSig',
		'dynamic SMP receiver discovery',
		'Dynamic SML / SMP Discovery',
		'BR-DE-01',
		'Schematron & Schema Validation',
		'Every received and transmitted invoice is sealed',
		'Neutralizes CSV Formula Injection attacks on invoice metadata exports',
		'Generates and parses Standard Business Document Headers',
		'enterprise-grade',
		'fails the build if any goes missing',
	];
	foreach ( $claims as $claim ) {
		pp_assert( ! str_contains( $docs, $claim ), "the docs no longer claim: \"{$claim}\"" );
	}

	// 'dynamic SMP receiver discovery', 'Dynamic SML / SMP Discovery' and 'resolve a receiver access
	// point' were on the list above until 2026-09-16, when Discovery/SmpClient stopped being a stub.
	// They are not banned now, they are BACKED: a document may say them only while the code that makes
	// them true is there, so gutting discovery and leaving the prose fails here instead of shipping.
	$smp = (string) file_get_contents( "{$root}/includes/Discovery/SmpClient.php" );
	$backed = [
		'dynamic SMP receiver discovery'  => 'function lookup_receiver_endpoint',
		'Dynamic SML / SMP Discovery'     => 'function resolve_smp_url',
		'resolve a receiver access point' => 'TRANSPORT_PROFILE_AS4',
	];
	// No entry in tests/mutations.php isolates this one: falsifying it means removing discovery AND
	// keeping the prose, and removing discovery turns half the suite red on its own. It is a coupling
	// guard, kept for the day someone rewrites SmpClient, not a measured gate.
	foreach ( $backed as $claim => $symbol ) {
		pp_assert( ! str_contains( $docs, $claim ) || str_contains( $smp, $symbol ), "a document claims \"{$claim}\" while {$symbol} is gone" );
	}

	// The rot in the other direction: the code shipped and the prose still calls it missing. Measured
	// on 2026-09-16 — `wp peppol status` printed "SMP discovery: not implemented" with discovery live,
	// and the PLUGIN HEADER, which is the first description a site owner reads on the Plugins screen,
	// still said "keine SMP-Empfängersuche" hours after discovery shipped. The header is scanned too.
	// The runtime probe joins the scan: it runs only inside WordPress, so nothing here executes it, and
	// its assertion that /lookup answers 501 survived this whole gate and was caught by the deployment.
	// The adapter is what the hub shows about this plugin, so it speaks for the whole plugin; the CLI
	// message speaks only for `wp peppol validate`, which genuinely does not run the rule set — that
	// one stays. A ban that also catches a true sentence gets switched off within a week.
	$docs .= "\n" . (string) file_get_contents( "{$root}/tests/wp-runtime-peppol.php" ) . "\n" . (string) file_get_contents( "{$root}/includes/CLI/PeppolCommand.php" ) . "\n" . (string) file_get_contents( "{$root}/wm-peppol-as4-gateway.php" ) . "\n" . (string) file_get_contents( "{$root}/includes/Adapters/Peppol_Spoke_Adapter.php" );
	foreach ( [ 'receiver discovery (SMP) is not implemented', 'Reports that SMP discovery is not implemented', 'reports that SMP discovery is not implemented, exit 1', 'Always 501, not implemented', "/wm-peppol/v1/lookup answers 501", "'SMP discovery:', 'not implemented'", 'Schema and Schematron validation.', 'not a Schematron run', 'keine SMP-Empfängersuche', 'structural checks, no schema or Schematron validation' ] as $stale ) {
		pp_assert( ! str_contains( $docs, $stale ), "no document still calls discovery missing: \"{$stale}\"" );
	}
	pp_assert( 0 === preg_match_all( '/[\x{1F300}-\x{1FAFF}\x{2328}]/u', $docs ), 'no emoji in the documents' );
	pp_assert( str_contains( $docs, 'not implemented' ) && str_contains( $docs, 'PayableRoundingAmount' ) && str_contains( $docs, 'wp peppol generate-ubl' ), 'the docs state what is not implemented and describe the current commands' );

	$ci = (string) file_get_contents( "{$root}/.github/workflows/ci.yml" );
	foreach ( [ 'php tests/test-suite.php', 'php tests/test-ui-family.php', 'bash tests/run-mutations.sh', 'python3 .github/scripts/security-audit.py', 'vendor/bin/phpunit', 'pdo_sqlite', 'en16931-schematron-conformance.php --corpus=tests/fixtures/en16931-1.3.16 --min=100', 'en16931-compile-schematron.php --download --check', 'make-as4-receipt.php --out="${RUNNER_TEMP}/as4" --self-check' ] as $step ) {
		pp_assert( str_contains( $ci, $step ), "CI runs: {$step}" );
	}
	pp_assert( is_file( "{$root}/tests/mutations.php" ) && count( (array) require "{$root}/tests/mutations.php" ) >= 30, 'the mutation list defends the corrections (at least 30 entries)' );
} );
