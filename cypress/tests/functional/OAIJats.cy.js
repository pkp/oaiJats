/**
 * @file cypress/tests/functional/OAIJats.spec.js
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2000-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 */

describe('OAI JATS plugin tests', function() {
	it('Sets up the testing environment', function() {
		cy.login('admin', 'admin', 'publicknowledge');

		cy.get('nav').contains('Settings').click();
		// Ensure submenu item click despite animation
		cy.get('nav').contains('Website').click({ force: true });

		cy.get('button[id="plugins-button"]').click();

		// Find and enable the OAI JATS plugin
		cy.get('input[id^="select-cell-OAIMetadataFormatPlugin_JATS-enabled"]').click();
		cy.get('div:contains(\'The plugin "JATS Metadata Format" has been enabled.\')');
	});
	it('Exercises a JATS OAI request', function() {
		cy.request('index.php/publicknowledge/oai?verb=ListRecords&metadataPrefix=jats').then(response => {
			var identifier = null;

			// Ensure we got a valid XML response
			expect(response.status).to.eq(200);
			expect(response.headers['content-type']).to.eq('text/xml;charset=utf-8');

			// Parse the XML response and assert that it's a ListRecords
			var $xml = Cypress.$(Cypress.$.parseXML(response.body)),
				$listRecords = $xml.find('ListRecords');

			// There should only be one ListRecords element
			expect($listRecords.length).to.eq(1);

			// Run some tests on each record
			$listRecords.find('> record').each((index, element) => {
				var $record = Cypress.$(element);
				var $dc = $record.find('> metadata > article');
				expect($dc.length).to.eq(1);

				// Ensure that every element has a title
				expect($dc.find('> front > article-meta > title-group > article-title').length).to.eq(1);

				// Ensure that every element has at least one author (pkp/pkp-lib#5417)
				expect($dc.find('> front > article-meta > contrib-group > contrib').length).to.be.at.least(1);

				// Save a sample identifier for further exercise
				identifier = $record.find('> header > identifier').text();
			});

			// Make sure we actually tested at least one record
			expect(identifier).to.not.eq(null);

			// Fetch an individual record by identifier
			cy.request('index.php/publicknowledge/oai?verb=GetRecord&metadataPrefix=jats&identifier=' + encodeURI(identifier)).then(response => {
				// Ensure we got a valid XML response
				expect(response.status).to.eq(200);
				expect(response.headers['content-type']).to.eq('text/xml;charset=utf-8');

				// Parse the XML response and assert that it's a ListRecords
				const $xml = Cypress.$(Cypress.$.parseXML(response.body)),
					$getRecord = $xml.find('GetRecord');

					// There should only be one GetRecord element
					expect($getRecord.length).to.eq(1);
			});
		});
	});
})

