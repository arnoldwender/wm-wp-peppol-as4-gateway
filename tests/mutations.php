<?php
/**
 * Mutations for tests/run-mutations.sh: each entry undoes one correction of the reader pass of 2026-09-15 (or
 * reintroduces the defect it removed) with literal replacements. tests/test-suite.php must fail for every entry.
 * A search string must occur exactly once in its file.
 *
 * Format: [ description, [ [ file, search, replace ], ... ] ]
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

$gen = 'includes/UBL/UblInvoiceGenerator.php';
$val = 'includes/class-peppol-validator.php';
$wc  = 'includes/WooCommerce/WooCommerceInvoiceHook.php';
$api = 'includes/API/RestController.php';
$adm = 'includes/Admin/AdminDashboard.php';
$rcv = 'includes/class-peppol-webhook-receiver.php';

return [
	// --- Package (Plugin Check hidden_files, 2026-09-17) ----------------------------------------------------------------
	[ 'package: the licence-gate allowlist reaches the ZIP again', [ [ '.distignore', "\n.license-allowlist.yml\n", "\n" ] ] ],

	// --- Generator (D15, D12, D13, D14, D35, BR-61, BR-CL-10) ---------------------------------------------------------
	[ 'D15 generator: the invoice number stands in for the buyer reference', [ [ $gen, '$problems[] = \'buyer_reference\';', '$buyer_reference = $invoice_number;' ] ] ],
	[ 'D15 generator: no seller field is required', [ [ $gen, '$required = \'seller\' === $role ? [ \'endpoint\', \'name\', \'street\', \'city\', \'postal_code\', \'country\', \'vat_id\' ] : [ \'endpoint\', \'name\', \'country\' ];', '$required = [];' ] ] ],
	[ 'D16 generator: the seller no longer falls back to the stored settings', [ [ $gen, '$value = $stored[ $field ] ?? \'\';', '$value = \'\';' ] ] ],
	[ 'D15 generator: missing amounts pass', [ [ $gen, '$problems[] = \'amounts\';', '// removed' ] ] ],
	[ 'D15 generator: a line without name passes', [ [ $gen, '$problems[] = \'line.name\';', '// removed' ] ] ],
	[ 'generator: EndpointID repeats the scheme in its value', [ [ $gen, '$endpoint = $this->cbc( $node, \'EndpointID\', $party[\'endpoint_id\'] );', '$endpoint = $this->cbc( $node, \'EndpointID\', $party[\'endpoint\'] );' ] ] ],
	[ 'BR-CL-10 generator: PartyIdentification always emitted', [ [ $gen, 'if ( \'\' !== $party[\'party_id\'] ) {', 'if ( true ) {' ] ] ],
	[ 'D15 generator: PartyTaxScheme without VAT id', [ [ $gen, 'if ( \'\' !== $party[\'vat_id\'] ) {', 'if ( true ) {' ] ] ],
	[ 'BR-CO-09 generator: VAT id without country prefix passes', [ [ $gen, '$problems[] = "{$role}.vat_id_prefix";', '// removed' ] ] ],
	[ 'generator: endpoint without scheme passes', [ [ $gen, '$problems[] = "{$role}.endpoint_format";', '// removed' ] ] ],
	[ 'D14 generator: one VAT breakdown for all rates', [ [ $gen, '$key = $category . \'|\' . number_format( $rate, 2, \'.\', \'\' );', '$key = \'all\';' ] ] ],
	[ 'D12 generator: the gross remainder is not carried', [ [ $gen, '$rounding = round( $rounding + $gross_in - $gross, 2 );', '$rounding = round( $rounding, 2 );' ] ] ],
	[ 'D13 generator: PayableRoundingAmount not emitted', [ [ $gen, 'if ( abs( $totals[\'rounding\'] ) >= 0.005 ) {', 'if ( false ) {' ] ] ],
	[ 'D35 generator: 381 emitted in the Invoice syntax', [ [ $gen, '$problems[] = \'credit_note\';', '// removed' ] ] ],
	[ 'P0100 generator: any type code passes', [ [ $gen, '} elseif ( ! in_array( $type_code, self::INVOICE_TYPE_CODES, true ) ) {', '} elseif ( false ) {' ] ] ],
	[ 'BR-61 generator: credit transfer without IBAN passes', [ [ $gen, '$problems[] = \'seller.iban\';', '// removed' ] ] ],
	[ 'BR-S-05 generator: standard rate at 0 % passes', [ [ $gen, '$problems[] = \'rate_standard\';', '// removed' ] ] ],
	[ 'generator: a non-numeric amount becomes 0', [ [ $gen, '$problems[] = \'amount_not_numeric\';', '// removed' ] ] ],
	[ 'generator: a malformed issue date passes', [ [ $gen, '$problems[] = \'issue_date\';', '// removed' ] ] ],
	[ 'generator: a malformed currency passes', [ [ $gen, '$problems[] = \'currency\';', '// removed' ] ] ],

	// --- Validator (D3, D4, D5) ------------------------------------------------------------------------------------------
	[ 'D3 validator: a document without lines passes', [ [ $val, '$result[\'errors\'][] = "[BR-16] Die Rechnung enthält keine Position (BG-25, cac:{$line_element}).";', '// removed' ] ] ],
	[ 'D3 validator: missing required totals pass', [ [ $val, '$result[\'errors\'][] = "{$label} fehlt.";', '// removed' ] ] ],
	[ 'D3 validator: a non-numeric amount passes', [ [ $val, '$result[\'errors\'][] = "{$label} ist keine Zahl: \"{$raw}\".";', '// removed' ] ] ],
	[ 'D3 validator: no VAT breakdown passes', [ [ $val, '$result[\'errors\'][] = \'[BR-CO-18] Umsatzsteueraufschlüsselung (BG-23, cac:TaxSubtotal) fehlt.\';', '// removed' ] ] ],
	[ 'D4 validator: CreditNote checked for InvoiceTypeCode', [ [ $val, '$type_element = $credit_note ? \'CreditNoteTypeCode\' : \'InvoiceTypeCode\';', '$type_element = \'InvoiceTypeCode\';' ] ] ],
	[ 'D5 validator: Peppol type code lists not applied', [ [ $val, 'if ( ! in_array( $type_code, $allowed, true ) ) {', 'if ( false ) {' ] ] ],
	[ 'D5 validator: type code syntax not checked outside Peppol', [ [ $val, '} elseif ( ! preg_match( \'/^\d{1,3}$/\', $type_code ) ) {', '} elseif ( false ) {' ] ] ],
	[ 'D5 validator: currency syntax not checked', [ [ $val, '} elseif ( ! preg_match( \'/^[A-Z]{3}$/\', $currency ) ) {', '} elseif ( false ) {' ] ] ],
	[ 'validator: R003 not enforced for Peppol BIS', [ [ $val, '} elseif ( $peppol && \'\' === $order_ref ) {', '} elseif ( false ) {' ] ] ],
	[ 'validator: buyer postal address not required (BR-10)', [ [ $val, '$result[\'errors\'][] = \'[BR-10] Anschrift des Käufers (BG-8) fehlt.\';', '// removed' ] ] ],
	[ 'validator: BR-CO-15 tolerance back to 0.02', [ [ $val, 'private const CENT = 0.005;', 'private const CENT = 0.02;' ] ] ],
	[ 'validator: BR-CO-10 not checked', [ [ $val, 'if ( abs( round( $sum, 2 ) - $lines_sum ) >= self::CENT ) {', 'if ( false ) {' ] ] ],
	[ 'validator: BR-CO-13 not checked', [ [ $val, 'if ( null !== $lines_sum && null !== $net && abs( round( $lines_sum - $allowances + $charges, 2 ) - $net ) >= self::CENT ) {', 'if ( false ) {' ] ] ],
	[ 'validator: BR-CO-16 not checked', [ [ $val, 'if ( null !== $gross && null !== $payable && abs( round( $gross - $prepaid + $rounding, 2 ) - $payable ) >= self::CENT ) {', 'if ( false ) {' ] ] ],

	// --- Leitweg-ID (D34) and SMP (D2) ----------------------------------------------------------------------------------
	[ 'Leitweg-ID: structure of the specification not checked', [ [ 'includes/class-peppol-leitweg-validator.php', 'private const PATTERN = \'/^(\d{2,12})(?:-([A-Za-z0-9]{1,30}))?-(\d{2})$/\';', 'private const PATTERN = \'/^(\d{1,13})(?:-([A-Za-z0-9_]{1,31}))?-(\d{2})$/\';' ] ] ],
	[ 'Leitweg-ID: check digits not verified', [ [ 'includes/class-peppol-leitweg-validator.php', 'return 1 === self::mod97( $numeric );', 'return true;' ] ] ],
	[ 'D2 SMP: lookup no longer reports not implemented', [ [ 'includes/Discovery/SmpClient.php', "\tpublic function is_implemented(): bool {\n\t\treturn true;\n\t}", "\tpublic function is_implemented(): bool {\n\t\treturn false;\n\t}" ] ] ],

	// --- REST (D18, D19, D20) -------------------------------------------------------------------------------------------
	[ 'D20 REST: invoice_number undeclared', [ [ $api, '\'invoice_number\'       => $string( 64 ),', '\'invoice_number\'       => [],' ] ] ],
	[ 'D19 REST: a non-JSON body reaches the generator', [ [ $api, 'if ( ! is_array( $params ) || ( [] !== $params && array_keys( $params ) === range( 0, count( $params ) - 1 ) ) ) {', 'if ( false ) {' ] ] ],
	[ 'D18 REST: demo rows reported publicly', [ [ $api, 'if ( null === $invoice || in_array( (int) $invoice[\'id\'], InvoiceStore::demo_ids(), true ) ) {', 'if ( null === $invoice ) {' ] ] ],
	[ 'D19 REST: duplicate invoice number not answered 409', [ [ $api, 'if ( null !== $wpdb->get_var( $wpdb->prepare( \'SELECT id FROM %i WHERE invoice_number = %s\', $table, $document[\'invoice_number\'] ) ) ) {', 'if ( false ) {' ] ] ],
	[ 'D19 REST: a failed insert answers 201', [ [ $api, 'if ( false === $ok ) {', 'if ( false ) {' ] ] ],
	[ 'D19 REST: stored total from the request, not the document', [ [ $api, '\'total_payable_amount\'  => $document[\'totals\'][\'payable\'],', '\'total_payable_amount\'  => (float) ( $params[\'payable_amount\'] ?? 100.00 ),' ] ] ],

	// --- WooCommerce flow (D6, D7, D8, D11, D12, D22, D23, D36) ----------------------------------------------------------
	[ 'D11 WooCommerce: zero-quantity lines not skipped', [ [ $wc, 'if ( $quantity <= 0 ) {', 'if ( false ) {' ] ] ],
	[ 'D36 WooCommerce: the order is invoiced again', [ [ $wc, 'if ( null !== $wpdb->get_var( $wpdb->prepare( \'SELECT id FROM %i WHERE invoice_number = %s\', $table, $number ) ) ) {', 'if ( false ) {' ] ] ],
	[ 'D12 WooCommerce: any difference to the order total is carried', [ [ $wc, 'if ( abs( $difference ) > self::ROUNDING_PER_POSITION * $positions + 0.0001 ) {', 'if ( false ) {' ] ] ],
	[ 'D12 WooCommerce: the cent difference is dropped', [ [ $wc, '$data[\'rounding_amount\'] = $difference;', '$data[\'rounding_amount\'] = 0.0;' ] ] ],
	[ 'D23 WooCommerce: dispatch scheduled without configured services', [ [ $wc, 'if ( ( $this->client ?? new \WM_Peppol_Phase4_Client() )->is_configured() ) {', 'if ( true ) {' ] ] ],
	[ 'D6 WooCommerce: a document the checks refuse is sent', [ [ $wc, 'if ( ! $check[\'valid\'] ) {', 'if ( false ) {' ] ] ],
	[ 'D7 WooCommerce: scheme prefixed onto the receiver id', [ [ $wc, '$message_id = $client->send_as4( (string) $row[\'ubl_xml\'], (string) $row[\'seller_participant_id\'], (string) $row[\'buyer_participant_id\'] );', '$message_id = $client->send_as4( (string) $row[\'ubl_xml\'], (string) $row[\'seller_participant_id\'], \'0204:\' . (string) $row[\'buyer_participant_id\'] );' ] ] ],
	[ 'D10 WooCommerce: a fixed 19 % instead of the order rate', [ [ $wc, 'return (float) \WC_Tax::get_rate_percent_value( (string) array_key_first( $totals ) );', 'return 19.0;' ] ] ],
	[ 'D15 WooCommerce: the invoice number as buyer reference', [ [ $wc, '$reference = trim( (string) $order->get_meta( \'_peppol_leitweg_id\' ) );', '$reference = $number;' ] ] ],
	[ 'D6 WooCommerce: no transmission row', [ [ $wc, '$wpdb->prefix . \'wm_peppol_transmissions\',', '$wpdb->prefix . \'wm_peppol_nowhere\',' ] ] ],
	[ 'D8 Phase4 client: an answer without messageId counts as sent', [ [ 'includes/class-peppol-phase4-client.php', 'if ( \'\' === $message_id ) {', 'if ( false ) {' ] ] ],
	[ 'D23 Phase4 client: localhost default', [ [ 'includes/class-peppol-phase4-client.php', '$this->kosit_url  = rtrim( $kosit_url ?? Settings::kosit_url(), \'/\' );', '$this->kosit_url  = rtrim( $kosit_url ?? \'http://localhost:8080/api/validate\', \'/\' );' ] ] ],
	[ 'D22 order fields: post meta instead of the order meta API', [ [ 'wm-peppol-as4-gateway.php', '$order->update_meta_data( \'_peppol_buyer_reference\', trim( sanitize_text_field( wp_unslash( $_POST[\'_peppol_buyer_reference\'] ) ) ) );', 'update_post_meta( (int) $order_id, \'_peppol_buyer_reference\', trim( sanitize_text_field( wp_unslash( $_POST[\'_peppol_buyer_reference\'] ) ) ) );' ] ] ],
	[ 'order fields: an invalid Leitweg-ID is saved', [ [ 'wm-peppol-as4-gateway.php', 'if ( \'\' !== $leitweg_id && ! WM_Peppol_Leitweg_Validator::is_valid( $leitweg_id ) ) {', 'if ( false ) {' ] ] ],

	// --- Receipt webhook (D21) -------------------------------------------------------------------------------------------
	[ 'receipt: accepted without a configured secret', [ [ $rcv, 'if ( \'\' === $secret ) {', 'if ( false ) {' ] ] ],
	[ 'D21 receipt: status not restricted to SUCCESS and FAILURE', [ [ $rcv, '\'status\'       => [ \'enum\' => [ \'SUCCESS\', \'FAILURE\' ] ] + $string( 16, true ),', '\'status\'       => $string( 16, true ),' ] ] ],
	[ 'D21 receipt: the transmission row is not updated', [ [ $rcv, '\'transport_status\'        => $delivered ? \'delivered\' : \'failed\',', '\'transport_status\'        => \'sent\',' ] ] ],
	[ 'D21 receipt: an unknown messageId answers 200', [ [ $rcv, 'if ( ! is_array( $transmission ) && empty( $orders ) ) {', 'if ( false ) {' ] ] ],

	// --- Admin and widget (D1, D16, D17, D18, D31, D37) ------------------------------------------------------------------
	[ 'D1 admin: the generator form calls a method that does not exist', [ [ $adm, '$document = $this->ubl_generator->build( $data );', '$document = $this->ubl_generator->generate_invoice_xml( $data );' ] ] ],
	[ 'D16 admin: settings saved where nothing reads them', [ [ $adm, 'update_option( $option, $value, false );', 'update_option( \'wm_peppol_settings\', [ $option => $value ], false );' ] ] ],
	[ 'D37 admin: site time instead of GMT', [ [ $adm, '$now = current_time( \'mysql\', true );', '$now = current_time( \'mysql\' );' ] ] ],
	[ 'D18 admin: demo rows stored as delivered', [ [ $adm, '\'status\'                => InvoiceStore::DEMO_STATUS,', '\'status\'                => \'delivered\',' ] ] ],
	[ 'D18 store: the stub rows of the old seeder are not recognised', [ [ 'includes/Core/InvoiceStore.php', 'if ( self::DEMO_STATUS === $row[\'status\'] || \'<Invoice><ID>\' . $row[\'invoice_number\'] . \'</ID></Invoice>\' === $row[\'ubl_xml\'] ) {', 'if ( self::DEMO_STATUS === $row[\'status\'] ) {' ] ] ],
	[ 'D31 admin: no family sheets on the dashboard', [ [ $adm, 'if ( \'index.php\' !== $hook && self::PAGE !== $page ) {', 'if ( self::PAGE !== $page ) {' ] ] ],
	[ 'D31 admin: banner badge class in the light table', [ [ $adm, '\'delivered\'       => [ __( \'Zugestellt (Quittung)\', \'wm-peppol-as4-gateway\' ), \'wm-status-healthy\' ],', '\'delivered\'       => [ __( \'Zugestellt (Quittung)\', \'wm-peppol-as4-gateway\' ), \'wm-badge-green\' ],' ] ] ],
	[ 'D17 widget: no message without tables', [ [ $adm, '<?php if ( ! $counts[\'tables\'] ) : ?>', '<?php if ( false ) : ?>' ] ] ],

	// --- Hub adapter (D17) -----------------------------------------------------------------------------------------------
	[ 'D17 adapter: HEALTHY without tables', [ [ 'includes/Adapters/Peppol_Spoke_Adapter.php', 'return \'ERROR\';', 'return \'HEALTHY\';' ] ] ],
	[ 'D17 adapter: HEALTHY without seller data', [ [ 'includes/Adapters/Peppol_Spoke_Adapter.php', 'return \'NEEDS_CONFIG\';', 'return \'HEALTHY\';' ] ] ],
	[ 'D17 adapter: a readiness literal in the telemetry', [ [ 'includes/Adapters/Peppol_Spoke_Adapter.php', '\'dispatch_configured\'             => Settings::dispatch_configured(),', '\'dispatch_configured\'             => \'OASIS_EBMS3_READY\',' ] ] ],

	// --- Portal (D32, D33) -----------------------------------------------------------------------------------------------
	[ 'D33 portal: fixed ids', [ [ 'includes/Frontend/InvoicePortal.php', '$id     = \'wm-peppol-portal-\' . ( ++self::$instances );', '$id     = \'wm-peppol-portal\';' ] ] ],
	[ 'D32 portal: script strings HTML-escaped', [ [ 'includes/Frontend/InvoicePortal.php', '\'found\'     => __( \'Rechnung gefunden\', \'wm-peppol-as4-gateway\' ),', '\'found\'     => esc_html__( \'Rechnung gefunden\', \'wm-peppol-as4-gateway\' ),' ] ] ],

	// --- CLI (D3, D24) ---------------------------------------------------------------------------------------------------
	[ 'D24 CLI: csv cells not sanitised', [ [ 'includes/CLI/PeppolCommand.php', 'if ( \'csv\' === $format ) {', 'if ( false ) {' ] ] ],
	[ 'D3 CLI: validate calls the file conformant', [ [ 'includes/CLI/PeppolCommand.php', '\'%s (%s) passed the structural checks of this plugin (no schema or Schematron validation). Profile: %s | Payable: %s %s\',', '\'%s (%s) is EN 16931 conformant. Profile: %s | Payable: %s %s\',' ] ] ],

	// --- Uninstall (D28), boot (D24, D27), files (D29), docs --------------------------------------------------------------
	[ 'D28 uninstall: the receipt secret stays', [ [ 'uninstall.php', '\'wm_peppol_receipt_secret\',', '' ] ] ],
	[ 'D28 uninstall: transients stay', [ [ 'uninstall.php', '$wpdb->esc_like( \'_transient_wm_peppol_\' ) . \'%\',', '$wpdb->esc_like( \'_transient_nothing_\' ) . \'%\',' ] ] ],
	[ 'D28 uninstall: the guard files stay', [ [ 'uninstall.php', 'wp_delete_file( $wm_peppol_as4_gateway_dir . \'/\' . $wm_peppol_as4_gateway_file );', '// removed' ] ] ],
	[ 'D27 boot: the directory writer is called again', [ [ 'includes/Core/Plugin.php', '( new RestController( $this->ubl_generator, $this->smp_client ) )->register_routes();', '\WMCyber\Peppol\Security\DirectoryProtector::class; ( new RestController( $this->ubl_generator, $this->smp_client ) )->register_routes();' ] ] ],
	[ 'deactivation: scheduled dispatches stay', [ [ 'includes/Database/Schema.php', 'wp_clear_scheduled_hook( \'wm_peppol_dispatch_invoice\' );', '// removed' ] ] ],
	[ 'D29 files: a shipped file without strict types', [ [ 'includes/class-peppol-phase4-client.php', 'declare(strict_types=1);', '' ] ] ],
	[ 'docs: the README claim "enterprise-grade" is back', [ [ 'README.md', 'A WordPress plugin that builds UBL 2.1 invoices', 'An enterprise-grade WordPress plugin that builds UBL 2.1 invoices' ] ] ],

	// SMP/SML receiver discovery (2026-09-16).
	["SML name back to md5 behind a \"b-\" prefix", [
		['includes/Discovery/SmpClient.php', "\$hash = rtrim( self::base32( hash( 'sha256', strtolower( \$parts['value'] ), true ) ), '=' );\n\n\t\treturn strtolower( \$hash ) . '.' . \$parts['scheme'] . '.' . ( \$zone ?? self::zone() );", "\$hash = md5( \$parts['scheme'] . '::' . strtolower( \$parts['value'] ) );\n\n\t\treturn 'b-' . \$hash . '.' . \$parts['scheme'] . '.' . ( \$zone ?? self::zone() );"],
	]],
	["SML zone back to the old edelivery host", [
		['includes/Discovery/SmpClient.php', "public const SML_ZONE_PRODUCTION = 'participant.sml.prod.tech.peppol.org';", "public const SML_ZONE_PRODUCTION = 'edelivery.tech.ec.europa.eu';"],
	]],
	["SML name keeps the base32 padding", [
		['includes/Discovery/SmpClient.php', "rtrim( self::base32( hash( 'sha256', strtolower( \$parts['value'] ), true ) ), '=' )", "self::base32( hash( 'sha256', strtolower( \$parts['value'] ), true ) )"],
	]],
	["identifier hashed as given instead of lower-cased", [
		['includes/Discovery/SmpClient.php', "hash( 'sha256', strtolower( \$parts['value'] ), true )", "hash( 'sha256', \$parts['value'], true )"],
	]],
	["identifier shape not checked", [
		['includes/Discovery/SmpClient.php', "if ( '' === \$value || ! preg_match( '/^[0-9]{4}:.+\$/', \$value ) ) {", "if ( false ) {"],
	]],
	["NAPTR records not ordered", [
		['includes/Discovery/SmpClient.php', "\t\tusort(\n\t\t\t\$records,\n\t\t\tstatic fn( array \$a, array \$b ): int => [ (int) ( \$a['order'] ?? 0 ), (int) ( \$a['pref'] ?? 0 ) ] <=> [ (int) ( \$b['order'] ?? 0 ), (int) ( \$b['pref'] ?? 0 ) ]\n\t\t);\n\n", ""],
	]],
	["any NAPTR service taken as an SMP", [
		['includes/Discovery/SmpClient.php', "if ( 0 !== strcasecmp( (string) ( \$record['services'] ?? '' ), self::NAPTR_SERVICE ) ) {\n\t\t\t\tcontinue;\n\t\t\t}\n\n", ""],
	]],
	["NAPTR replacement used without checking it is a URL", [
		['includes/Discovery/SmpClient.php', "return preg_match( '#^https?://#i', \$url ) ? \$url : '';", "return \$url;"],
	]],
	["first endpoint taken whatever its transport profile", [
		['includes/Discovery/SmpClient.php', "\t\t\t\$profile = (string) \$endpoint->getAttribute( 'transportProfile' );\n\t\t\tif ( self::TRANSPORT_PROFILE_AS4 !== \$profile ) {\n\t\t\t\tcontinue;\n\t\t\t}\n", "\t\t\t\$profile = (string) \$endpoint->getAttribute( 'transportProfile' );\n"],
	]],
	["a 404 from the SMP reported as a transport error", [
		['includes/Discovery/SmpClient.php', "\t\tif ( 404 === \$code ) {", "\t\tif ( false ) {"],
	]],
	["lookup result not cached", [
		['includes/Discovery/SmpClient.php', "\t\tset_transient( \$cache_key, \$result, self::CACHE_TTL );\n\n", ""],
	]],
	["certificate reported as expired whatever its dates", [
		['includes/Discovery/SmpClient.php', "return \$valid_to > 0 && \$valid_to < time();", "return true;"],
	]],
	["test zone ignored", [
		['includes/Discovery/SmpClient.php', "( \$zone ?? self::zone() )", "self::zone()"],
	]],
	["SMP path without percent-encoding", [
		['includes/Discovery/SmpClient.php', "\t\t\trawurlencode( \$parts['scheme'] . '::' . \$parts['value'] ),\n\t\t\trawurlencode( self::DOCTYPE_SCHEME . '::' . \$document_type )", "\t\t\t\$parts['scheme'] . '::' . \$parts['value'],\n\t\t\tself::DOCTYPE_SCHEME . '::' . \$document_type"],
	]],
	["document_type required again, so its default is unreachable", [
		['includes/API/RestController.php', "'document_type'  => [ 'required' => false ] + \$string( 512 ),", "'document_type'  => \$string( 512 ),"],
	]],
	["EN 16931: a function call left sitting as a path step", [
		['tools/en16931-compile-schematron.php', "\t// First, because the rules below rename the very functions that sit as steps.\n\t\$expr = lift_path_functions( \$expr );", "\t// First, because the rules below rename the very functions that sit as steps."],
	]],
	["EN 16931: a boolean literal compared as XPath 1.0 would", [
		['tools/en16931-compile-schematron.php', "\t\$expr = compare_boolean_literals( (string) \$expr );\n\n\treturn expand_union_steps( \$expr );", "\treturn expand_union_steps( (string) \$expr );"],
	]],
	["EN 16931: only the word form of xs:boolean compared", [
		['tools/en16931-compile-schematron.php', "\t\t\t: \"(\" . \$compared . \" = '\" . \$literal . \"' or \" . \$compared . \" = '\" . \$digit . \"')\";", "\t\t\t: \$compared . \" = '\" . \$literal . \"'\";"],
	]],
	["EN 16931: a rule context read as a path from the root", [
		['tools/en16931-compile-schematron.php', "static fn( string \$branch ): string => str_starts_with( \$branch, '/' ) ? \$branch : '//' . \$branch,", "static fn( string \$branch ): string => \$branch,"],
	]],
	["EN 16931: a date cast compared as a number", [
		['tools/en16931-compile-schematron.php', "\t\$expr = rewrite_calls( \$expr, 'xs:date', static fn( string \$a ): string => \"number(translate(\" . \$a . \", '-', ''))\" );", "\t\$expr = rewrite_calls( \$expr, 'xs:date', static fn( string \$a ): string => \$a );"],
	]],
	["EN 16931: ends-with left as XPath 2.0", [
		['tools/en16931-compile-schematron.php', "\t\t\treturn 'substring(' . \$haystack . ', string-length(' . \$haystack . ') - string-length(' . \$needle . ') + 1) = ' . \$needle;", "\t\t\treturn 'ends-with(' . \$haystack . ', ' . \$needle . ')';"],
	]],
	["EN 16931: a parenthesised step left unexpanded", [
		['tools/en16931-compile-schematron.php', "\t\t\t\$replacement = substr( \$expr, \$path_from, \$at - \$path_from ) . '[' . \$inside . ']';", "\t\t\t\$replacement = substr( \$expr, \$path_from, \$at - \$path_from ) . '/(' . \$inside . ')';"],
	]],
	["EN 16931: a numeric cast in a path step turned into number()", [
		['tools/en16931-compile-schematron.php', "\t\$cast = static fn( string \$a, bool \$after_slash ): string => \$after_slash ? \$a : 'number(' . \$a . ')';", "\t\$cast = static fn( string \$a, bool \$after_slash ): string => 'number(' . \$a . ')';"],
	]],
	["EN 16931: exists() left as XPath 2.0", [
		['tools/en16931-compile-schematron.php', "\t\$expr = rewrite_calls( \$expr, 'exists', static fn( string \$a ): string => 'boolean(' . \$a . ')' );", "\t\$expr = rewrite_calls( \$expr, 'exists', static fn( string \$a ): string => 'exists(' . \$a . ')' );"],
	]],
	["EN 16931: a quantifier not split into a bound variable", [
		['tools/en16931-compile-schematron.php', "\t\t\$bindings[ \$name ] = trim( substr( \$test, \$from, \$at - \$from ) );", "\t\t\$bindings[ \$name ] = '';"],
	]],
	["EN 16931 engine: an expression it cannot evaluate reads as satisfied", [
		['includes/Validation/SchematronValidator.php', "\t\tif ( ! \$usable ) {\n\t\t\treturn null;\n\t\t}", "\t\tif ( ! \$usable ) {\n\t\t\treturn true;\n\t\t}"],
	]],
	["EN 16931 engine: a bound variable read only from a node list", [
		['includes/Validation/SchematronValidator.php', "\t\t\t\t\$literal = is_nan( \$value ) ? null : (string) \$value;", "\t\t\t\t\$literal = null;"],
	]],
	["EN 16931 engine: every rule of a pattern fires, not the first match", [
		['includes/Validation/SchematronValidator.php', "\t\t\t\t\tif ( isset( \$claimed[ \$node ] ) ) {\n\t\t\t\t\t\tcontinue;\n\t\t\t\t\t}\n\t\t\t\t\t\$claimed[ \$node ] = true;", "\t\t\t\t\t\$claimed[ \$node ] = true;"],
	]],
	["EN 16931 engine: a rule marked unsupported is evaluated anyway", [
		['includes/Validation/SchematronValidator.php', "\t\t\t\t\t\tif ( isset( \$assertion['unsupported'] ) ) {\n\t\t\t\t\t\t\tcontinue;\n\t\t\t\t\t\t}\n\n\t\t\t\t\t\t\$evaluated++;", "\t\t\t\t\t\t\$evaluated++;"],
	]],
	["EN 16931 engine: a report treated like an assert", [
		['includes/Validation/SchematronValidator.php', "\$fires = 'report' === \$assertion['type'] ? \$holds : ! \$holds;", "\$fires = ! \$holds;"],
	]],
	["EN 16931: the dispatch gate defaults to blocking", [
		['includes/WooCommerce/WooCommerceInvoiceHook.php', "return '1' === (string) get_option( self::SCHEMATRON_GATE_OPTION, '0' );", "return '1' === (string) get_option( self::SCHEMATRON_GATE_OPTION, '1' );"],
	]],
	["EN 16931: uninstall leaves the dispatch-gate option behind", [
		['uninstall.php', "\n\t'wm_peppol_schematron_gate',", ""],
	]],
	["receipt: the reference digests of SignedInfo are not checked", [
		['includes/AS4/ReceiptEvidence.php', "\t\t[ \$refs_ok, \$refs_total ] = self::check_references( \$signed_info, \$xpath );", "\t\t[ \$refs_ok, \$refs_total ] = [ 1, 1 ];"],
	]],
	["receipt: a reference digest mismatch does not refuse the receipt", [
		['includes/AS4/ReceiptEvidence.php', "\t\tif ( \$refs_ok !== \$refs_total ) {", "\t\tif ( false ) {"],
	]],
	["receipt: the enveloped-signature transform is ignored", [
		['includes/AS4/ReceiptEvidence.php', "\t\tif ( ! \$enveloped ) {\n\t\t\treturn (string) \$node->C14N( \$exclusive, \$comments, null, \$prefixes );\n\t\t}", "\t\treturn (string) \$node->C14N( \$exclusive, \$comments, null, \$prefixes );\n\t\tif ( ! \$enveloped ) {"],
	]],
	["receipt: canonicalised inclusively instead of exclusively", [
		['includes/AS4/ReceiptEvidence.php', "\t\t\$exclusive = self::C14N_INCLUSIVE !== \$algorithm && self::C14N_INCLUSIVE_COMMENTS !== \$algorithm;", "\t\t\$exclusive = false;"],
	]],
	["receipt: an unknown signature algorithm is taken as verified", [
		['includes/AS4/ReceiptEvidence.php', "\t\tif ( ! isset( self::SIGNATURES[ \$algorithm ] ) ) {", "\t\tif ( false ) {"],
	]],
	["receipt: a receipt without any signature is accepted", [
		['includes/AS4/ReceiptEvidence.php', "\t\tif ( ! \$signature instanceof \\DOMElement ) {", "\t\tif ( false && ! \$signature instanceof \\DOMElement ) {"],
	]],
	["receipt: an incomparable digest algorithm counts as a mismatch", [
		['includes/AS4/ReceiptEvidence.php', "\t\treturn \$comparable ? 'no' : 'unknown';", "\t\treturn 'no';"],
	]],
	["receipt: nothing to compare against counts as a match", [
		['includes/AS4/ReceiptEvidence.php', "\t\tif ( null === \$sent || '' === \$sent || [] === \$references ) {\n\t\t\treturn 'unknown';\n\t\t}", "\t\tif ( null === \$sent || '' === \$sent || [] === \$references ) {\n\t\t\treturn 'yes';\n\t\t}"],
	]],
	["receipt: external entities are resolved", [
		['includes/AS4/ReceiptEvidence.php', "\t\t\$loaded = \$document->loadXML( \$xml, LIBXML_NONET );", "\t\t\$loaded = \$document->loadXML( \$xml, LIBXML_NONET | LIBXML_NOENT );"],
	]],
	["receipt: the size limit is not applied", [
		['includes/AS4/ReceiptEvidence.php', "\t\tif ( strlen( \$xml ) > self::MAX_BYTES ) {", "\t\tif ( false ) {"],
	]],
	["receipt: a receipt for another document is accepted anyway", [
		['includes/class-peppol-webhook-receiver.php', "if ( ! \$evidence['signature_verified'] || ! \$evidence['trust_verified'] || 'no' === \$evidence['payload_match'] ) {", "if ( ! \$evidence['signature_verified'] || ! \$evidence['trust_verified'] ) {"],
	]],
	["receipt: an untrusted signer receipt is accepted anyway", [
		['includes/class-peppol-webhook-receiver.php', "if ( ! \$evidence['signature_verified'] || ! \$evidence['trust_verified'] || 'no' === \$evidence['payload_match'] ) {", "if ( ! \$evidence['signature_verified'] || 'no' === \$evidence['payload_match'] ) {"],
	]],
	["receipt: an expired signing certificate is accepted", [
		['includes/AS4/ReceiptEvidence.php', "\t\tif ( '1' === ( \$signer['expired'] ?? '0' ) ) {", "\t\tif ( false ) {"],
	]],
	["receipt: a not-yet-valid signing certificate is accepted", [
		['includes/AS4/ReceiptEvidence.php', "\t\tif ( '1' === ( \$signer['not_yet_valid'] ?? '0' ) ) {", "\t\tif ( false ) {"],
	]],
	["receipt: a configured SHA-256 pin mismatch still counts as trusted", [
		['includes/AS4/ReceiptEvidence.php', "\t\t\t'trust_verified' => false,\n\t\t\t'trust_status'   => 'untrusted',", "\t\t\t'trust_verified' => true,\n\t\t\t'trust_status'   => 'untrusted',"],
	]],
	["receipt: local PKI chain check passes any certificate", [
		['includes/AS4/ReceiptEvidence.php', "\t\t\t\$ok = @openssl_x509_checkpurpose( \$leaf_pem, X509_PURPOSE_ANY, [ \$ca_file ], \$un_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged\n\t\t\treturn true === \$ok;", "\t\t\treturn true;"],
	]],
	["schema: missing PHP extensions do not abort activation", [
		['includes/Database/Schema.php', "\t\tif ( [] === \$missing ) {\n\t\t\treturn;\n\t\t}", "\t\tif ( true ) {\n\t\t\treturn;\n\t\t}"],
	]],
	["uninstall: trusted signer SHA-256 option left behind", [
		['uninstall.php', "\n\t'wm_peppol_trusted_signer_sha256',", ""],
	]],
	["receipt: a rejected receipt is not recorded", [
		['includes/class-peppol-webhook-receiver.php', "                \\WMCyber\\Peppol\\AS4\\DeliveryRegister::record(\n                    is_array( \$transmission ) ? self::invoice_number( (int) \$transmission['invoice_id'] ) : '',\n                    \\WMCyber\\Peppol\\AS4\\DeliveryRegister::ACTION_REJECTED,\n                    \$message_id,\n                    self::evidence_detail( \$evidence )\n                );\n\n                return new WP_Error(", "                return new WP_Error("],
	]],
	["receipt: the receipt is compared against nothing instead of the sent document", [
		['includes/class-peppol-webhook-receiver.php', "            \$sent      = is_array( \$transmission ) ? self::sent_document( (int) \$transmission['invoice_id'] ) : null;", "            \$sent      = null;"],
	]],
	["register: a row is not chained to the one before it", [
		['includes/AS4/DeliveryRegister.php', "\t\t\$current     = self::fingerprint( \$previous, \$invoice_number, \$action, \$message_id, \$detail_json, \$created_at );", "\t\t\$current     = self::fingerprint( self::GENESIS, \$invoice_number, \$action, \$message_id, \$detail_json, \$created_at );"],
	]],
	["register: the detail is left out of the fingerprint", [
		['includes/AS4/DeliveryRegister.php', "implode( '|', [ \$previous, \$invoice_number, \$action, \$message_id, hash( 'sha256', \$detail_json ), \$created_at ] ),", "implode( '|', [ \$previous, \$invoice_number, \$action, \$message_id, \$created_at ] ),"],
	]],
	["register: the link to the previous row is not verified", [
		['includes/AS4/DeliveryRegister.php', "\t\t\tif ( (string) \$row['previous_hash'] !== \$expected ) {", "\t\t\tif ( false ) {"],
	]],
	["register: a changed row still verifies", [
		['includes/AS4/DeliveryRegister.php', "\t\t\tif ( ! hash_equals( \$fingerprint, (string) \$row['current_hash'] ) ) {", "\t\t\tif ( false ) {"],
	]],
	["register: the tip of an empty register is not the genesis hash", [
		['includes/AS4/DeliveryRegister.php', "\t\treturn is_string( \$tip ) && '' !== \$tip ? \$tip : self::GENESIS;", "\t\treturn (string) \$tip;"],
	]],
	["schema: the tables are not brought forward on an in-place update", [
		['wm-peppol-as4-gateway.php', "    \\WMCyber\\Peppol\\Database\\Schema::maybe_upgrade();\n", ""],
	]],
	["uninstall leaves the schema version option behind", [
		['uninstall.php', "\n\t'wm_peppol_schema_version',", ""],
	]],
	["the runtime probe expects the 501 of the stub again", [
		['tests/wp-runtime-peppol.php', "'POST /wm-peppol/v1/lookup refuses a malformed identifier with its own error code", "'POST /wm-peppol/v1/lookup answers 501 (was: 200 with an access point for any id)' . '"],
	]],
	["the hub adapter tells the hub there is no Schematron", [
		['includes/Adapters/Peppol_Spoke_Adapter.php', "structural checks and the official EN 16931 Schematron (artefact 1.3.16), no XSD schema validation", "structural checks, no schema or Schematron validation"],
	]],
	["the plugin header denies the discovery it ships", [
		['wm-peppol-as4-gateway.php', "sucht den Empfänger über SML und SMP", "keine SMP-Empfängersuche"],
	]],
	["docs call discovery missing again although it ships", [
		['README.md', "The plugin does not sign, encrypt or transmit AS4 messages itself. Since 2026-09-16 it does look a Peppol receiver up in the SML and its SMP.", "The plugin does not sign, encrypt or transmit AS4 messages itself, and Peppol receiver discovery (SMP) is not implemented."],
	]],
	["status still reports discovery as not implemented", [
		['includes/CLI/PeppolCommand.php', "'SMP discovery:', 'SML zone ' . SmpClient::zone()", "'SMP discovery:', 'not implemented'"],
	]],
	["uninstall leaves the SML zone option behind", [
		['uninstall.php', "\n\t'wm_peppol_sml_zone',", ""],
	]],
	["CLI lookup hides the error code", [
		['includes/CLI/PeppolCommand.php', "\$result        = \$client->lookup_receiver_endpoint( (string) \$args[0], \$document_type );\n\n\t\tif ( is_wp_error( \$result ) ) {\n\t\t\tWP_CLI::error( sprintf( '[%s] %s', \$result->get_error_code(), \$result->get_error_message() ) );", "\$result        = \$client->lookup_receiver_endpoint( (string) \$args[0], \$document_type );\n\n\t\tif ( is_wp_error( \$result ) ) {\n\t\t\tWP_CLI::error( \$result->get_error_message() );"],
	]],
	["D-UNINSTALL: uninstall wipes options and tables without explicit opt-in", [
		['uninstall.php', "if ( '1' !== (string) \$wm_peppol_delete_data && true !== \$wm_peppol_delete_data ) {\n\treturn;\n}", "if ( false ) {\n\treturn;\n}"],
	]],
	["SEC-02: /invoice-status reverts to __return_true without rate-limit gate", [
		['includes/API/RestController.php', "'permission_callback' => [ \$this, 'check_public_invoice_lookup_access' ],", "'permission_callback' => '__return_true',"],
	]],
	["SEC-02: route rate limiter ignores wm_peppol_rest_rate_limit threshold", [
		['includes/API/RestController.php', "if ( \$seen >= \$limit ) {", "if ( false ) {"],
	]],
	["Gutenberg block: editorScript missing from block.json", [
		['blocks/invoice-portal/block.json', "\"editorScript\": \"wm-peppol-blocks-editor\",", "\"editorScript\": \"\","],
	]],
	["Extensibility: wm_peppol_ready action not fired on boot", [
		['includes/Core/Plugin.php', "do_action( 'wm_peppol_ready', \$this );", "// removed"],
	]],
	["Ecosystem: DynamicBindings not initialized on boot", [
		['includes/Core/Plugin.php', "( new DynamicBindings() )->init();", "// removed"],
	]],

	// --- Multisite & autoload (R2-B8) ------------------------------------------------------------------------------------
	["R2-B8 multisite: wp_initialize_site hook removed from entry file", [
		['wm-peppol-as4-gateway.php', "add_action( 'wp_initialize_site', 'wm_peppol_as4_gateway_initialize_site', 10, 1 );", "// removed"],
	]],
	["R2-B8 multisite: wp_initialize_site runs outside multisite", [
		['wm-peppol-as4-gateway.php', "\tif ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {\n\t\treturn;\n\t}", "\tif ( false ) {\n\t\treturn;\n\t}"],
	]],
	["R2-B8 multisite: wp_initialize_site provisions tables even when not network-activated", [
		['wm-peppol-as4-gateway.php', "\tif ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {\n\t\treturn;\n\t}", "\tif ( false ) {\n\t\treturn;\n\t}"],
	]],
	["R2-B8 multisite: wp_initialize_site does not switch to the new blog", [
		['wm-peppol-as4-gateway.php', "\tswitch_to_blog( (int) \$new_site->blog_id );", "\t// skipped"],
	]],
	["R2-B8 multisite: wp_initialize_site does not restore the current blog", [
		['wm-peppol-as4-gateway.php', "\t\trestore_current_blog();\n\t}", "\t\t// removed\n\t}"],
	]],
	["R2-B8 multisite: Schema::activate ignores network_wide", [
		['includes/Database/Schema.php', "self::for_each_site( \$network_wide, static function (): void { self::create_tables(); } );", "self::create_tables();"],
	]],
	["R2-B8 autoload: wm_peppol_db_version autoload reverts to default", [
		['includes/class-peppol-database.php', "update_option( 'wm_peppol_db_version', self::SCHEMA_VERSION, false );", "update_option( 'wm_peppol_db_version', self::SCHEMA_VERSION );"],
	]],
];
