<?php

/**
 * @file OAIJatsSettingsForm.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OAIJatsSettingsForm
 * @ingroup plugins_generic_webFeed
 *
 * @brief Form for managers to modify JATS Metadata Format plugin settings
 */

namespace APP\plugins\oaiMetadataFormats\oaiJats;

use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorPost;
use PKP\form\validation\FormValidatorCSRF;
use PKP\plugins\Plugin;

class OAIJatsSettingsForm extends Form
{
    /** @var int|null Associated context ID */
    private ?int $contextId;

    private Plugin $plugin;

    /**
     * Constructor
     * @param Plugin $plugin
     * @param int|null $contextId Context ID
     */
    public function __construct($plugin, $contextId)
    {
        $this->contextId = $contextId;
        $this->plugin = $plugin;

        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * Initialize form data.
     */
    public function initData()
    {
        $contextId = $this->contextId;
        $plugin = $this->plugin;

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
     * @copydoc Form::fetch()
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $plugin = $this->plugin;
        $contextId = $this->contextId;

        $plugin->updateSetting($contextId, 'forceJatsTemplate', $this->getData('forceJatsTemplate'));

        parent::execute(...$functionArgs);
    }
}
