<?php
/**
 * PHPUnit bootstrap: the UBL generator, its settings and exception, the AS4 packager and the SMP client, without
 * WordPress. Settings::seller() returns empty values here, so every test passes its parties.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

define( 'WM_PEPPOL_TESTING', true );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
}
if ( ! defined( 'WM_PEPPOL_PATH' ) ) {
	define( 'WM_PEPPOL_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'WM_PEPPOL_VERSION' ) ) {
	define( 'WM_PEPPOL_VERSION', '1.2.2' );
}

require_once dirname( __DIR__ ) . '/includes/Core/Settings.php';
require_once dirname( __DIR__ ) . '/includes/UBL/InvoiceDataException.php';
require_once dirname( __DIR__ ) . '/includes/UBL/UblInvoiceGenerator.php';
require_once dirname( __DIR__ ) . '/includes/AS4/As4MessagePackager.php';
require_once dirname( __DIR__ ) . '/includes/Discovery/SmpClient.php';
