<?php
declare(strict_types=1);

namespace WMCyber\Peppol\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WMCyber\Peppol\AS4\As4MessagePackager;

final class As4MessagePackagerTest extends TestCase {
	private As4MessagePackager $packager;

	protected function setUp(): void {
		parent::setUp();
		$this->packager = new As4MessagePackager();
	}

	public function test_package_user_message_structure(): void {
		$ubl_xml = '<Invoice>Test</Invoice>';
		$c2 = 'https://ap.sender.de/as4';
		$c3 = 'https://ap.receiver.de/as4';

		$res = $this->packager->package_user_message( $ubl_xml, $c2, $c3, 'MSG-12345' );

		$this->assertSame( 'MSG-12345', $res['message_id'] );
		$this->assertNotEmpty( $res['payload_hash'] );
		$this->assertStringContainsString( '<eb:UserMessage>', $res['soap_envelope'] );
		$this->assertStringContainsString( '<eb:PartyId type="urn:fdc:peppol.eu:2017:identifiers:ap">https://ap.sender.de/as4</eb:PartyId>', $res['soap_envelope'] );
	}
}
