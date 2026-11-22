<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Tests of lib/peppol.lib.php (made-up data only).
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests of the search, matching and request helpers of the module
 */
class PeppolLibTest extends TestCase
{
	/**
	 * Document type of a Peppol BIS Billing 3.0 invoice, as the directory lists it
	 */
	const INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice##urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0::2.1';

	/**
	 * A made-up third party
	 *
	 * @param string $vat     VAT number
	 * @param string $profid1 Professional ID 1 (KvK number in the Netherlands)
	 * @param string $name    Name
	 * @return stdClass
	 */
	private function thirdparty($vat, $profid1, $name)
	{
		$soc = new stdClass();
		$soc->tva_intra = $vat;
		$soc->idprof1 = $profid1;
		$soc->idprof2 = '';
		$soc->idprof3 = '';
		$soc->idprof4 = '';
		$soc->idprof5 = '';
		$soc->idprof6 = '';
		$soc->name = $name;
		$soc->country_code = 'NL';
		return $soc;
	}

	/**
	 * A directory match as the Peppol directory returns it
	 *
	 * @param string   $id       Participant ID, e.g. 0106:12345678
	 * @param string[] $doctypes Document types
	 * @return array<string,mixed>
	 */
	private function match($id, $doctypes)
	{
		$m = array(
			'participantID' => array('scheme' => 'iso6523-actorid-upis', 'value' => $id),
			'entities' => array(array('name' => array(array('name' => 'Example Company B.V.')), 'countryCode' => 'NL', 'geoInfo' => 'Utrecht', 'regDate' => '2024-05-01')),
		);
		if ($doctypes !== null) {
			$m['docTypes'] = array();
			foreach ($doctypes as $d) {
				$m['docTypes'][] = array('scheme' => 'busdox-docid-qns', 'value' => $d);
			}
		}
		return $m;
	}

	/**
	 * Search terms: VAT number, then professional ID 1, then name; normalised, without duplicates or short numbers
	 *
	 * @return void
	 */
	public function testDirectorySearchCandidates()
	{
		$this->assertSame(
			array('vat' => 'NL001234567B01', 'profid1' => '12345678', 'name' => 'Example Company B.V.'),
			peppolDirectorySearchCandidates($this->thirdparty('NL 0012.34.567.B01', '1234 5678', 'Example  Company B.V.'))
		);
		$this->assertSame(array('name' => 'Only A Name'), peppolDirectorySearchCandidates($this->thirdparty('', '', 'Only A Name')));
		$this->assertSame(array('name' => 'Short'), peppolDirectorySearchCandidates($this->thirdparty('', '12', 'Short')));
		$this->assertSame(array('vat' => 'BE0123456789', 'name' => 'Same'), peppolDirectorySearchCandidates($this->thirdparty('BE0123456789', 'BE0123456789', 'Same')));
	}

	/**
	 * A directory match: scheme and value, invoice support and the matching field of the third party
	 *
	 * @return void
	 */
	public function testDirectoryMatchInfo()
	{
		$soc = $this->thirdparty('NL001234567B01', '12345678', 'Example Company B.V.');

		$info = peppolDirectoryMatchInfo($this->match('0106:12345678', array(self::INVOICE)), $soc);
		$this->assertSame('0106:12345678', $info['participantId']);
		$this->assertSame('0106', $info['icd']);
		$this->assertSame('12345678', $info['value']);
		$this->assertTrue($info['acceptsInvoice']);
		$this->assertSame('idprof1', $info['matchedField']);
		$this->assertSame('Example Company B.V.', $info['name']);
		$this->assertSame('2024-05-01', $info['regDate']);

		$info = peppolDirectoryMatchInfo($this->match('9944:nl001234567b01', array('urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2::CreditNote##x')), $soc);
		$this->assertFalse($info['acceptsInvoice']);
		$this->assertSame('tva_intra', $info['matchedField']);

		$info = peppolDirectoryMatchInfo($this->match('0106:99999999', null), $soc);
		$this->assertNull($info['acceptsInvoice']);
		$this->assertSame('', $info['matchedField']);

		$this->assertSame('', peppolDirectoryMatchInfo('not an array')['participantId']);
	}

	/**
	 * The kind of number behind a scheme code
	 *
	 * @return void
	 */
	public function testSchemeLabel()
	{
		global $langs;
		$langs->load('peppol@peppolpeppyrus');
		$this->assertStringContainsString('KvK', peppolSchemeLabel('0106'));
		$this->assertStringContainsString('NL', peppolSchemeLabel('9944'));
		$this->assertSame('', peppolSchemeLabel('1234'));
		$this->assertSame('', peppolSchemeLabel('abc'));
	}

	/**
	 * The way back after the Peppol ID search is built from a fixed list, never from the request
	 *
	 * @return void
	 */
	public function testThirdpartyBackUrl()
	{
		$this->assertSame(DOL_URL_ROOT.'/societe/card.php?socid=12', peppolThirdpartyBackUrl(12, 'card'));
		$this->assertSame(DOL_URL_ROOT.'/comm/card.php?socid=12', peppolThirdpartyBackUrl('12', 'comm'));
		$this->assertSame(DOL_URL_ROOT.'/fourn/card.php?socid=12', peppolThirdpartyBackUrl(12, 'fourn'));
		$this->assertSame(DOL_URL_ROOT.'/societe/card.php?socid=12', peppolThirdpartyBackUrl(12, 'https://example.invalid/'));
		$this->assertSame('', peppolThirdpartyBackUrl(0, 'card'));
	}

	/**
	 * Requests only go over HTTPS to the Peppyrus API (all methods) or the Peppol directory (GET); nothing else is sent
	 *
	 * @return void
	 */
	public function testApiRequestRefusesOtherHosts()
	{
		foreach (array(
			array('https://example.invalid/v1/', 'GET'),
			array('http://api.peppyrus.be/v1/', 'GET'),
			array('https://api.peppyrus.be:8443/v1/', 'GET'),
			array('https://user@api.peppyrus.be/v1/', 'GET'),
			array('https://directory.peppol.eu/search/1.0/json', 'POST'),
			array('https://api.peppyrus.be/v1/', 'TRACE'),
		) as $case) {
			$result = peppolApiRequest($case[0], $case[1], '', array());
			$this->assertSame(-1, $result['curl_error_no'], $case[1].' '.$case[0]);
			$this->assertSame(0, $result['http_code']);
		}
	}

	/**
	 * The booking link is only there with the module enabled; without it (as in the tests) it is empty
	 *
	 * @return void
	 */
	public function testBookingUrlNeedsModule()
	{
		global $conf;
		$conf->modules = array();
		$doc = new stdClass();
		$doc->id = 5;
		$doc->fk_invoice = 0;
		$this->assertSame('', peppolBookingUrl($doc, null));
		$this->assertSame('', peppolBookingUrl(null, null));
	}

	/**
	 * The About page shows the most recent versions of the changelog, without the title of the file
	 *
	 * @return void
	 */
	public function testChangelogExcerpt()
	{
		$md = "# Changelog\n\n## 3.0\n\n- C\n\n## 2.0\n\n- B\n\n## 1.0\n\n- A\n";
		$out = peppolChangelogExcerpt($md, 2);
		$this->assertStringContainsString('## 3.0', $out);
		$this->assertStringContainsString('## 2.0', $out);
		$this->assertStringNotContainsString('## 1.0', $out);
		$this->assertStringNotContainsString('# Changelog', $out);
		$this->assertStringContainsString('## 1.0', peppolChangelogExcerpt($md, 10));
		$this->assertSame('', peppolChangelogExcerpt('', 5));
	}

	/**
	 * Dolibarr older than 22 gets a notice; 22 and newer not
	 *
	 * @return void
	 */
	public function testDolibarrBelowRecommended()
	{
		$this->assertTrue(peppolDolibarrBelowRecommended('18.0.4'));
		$this->assertTrue(peppolDolibarrBelowRecommended('21.0.3'));
		$this->assertFalse(peppolDolibarrBelowRecommended('22.0.0'));
		$this->assertFalse(peppolDolibarrBelowRecommended('24.0.2'));
	}

	/**
	 * Through Peppol: validated and paid documents (also a credit note that was used), no draft or abandoned
	 *
	 * @return void
	 */
	public function testInvoiceStatusEligible()
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$inv = new stdClass();
		foreach (array(Facture::STATUS_VALIDATED => true, Facture::STATUS_CLOSED => true, Facture::STATUS_DRAFT => false, Facture::STATUS_ABANDONED => false) as $status => $expected) {
			$inv->status = $status;
			$this->assertSame($expected, peppolIsInvoiceStatusEligible($inv), 'status '.$status);
		}
		$this->assertFalse(peppolIsInvoiceStatusEligible(null));
	}

	/**
	 * Identifiers from the access point: only letters, digits and . _ : @ -
	 *
	 * @return void
	 */
	public function testSanitizeIdentifier()
	{
		$this->assertSame('0106:12345678', peppolSanitizeIdentifier('0106:12345678'));
		$this->assertSame('abc-123_x.y@z', peppolSanitizeIdentifier('abc-123_x.y@z'));
		$this->assertSame('scriptalert1script', peppolSanitizeIdentifier('<script>alert(1)</script>'));
		$this->assertSame('', peppolSanitizeIdentifier(array('x')));
	}

	/**
	 * No access without user or invoice (the rest needs the database)
	 *
	 * @return void
	 */
	public function testUserCanReadInvoiceWithoutObjects()
	{
		$this->assertFalse(peppolUserCanReadInvoice(null, null));
		$inv = new stdClass();
		$inv->id = 0;
		$this->assertFalse(peppolUserCanReadInvoice(new stdClass(), $inv));
	}

	/**
	 * Peppyrus report, failed deliveries and the signal for the plan (since 2.12.0)
	 *
	 * @return void
	 */
	public function testReportFailedLicense()
	{
		$r = peppolReportRules(array('validationRules' => array(array('id' => 'BR-01', 'type' => 'fatal', 'location' => '/Invoice', 'message' => 'x'), array('id' => 'W1', 'type' => 'WARNING'), 'onzin'), 'transmissionRules' => 'AS4 ok'));
		$this->assertSame(1, $r['fatal']);
		$this->assertSame(1, $r['warnings']);
		$this->assertCount(2, $r['rules']);
		$this->assertSame('FATAL', $r['rules'][0]['type']);
		$this->assertSame('AS4 ok', $r['transport']);
		$this->assertSame(array('rules' => array(), 'transport' => '', 'fatal' => 0, 'warnings' => 0), peppolReportRules(-404));

		$failed = array(array('id' => 'a', 'created' => '2026-11-10'), array('id' => 'b', 'created' => ''), array('id' => 'c', 'created' => ''), array('id' => 'a2', 'created' => ''));
		$invoices = array('a' => 10, 'b' => 11, 'a2' => 10);
		$toReport = peppolFailedToReport($failed, $invoices, array(11 => true));
		// c is not an own invoice, b is already reported, invoice 10 only once
		$this->assertSame(array(array('invoice' => 10, 'id' => 'a', 'created' => '2026-11-10')), $toReport);

		$this->assertSame('', peppolLicenseSignal(array('messagesSent' => 500, 'messagesLimit' => null)));
		$this->assertSame('limit', peppolLicenseSignal(array('messagesSent' => 100, 'messagesLimit' => 100)));
		$this->assertSame('near', peppolLicenseSignal(array('messagesSent' => 90, 'messagesLimit' => 100)));
		$this->assertSame('', peppolLicenseSignal(array('messagesSent' => 10, 'messagesLimit' => 100)));
	}

	/**
	 * Status of an outgoing invoice at Peppyrus (since 2.12.0)
	 *
	 * @return void
	 */
	public function testOutgoingStatus()
	{
		$outbox = array('q' => true, 'x' => true);
		$failed = array('f' => true, 'x' => true);
		$this->assertSame('queued', peppolOutgoingStatus('q', $outbox, $failed));
		$this->assertSame('failed', peppolOutgoingStatus('f', $outbox, $failed));
		// In both folders: failed wins
		$this->assertSame('failed', peppolOutgoingStatus('x', $outbox, $failed));
		$this->assertSame('sent', peppolOutgoingStatus('s', $outbox, $failed));
	}

	/**
	 * Invoice Response (since 2.13.0): building, reading back, escaping and the checks on required data
	 *
	 * @return void
	 */
	public function testInvoiceResponse()
	{
		$p = array('id' => 'IR-12', 'date' => '2026-11-10', 'sender' => '0106:12345678', 'senderName' => 'Koper & Co', 'receiver' => 'iso6523-actorid-upis::0208:0475670182',
			'receiverName' => 'Supplier <Ltd>', 'code' => 'RE', 'reason' => 'PRI', 'reasonText' => 'Price is wrong', 'invoiceId' => 'F2026-001', 'invoiceDate' => '2026-11-01');
		$xml = peppolInvoiceResponseXml($p);
		$this->assertStringContainsString('<cbc:CustomizationID>urn:fdc:peppol.eu:poacc:trns:invoice_response:3</cbc:CustomizationID>', $xml);
		$this->assertStringContainsString('<cbc:EndpointID schemeID="0208">0475670182</cbc:EndpointID>', $xml);
		$this->assertStringContainsString('Koper &amp; Co', $xml);
		$this->assertStringContainsString('Supplier &lt;Ltd&gt;', $xml);
		$this->assertStringContainsString('<cbc:StatusReasonCode listID="OPStatusReason">PRI</cbc:StatusReasonCode>', $xml);
		$dom = new DOMDocument();
		$this->assertTrue($dom->loadXML($xml));

		$terug = peppolParseInvoiceResponse($xml);
		$this->assertSame('RE', $terug['code']);
		$this->assertSame('PRI', $terug['reason']);
		$this->assertSame('Price is wrong', $terug['reasonText']);
		$this->assertSame('F2026-001', $terug['invoiceId']);
		$this->assertSame('0106:12345678', $terug['sender']);

		// Accepting without reason is allowed, rejecting without reason is not; unknown code or reason is not
		$ap = array_merge($p, array('code' => 'AP', 'reason' => '', 'reasonText' => ''));
		$this->assertStringNotContainsString('cac:Status', peppolInvoiceResponseXml($ap));
		$this->assertSame('', peppolInvoiceResponseXml(array_merge($ap, array('code' => 'RE'))));
		$this->assertSame('', peppolInvoiceResponseXml(array_merge($p, array('code' => 'XX'))));
		$this->assertSame('', peppolInvoiceResponseXml(array_merge($p, array('reason' => 'ZZZ'))));
		$this->assertSame('', peppolInvoiceResponseXml(array_merge($p, array('sender' => '12345678'))));
		$this->assertSame('', peppolInvoiceResponseXml(array_merge($p, array('invoiceDate' => '1-11-2026'))));

		// Lezen: geen DOCTYPE, geen ander soort document
		$this->assertNull(peppolParseInvoiceResponse('<?xml version="1.0"?><!DOCTYPE x><ApplicationResponse/>'));
		$this->assertNull(peppolParseInvoiceResponse('<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/>'));

		$this->assertTrue(peppolSupportsInvoiceResponse((object) array('services' => array((object) array('documentType' => PEPPOL_IR_DOCTYPE)))));
		$this->assertFalse(peppolSupportsInvoiceResponse(array('services' => array(array('documentType' => 'busdox-docid-qns::urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice')))));
		$this->assertFalse(peppolSupportsInvoiceResponse(-404));
	}

	/**
	 * Answer of the customer for the list Outgoing, and comparing Peppol IDs (2.13.0)
	 *
	 * @return void
	 */
	public function testResponseLevelAndParticipant()
	{
		$this->assertSame('read', peppolResponseLevel('AB'));
		$this->assertSame('read', peppolResponseLevel('ip'));
		$this->assertSame('approved', peppolResponseLevel('PD'));
		$this->assertSame('query', peppolResponseLevel('UQ'));
		$this->assertSame('rejected', peppolResponseLevel('RE'));
		$this->assertSame('', peppolResponseLevel('XX'));
		$this->assertTrue(peppolSameParticipant('iso6523-actorid-upis::0106:12345678', '0106:12345678'));
		$this->assertTrue(peppolSameParticipant('0208:ABC', '0208:abc'));
		$this->assertFalse(peppolSameParticipant('0106:12345678', '0106:87654321'));
		$this->assertFalse(peppolSameParticipant('', ''));
	}

	/**
	 * Received this month and a readable size (2.13.0)
	 *
	 * @return void
	 */
	public function testCountInMonthAndReadableSize()
	{
		$now = dol_mktime(12, 0, 0, 11, 11, 2026, 'gmt');
		$list = array(
			array('id' => 'a', 'created' => '2026-11-10T21:00:00+00:00'),
			array('id' => 'b', 'created' => '2026-11-01 08:00:00'),
			array('id' => 'c', 'created' => '2026-10-31T23:00:00+00:00'),
			array('id' => 'd', 'created' => ''),
		);
		$this->assertSame(2, peppolCountInMonth($list, $now));
		$this->assertSame(0, peppolCountInMonth(array(), $now));
		$this->assertSame('0 B', peppolReadableSize(0));
		$this->assertSame('25 GB', peppolReadableSize(26843545600));
		$this->assertSame('512 B', peppolReadableSize(512));
		$this->assertSame('2 KB', peppolReadableSize(2048));
		$this->assertMatchesRegularExpression('/^1[.,]5 MB$/', peppolReadableSize(1572864));
	}

	/**
	 * Default status filter of Incoming documents (2.13.0)
	 *
	 * @return void
	 */
	public function testInboxDefaultStatus()
	{
		$this->assertSame('open', peppolInboxDefaultStatus('open'));
		$this->assertSame('', peppolInboxDefaultStatus('all'));
		$this->assertSame('1', peppolInboxDefaultStatus('1'));
		$this->assertSame('9', peppolInboxDefaultStatus('9'));
		$this->assertSame('open', peppolInboxDefaultStatus('xyz'));
		$this->assertSame('open', peppolInboxDefaultStatus(''));
		$this->assertSame(array(0, 1, 3, 5), peppolInboxOpenStatuses());
		$this->assertNotContains(2, peppolInboxOpenStatuses());
	}

	/**
	 * Agenda event types (2.14.0): codes, on or off, SQL for disabling, note on booking
	 *
	 * @return void
	 */
	public function testAgenda()
	{
		global $langs;
		$langs->load('peppol@peppolpeppyrus');

		$types = peppolAgendaTypes();
		$this->assertArrayHasKey('AC_PEPPOL_SENT', $types);
		$this->assertArrayHasKey('AC_PEPPOL_FAILED', $types);
		foreach (array_keys($types) as $code) {
			$this->assertMatchesRegularExpression('/^[A-Z_]+$/', $code);
			$this->assertNotSame('Notify_'.$code, $langs->transnoentitiesnoconv('Notify_'.$code), $code);
		}

		// Never switched on by default (module not disabled and enabled after the update): on, as before
		$this->assertTrue(peppolAgendaTypeEnabled('AC_PEPPOL_FAILED', '', ''));
		// Ticked
		$this->assertTrue(peppolAgendaTypeEnabled('AC_PEPPOL_FAILED', '1', 'AC_PEPPOL_FAILED'));
		// Deliberately unticked (Dolibarr then removes the setting)
		$this->assertFalse(peppolAgendaTypeEnabled('AC_PEPPOL_FAILED', '', 'AC_PEPPOL_SENT,AC_PEPPOL_FAILED'));
		$this->assertFalse(peppolAgendaTypeEnabled('AC_PEPPOL_FAILED', '0', 'AC_PEPPOL_FAILED'));

		// Without the module Agenda nothing is recorded (so no database needed either)
		$this->assertFalse(peppolAgendaEnabled('AC_PEPPOL_SENT'));
		$this->assertSame(0, peppolAgendaEvent(null, null, 'AC_PEPPOL_SENT', 'x', 'y'));

		$sql = peppolAgendaRemoveSql();
		$this->assertCount(1, $sql);
		$this->assertStringContainsString("'AC_PEPPOL_ID_LINKED'", $sql[0]);
		$this->assertStringContainsString("'AC_PEPPOL_IR_RE'", $sql[0]);
		$this->assertStringStartsWith('DELETE FROM llx_c_action_trigger WHERE code IN', $sql[0]);

		$note = peppolAgendaBookedNote($langs, 'PI-0001', 'INV-001', false, true, true);
		$this->assertStringContainsString('PI-0001', $note);
		$this->assertStringContainsString('INV-001', $note);
		$this->assertStringContainsString($langs->transnoentitiesnoconv('peppolAgendaDraft'), $note);
		$this->assertStringContainsString($langs->transnoentitiesnoconv('peppolAgendaApprovalSent'), $note);
		$note = peppolAgendaBookedNote($langs, 'PI-0002', '', true, true, false);
		$this->assertStringContainsString($langs->transnoentitiesnoconv('peppolAgendaLinkedExisting'), $note);
		$this->assertStringNotContainsString($langs->transnoentitiesnoconv('peppolAgendaDraft'), $note);
		$this->assertStringContainsString($langs->transnoentitiesnoconv('peppolAgendaApprovalNotSent'), $note);
	}

	/**
	 * Only the Peppol XML of the invoice can be sent (original module, #21)
	 *
	 * @return void
	 */
	public function testOnlyPeppolXmlOfTheInvoice()
	{
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		// No database in the unit tests; Dolibarr 18 requires a DoliDB in the constructor (since 2.16.0 a stand-in)
		$inv = new Facture($this->database());
		$inv->last_main_doc = 'facture/FA0001/FA0001.pdf';
		foreach (array('other-document.xml', 'FA0001.xml', 'FA0001_peppol.pdf', 'FA0001_peppol.xml.txt') as $file) {
			$_GET['selectFilename'] = $file;
			$this->assertSame(-1, peppolFindFileToUse($inv, ''), $file);
		}
		$_GET['selectFilename'] = 'FA0001_peppol.xml';
		$this->assertSame('FA0001_peppol.xml', peppolFindFileToUse($inv, ''));
		unset($_GET['selectFilename']);
	}

	/**
	 * Since 2.16.0: invoices that never go through Peppol, by PDF template or product category (general settings)
	 *
	 * @return void
	 */
	public function testExclusion()
	{
		$this->assertSame('model', peppolExclusionReason('sponge', false, array('crabe', 'sponge')));
		$this->assertSame('', peppolExclusionReason('sponge', false, array('crabe')));
		$this->assertSame('', peppolExclusionReason('', false, array('')));
		$this->assertSame('category', peppolExclusionReason('crabe', true, array()));
		$this->assertSame('model', peppolExclusionReason('crabe', true, array('crabe')));
		$this->assertSame(array('crabe', 'sponge'), peppolExcludedModels(' crabe, sponge,crabe,,bad name;x '));
		$this->assertSame(array(), peppolExcludedModels(''));
	}

	/**
	 * Since 2.15.0: the choice on the third party wins over the general setting for the approval
	 *
	 * @return void
	 */
	public function testSendApprovalFor()
	{
		$this->assertFalse(peppolSendApprovalFor(false, ''));
		$this->assertTrue(peppolSendApprovalFor(true, ''));
		$this->assertTrue(peppolSendApprovalFor(false, 'aan'));
		$this->assertFalse(peppolSendApprovalFor(true, 'uit'));
		$this->assertTrue(peppolSendApprovalFor(true, 'other'));
	}

	/**
	 * Since 2.15.0: the disclaimer, accepted by an administrator only, per entity
	 *
	 * @return void
	 */
	public function testDisclaimerAcceptance()
	{
		// dol_delete_dir_recursive() needs the hook manager on Dolibarr 18; the unit tests have none (since 2.16.0)
		if (!is_object($GLOBALS['hookmanager'] ?? null) && $this->database() !== null) {
			require_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$GLOBALS['hookmanager'] = new HookManager($this->database());
		}
		global $db, $conf;
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		if (!defined('PEPPOL_DISCLAIMER_DIR')) {
			define('PEPPOL_DISCLAIMER_DIR', sys_get_temp_dir().'/peppoltest-disclaimer-'.getmypid());
		}
		dol_delete_dir_recursive(PEPPOL_DISCLAIMER_DIR);
		$this->assertNull(peppolDisclaimerAcceptance());

		$plain = new User($db);
		$plain->id = 7;
		$plain->login = 'test';
		$plain->admin = 0;
		$this->assertFalse(peppolDisclaimerAccept($db, $plain));

		$admin = new User($db);
		$admin->id = 8;
		$admin->login = 'admin';
		$admin->admin = 1;
		$this->assertTrue(peppolDisclaimerAccept($db, $admin));
		$accepted = peppolDisclaimerAcceptance();
		$this->assertNotNull($accepted);
		$this->assertSame(8, $accepted['user_id']);

		$entity = $conf->entity;
		$conf->entity = 987654;
		$this->assertNull(peppolDisclaimerAcceptance());
		$conf->entity = $entity;

		dol_delete_dir_recursive(PEPPOL_DISCLAIMER_DIR);
		$this->assertNull(peppolDisclaimerAcceptance());
	}

	/**
	 * The database of the test run. Without one: null where the constructors of Dolibarr accept that (19 and newer;
	 * PHP refuses a mock of DoliDB there), a stand-in where they require a DoliDB (Dolibarr 18) (since 2.16.0)
	 *
	 * @return ?DoliDB
	 */
	private function database()
	{
		global $db;
		if ($db instanceof DoliDB) {
			return $db;
		}
		require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$parameter = (new ReflectionMethod('Facture', '__construct'))->getParameters()[0];
		if (!$parameter->hasType() || $parameter->allowsNull()) {
			return null;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/db/DoliDB.class.php';
		return $this->createMock(DoliDB::class);
	}
}
