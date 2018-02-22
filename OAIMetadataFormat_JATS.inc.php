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
		}

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

		// If no candidate files were located, return the null XML.
		if (empty($candidateFiles)) {
			$oaiDao->oai->error('cannotDisseminateFormat', 'Cannot disseminate format (JATS XML not available)');
		}
		if (count($candidateFiles) > 1) error_log('WARNING: More than one JATS XML candidate documents were located for submission ' . $article->getId() . '.');

		// Fetch the XML document
		$candidateFile = array_shift($candidateFiles);
		$doc = new DOMDocument;
		$doc->loadXML(file_get_contents($candidateFile->getFilePath()));

		$xpath = new DOMXPath($doc);

		// Set the article language.
		$xpath->query('//article')->item(0)->setAttribute('xml:lang', substr($article->getLocale(),0,2));

		// Set the publication date.
		if ($datePublished = $article->getDatePublished()) {
			$datePublished = strtotime($datePublished);
			$match = $xpath->query("//article/front/article-meta/pub-date[@pub-type='pub' and publication-format='print']");
			if ($match->length) {
				// An existing pub-date was found; empty and re-use.
				$dateNode = $match->item(0);
				while ($dateNode->hasChildNodes()) $dateNode->removeChild($dateNode->firstChild);
			} else {
				// No pub-date was found; create a new one.
				$articleMetaNode = $xpath->query('//article/front/article-meta')->item(0);
				$dateNode = $articleMetaNode->appendChild($doc->createElement('pub-date'));
				$dateNode->setAttribute('pub-type', 'pub');
				$dateNode->setAttribute('publication-format', 'print');
			}
			$dateNode->setAttribute('iso-8601-date', strftime('%Y-%m-%d', $datePublished));
			$dateNode->appendChild($doc->createElement('day'))->nodeValue = strftime('%d', $datePublished);
			$dateNode->appendChild($doc->createElement('month'))->nodeValue = strftime('%m', $datePublished);
			$dateNode->appendChild($doc->createElement('year'))->nodeValue = strftime('%Y', $datePublished);
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
		$articleMetaNode = $xpath->query('//article/front/article-meta')->item(0);
		foreach ($articleMetaNode->getElementsByTagName('abstract') as $abstractNode) $articleMetaNode->removeChild($abstractNode);
		foreach ((array) $article->getAbstract(null) as $locale => $abstract) {
			$abstractNode = $articleMetaNode->appendChild($doc->createElement('abstract'));
			$abstractNode->setAttribute('xml:lang', substr($locale,0,2));
			$abstractNode->nodeValue = $purifier->purify($abstract);
		}

		// Set the journal-id[publisher-id']
		$match = $xpath->query("//article/front/journal-meta/journal-id[@journal-id-type='publisher-id']");
		if ($match->length) $match->item(0)->nodeValue = $journal->getPath();
		else {
			$journalMetaNode = $xpath->query('//article/front/journal-meta')->item(0);
			$journalIdNode = $journalMetaNode->appendChild($doc->createElement('journal-id'));
			$journalIdNode->setAttribute('journal-id-type', 'publisher-id');
			$journalIdNode->nodeValue = $journal->getPath();
		}

		// Store the DOI
		if ($doi = trim($article->getStoredPubId('doi'))) {
			$match = $xpath->query("//article/front/article-meta/article-id[@pub-id-type='doi']");
			if ($match->length) $match->item(0)->nodeValue = $doi;
			else {
				$articleMetaNode = $xpath->query('//article/front/article-meta')->item(0);
				$articleIdNode = $articleMetaNode->appendChild($doc->createElement('article-id'));
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
			$articleMetaNode = $xpath->query('//article/front/article-meta')->item(0);
			$permissionsNode = $articleMetaNode->appendChild($doc->createElement('permissions'));
			if ($copyrightYear) $permissionsNode->appendChild($doc->createElement('copyright-year'))->nodeValue = $copyrightYear;
			if ($copyrightHolder) $permissionsNode->appendChild($doc->createElement('copyright-holder'))->nodeValue = $copyrightHolder;
			if ($licenseUrl) {
				$licenseNode = $permissionsNode->appendChild($doc->createElement('license'));
				$licenseNode->setAttribute('xlink:href', $licenseUrl);
			}
		}

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
			$articleMetaNode = $xpath->query('//article/front/article-meta')->item(0);
			$issueIdNode = $articleMetaNode->appendChild($doc->createElement('issue-id'));
			$issueIdNode->nodeValue = $issue->getId();
		}

		// Article type
		if ($articleType = trim($section->getLocalizedIdentifyType())) {
			$match = $xpath->query("//article/article-type");
			if ($match->length) $match->item(0)->nodeValue = $articleType;
			else {
				$articleNode = $xpath->query('//article')->item(0);
				$articleTypeNode = $articleNode->appendChild($doc->createElement('article-type'));
				$articleTypeNode->nodeValue = $articleType;
			}
		}

		// Editorial team
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
		$userGroups = $userGroupDao->getByContextId($journal->getId());
		$journalMetaNode = $xpath->query('//article/front/journal-meta')->item(0);
		$contribGroupNode = $journalMetaNode->appendChild($doc->createElement('contrib-group'));
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
				$surnameNode->nodeValue = $user->getLastName();
				$givenNamesNode = $contribNode->appendChild($doc->createElement('given-names'));
				$givenNamesNode->nodeValue = $user->getFirstName();
				if ($s = $user->getMiddleName()) $givenNamesNode->nodeValue .= " $s";
			}
		}

		return $doc->saveXml($doc->getElementsByTagName('article')->item(0));
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
