<?php

/**
 * @file tests/functional/OAIJatsFunctionalTest.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2000-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class StaticPagesFunctionalTest
 * @package plugins.generic.staticPages
 *
 * @brief Functional tests for the static pages plugin.
 */

import('lib.pkp.tests.WebTestCase');

use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Interactions\WebDriverActions;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverSelect;

class OAIJatsFunctionalTest extends WebTestCase {
	/**
	 * @copydoc WebTestCase::getAffectedTables
	 */
	protected function getAffectedTables() {
		return PKP_TEST_ENTIRE_DB;
	}

	/**
	 * Enable the plugin
	 */
	function testStaticPages() {
		$this->open(self::$baseUrl);

		$this->logIn('admin', 'admin');
		$actions = new WebDriverActions(self::$driver);
		$actions->moveToElement($this->waitForElementPresent('//ul[@id="navigationPrimary"]//a[contains(text(),"Settings")]'))
			->click($this->waitForElementPresent('//ul[@id="navigationPrimary"]//a[contains(text(),"Website")]'))
			->perform();
		$this->click('//button[@id="plugins-button"]');

		// Find and enable the JATS Template plugin
		$this->waitForElementPresent($selector='//input[starts-with(@id, \'select-cell-jatstemplateplugin-enabled\')]');
		$this->click($selector);
		$this->waitForElementPresent('//div[contains(.,\'The plugin "JATS Template Plugin" has been enabled.\')]');

		// Find and enable this plugin
		$this->waitForElementPresent($selector='//input[starts-with(@id, \'select-cell-OAIMetadataFormatPlugin_JATS-enabled\')]');
		$this->click($selector);
		$this->waitForElementPresent('//div[contains(.,\'The plugin "JATS Metadata Format" has been enabled.\')]');

		// Fetch the resulting OAI XML. NOTE: We cannot (easily) use Webdriver to do
		// this because the OAI interface is transformed in the browser via the XSLT
		// front-end and we want to work with the raw OAI XML instead.
		$ch = curl_init(self::$baseUrl . '/index.php/publicknowledge/oai?verb=ListRecords&metadataPrefix=jats');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$oaiRecord = curl_exec($ch);
		curl_close($ch);
		$this->assertNotEquals($oaiRecord, false);

		$doc = new DOMDocument();
		$doc->loadXML($oaiRecord);
		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('oai', 'http://www.openarchives.org/OAI/2.0/');
die($oaiRecord);
		// Ensure that there is only a single record.
		$elements = $xpath->query('/oai:OAI-PMH/oai:ListRecords/oai:record');
		$this->assertEquals(count($elements), 1);

		self::$driver->close();
	}
}

