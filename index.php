<?php

/**
 * @file index.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @ingroup oai_format_jats
 * @brief Wrapper for the OAI JATS format plugin.
 *
 */

require_once('OAIMetadataFormatPlugin_JATS.inc.php');
require_once('OAIMetadataFormat_JATS.inc.php');

return new OAIMetadataFormatPlugin_JATS();
