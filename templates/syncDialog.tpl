<script type="text/javascript">
$(function() {ldelim}
    $('#scholar9SyncForm, #scholar9SyncMissingForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
{rdelim});
</script>

{if $blockingError}
<form class="pkp_form s9-sync-dialog" id="scholar9SyncMissingForm">
    <div class="s9-sync-dialog__summary s9-sync-dialog__summary--warning" role="alert">
        <span class="s9-sync-dialog__icon" aria-hidden="true">!</span>
        <div><h3>{translate key="plugins.generic.scholar9Review.sync.missing.title"}</h3><p>{$blockingError|escape}</p></div>
    </div>
    <div class="s9-sync-dialog__actions form_buttons"><a href="#" id="cancelFormButton-scholar9SyncMissing" class="cancelButton pkp_button">{translate key="common.close"}</a></div>
</form>
{else}
<form class="pkp_form s9-sync-dialog s9-sync-dialog--profile" id="scholar9SyncForm" method="POST"
      action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" plugin=$pluginName category="generic" verb="syncToScholar9" save=true escape=false}">
    {csrf}
    {include file="controllers/notification/inPlaceNotification.tpl" notificationId="scholar9SyncFormNotification"}

    {if $formError}
        <div class="s9-sync-dialog__runtime-error" role="alert" aria-live="assertive">
            <span class="s9-sync-dialog__icon s9-sync-dialog__icon--error" aria-hidden="true">!</span>
            <div><h3>{translate key="plugins.generic.scholar9Review.sync.error.title"}</h3><p class="s9-sync-dialog__runtime-message">{$formError|escape}</p><p>{translate key="plugins.generic.scholar9Review.sync.error.connectivity"}</p></div>
        </div>
    {/if}

    {if $syncRequestPending}
        <div class="s9-sync-dialog__summary s9-sync-dialog__summary--pending" role="status">
            <span class="s9-sync-dialog__icon s9-sync-dialog__icon--pending" aria-hidden="true">&#8230;</span>
            <div><h3>{translate key="plugins.generic.scholar9Review.sync.pending.title"}</h3><p>{translate key="plugins.generic.scholar9Review.sync.pending"}{if $syncRequestReference} <strong>{translate key="plugins.generic.scholar9Review.sync.reference"}: {$syncRequestReference|escape}</strong>{/if}</p></div>
        </div>
    {elseif $syncRequestApproved}
        <div class="s9-sync-dialog__summary s9-sync-dialog__summary--approved" role="status">
            <span class="s9-sync-dialog__icon s9-sync-dialog__icon--ready" aria-hidden="true">&#10003;</span>
            <div><h3>{translate key="plugins.generic.scholar9Review.sync.approved.title"}</h3><p>{translate key="plugins.generic.scholar9Review.sync.approved"}</p></div>
        </div>
    {/if}

    {if !$syncRequestPending && !$syncRequestApproved}
    <div class="s9-sync-dialog__summary{if $missingFields} s9-sync-dialog__summary--warning{/if}">
        <span class="s9-sync-dialog__icon{if !$missingFields} s9-sync-dialog__icon--ready{/if}" aria-hidden="true">{if $missingFields}!{else}&#10003;{/if}</span>
        <div><h3>{if $missingFields}{translate key="plugins.generic.scholar9Review.sync.missing.title"}{else}{translate key="plugins.generic.scholar9Review.sync.ready.title"}{/if}</h3><p>{translate key="plugins.generic.scholar9Review.sync.review.intro"}</p></div>
    </div>

    {if $missingFields}
        <section class="s9-sync-dialog__requirements"><h4>{translate key="plugins.generic.scholar9Review.sync.missing.listTitle"}</h4><ul>{foreach from=$missingFields item=field}<li><span aria-hidden="true">&#10005;</span> {$field|escape}</li>{/foreach}</ul></section>
    {/if}

    <div class="s9-sync-dialog__help"><strong>{if $configured}{translate key="plugins.generic.scholar9Review.sync.ready.update"}{else}{translate key="plugins.generic.scholar9Review.sync.ready.request"}{/if}</strong><p>{translate key="plugins.generic.scholar9Review.sync.review.help"}</p></div>

    <section class="s9-sync-profile">
        <h3>{translate key="plugins.generic.scholar9Review.sync.section.identity"}</h3>
        <div class="s9-sync-profile__grid">
            <label class="s9-sync-profile__field s9-sync-profile__field--wide s9-sync-profile__field--locked"><span>{translate key="plugins.generic.scholar9Review.sync.field.journalName"} *</span><input type="text" name="profile[name]" maxlength="200" required readonly aria-readonly="true" value="{$syncProfile.name|default:''|escape}" /><small>{translate key="plugins.generic.scholar9Review.sync.field.journalName.help"} {translate key="plugins.generic.scholar9Review.sync.field.locked"}</small></label>
            <label class="s9-sync-profile__field s9-sync-profile__field--locked"><span>{translate key="plugins.generic.scholar9Review.sync.field.shortName"} *</span><input type="text" name="profile[short_name]" maxlength="50" required readonly aria-readonly="true" value="{$syncProfile.short_name|default:''|escape}" /><small>{translate key="plugins.generic.scholar9Review.sync.field.locked"}</small></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.apiSlug"} *</span><input type="text" name="profile[api_slug]" maxlength="60" pattern="[a-z0-9_-]+" required value="{$syncApiSlug|escape}" /></label>
            <label class="s9-sync-profile__field s9-sync-profile__field--wide"><span>{translate key="plugins.generic.scholar9Review.sync.field.description"} *</span><textarea name="profile[description]" maxlength="4000" rows="4" required>{$syncProfile.description|default:''|escape}</textarea></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.onlineIssn"}</span><input type="text" name="profile[online_issn]" maxlength="20" value="{$syncProfile.online_issn|default:''|escape}" /></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.printIssn"}</span><input type="text" name="profile[print_issn]" maxlength="20" value="{$syncProfile.print_issn|default:''|escape}" /></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.firstYear"} *</span><input type="number" name="profile[first_publication_year]" min="1600" max="2100" required value="{$syncProfile.first_publication_year|default:''|escape}" /></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.frequency"} *</span><select name="profile[issue_frequency]" required><option value="">{translate key="common.select"}</option>{foreach from=['Weekly','Bi-Weekly','Monthly','Bi-Monthly','Quarterly','Semiannual','Yearly'] item=frequency}<option value="{$frequency|escape}"{if $syncProfile.issue_frequency|default:'' == $frequency} selected{/if}>{$frequency|escape}</option>{/foreach}</select></label>
            <label class="s9-sync-profile__field s9-sync-profile__field--wide"><span>{translate key="plugins.generic.scholar9Review.sync.field.languages"} *</span><input type="text" name="profile[languages]" required value="{$syncLanguages|escape}" /><small>{translate key="plugins.generic.scholar9Review.sync.field.commaSeparated"}</small></label>
            <label class="s9-sync-profile__field s9-sync-profile__field--wide"><span>{translate key="plugins.generic.scholar9Review.sync.field.subjects"}</span><textarea name="profile[subjects]" rows="2">{$syncSubjects|escape}</textarea></label>
        </div>
    </section>

    <section class="s9-sync-profile">
        <h3>{translate key="plugins.generic.scholar9Review.sync.section.publisher"}</h3>
        <p class="s9-sync-profile__note">{translate key="plugins.generic.scholar9Review.sync.section.publisher.help"}</p>
        <div class="s9-sync-profile__grid">
            <label class="s9-sync-profile__field s9-sync-profile__field--wide"><span>{translate key="plugins.generic.scholar9Review.sync.field.publisher"} *</span><input type="text" name="profile[publisher]" maxlength="200" required value="{$syncProfile.publisher|default:''|escape}" /></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.contactName"} *</span><input type="text" name="profile[contact_name]" maxlength="200" required value="{$syncProfile.contact_name|default:''|escape}" /></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.contactEmail"} *</span><input type="email" name="profile[contact_email]" maxlength="200" required value="{$syncProfile.contact_email|default:''|escape}" /></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.contactPhone"}</span><input type="text" name="profile[contact_phone]" maxlength="50" value="{$syncProfile.contact_phone|default:''|escape}" /></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.country"} *</span><input type="text" name="profile[country]" maxlength="100" required value="{$syncProfile.country|default:''|escape}" /></label>
            <label class="s9-sync-profile__field s9-sync-profile__field--wide"><span>{translate key="plugins.generic.scholar9Review.sync.field.address"} *</span><textarea name="profile[contact_address]" maxlength="1000" rows="2" required>{$syncProfile.contact_address|default:''|escape}</textarea></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.state"}</span><input type="text" name="profile[state]" maxlength="100" value="{$syncProfile.state|default:''|escape}" /></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.city"}</span><input type="text" name="profile[city]" maxlength="100" value="{$syncProfile.city|default:''|escape}" /></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.postalCode"}</span><input type="text" name="profile[postal_code]" maxlength="30" value="{$syncProfile.postal_code|default:''|escape}" /></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.issnCountry"}</span><input type="text" name="profile[issn_assignee_country]" maxlength="100" value="{$syncProfile.issn_assignee_country|default:''|escape}" /></label>
        </div>
    </section>

    <section class="s9-sync-profile">
        <h3>{translate key="plugins.generic.scholar9Review.sync.section.policies"}</h3>
        <div class="s9-sync-profile__grid s9-sync-profile__grid--policies">
            {foreach from=['open_access'=>'Open access','cope_guidelines'=>'COPE guidelines','authors_retain_copyright'=>'Authors retain copyright','peer_review'=>'Peer review','plagiarism_checks'=>'Plagiarism checks','apc_charged'=>'APC charged','preservation_policy'=>'Preservation policy','doi_enabled'=>'DOI enabled'] key=policyKey item=policyLabel}
                <label class="s9-sync-profile__field"><span>{$policyLabel|escape}</span><select name="profile[{$policyKey|escape}]"><option value="">{translate key="plugins.generic.scholar9Review.sync.value.notSpecified"}</option><option value="1"{if $syncProfile[$policyKey] === true} selected{/if}>{translate key="common.yes"}</option><option value="0"{if $syncProfile[$policyKey] === false} selected{/if}>{translate key="common.no"}</option></select></label>
            {/foreach}
            <label class="s9-sync-profile__field s9-sync-profile__field--wide"><span>{translate key="plugins.generic.scholar9Review.sync.field.license"}</span><input type="text" name="profile[open_access_license]" maxlength="500" value="{$syncProfile.open_access_license|default:''|escape}" /></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.impactFactor"}</span><input type="text" name="profile[impact_factor]" maxlength="30" value="{$syncProfile.impact_factor|default:''|escape}" /></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.impactAgency"}</span><input type="text" name="profile[impact_factor_assignee]" maxlength="200" value="{$syncProfile.impact_factor_assignee|default:''|escape}" /></label>
            <label class="s9-sync-profile__field s9-sync-profile__field--wide"><span>{translate key="plugins.generic.scholar9Review.sync.field.editorialBoard"}</span><textarea name="profile[editorial_board]" rows="3">{$syncEditorialBoard|escape}</textarea><small>{translate key="plugins.generic.scholar9Review.sync.field.editorialBoard.help"}</small></label>
            <label class="s9-sync-profile__field"><span>{translate key="plugins.generic.scholar9Review.sync.field.conferenceCharges"}</span><input type="text" name="profile[conference_charges]" maxlength="100" value="{$syncProfile.conference_charges|default:''|escape}" /></label>
        </div>
    </section>

    <section class="s9-sync-profile">
        <h3>{translate key="plugins.generic.scholar9Review.sync.section.indexing"}</h3>
        <p class="s9-sync-profile__note">{translate key="plugins.generic.scholar9Review.sync.section.indexing.help"}</p>
        <div class="s9-sync-profile__grid">
            {foreach from=['Google Scholar','Scopus','Web of Science','DOAJ','PubMed'] item=agency}
                <label class="s9-sync-profile__field"><span>{$agency|escape}</span><input type="url" name="profile[indexing_agencies][{$agency|escape}]" maxlength="500" placeholder="https://" value="{$syncIndexing[$agency]|default:''|escape}" /></label>
            {/foreach}
        </div>
    </section>

    <details class="s9-sync-profile s9-sync-profile--system">
        <summary>{translate key="plugins.generic.scholar9Review.sync.section.system"}</summary>
        <p class="s9-sync-profile__note">{translate key="plugins.generic.scholar9Review.sync.section.system.help"}</p>
        <dl><div><dt>{translate key="plugins.generic.scholar9Review.sync.field.sourceId"}</dt><dd>{$syncSystem.journalId|escape}</dd></div><div><dt>{translate key="plugins.generic.scholar9Review.sync.field.sourceUrl"}</dt><dd>{$syncSystem.sourceUrl|escape}</dd></div><div><dt>{translate key="plugins.generic.scholar9Review.sync.field.contextId"}</dt><dd>{$syncSystem.contextId|escape}</dd></div><div><dt>{translate key="plugins.generic.scholar9Review.sync.field.ojsVersion"}</dt><dd>{$syncSystem.ojsVersion|escape}</dd></div><div><dt>{translate key="plugins.generic.scholar9Review.sync.field.path"}</dt><dd>{$syncSystem.journalPath|escape}</dd></div><div><dt>{translate key="plugins.generic.scholar9Review.sync.field.journalUrl"}</dt><dd>{$syncSystem.journalUrl|escape}</dd></div><div><dt>{translate key="plugins.generic.scholar9Review.sync.field.photoUrl"}</dt><dd>{$syncSystem.photoUrl|escape}</dd></div><div><dt>{translate key="plugins.generic.scholar9Review.sync.field.primaryLocale"}</dt><dd>{$syncSystem.primaryLocale|escape}</dd></div><div><dt>{translate key="plugins.generic.scholar9Review.sync.field.supportedLocales"}</dt><dd>{$syncSystem.supportedLocales|escape}</dd></div><div><dt>{translate key="plugins.generic.scholar9Review.sync.field.publicationPlatform"}</dt><dd>{$syncSystem.publicationPlatform|escape}</dd></div></dl>
    </details>

    <div class="s9-sync-dialog__help s9-sync-dialog__help--security"><strong>{translate key="plugins.generic.scholar9Review.sync.review.securityTitle"}</strong><p>{translate key="plugins.generic.scholar9Review.sync.ready.security"}</p></div>
    {/if}
    {if $syncRequestPending || $syncRequestApproved}
        <div class="s9-sync-dialog__actions form_buttons"><a href="#" id="cancelFormButton-scholar9SyncStatus" class="cancelButton pkp_button">{translate key="common.close"}</a></div>
    {else}
        {fbvFormButtons submitText="plugins.generic.scholar9Review.sync.submit"}
    {/if}
</form>
{/if}
