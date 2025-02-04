<?php

/**
 * @file OAIMetadataFormatPlugin_JATS.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class OAIMetadataFormatPlugin_JATS
 * @see OAI
 *
 * @brief JATS XML format plugin for OAI.
 */

namespace APP\plugins\oaiMetadataFormats\oaiJats;

use APP\core\Application;
use APP\notification\NotificationManager;
use PKP\plugins\OAIMetadataFormatPlugin;
use PKP\core\JSONMessage;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\LinkAction;

class OAIMetadataFormatPlugin_JATS extends OAIMetadataFormatPlugin
{
    /**
     * Get the name of this plugin. The name must be unique within
     * its category.
     * @return String name of plugin
     */
    public function getName()
    {
        return 'OAIMetadataFormatPlugin_JATS';
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.oaiMetadata.jats.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.oaiMetadata.jats.description');
    }

    /**
     * Determine whether the plugin can be disabled.
     * @return boolean
     */
    public function getCanDisable()
    {
        return true;
    }

    /**
     * Determine whether the plugin can be enabled.
     * @return boolean
     */
    public function getCanEnable()
    {
        return true;
    }

    /**
     * Determine whether the plugin is enabled.
     * @return boolean
     */
    public function getEnabled()
    {
        $request = Application::get()->getRequest();
        if (!$request) {
            return false;
        }
        $contextId = $request->getContext() ? $request->getContext()->getId() : Application::SITE_CONTEXT_ID;
        return $this->getSetting($contextId, 'enabled');
    }

    /**
     * Set whether the plugin is enabled.
     * @param $enabled boolean
     */
    public function setEnabled($enabled)
    {
        $request = Application::get()->getRequest();
        $contextId = $request->getContext() ? $request->getContext()->getId() : Application::SITE_CONTEXT_ID;
        $this->updateSetting($contextId, 'enabled', $enabled, 'bool');
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb)
    {
        $router = $request->getRouter();
        return array_merge(
            $this->getEnabled() ? [
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'oaiMetadataFormats')),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
            ] : [],
            parent::getActions($request, $verb)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $form = new OAIJatsSettingsForm($this, $request->getContext()->getId());

                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        $notificationManager = new NotificationManager();
                        $notificationManager->createTrivialNotification($request->getUser()->getId());
                        return new JSONMessage(true);
                    }
                } else {
                    $form->initData();
                }
                return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }

    public function getFormatClass()
    {
        return '\APP\plugins\oaiMetadataFormats\oaiJats\OAIMetadataFormat_JATS';
    }

    public static function getMetadataPrefix()
    {
        return 'jats';
    }

    public static function getSchema()
    {
        return 'https://jats.nlm.nih.gov/publishing/0.4/xsd/JATS-journalpublishing0.xsd';
    }

    public static function getNamespace()
    {
        return 'http://jats.nlm.nih.gov';
    }
}
