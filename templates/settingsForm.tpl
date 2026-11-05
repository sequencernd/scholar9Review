<script type="text/javascript">
$(function() {
    $('#scholar9SettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
});
</script>

<form class="pkp_form" id="scholar9SettingsForm" method="POST"
      action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" plugin=$pluginName category="generic" verb="settings" save=true escape=false}">
    {csrf}
    {include file="controllers/notification/inPlaceNotification.tpl" notificationId="scholar9SettingsFormNotification"}

    <div class="pkp_helpers_wrap">

        <section class="s9-settings-guide" aria-labelledby="scholar9CredentialSetupTitle">
            <h3 id="scholar9CredentialSetupTitle">{translate key="plugins.generic.scholar9Review.settings.setup.title"}</h3>
            <p>{translate key="plugins.generic.scholar9Review.settings.setup.intro"}</p>
            <ol>
                <li>{translate key="plugins.generic.scholar9Review.settings.setup.baseUrl"}</li>
                <li>{translate key="plugins.generic.scholar9Review.settings.setup.request"}</li>
                <li>{translate key="plugins.generic.scholar9Review.settings.setup.approve"}</li>
                <li>{translate key="plugins.generic.scholar9Review.settings.setup.credentials"}</li>
            </ol>
            <p class="s9-settings-guide__security">{translate key="plugins.generic.scholar9Review.settings.setup.security"}</p>
        </section>

        {* ── Connection ── *}
        <h3>{translate key="plugins.generic.scholar9Review.settings.connection"}</h3>

        {fbvFormSection label="plugins.generic.scholar9Review.settings.baseUrl" required=true}
            {fbvElement type="text" id="baseUrl" name="baseUrl" value=$baseUrl maxlength="255"
                label="plugins.generic.scholar9Review.settings.baseUrl.description" size=$fbvStyles.size.LARGE}
        {/fbvFormSection}

        {fbvFormSection label="plugins.generic.scholar9Review.settings.clientId"}
            {fbvElement type="text" id="clientId" name="clientId" value=$clientId maxlength="255" size=$fbvStyles.size.LARGE}
        {/fbvFormSection}

        {fbvFormSection label="plugins.generic.scholar9Review.settings.clientSecret"}
            {fbvElement type="text" password=true id="clientSecret" name="clientSecret" value=$clientSecret maxlength="255" size=$fbvStyles.size.LARGE autocomplete="new-password"}
        {/fbvFormSection}

        {fbvFormSection label="plugins.generic.scholar9Review.settings.journalSlug"}
            {fbvElement type="text" id="journalSlug" name="journalSlug" value=$journalSlug maxlength="100"
                label="plugins.generic.scholar9Review.settings.journalSlug.description" size=$fbvStyles.size.MEDIUM}
        {/fbvFormSection}

        {fbvFormSection label="plugins.generic.scholar9Review.settings.webhookSecret"}
            {fbvElement type="text" password=true id="webhookSecret" name="webhookSecret" value=$webhookSecret maxlength="255" size=$fbvStyles.size.LARGE autocomplete="new-password"}
            <span class="instruct">{translate key="plugins.generic.scholar9Review.settings.webhookSecret.description"}</span>
        {/fbvFormSection}

        {fbvFormSection label="plugins.generic.scholar9Review.settings.verifySsl" list=true}
            {fbvElement type="checkbox" id="verifySsl" name="verifySsl" checked=$verifySsl label="plugins.generic.scholar9Review.settings.verifySsl.label"}
        {/fbvFormSection}

        {* ── Review defaults ── *}
        <h3>{translate key="plugins.generic.scholar9Review.settings.reviewDefaults"}</h3>

        {fbvFormSection label="plugins.generic.scholar9Review.settings.defaultReviewType"}
            {fbvElement type="select" id="defaultReviewType" name="defaultReviewType" from=$reviewTypes selected=$defaultReviewType size=$fbvStyles.size.SMALL}
        {/fbvFormSection}

        {fbvFormSection label="plugins.generic.scholar9Review.settings.reviewDeadline"}
            {fbvElement type="text" id="reviewDeadline" name="reviewDeadline" value=$reviewDeadline maxlength="3" size=$fbvStyles.size.SMALL
                label="plugins.generic.scholar9Review.settings.reviewDeadline.description"}
        {/fbvFormSection}

        {* ── Automation ── *}
        <h3>{translate key="plugins.generic.scholar9Review.settings.automation"}</h3>

        {fbvFormSection list=true}
            {fbvElement type="checkbox" id="autoSync" name="autoSync" checked=$autoSync
                label="plugins.generic.scholar9Review.settings.autoSync.label"}
        {/fbvFormSection}

        {fbvFormSection list=true}
            {fbvElement type="checkbox" id="uploadFiles" name="uploadFiles" checked=$uploadFiles
                label="plugins.generic.scholar9Review.settings.uploadFiles.label"}
        {/fbvFormSection}

        {fbvFormSection list=true}
            {fbvElement type="checkbox" id="uploadSupplementary" name="uploadSupplementary" checked=$uploadSupplementary
                label="plugins.generic.scholar9Review.settings.uploadSupplementary.label"}
        {/fbvFormSection}

    </div>

    {fbvFormButtons submitText="common.save"}
</form>
