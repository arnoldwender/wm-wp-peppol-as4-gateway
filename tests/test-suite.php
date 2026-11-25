<?php
/**
 * WM Peppol AS4 Gateway — standalone test suite (no WordPress).
 *
 * Loads the real entry file against the stubs in tests/support/ and a $wpdb that runs SQL on SQLite with the
 * tables of includes/Database/Schema.php, then runs the groups in tests/groups/ in file order. Each group runs in
 * its own try/catch; a group that throws counts as one failure and the run goes on to the results line. PHP
 * notices and warnings are errors.
 *
 * Run:       php tests/test-suite.php
 * Exit:      0 only when every assertion passed; the last line is "TEST SUITE RESULTS: N PASSED | M FAILED".
 * Mutations: bash tests/run-mutations.sh (tests/mutations.php undoes one correction per entry; this suite must fail).
 * Needs:     pdo_sqlite, dom, mbstring.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

// --- Environment ---------------------------------------------------------------------------------------------------

$pp_tmp = sys_get_temp_dir() . '/wm-peppol-suite-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
mkdir( $pp_tmp . '/wp/wp-admin/includes', 0777, true );
mkdir( $pp_tmp . '/wp-content/uploads', 0777, true );
file_put_contents( $pp_tmp . '/wp/wp-admin/includes/upgrade.php', "<?php\n" );

define( 'ABSPATH', $pp_tmp . '/wp/' );
define( 'WP_CONTENT_DIR', $pp_tmp . '/wp-content' );
define( 'AUTH_KEY', 'suite-auth-key' );

register_shutdown_function(
	static function () use ( $pp_tmp ): void {
		if ( ! is_dir( $pp_tmp ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $pp_tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $pp_tmp );
	}
);

set_error_handler(
	static function ( int $errno, string $errstr, string $errfile = '', int $errline = 0 ): bool {
		if ( ! ( error_reporting() & $errno ) ) {
			return false;
		}
		throw new ErrorException( $errstr, 0, $errno, $errfile, $errline );
	}
);

require __DIR__ . '/support/wp-stubs.php';
require __DIR__ . '/support/wc-cli-stubs.php';
require __DIR__ . '/support/hub-contract.php';
require __DIR__ . '/support/sqlite-wpdb.php';

$GLOBALS['wpdb'] = new Test_WPDB();
require dirname( __DIR__ ) . '/wm-peppol-as4-gateway.php';
$GLOBALS['pp_boot_hooks'] = [
	'actions'      => $GLOBALS['wp_test']['actions'],
	'activation'   => $GLOBALS['wp_test']['activation'] ?? null,
	'deactivation' => $GLOBALS['wp_test']['deactivation'] ?? null,
];

// --- Harness -------------------------------------------------------------------------------------------------------

$GLOBALS['pp'] = [ 'passed' => 0, 'failed' => 0 ];

function pp_assert( bool $ok, string $label ): void {
	$GLOBALS['pp'][ $ok ? 'passed' : 'failed' ]++;
	echo ( $ok ? '  [PASS] ' : '  [FAIL] ' ) . $label . "\n";
}

function pp_group( string $title, callable $fn ): void {
	echo "\n[{$title}]\n";
	try {
		$fn();
	} catch ( Throwable $t ) {
		pp_assert( false, "{$title}: ran to its end (" . get_class( $t ) . ': ' . $t->getMessage() . ' at ' . basename( $t->getFile() ) . ':' . $t->getLine() . ')' );
	}
}

/** Fresh recorder, fresh database with the plugin's tables, empty fakes. */
function pp_reset(): void {
	$GLOBALS['wp_test']          = wm_test_state();
	$GLOBALS['wpdb']             = new Test_WPDB();
	\WMCyber\Peppol\Database\Schema::activate();
	WC_Tax::$rates               = [];
	WC_Admin_Meta_Boxes::$errors = [];
	WP_CLI::$out                 = [];
}

/** Source of a plugin file without comments, so an explanation of an old defect does not count as the defect. */
function pp_code( string $relative ): string {
	$code = '';
	foreach ( token_get_all( (string) file_get_contents( dirname( __DIR__ ) . '/' . $relative ) ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
			continue;
		}
		$code .= is_array( $token ) ? $token[1] : $token;
	}
	return $code;
}

/** Every PHP file the plugin ships. */
function pp_shipped_php(): array {
	$root  = dirname( __DIR__ );
	$files = [ 'wm-peppol-as4-gateway.php', 'uninstall.php' ];
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( str_ends_with( (string) $file, '.php' ) ) {
			$files[] = substr( (string) $file, strlen( $root ) + 1 );
		}
	}
	sort( $files );
	return $files;
}

function pp_render( callable $fn ): string {
	ob_start();
	try {
		$fn();
	} finally {
		$out = (string) ob_get_clean();
	}
	return $out;
}

// --- Invoice fixtures ------------------------------------------------------------------------------------------------

function pp_seller(): array {
	return [
		'endpoint'    => '9930:DE111222333',
		'name'        => 'Muster & Söhne GmbH',
		'legal_name'  => 'Muster & Söhne GmbH',
		'street'      => 'Weg 1',
		'city'        => 'Halle (Saale)',
		'postal_code' => '06108',
		'country'     => 'DE',
		'vat_id'      => 'DE111222333',
		'company_id'  => '',
		'iban'        => 'DE89370400440532013000',
	];
}

function pp_seller_options( array $overrides = [] ): void {
	$seller = array_replace( pp_seller(), $overrides );
	foreach ( \WMCyber\Peppol\Core\Settings::SELLER_OPTIONS as $field => $option ) {
		update_option( $option, $seller[ $field ] );
	}
}

function pp_buyer(): array {
	return [
		'endpoint'    => '0088:4000000000001',
		'name'        => 'Beispiel Käufer AG',
		'street'      => 'Platz 2',
		'city'        => 'Leipzig',
		'postal_code' => '04109',
		'country'     => 'DE',
	];
}

function pp_invoice( array $overrides = [] ): array {
	return array_replace(
		[
			'invoice_number'  => 'T-2026-0001',
			'issue_date'      => '2026-09-15',
			'buyer_reference' => '04011000-1234512345-06',
			'seller'          => pp_seller(),
			'buyer'           => pp_buyer(),
			'lines'           => [ [ 'name' => 'Beratung', 'quantity' => 2, 'unit_price' => 50, 'vat_rate' => 19 ] ],
		],
		$overrides
	);
}

function pp_build( array $data ): array {
	return ( new \WMCyber\Peppol\UBL\UblInvoiceGenerator() )->build( $data );
}

/** Problem codes of the InvoiceDataException the call raises, null when it raises none. */
function pp_problems( callable $fn ): ?array {
	try {
		$fn();
		return null;
	} catch ( \WMCyber\Peppol\UBL\InvoiceDataException $e ) {
		return $e->problems();
	}
}

function pp_xpath( string $xml ): DOMXPath {
	$dom = new DOMDocument();
	$dom->loadXML( $xml );
	$x = new DOMXPath( $dom );
	$x->registerNamespace( 'ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2' );
	$x->registerNamespace( 'cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2' );
	$x->registerNamespace( 'cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2' );
	return $x;
}

function pp_text( DOMXPath $x, string $expr ): string {
	$nodes = $x->query( $expr );
	return ( false !== $nodes && $nodes->length > 0 ) ? (string) $nodes->item( 0 )->nodeValue : '';
}

function pp_attr( DOMXPath $x, string $expr, string $attribute ): string {
	$nodes = $x->query( $expr );
	return ( false !== $nodes && $nodes->length > 0 && $nodes->item( 0 ) instanceof DOMElement ) ? $nodes->item( 0 )->getAttribute( $attribute ) : '';
}

function pp_count( DOMXPath $x, string $expr ): int {
	$nodes = $x->query( $expr );
	return false === $nodes ? 0 : $nodes->length;
}

/** Change a document through DOM and return the new XML. */
function pp_edit_xml( string $xml, callable $fn ): string {
	$dom = new DOMDocument();
	$dom->loadXML( $xml );
	$x = new DOMXPath( $dom );
	$x->registerNamespace( 'cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2' );
	$x->registerNamespace( 'cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2' );
	$fn( $x, $dom );
	return (string) $dom->saveXML();
}

/** Remove every node an expression matches. */
function pp_remove( DOMXPath $x, string $expr ): void {
	foreach ( iterator_to_array( $x->query( $expr ) ) as $node ) {
		$node->parentNode->removeChild( $node );
	}
}

function pp_errors( string $xml ): string {
	return implode( ' | ', WM_Peppol_Validator::validate( $xml )['errors'] );
}

// --- Measurement helpers ---------------------------------------------------------------------------------------------

/** WCAG contrast ratio of two #rrggbb colours. */
function pp_contrast( string $fg, string $bg ): float {
	$luminance = static function ( string $hex ): float {
		$channels = array_map( static fn( string $h ): float => hexdec( $h ) / 255, str_split( ltrim( $hex, '#' ), 2 ) );
		$channels = array_map( static fn( float $v ): float => $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4, $channels );
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	};
	$a = $luminance( $fg );
	$b = $luminance( $bg );
	return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
}

/** Custom properties with a #rrggbb value; the first definition of a name wins (the default palette). */
function pp_css_tokens( string $css ): array {
	preg_match_all( '/(--[a-z0-9-]+)\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $css, $m, PREG_SET_ORDER );
	$tokens = [];
	foreach ( $m as $match ) {
		$tokens[ $match[1] ] ??= strtolower( $match[2] );
	}
	return $tokens;
}

/** msgids of a .po / .pot file, continuation lines joined. */
function pp_po_msgids( string $file ): array {
	$ids     = [];
	$current = null;
	$open    = false;
	foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		if ( str_starts_with( $line, 'msgid "' ) ) {
			if ( null !== $current ) {
				$ids[] = $current;
			}
			$current = stripcslashes( substr( $line, 7, -1 ) );
			$open    = true;
			continue;
		}
		if ( $open && str_starts_with( $line, '"' ) ) {
			$current .= stripcslashes( substr( $line, 1, -1 ) );
			continue;
		}
		$open = false;
	}
	if ( null !== $current ) {
		$ids[] = $current;
	}
	return array_values( array_filter( $ids, static fn( string $id ): bool => '' !== $id ) );
}

/** The checks MO::import_from_reader() makes before it accepts a catalog (wp-includes/pomo/mo.php). */
function pp_mo_readable( string $file ): bool {
	$data = (string) file_get_contents( $file );
	if ( strlen( $data ) < 28 ) {
		return false;
	}
	if ( 0x950412de === unpack( 'V', substr( $data, 0, 4 ) )[1] ) {
		$e = 'V';
	} elseif ( 0x950412de === unpack( 'N', substr( $data, 0, 4 ) )[1] ) {
		$e = 'N';
	} else {
		return false;
	}
	$h = unpack( "{$e}revision/{$e}total/{$e}orig/{$e}trans/{$e}hashlen/{$e}hashaddr", substr( $data, 4, 24 ) );
	if ( 0 !== $h['revision'] || $h['trans'] - $h['orig'] !== $h['total'] * 8 || $h['hashaddr'] - $h['trans'] !== $h['total'] * 8 ) {
		return false;
	}
	for ( $i = 0; $i < $h['total']; $i++ ) {
		foreach ( [ $h['orig'], $h['trans'] ] as $table ) {
			$entry = unpack( "{$e}len/{$e}pos", substr( $data, $table + $i * 8, 8 ) );
			if ( $entry['pos'] + $entry['len'] > strlen( $data ) ) {
				return false;
			}
		}
	}
	return true;
}

// --- Run ---------------------------------------------------------------------------------------------------------------

echo "==============================================================================\n";
echo '  WM PEPPOL AS4 GATEWAY — STANDALONE TEST SUITE (PHP ' . PHP_VERSION . ", SQLite)\n";
echo "==============================================================================\n";

foreach ( glob( __DIR__ . '/groups/*.php' ) ?: [] as $pp_group_file ) {
	require $pp_group_file;
}

echo "\n==============================================================================\n";
echo "  TEST SUITE RESULTS: {$GLOBALS['pp']['passed']} PASSED | {$GLOBALS['pp']['failed']} FAILED\n";
exit( 0 === $GLOBALS['pp']['failed'] && $GLOBALS['pp']['passed'] > 0 ? 0 : 1 );
