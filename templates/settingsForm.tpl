{**
 * plugins/generic/oaiJats/templates/settingsForm.tpl
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * JATS Metadata Format plugin settings
 *
 *}
<script>
	$(function() {ldelim}
		// Attach the form handler.
		$('#oaiJatsSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="oaiJatsSettingsForm" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="oaiMetadataFormats" plugin=$pluginName verb="settings" save=true}">
	<div id="oaiJatsSettings">
		<div id="description">{translate key="plugins.oaiMetadataFormats.oaiJats.description"}</div>
		<h3>{translate key="plugins.oaiMetadataFormats.oaiJats.settings"}</h3>

		{csrf}
		{include file="controllers/notification/inPlaceNotification.tpl" notificationId="oaiJatsSettingsFormNotification"}

		{fbvFormArea id="oaiJatsSettingsFormArea"}
			{fbvFormSection list=true}
				{fbvElement type="checkbox" id="forceJatsTemplate" name="forceJatsTemplate" value="1" checked=$forceJatsTemplate label="plugins.oaiMetadataFormats.oaiJats.forceJatsTemplate"}
			{/fbvFormSection}
		{/fbvFormArea}
		{fbvFormButtons}
		<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
	</div>
</form>
