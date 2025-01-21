<?php

/**
 * @file OAIJatsSettingsForm.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OAIJatsSettingsForm
 *
 * @ingroup plugins_generic_webFeed
 *
 * @brief Form for managers to modify web feeds plugin settings
 */

namespace APP\plugins\oaiMetadataFormats\oaiJats;

use APP\template\TemplateManager;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class OAIJatsSettingsForm extends \PKP\form\Form
{
    /** @var int Associated context ID */
    private $_contextId;

    /** @var WebFeedPlugin Web feed plugin */
    private $_plugin;

    /**
     * Constructor
     *
     * @param $plugin WebFeedPlugin Web feed plugin
     * @param $contextId int Context ID
     */
    public function __construct($plugin, $contextId)
    {
        $this->_contextId = $contextId;
        $this->_plugin = $plugin;

        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * Initialize form data.
     */
    public function initData()
    {
        $contextId = $this->_contextId;
        $plugin = $this->_plugin;

        $this->setData('forceJatsTemplate', $plugin->getSetting($contextId, 'forceJatsTemplate'));
    }

    /**
     * Assign form data to user-submitted data.
     */
    public function readInputData()
    {
        $this->readUserVars(['forceJatsTemplate']);
    }

    /**
     * Fetch the form.
     *
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->_plugin->getName());
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $plugin = $this->_plugin;
        $contextId = $this->_contextId;

        $plugin->updateSetting($contextId, 'forceJatsTemplate', $this->getData('forceJatsTemplate'));

        parent::execute(...$functionArgs);
    }
}
