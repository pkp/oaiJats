<?php

/**
 * @defgroup oai_format_jats
 */

/**
 * @file OAIMetadataFormat_JATS.inc.php
 *
 * Copyright (c) 2013-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class OAIMetadataFormat_JATS
 * @ingroup oai_format
 * @see OAI
 *
 * @brief OAI metadata format class -- JATS
 */

class OAIMetadataFormat_JATS extends OAIMetadataFormat {
	/**
	 * Identify a candidate JATS file to expose via OAI.
	 * @param $article Article
	 * @param $galleys array
	 * @return DOMDocument|null
	 */
	protected function _findJats($record) {
		$article = $record->getData('article');
		$galleys = $record->getData('galleys');

		import('lib.pkp.classes.submission.SubmissionFile'); // SUBMISSION_FILE_... constants
		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$candidateFiles = array();

		// First, look for candidates in the galleys area (published content).
		foreach ($galleys as $galley) {
			$galleyFiles = $submissionFileDao->getLatestRevisionsByAssocId(ASSOC_TYPE_GALLEY, $galley->getId(), $galley->getSubmissionId(), SUBMISSION_FILE_PROOF);
			foreach ($galleyFiles as $galleyFile) {
				if ($this->_isCandidateFile($galleyFile)) $candidateFiles[] = $galleyFile;
			}
		}

		// If no candidates were found, look in the layout area (unpublished content).
		if (empty($candidateFiles)) {
			$layoutFiles = $submissionFileDao->getLatestRevisions($article->getId(), SUBMISSION_FILE_PRODUCTION_READY);
			foreach ($layoutFiles as $layoutFile) {
				if ($this->_isCandidateFile($layoutFile)) $candidateFiles[] = $layoutFile;
			}
		}

		$doc = null;
		HookRegistry::call('OAIMetadataFormat_JATS::findJats', array(&$this, &$record, &$candidateFiles, &$doc));

		// If no candidate files were located, return the null XML.
		if (!$doc && empty($candidateFiles)) {
			return null;
		}
		if (count($candidateFiles) > 1) error_log('WARNING: More than one JATS XML candidate documents were located for submission ' . $article->getId() . '.');

		// Fetch the XML document
		if (!$doc) {
			$candidateFile = array_shift($candidateFiles);
			$doc = new DOMDocument;
			$doc->loadXML(file_get_contents($candidateFile->getFilePath()));
		}

		return $doc;
	}

	/**
	 * @copydoc OAIMetadataFormat#toXml
	 */
	function toXml($record, $format = null) {
		$oaiDao = DAORegistry::getDAO('OAIDAO');
		$journal = $record->getData('journal');
		$article = $record->getData('article');
		$galleys = $record->getData('galleys');
		$section = $record->getData('section');
		$issue = $record->getData('issue');

		// Check access
		import('classes.issue.IssueAction');
		$subscriptionRequired = IssueAction::subscriptionRequired($issue, $journal);
		$isSubscribedDomain = IssueAction::subscribedDomain(Application::getRequest(), $journal, $issue->getId(), $article->getId());
		if ($subscriptionRequired && !$subscriptionRequired) {
			$oaiDao->oai->error('cannotDisseminateFormat', 'Cannot disseminate format (JATS XML not available)');
			exit();
		}

		$doc = $this->_findJats($record);
		if (!$doc) {
			$oaiDao->oai->error('cannotDisseminateFormat', 'Cannot disseminate format (JATS XML not available)');
			exit();
		}
		$this->_mungeMetadata($doc, $journal, $article, $section, $issue);

		return $doc->saveXml($doc->getElementsByTagName('article')->item(0));
	}

	/**
	 * Add the child node to the parent node in the appropriate node order.
	 * @param $parentNode DOMNode The parent element.
	 * @param $childNode DOMNode The child node.
	 * @return DOMNode The child node.
	 */
	protected function _addChildInOrder($parentNode, $childNode) {
		$permittedElementOrders = array(
			'front' => array('article-meta', 'journal-meta'),
			'article-meta' => array('article-id', 'article-categories', 'title-group', 'contrib-group', 'aff', 'aff-alternatives', 'x', 'author-notes', 'pub-date', 'volume', 'volume-id', 'volume-series', 'issue', 'issue-id', 'issue-title', 'issue-sponsor', 'issue-part', 'isbn', 'supplement', 'fpage', 'lpage', 'page-range', 'elocation-id', 'email', 'ext-link', 'uri', 'product', 'supplementary-material', 'history', 'permissions', 'self-uri', 'related-article', 'related-object', 'abstract', 'trans-abstract', 'kwd-group', 'funding-group', 'conference', 'counts', 'custom-meta-group'),
			'journal-meta' => array('journal-id', 'journal-title-group', 'contrib-group', 'aff', 'aff-alternatives', 'issn', 'issn-l', 'isbn', 'publisher', 'notes', 'self-uri', 'custom-meta-group'),
		);
		assert(isset($permittedElementOrders[$parentNode->nodeName])); // We have an order list for the parent node
		$order = $permittedElementOrders[$parentNode->nodeName];
		$position = array_search($childNode->nodeName, $order);
		assert($position !== false); // The child node appears in the order list

		$followingElements = array_slice($order, $position);
		$followingElement = null;
		foreach ($parentNode->childNodes as $node) {
			if (in_array($node->nodeName, $followingElements)) {
				$followingElement = $node;
				break;
			}
		}

		return $parentNode->insertBefore($childNode, $followingElement);
	}

	/**
	 * Override elements of the JATS XML with aspects of the OJS article's metadata.
	 * @param $doc DOMDocument
	 * @param $journal Journal
	 * @param $article Article
	 * @param $section Section
	 * @param $issue Issue
	 */
	protected function _mungeMetadata($doc, $journal, $article, $section, $issue) {
		$xpath = new DOMXPath($doc);
		$articleMetaNode = $xpath->query('//article/front/article-meta')->item(0);
		$journalMetaNode = $xpath->query('//article/front/journal-meta')->item(0);
		if (!$journalMetaNode) {
			$frontNode = $xpath->query('//article/front')->item(0);
			$journalMetaNode = $this->_addChildInOrder($frontNode, $doc->createElement('journal-meta'));
		}

		$request = Application::getRequest();

		$articleNode = $xpath->query('//article')->item(0);
		$articleNode->setAttribute('xml:lang', substr($article->getLocale(),0,2));
		$articleNode->setAttribute('specific-use', 'eps-0.1');

		// Set the article publication date. http://erudit-ps-documentation.readthedocs.io/en/latest/tagset/element-pub-date.html
		if ($datePublished = $article->getDatePublished()) {
			$datePublished = strtotime($datePublished);
			$match = $xpath->query("//article/front/article-meta/pub-date[@date-type='pub' and publication-format='epub']");
			if ($match->length) {
				// An existing pub-date was found; empty and re-use.
				$dateNode = $match->item(0);
				while ($dateNode->hasChildNodes()) $dateNode->removeChild($dateNode->firstChild);
			} else {
				// No pub-date was found; create a new one.
				$dateNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('pub-date'));
				$dateNode->setAttribute('date-type', 'pub');
				$dateNode->setAttribute('publication-format', 'epub');
			}

			$dateNode->setAttribute('iso-8601-date', strftime('%Y-%m-%d', $datePublished));
			$dateNode->appendChild($doc->createElement('day'))->nodeValue = strftime('%d', $datePublished);
			$dateNode->appendChild($doc->createElement('month'))->nodeValue = strftime('%m', $datePublished);
			$dateNode->appendChild($doc->createElement('year'))->nodeValue = strftime('%Y', $datePublished);
		}

		// Set the issue publication date. http://erudit-ps-documentation.readthedocs.io/en/latest/tagset/element-pub-date.html
		$issueYear = null;
		if ($issue && $issue->getShowYear()) $issueYear = $issue->getYear();
		if (!$issueYear && $issue && $issue->getDatePublished()) $issueYear = strftime('%Y', $issue->getDatePublished());
		if (!$issueYear && $datePublished) $issueYear = strftime('%Y', $datePublished);
		if ($issueYear) {
			$match = $xpath->query("//article/front/article-meta/pub-date[@date-type='issue' and publication-format='epub']");
			if ($match->length) {
				// An existing pub-date was found; empty and re-use.
				$dateNode = $match->item(0);
				while ($dateNode->hasChildNodes()) $dateNode->removeChild($dateNode->firstChild);
			} else {
				// No pub-date was found; create a new one.
				$dateNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('pub-date'));
				$dateNode->setAttribute('date-type', 'issue');
				$dateNode->setAttribute('publication-format', 'epub');
			}
			if ($issue && $issue->getDatePublished()) {
				$issueDatePublished = strtotime($issue->getDatePublished());
				$dateNode->setAttribute('iso-8601-date', strftime('%Y-%m-%d', $issueDatePublished));
				$dateNode->appendChild($doc->createElement('day'))->nodeValue = strftime('%d', $issueDatePublished);
				$dateNode->appendChild($doc->createElement('month'))->nodeValue = strftime('%m', $issueDatePublished);
				$dateNode->appendChild($doc->createElement('year'))->nodeValue = strftime('%Y', $issueDatePublished);
			} else {
				$dateNode->appendChild($doc->createElement('year'))->nodeValue = strftime('%Y', $issueYear);
			}
		}

		// Set the article URLs: Landing page
		$uriNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('self-uri'));
		$uriNode->nodeValue = $request->url(null, 'article', 'view', $article->getBestArticleId());

		// Set the article URLs: Galleys
		foreach ($article->getGalleys() as $galley) {
			$uriNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('self-uri'));
			$uriNode->nodeValue = $request->url(null, 'article', 'view', array($article->getBestArticleId(), $galley->getId()));
			$uriNode->setAttribute('content-type', $galley->getFileType());
		}

		// Set the issue volume (if applicable).
		if ($issue && $issue->getShowVolume()) {
			$match = $xpath->query('//article/front/article-meta/volume');
			if ($match->length) $volumeNode = $match->item(0);
			else $volumeNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('volume'));
			$volumeNode->nodeValue = $issue->getVolume();
		}

		// Set the issue number (if applicable).
		if ($issue && $issue->getShowNumber()) {
			$match = $xpath->query('//article/front/article-meta/issue');
			if ($match->length) $numberNode = $match->item(0);
			else $numberNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('issue'));
			$numberNode->nodeValue = $issue->getNumber();
		}

		// Set the issue title (if applicable).
		if ($issue && $issue->getShowTitle()) {
			$match = $xpath->query('//article/front/article-meta/issue-title');
			if ($match->length) $titleNode = $match->item(0);
			else $titleNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('issue-title'));
			$titleNode->nodeValue = $issue->getTitle($journal->getPrimaryLocale());
			$titleNode->setAttribute('xml:lang', substr($journal->getPrimaryLocale(),0,2));
		}


		// Set the article title.
		$titleGroupNode = $xpath->query('//article/front/article-meta/title-group')->item(0);
		foreach ($titleGroupNode->getElementsByTagName('article-title') as $titleNode) $titleGroupNode->removeChild($titleNode);
		foreach ($article->getTitle(null) as $locale => $title) {
			$titleNode = $titleGroupNode->appendChild($doc->createElement('article-title'));
			$titleNode->setAttribute('xml:lang', substr($locale,0,2));
			$titleNode->nodeValue = $title;
		}

		// Set the article abstract.
		static $purifier;
		if (!$purifier) {
			$config = HTMLPurifier_Config::createDefault();
			$config->set('HTML.Allowed', 'p');
			$config->set('Cache.SerializerPath', 'cache');
			$purifier = new HTMLPurifier($config);
		}
		foreach ($articleMetaNode->getElementsByTagName('abstract') as $abstractNode) $articleMetaNode->removeChild($abstractNode);
		foreach ((array) $article->getAbstract(null) as $locale => $abstract) {
			$abstractDoc = new DOMDocument;
			$abstractDoc->loadXML('<abstract>' . $purifier->purify($abstract) . '</abstract>');
			$abstractNode = $this->_addChildInOrder($articleMetaNode, $doc->importNode($abstractDoc->documentElement, true));
			$abstractNode->setAttribute('xml:lang', substr($locale,0,2));
		}

		// Set the journal-id[publisher-id']
		$match = $xpath->query("//article/front/journal-meta/journal-id[@journal-id-type='publisher']");
		if ($match->length) $match->item(0)->nodeValue = $journal->getPath();
		else {
			$journalIdNode = $this->_addChildInOrder($journalMetaNode, $doc->createElement('journal-id'));
			$journalIdNode->setAttribute('journal-id-type', 'publisher');
			$journalIdNode->nodeValue = $journal->getPath();
		}

		// Store the DOI
		if ($doi = trim($article->getStoredPubId('doi'))) {
			$match = $xpath->query("//article/front/article-meta/article-id[@pub-id-type='doi']");
			if ($match->length) $match->item(0)->nodeValue = $doi;
			else {
				$articleIdNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('article-id'));
				$articleIdNode->setAttribute('pub-id-type', 'doi');
				$articleIdNode->nodeValue = $doi;
			}
		}

		// Override permissions, when not supplied in the document
		$match = $xpath->query('//article/front/article-meta/permissions');
		$copyrightHolder = $article->getLocalizedCopyrightHolder($article->getLocale());
		$copyrightYear = $article->getCopyrightYear();
		$licenseUrl = $article->getLicenseURL();
		if (!$match->length && ($copyrightHolder || $copyrightYear || $licenseUrl)) {
			$permissionsNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('permissions'));
			if ($copyrightYear) $permissionsNode->appendChild($doc->createElement('copyright-year'))->nodeValue = $copyrightYear;
			if ($copyrightHolder) $permissionsNode->appendChild($doc->createElement('copyright-holder'))->nodeValue = $copyrightHolder;
			if ($licenseUrl) {
				$licenseNode = $permissionsNode->appendChild($doc->createElement('license'));
				$licenseNode->setAttribute('xlink:href', $licenseUrl);
			}
		}

		// Section information
		$match = $xpath->query("//article/front/article-meta/article-categories");
		if ($match->length) $articleCategoriesNode = $match->item(0);
		else {
			$articleCategoriesNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('article-categories'));
		}
		$match = $xpath->query('//article/front/article-meta/subj-group[@subj-group-type="heading"]');
		if ($match->length) $subjGroupNode = $match->item(0);
		else {
			$subjGroupNode = $articleCategoriesNode->appendChild($doc->createElement('subj-group'));
			$subjGroupNode->setAttribute('subj-group-type', 'heading');
		}
		$subjectNode = $subjGroupNode->appendChild($doc->createElement('subject'));
		$subjectNode->nodeValue = $section->getTitle($journal->getPrimaryLocale());

		// Article sequence information
		$publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
		$articleIds = array_map(function($publishedArticle) {
			return $publishedArticle->getId();
		}, $publishedArticleDao->getPublishedArticles($issue->getId()));
		foreach (array('volume', 'number') as $nodeName) {
			$match = $xpath->query("//article/front/article-meta/$nodeName");
			if ($match->length) $match->item(0)->setAttribute('seq', array_search($article->getId(), $articleIds));
		}

		// Issue ID
		$match = $xpath->query("//article/front/article-meta/issue-id");
		if ($match->length) $match->item(0)->nodeValue = $issue->getId();
		else {
			$issueIdNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('issue-id'));
			$issueIdNode->nodeValue = $issue->getId();
		}

		// Article type
		if ($articleType = trim($section->getLocalizedIdentifyType())) {
			$articleNode = $xpath->query("//article")->item(0);
			$articleNode->setAttribute('article-type', $articleType);
		}

		// Editorial team
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
		$userGroups = $userGroupDao->getByContextId($journal->getId());
		$journalMetaNode = $xpath->query('//article/front/journal-meta')->item(0);
		$contribGroupNode = $this->_addChildInOrder($journalMetaNode, $doc->createElement('contrib-group'));
		$keyContribTypeMapping = array(
			'default.groups.name.manager' => 'jmanager',
			'default.groups.name.editor' => 'editor',
			'default.groups.name.sectionEditor' => 'secteditor',
		);
		while ($userGroup = $userGroups->next()) {
			if (!isset($keyContribTypeMapping[$userGroup->getData('nameLocaleKey')])) continue;

			$users = $userGroupDao->getUsersById($userGroup->getId());
			while ($user = $users->next()) {
				$contribNode = $contribGroupNode->appendChild($doc->createElement('contrib'));
				$contribNode->setAttribute('contrib-type', $keyContribTypeMapping[$userGroup->getData('nameLocaleKey')]);
				$nameNode = $contribNode->appendChild($doc->createElement('name'));
				$surnameNode = $nameNode->appendChild($doc->createElement('surname'));
				$surnameNode->nodeValue = method_exists($user, 'getLastName')?$user->getLastName():$user->getLocalizedFamilyName();
				$givenNamesNode = $nameNode->appendChild($doc->createElement('given-names'));
				$givenNamesNode->nodeValue = method_exists($user, 'getFirstName')?$user->getFirstName():$user->getLocalizedGivenName();
				if (method_exists($user, 'getMiddleName') && $s = $user->getMiddleName()) $givenNamesNode->nodeValue .= " $s";
			}
		}

	}

	/**
	 * Determine whether a submission file is a good candidate for JATS XML.
	 * @param $submissionFile SubmissionFile
	 * @return boolean
	 */
	protected function _isCandidateFile($submissionFile) {
		// The file type isn't XML.
		if (!in_array($submissionFile->getFileType(), array('application/xml', 'text/xml'))) return false;

		static $genres = array();
		$genreDao = DAORegistry::getDAO('GenreDAO');
		$genreId = $submissionFile->getGenreId();
		if (!isset($genres[$genreId])) $genres[$genreId] = $genreDao->getById($genreId);
		assert($genres[$genreId]);
		$genre = $genres[$genreId];

		// The genre doesn't look like a main submission document.
		if ($genre->getCategory() != GENRE_CATEGORY_DOCUMENT) return false;
		if ($genre->getDependent()) return false;
		if ($genre->getSupplementary()) return false;

		return true;
	}
}
