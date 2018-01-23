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
		$journal = $record->getData('journal');
		$article = $record->getData('article');
		$galleys = $record->getData('galleys');
		$issue = $record->getData('issue');

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
			$oaiDao = DAORegistry::getDAO('OAIDAO');
			$oaiDao->oai->error('cannotDisseminateFormat', 'Cannot disseminate format (JATS XML not available)');
		}

		if (count($candidateFiles) > 1) error_log('WARNING: More than one JATS XML candidate documents were located for submission ' . $article->getId() . '.');

		// Fetch the XML document
		$candidateFile = array_shift($candidateFiles);
		$doc = new DOMDocument;
		$doc->loadXML(file_get_contents($candidateFile->getFilePath()));

		// Load the XSL transform (if needed)
		static $xslDocument;
		if (!isset($xslDocument)) {
			$xslDocument = new DOMDocument();
			$xslDocument->load(dirname(__FILE__) . '/transform.xsl');
		}
		$xslTransform = new XSLTProcessor();

		// Set the transformation variables
		$datePublished = $article->getDatePublished();
		if (!$datePublished) $datePublished = $issue->getDatePublished();
		if ($datePublished) $datePublished = strtotime($datePublished);
		$xslTransform->setParameter('', 'datePublished', $datePublished?strftime('%Y-%m-%d', $datePublished):'');
		$xslTransform->setParameter('', 'datePublishedDay', $datePublished?strftime('%d', $datePublished):'');
		$xslTransform->setParameter('', 'datePublishedMonth', $datePublished?strftime('%m', $datePublished):'');
		$xslTransform->setParameter('', 'datePublishedYear', $datePublished?strftime('%Y', $datePublished):'');
		$xslTransform->setParameter('', 'title', $article->getTitle($article->getLocale()));
		$xslTransform->setParameter('', 'doi', trim($article->getStoredPubId('doi')));
		$xslTransform->setParameter('', 'copyrightHolder', $article->getLocalizedCopyrightHolder($article->getLocale()));
		$xslTransform->setParameter('', 'copyrightYear', $article->getCopyrightYear());
		$xslTransform->setParameter('', 'licenseUrl', $article->getLicenseURL());
		$xslTransform->setParameter('', 'language', substr($article->getLocale(),0,2));
		$xslTransform->setParameter('', 'isUnpublishedXml', $candidateFile->getFileStage()==SUBMISSION_FILE_PRODUCTION_READY?1:0);
		$xslTransform->setParameter('', 'sectionTitle', $article->getSectionTitle());
		$xslTransform->setParameter('', 'journalPath', $journal->getPath());
		$xslTransform->setParameter('', 'journalPath', $journal->getPath());

		// Determine the article's sequence in the issue
		$publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
		$articleIds = array_map(function($publishedArticle) {
			return $publishedArticle->getId();
		}, $publishedArticleDao->getPublishedArticles($issue->getId()));
		$xslTransform->setParameter('', 'articleSeq', (int) array_search($article->getId(), $articleIds));

		static $purifier;
		if (!$purifier) {
			$config = HTMLPurifier_Config::createDefault();
			$config->set('HTML.Allowed', 'p');
			$config->set('Cache.SerializerPath', 'cache');
			$purifier = new HTMLPurifier($config);
		}
		$xslTransform->setParameter('', 'abstract', $purifier->purify($article->getAbstract($article->getLocale())));

		$xslTransform->importStyleSheet($xslDocument);

		// Transform the article
		$returner = $xslTransform->transformToDoc($doc);
		if ($returner === false) {
			$oaiDao = DAORegistry::getDAO('OAIDAO');
			$oaiDao->oai->error('cannotDisseminateFormat', 'Cannot disseminate format (transformation unsuccessful)');
			return false;
		}
		$articleNode = $returner->getElementsByTagName('article')->item(0);
		return $returner->saveXml($articleNode);
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
