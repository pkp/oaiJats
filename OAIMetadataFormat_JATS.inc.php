<?php

/**
 * @defgroup oai_format_jats
 */

/**
 * @file OAIMetadataFormat_JATS.inc.php
 *
 * Copyright (c) 2013-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
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
			$galleyFiles = $submissionFileDao->getLatestRevisionsByAssocId(ASSOC_TYPE_GALLEY, $galley->getId(), $article->getId(), SUBMISSION_FILE_PROOF);
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
		$issueAction = new IssueAction();
		$subscriptionRequired = $issueAction->subscriptionRequired($issue, $journal);
		$isSubscribedDomain = $issueAction->subscribedDomain(Application::get()->getRequest(), $journal, $issue->getId(), $article->getId());
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
			'counts' => array('count', 'fig-count', 'table-count', 'equation-count', 'ref-count', 'page-count', 'word-count'),
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

		$request = Application::get()->getRequest();

		$articleNode = $xpath->query('//article')->item(0);
		$articleNode->setAttribute('xml:lang', substr($article->getLocale(),0,2));
		$articleNode->setAttribute('dtd-version', '1.1');
		$articleNode->setAttribute('specific-use', 'eps-0.1');
		$articleNode->setAttribute('xmlns', 'https://jats.nlm.nih.gov/publishing/1.1/');

		// Set the article publication date. http://erudit-ps-documentation.readthedocs.io/en/latest/tagset/element-pub-date.html
		if ($datePublished = $article->getDatePublished()) {
			$datePublished = strtotime($datePublished);
			$match = $xpath->query("//article/front/article-meta/pub-date[@date-type='pub' and @publication-format='epub']");
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

			$dateNode->appendChild($doc->createElement('day'))->nodeValue = strftime('%d', $datePublished);
			$dateNode->appendChild($doc->createElement('month'))->nodeValue = strftime('%m', $datePublished);
			$dateNode->appendChild($doc->createElement('year'))->nodeValue = strftime('%Y', $datePublished);
		}

		// Set the issue publication date. http://erudit-ps-documentation.readthedocs.io/en/latest/tagset/element-pub-date.html
		$issueYear = null;
		if ($issue && $issue->getShowYear()) $issueYear = $issue->getYear();
		if (!$issueYear && $issue && $issue->getDatePublished()) $issueYear = strftime('%Y', strtotime($issue->getDatePublished()));
		if (!$issueYear && $datePublished) $issueYear = strftime('%Y', $datePublished);
		if ($issueYear) {
			$match = $xpath->query("//article/front/article-meta/pub-date[@date-type='collection']");
			if ($match->length) {
				// An existing pub-date was found; empty and re-use.
				$dateNode = $match->item(0);
				while ($dateNode->hasChildNodes()) $dateNode->removeChild($dateNode->firstChild);
			} else {
				// No pub-date was found; create a new one.
				$dateNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('pub-date'));
				$dateNode->setAttribute('date-type', 'collection');
			}
			$dateNode->appendChild($doc->createElement('year'))->nodeValue = $issueYear;
		}

		// Remove all article-meta/self-uri nodes in preparation for additions below
		$match = $xpath->query('//article/front/article-meta/self-uri');
		foreach ($match as $node) $articleMetaNode->removeChild($node);

		// Set the article URLs: Landing page
		$uriNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('self-uri'));
		$uriNode->setAttribute('xlink:href', $request->url(null, 'article', 'view', $article->getBestArticleId()));

		// Set the article URLs: Galleys
		foreach ($article->getGalleys() as $galley) {
			$uriNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('self-uri'));
			$uriNode->setAttribute('xlink:href', $request->url(null, 'article', 'view', array($article->getBestArticleId(), $galley->getId())));
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
			foreach ($match as $node) $articleMetaNode->removeChild($node);
			foreach ($issue->getTitle(null) as $locale => $title) {
				if (empty($title)) continue;
				$titleNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('issue-title'));
				$titleText = $doc->createTextNode($title);
				$titleNode->appendChild($titleText);
				$titleNode->setAttribute('xml:lang', substr($locale,0,2));
			}
		}


		// Set the article title.
		$titleGroupNode = $xpath->query('//article/front/article-meta/title-group')->item(0);
		while ($titleGroupNode->hasChildNodes()) $titleGroupNode->removeChild($titleGroupNode->firstChild);
		$titleNode = $titleGroupNode->appendChild($doc->createElement('article-title'));
		$titleNode->setAttribute('xml:lang', substr($article->getLocale(),0,2));
		$articleTitleText = $doc->createTextNode($article->getTitle($article->getLocale()));
                $titleNode->appendChild($articleTitleText);
		if (!empty($subtitle = $article->getSubtitle($article->getLocale()))) {
			$subtitleText = $doc->createTextNode($subtitle);
			$subtitleNode = $titleGroupNode->appendChild($doc->createElement('subtitle'));
			$subtitleNode->setAttribute('xml:lang', substr($article->getLocale(),0,2));
			$subtitleNode->appendChild($subtitleText);
		}
		foreach ($article->getTitle(null) as $locale => $title) {
			if ($locale == $article->getLocale()) continue;
			if (trim($title) === '') continue;
			$transTitleGroupNode = $titleGroupNode->appendChild($doc->createElement('trans-title-group'));
			$transTitleGroupNode->setAttribute('xml:lang', substr($locale,0,2));
			$titleNode = $transTitleGroupNode->appendChild($doc->createElement('trans-title'));
			$titleText = $doc->createTextNode($title);
                        $titleNode->appendChild($titleText);
			if (!empty($subtitle = $article->getSubtitle($locale))) {
				$subtitleNode = $transTitleGroupNode->appendChild($doc->createElement('trans-subtitle'));
				$subtitleText = $doc->createTextNode($subtitle);
                                $subtitleNode->appendChild($subtitleText);
			}
		}

		// Set the article keywords.
		$keywordGroupNode = $xpath->query('//article/front/article-meta/kwd-group')->item(0);
		$submissionKeywordDao = DAORegistry::getDAO('SubmissionKeywordDAO');
		foreach ($articleMetaNode->getElementsByTagName('kwd-group') as $kwdGroupNode) $articleMetaNode->removeChild($kwdGroupNode);
		foreach ($submissionKeywordDao->getKeywords($article->getId(), $journal->getSupportedLocales()) as $locale => $keywords) {
			if (empty($keywords)) continue;

			// Load the article.subject locale key in possible other languages
			AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, $locale);

			$kwdGroupNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('kwd-group'));
			$kwdGroupNode->setAttribute('xml:lang', substr($locale,0,2));
			$kwdGroupNode->appendChild($doc->createElement('title'))->nodeValue = __('article.subject', array(), $locale);
			foreach ($keywords as $keyword) $kwdGroupNode->appendChild($doc->createElement('kwd'))->nodeValue = $keyword;
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
			if (empty($abstract)) continue;
			$isPrimary = $locale == $article->getLocale();
			$abstractDoc = new DOMDocument;
			if (strpos($abstract, '<p>')===null) $abstract = "<p>$abstract</p>";
			$abstractDoc->loadXML(($isPrimary?'<abstract>':'<trans-abstract>') . $purifier->purify($abstract) . ($isPrimary?'</abstract>':'</trans-abstract>'));
			$abstractNode = $this->_addChildInOrder($articleMetaNode, $doc->importNode($abstractDoc->documentElement, true));
			if (!$isPrimary) $abstractNode->setAttribute('xml:lang', substr($locale,0,2));
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
			if ($copyrightYear || $copyrightHolder) $permissionsNode->appendChild($doc->createElement('copyright-statement'))->nodeValue = __('submission.copyrightStatement', array('copyrightYear' => $copyrightYear, 'copyrightHolder' => $copyrightHolder));
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
		while ($articleCategoriesNode->hasChildNodes()) $articleCategoriesNode->removeChild($articleCategoriesNode->firstChild);
		foreach ($section->getTitle(null) as $locale => $title) {
			if (empty($title)) continue;
			$subjGroupNode = $articleCategoriesNode->appendChild($doc->createElement('subj-group'));
			$subjGroupNode->setAttribute('subj-group-type', 'heading');
			$subjGroupNode->setAttribute('xml:lang', substr($locale,0,2));
			$subjectNode = $subjGroupNode->appendChild($doc->createElement('subject'));
			$subjectNode->nodeValue = $title;
		}

		// Article sequence information
		import('classes.submission.Submission'); // import STATUS_ constants
		$publishedArticles = iterator_to_array(Services::get('submission')->getMany([
			'contextId' => $journal->getId(),
			'issueIds' => [$issue->getId()],
			'status' => STATUS_PUBLISHED,
		]));
		$articleIds = array_map(function($publishedArticle) {
			return $publishedArticle->getId();
		}, $publishedArticles);
		foreach (array('volume', 'issue') as $nodeName) {
			$match = $xpath->query("//article/front/article-meta/$nodeName");
			if ($match->length) {
				$match->item(0)->setAttribute('seq', array_search($article->getId(), $articleIds)+1);
				break;
			}
		}

		// Issue ID
		$match = $xpath->query("//article/front/article-meta/issue-id");
		if ($match->length) $match->item(0)->nodeValue = $issue->getId();
		else {
			$issueIdNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('issue-id'));
			$issueIdNode->nodeValue = $issue->getId();
		}

		// Issue cover page
		if ($coverUrl = $issue->getLocalizedCoverImageUrl()) {
			$customMetaGroupNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('custom-meta-group'));
			$customMetaNode = $customMetaGroupNode->appendChild($doc->createElement('custom-meta'));
			$metaNameNode = $customMetaNode->appendChild($doc->createElement('meta-name'));
			$metaNameNode->nodeValue = 'issue-cover';
			$metaValueNode = $customMetaNode->appendChild($doc->createElement('meta-value'));
			$inlineGraphicNode = $metaValueNode->appendChild($doc->createElement('inline-graphic'));
			$inlineGraphicNode->setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
			$inlineGraphicNode->setAttribute('xlink:href', $coverUrl);
		}

		// Article type
		if ($articleType = strtolower(trim($section->getLocalizedIdentifyType()))) {
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
				$surname = method_exists($user, 'getLastName')?$user->getLastName():$user->getLocalizedFamilyName();
				if ($surname != '') {
					$surnameNode = $nameNode->appendChild($doc->createElement('surname'));
					$surnameNode->nodeValue = $surname;
				}
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
