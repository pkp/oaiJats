<?php

/**
 * @file OAIMetadataFormat_JATS.php
 *
 * Copyright (c) 2013-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class OAIMetadataFormat_JATS
 * @see OAI
 *
 * @brief OAI metadata format class -- JATS
 */

namespace APP\plugins\oaiMetadataFormats\oaiJats;

use APP\facades\Repo;
use PKP\oai\OAIMetadataFormat;
use PKP\db\DAORegistry;
use PKP\submissionFile\SubmissionFile;
use APP\core\Application;
use APP\issue\IssueAction;
use PKP\plugins\PluginRegistry;
use PKP\plugins\Hook;

class OAIMetadataFormat_JATS extends OAIMetadataFormat {
	/**
	 * Identify a candidate JATS file to expose via OAI.
	 * @param $record \PKP\core\DataObject
	 * @return DOMDocument|null
	 */
	protected function _findJats($record) {
		$article = $record->getData('article');
		$galleys = $record->getData('galleys');

		$candidateFiles = [];

		$plugin = PluginRegistry::getPlugin('oaiMetadataFormats', 'OAIMetadataFormatPlugin_JATS');
		$forceJatsTemplate = $plugin->getSetting($article->getData('contextId'), 'forceJatsTemplate');

		// First, look for candidates in the galleys area (published content).
		if (!$forceJatsTemplate) {
			foreach ($galleys as $galley) {
				$galleyFile = Repo::submissionFile()->get((int) $galley->getData('submissionFileId'));
				if ($galleyFile && $this->_isCandidateFile($galleyFile)) $candidateFiles[] = $galleyFile;
			}

			// If no candidates were found, look in the layout area (unpublished content).
			if (empty($candidateFiles)) {
				$layoutFiles = Repo::submissionFile()->getCollector()
					->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PRODUCTION_READY])
					->filterBySubmissionIds([$article->getId()])
					->getMany();

				foreach ($layoutFiles as $layoutFile) {
					if ($this->_isCandidateFile($layoutFile)) $candidateFiles[] = $layoutFile;
				}
			}
		}

		$doc = null;
		Hook::call('OAIMetadataFormat_JATS::findJats', [&$this, &$record, &$candidateFiles, &$doc]);

		// If no candidate files were located, return the null XML.
		if (!$doc && empty($candidateFiles)) {
			return null;
		}
		if (count($candidateFiles) > 1) error_log('WARNING: More than one JATS XML candidate documents were located for submission ' . $article->getId() . '.');

		// Fetch the XML document
		if (!$doc) {
			$candidateFile = array_shift($candidateFiles);
			$fileService = app()->get('file');
			$filepath = $fileService->get($candidateFile->getData('fileId'))->path;
			$doc = new DOMDocument;
			$doc->loadXML($fileService->fs->read($filepath));
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
		$section = $record->getData('section');
		$issue = $record->getData('issue');

		// Check access
		$request = Application::get()->getRequest();
		$issueAction = new IssueAction();
		$subscriptionRequired = $issueAction->subscriptionRequired($issue, $journal);
		$isSubscribedDomain = $issueAction->subscribedDomain(Application::get()->getRequest(), $journal, $issue->getId(), $article->getId());
		$allowedPrePublicationAccess = $issueAction->allowedIssuePrePublicationAccess($journal, $request->getUser());
		if ($subscriptionRequired && (!$allowedPrePublicationAccess && !$isSubscribedDomain)) {
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
		$permittedElementOrders = [
			'front' => ['article-meta', 'journal-meta'],
			'article-meta' => ['article-id', 'article-categories', 'title-group', 'contrib-group', 'aff', 'aff-alternatives', 'x', 'author-notes', 'pub-date', 'volume', 'volume-id', 'volume-series', 'issue', 'issue-id', 'issue-title', 'issue-sponsor', 'issue-part', 'isbn', 'supplement', 'fpage', 'lpage', 'page-range', 'elocation-id', 'email', 'ext-link', 'uri', 'product', 'supplementary-material', 'history', 'permissions', 'self-uri', 'related-article', 'related-object', 'abstract', 'trans-abstract', 'kwd-group', 'funding-group', 'conference', 'counts', 'custom-meta-group'],
			'journal-meta' => ['journal-id', 'journal-title-group', 'contrib-group', 'aff', 'aff-alternatives', 'issn', 'issn-l', 'isbn', 'publisher', 'notes', 'self-uri', 'custom-meta-group'],
			'counts' => ['count', 'fig-count', 'table-count', 'equation-count', 'ref-count', 'page-count', 'word-count'],
		];

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
		$xpath = new \DOMXPath($doc);
		$articleMetaNode = $xpath->query('//article/front/article-meta')->item(0);
		$journalMetaNode = $xpath->query('//article/front/journal-meta')->item(0);
		if (!$journalMetaNode) {
			$frontNode = $xpath->query('//article/front')->item(0);
			$journalMetaNode = $this->_addChildInOrder($frontNode, $doc->createElement('journal-meta'));
		}

		$request = Application::get()->getRequest();
		$publication = $article->getCurrentPublication();

		$articleNode = $xpath->query('//article')->item(0);
		$articleNode->setAttribute('xml:lang', substr($article->getData('locale'),0,2));
		$articleNode->setAttribute('dtd-version', '1.1');
		$articleNode->setAttribute('specific-use', 'eps-0.1');
		$articleNode->setAttribute('xmlns', 'https://jats.nlm.nih.gov/publishing/1.1/');

		// Set the article publication date. http://erudit-ps-documentation.readthedocs.io/en/latest/tagset/element-pub-date.html
		if ($datePublished = $publication->getData('datePublished')) {
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

			$dateNode->appendChild($doc->createElement('day'))->appendChild($doc->createTextNode(strftime('%d', $datePublished)));
			$dateNode->appendChild($doc->createElement('month'))->appendChild($doc->createTextNode(strftime('%m', $datePublished)));
			$dateNode->appendChild($doc->createElement('year'))->appendChild($doc->createTextNode(strftime('%Y', $datePublished)));
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
			$dateNode->appendChild($doc->createElement('year'))->appendChild($doc->createTextNode($issueYear));
		}

		// Remove all article-meta/self-uri nodes in preparation for additions below
		$match = $xpath->query('//article/front/article-meta/self-uri');
		foreach ($match as $node) $articleMetaNode->removeChild($node);

		// Set the article URLs: Landing page
		$uriNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('self-uri'));
		$uriNode->setAttribute('xlink:href', $request->url(null, 'article', 'view', [$article->getBestId()]));

		// Set the article URLs: Galleys
		foreach ($article->getGalleys() as $galley) {
			$uriNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('self-uri'));
			$uriNode->setAttribute('xlink:href', $request->url(null, 'article', 'view', [$article->getBestId(), $galley->getId()]));
			if (!$galley->getData('urlRemote')) $uriNode->setAttribute('content-type', $galley->getFileType());
		}

		// Set the issue volume (if applicable).
		if ($issue && $issue->getShowVolume()) {
			$match = $xpath->query('//article/front/article-meta/volume');
			if ($match->length) $volumeNode = $match->item(0);
			else $volumeNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('volume'));
			$volumeNode->appendChild($doc->createTextNode($issue->getVolume()));
		}

		// Set the issue number (if applicable).
		if ($issue && $issue->getShowNumber()) {
			$match = $xpath->query('//article/front/article-meta/issue');
			if ($match->length) $numberNode = $match->item(0);
			else $numberNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('issue'));
			$numberNode->appendChild($doc->createTextNode($issue->getNumber()));
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
		$titleNode->setAttribute('xml:lang', substr($article->getData('locale'),0,2));

		$articleTitleHtml = $doc->createDocumentFragment();
		$articleTitleHtml->appendXML(
			$this->mapHtmlTagsForTitle(
				$article->getCurrentPublication()->getLocalizedTitle(
					$article->getData('locale'),
					'html'
				)
			)
		);
		$titleNode->appendChild($articleTitleHtml);

		if (!empty($subtitle = $article->getCurrentPublication()->getLocalizedSubTitle($article->getData('locale'), 'html'))) {

			$subtitleHtml = $doc->createDocumentFragment();
			$subtitleHtml->appendXML($this->mapHtmlTagsForTitle($subtitle));

			$subtitleNode = $titleGroupNode->appendChild($doc->createElement('subtitle'));
			$subtitleNode->setAttribute('xml:lang', substr($article->getData('locale'),0,2));

			$subtitleNode->appendChild($subtitleHtml);
		}
		foreach ($article->getCurrentPublication()->getTitles('html') as $locale => $title) {
			if ($locale == $article->getData('locale')) {
				continue;
			}

			if ( trim($title = $this->mapHtmlTagsForTitle($title)) === '' ) {
				continue;
			}

			$transTitleGroupNode = $titleGroupNode->appendChild($doc->createElement('trans-title-group'));
			$transTitleGroupNode->setAttribute('xml:lang', substr($locale,0,2));
			$titleNode = $transTitleGroupNode->appendChild($doc->createElement('trans-title'));

			$titleHtml = $doc->createDocumentFragment();
			$titleHtml->appendXML($title);
			$titleNode->appendChild($titleHtml);

			if (!empty($subtitle = $article->getCurrentPublication()->getLocalizedSubTitle($locale, 'html'))) {
				$subtitleNode = $transTitleGroupNode->appendChild($doc->createElement('trans-subtitle'));
				$subtitleHtml = $doc->createDocumentFragment();
				$subtitleHtml->appendXML($this->mapHtmlTagsForTitle($subtitle));
				$subtitleNode->appendChild($subtitleHtml);
			}
		}

		// Set the article keywords.
		$keywordGroupNode = $xpath->query('//article/front/article-meta/kwd-group')->item(0);
		$submissionKeywordDao = DAORegistry::getDAO('SubmissionKeywordDAO');
		while (($kwdGroupNodes = $articleMetaNode->getElementsByTagName('kwd-group'))->length !== 0) {
			$articleMetaNode->removeChild($kwdGroupNodes->item(0));
		}
		foreach ($submissionKeywordDao->getKeywords($publication->getId(), $journal->getSupportedLocales()) as $locale => $keywords) {
			if (empty($keywords)) continue;

			$kwdGroupNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('kwd-group'));
			$kwdGroupNode->setAttribute('xml:lang', substr($locale,0,2));
			$kwdGroupNode->appendChild($doc->createElement('title'))->appendChild($doc->createTextNode(__('article.subject', [], $locale)));
			foreach ($keywords as $keyword) $kwdGroupNode->appendChild($doc->createElement('kwd'))->appendChild($doc->createTextNode($keyword));
		}

		// Set the article abstract.
		static $purifier;
		if (!$purifier) {
			$config = \HTMLPurifier_Config::createDefault();
			$config->set('HTML.Allowed', 'p');
			$config->set('Cache.SerializerPath', 'cache');
			$purifier = new \HTMLPurifier($config);
		}

		foreach ($articleMetaNode->getElementsByTagName('abstract') as $abstractNode) $articleMetaNode->removeChild($abstractNode);
		foreach ((array) $publication->getData('abstract') as $locale => $abstract) {
			if (empty($abstract)) continue;
			$isPrimary = $locale == $article->getData('locale');
			$abstractDoc = new \DOMDocument;
			if (strpos($abstract, '<p>')===null) $abstract = "<p>$abstract</p>";
			$abstractDoc->loadXML(($isPrimary?'<abstract>':'<trans-abstract>') . $purifier->purify($abstract) . ($isPrimary?'</abstract>':'</trans-abstract>'));
			$abstractNode = $this->_addChildInOrder($articleMetaNode, $doc->importNode($abstractDoc->documentElement, true));
			if (!$isPrimary) $abstractNode->setAttribute('xml:lang', substr($locale,0,2));
		}

		// Set the journal-id[publisher-id']
		$match = $xpath->query("//article/front/journal-meta/journal-id[@journal-id-type='publisher']");
		if ($match->length) $match->item(0)->appendChild($doc->createTextNode($journal->getPath()));
		else {
			$journalIdNode = $this->_addChildInOrder($journalMetaNode, $doc->createElement('journal-id'));
			$journalIdNode->setAttribute('journal-id-type', 'publisher');
			$journalIdNode->appendChild($doc->createTextNode($journal->getPath()));
		}

		// Set article-id[publisher-id]
		$match = $xpath->query("//article/front/article-meta/article-id[@pub-id-type='publisher-id']");
		if ($match->length) {
			$originalIdNode = $match->item(0)->firstChild;
			if ($originalIdNode) {
				$match->item(0)->replaceChild($doc->createTextNode($article->getId()), $originalIdNode);
			}
		} else {
			$articleIdNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('article-id'));
			$articleIdNode->setAttribute('pub-id-type', 'publisher-id');
			$articleIdNode->appendChild($doc->createTextNode($article->getId()));
		}

		// Store the DOI
		if ($doi = trim($article->getStoredPubId('doi'))) {
			$match = $xpath->query("//article/front/article-meta/article-id[@pub-id-type='doi']");
			if ($match->length) {
				$originalDoiNode = $match->item(0)->firstChild;
				$match->item(0)->replaceChild($doc->createTextNode($doi), $originalDoiNode);
			} else {
				$articleIdNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('article-id'));
				$articleIdNode->setAttribute('pub-id-type', 'doi');
				$articleIdNode->appendChild($doc->createTextNode($doi));
			}
		}

		// Override permissions, when not supplied in the document
		$match = $xpath->query('//article/front/article-meta/permissions');
		$copyrightHolder = $publication->getLocalizedData('copyrightHolder', $article->getData('locale'));
		$copyrightYear = $publication->getData('copyrightYear');
		$licenseUrl = $publication->getData('licenseUrl');
		if (!$match->length && ($copyrightHolder || $copyrightYear || $licenseUrl)) {
			$permissionsNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('permissions'));
			if ($copyrightYear || $copyrightHolder) $permissionsNode->appendChild($doc->createElement('copyright-statement'))->appendChild($doc->createTextNode(__('submission.copyrightStatement', ['copyrightYear' => $copyrightYear, 'copyrightHolder' => $copyrightHolder])));
			if ($copyrightYear) $permissionsNode->appendChild($doc->createElement('copyright-year'))->appendChild($doc->createTextNode($copyrightYear));
			if ($copyrightHolder) $permissionsNode->appendChild($doc->createElement('copyright-holder'))->appendChild($doc->createTextNode($copyrightHolder));
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
			$subjectNode->appendChild($doc->createTextNode($title));
		}

		// Article sequence information
		foreach (['volume', 'issue'] as $nodeName) {
			$match = $xpath->query("//article/front/article-meta/$nodeName");
			if ($match->length) {
                $match->item(0)->setAttribute('seq', ((int) $publication->getData('seq')) + 1);
				break;
			}
		}

		// Issue ID
		$match = $xpath->query("//article/front/article-meta/issue-id");
		if ($match->length) $match->item(0)->appendChild($doc->createTextNode($issue->getId()));
		else {
			$issueIdNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('issue-id'));
			$issueIdNode->appendChild($doc->createTextNode($issue->getId()));
		}

		// Issue cover page
		if ($coverUrl = $issue->getLocalizedCoverImageUrl()) {
			$customMetaGroupNode = $this->_addChildInOrder($articleMetaNode, $doc->createElement('custom-meta-group'));
			$customMetaNode = $customMetaGroupNode->appendChild($doc->createElement('custom-meta'));
			$metaNameNode = $customMetaNode->appendChild($doc->createElement('meta-name'));
			$metaNameNode->appendChild($doc->createTextNode('issue-cover'));
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
		$userGroups = Repo::userGroup()->getCollector()->filterByContextIds([$journal->getId()])->getMany();
		$journalMetaNode = $xpath->query('//article/front/journal-meta')->item(0);
		$contribGroupNode = $this->_addChildInOrder($journalMetaNode, $doc->createElement('contrib-group'));
		$keyContribTypeMapping = [
			'default.groups.name.manager' => 'jmanager',
			'default.groups.name.editor' => 'editor',
			'default.groups.name.sectionEditor' => 'secteditor',
		];
		foreach ($userGroups as $userGroup) {
			if (!isset($keyContribTypeMapping[$userGroup->getData('nameLocaleKey')])) continue;

			$users = Repo::user()->getCollector()->filterByUserGroupIds([$userGroup->getId()])->getMany();
			foreach ($users as $user) {
				$contribNode = $contribGroupNode->appendChild($doc->createElement('contrib'));
				$contribNode->setAttribute('contrib-type', $keyContribTypeMapping[$userGroup->getData('nameLocaleKey')]);
				$nameNode = $contribNode->appendChild($doc->createElement('name'));
				$surname = method_exists($user, 'getLastName')?$user->getLastName():$user->getLocalizedFamilyName();
				if ($surname != '') {
					$surnameNode = $nameNode->appendChild($doc->createElement('surname'));
					$surnameNode->appendChild($doc->createTextNode($surname));
				}
				$givenNamesNode = $nameNode->appendChild($doc->createElement('given-names'));
				$givenNamesNode->appendChild($doc->createTextNode(method_exists($user, 'getFirstName')?$user->getFirstName():$user->getLocalizedGivenName()));
				if (method_exists($user, 'getMiddleName') && $s = $user->getMiddleName()) $givenNamesNode->appendChild($doc->createTextNode( " $s"));
			}
		}

	}

	/**
	 * Determine whether a submission file is a good candidate for JATS XML.
	 * @param $submissionFile SubmissionFile
	 * @return boolean
	 */
	protected function _isCandidateFile($submissionFile) {
		$fileService = app()->get('file');
		$filepath = $fileService->get($submissionFile->getData('fileId'))->path;
		$mimeType = $fileService->fs->mimeType($filepath);

		// The file type isn't XML.
		if (!in_array($mimeType, ['application/xml', 'text/xml'])) return false;

		static $genres = [];
		$genreDao = DAORegistry::getDAO('GenreDAO');
		$genreId = $submissionFile->getData('genreId');
		if (!isset($genres[$genreId])) $genres[$genreId] = $genreDao->getById($genreId);
		assert($genres[$genreId]);
		$genre = $genres[$genreId];

		// The genre doesn't look like a main submission document.
		if ($genre->getCategory() != GENRE_CATEGORY_DOCUMENT) return false;
		if ($genre->getDependent()) return false;
		if ($genre->getSupplementary()) return false;

		// Ensure that the file looks like a JATS document.
		$doc = new \DOMDocument;
		$doc->loadXML($fileService->fs->read($filepath));
		$xpath = new \DOMXPath($doc);
		$articleMetaNode = $xpath->query('//article/front/article-meta')->item(0);
		if (!$articleMetaNode) return false;

		return true;
	}

	/**
	 * Map the specific HTML tags in title/ sub title for JATS schema compability
	 * @see https://jats.nlm.nih.gov/publishing/0.4/xsd/JATS-journalpublishing0.xsd
	 *
	 * @param  string $htmlTitle The submission title/sub title as in HTML
	 * @return string
	 */
	public function mapHtmlTagsForTitle(string $htmlTitle): string
	{
		$mappings = [
			'<b>' 	=> '<bold>',
			'</b>' 	=> '</bold>',
			'<i>' 	=> '<italic>',
			'</i>' 	=> '</italic>',
			'<u>' 	=> '<underline>',
			'</u>' 	=> '</underline>',
		];

		return str_replace(array_keys($mappings), array_values($mappings), $htmlTitle);
	}
}
