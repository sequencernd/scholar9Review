<?php

declare(strict_types=1);

function assertSyncDialog(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$plugin = (string) file_get_contents(dirname(__DIR__) . '/Scholar9ReviewPlugin.php');
$apiClient = (string) file_get_contents(dirname(__DIR__) . '/classes/Scholar9ApiClient.php');
$settingsForm = (string) file_get_contents(dirname(__DIR__) . '/classes/Scholar9SettingsForm.php');
$template = (string) file_get_contents(dirname(__DIR__) . '/templates/syncDialog.tpl');
$settingsTemplate = (string) file_get_contents(dirname(__DIR__) . '/templates/settingsForm.tpl');
$styles = (string) file_get_contents(dirname(__DIR__) . '/css/workflow.css');
$locale = (string) file_get_contents(dirname(__DIR__) . '/locale/en/locale.po');

assertSyncDialog(
    str_contains($apiClient, 'https://scholar9.com')
        && str_contains($settingsForm, 'Scholar9ApiClient::DEFAULT_BASE_URL')
        && str_contains($settingsForm, '$isUnsetBaseUrl'),
    'Fresh or blank Scholar9 settings must default the Base URL to https://scholar9.com without replacing a saved URL.'
);

assertSyncDialog(
    str_contains($plugin, "'syncToScholar9',\n                    new AjaxModal("),
    'Sync to Scholar9 must use OJS AjaxModal instead of a browser-alert confirmation action.'
);
assertSyncDialog(
    !str_contains($plugin, 'RemoteActionConfirmationModal'),
    'The sync action must not retain the alert-producing remote confirmation modal.'
);
assertSyncDialog(
    str_contains($plugin, "'settingsUrl' => \$settingsUrl")
        && str_contains($plugin, "'management',\n            'settings',\n            ['website']"),
    'The workflow setup guide must link to the current journal native plugin settings page.'
);
assertSyncDialog(
    str_contains($plugin, '$metadataProvider->applyEditableOverrides(')
        && str_contains($plugin, '$metadataProvider->validateReviewedPayload($metadata)'),
    'The sync modal must apply and validate the exact reviewed profile before transmission.'
);
assertSyncDialog(
    str_contains($plugin, "'journalName' => \\htmlspecialchars("),
    'Journal names interpolated into the modal must be HTML-encoded by the controller.'
);
assertSyncDialog(
    str_contains($plugin, 'private function renderJournalSyncDialog(')
        && str_contains($plugin, '$this->safeSyncError($message)')
        && !str_contains($plugin, 'return new JSONMessage(false, $message);'),
    'Recoverable sync failures must re-render the native modal instead of triggering a browser alert.'
);
assertSyncDialog(
    str_contains($template, '{if $blockingError}')
        && str_contains($template, '{foreach from=$missingFields item=field}')
        && str_contains($template, '{$field|escape}'),
    'Missing metadata must be rendered as an escaped, structured list above the editable form.'
);
assertSyncDialog(
    str_contains($template, 'AjaxFormHandler')
        && str_contains($template, 'id="scholar9SyncForm"')
        && str_contains($template, 'save=true'),
    'A complete journal must use the native OJS AJAX form lifecycle.'
);
assertSyncDialog(
    str_contains($template, 'name="profile[name]"')
        && str_contains($template, 'name="profile[contact_name]"')
        && str_contains($template, 'name="profile[online_issn]"')
        && str_contains($template, 'name="profile[indexing_agencies]'),
    'The modal must review journal identity, contact, publication and indexing metadata.'
);
assertSyncDialog(
    preg_match('/name="profile\[name\]"[^>]*readonly/', $template) === 1
        && preg_match('/name="profile\[short_name\]"[^>]*readonly/', $template) === 1
        && preg_match('/name="profile\[online_issn\]"[^>]*readonly/', $template) === 0
        && preg_match('/name="profile\[print_issn\]"[^>]*readonly/', $template) === 0
        && preg_match('/name="profile\[publisher\]"[^>]*readonly/', $template) === 0
        && preg_match('/name="profile\[contact_name\]"[^>]*readonly/', $template) === 0
        && preg_match('/name="profile\[contact_email\]"[^>]*readonly/', $template) === 0
        && preg_match('/name="profile\[contact_address\]"[^>]*readonly/', $template) === 0,
    'Stable OJS identity must remain read-only while reviewed ISSN, publisher and contact fields stay editable.'
);
assertSyncDialog(
    str_contains($plugin, '$storedSyncStatus === \'pending\'')
        && str_contains($template, '$syncRequestPending')
        && str_contains($template, 'plugins.generic.scholar9Review.sync.approved'),
    'Pending and approved states must prevent duplicate registration and display clear status.'
);
assertSyncDialog(
    str_contains($template, '{if !$syncRequestPending && !$syncRequestApproved}')
        && str_contains($template, '{if $syncRequestPending || $syncRequestApproved}')
        && str_contains($template, 'cancelFormButton-scholar9SyncStatus'),
    'Pending and approved journals must show status only, without profile options or a sync submit button.'
);
assertSyncDialog(
    !preg_match('/\balert\s*\(/i', $template),
    'The sync dialog must not call a browser alert.'
);
assertSyncDialog(
    str_contains($template, '{if $formError}')
        && str_contains($template, '{$formError|escape}')
        && str_contains($template, 's9-sync-dialog__runtime-error'),
    'Transport failures must appear as escaped inline errors inside the sync form.'
);
assertSyncDialog(
    str_contains($styles, '.s9-sync-dialog__requirements')
        && str_contains($styles, '.s9-sync-dialog__summary--warning')
        && str_contains($styles, '.s9-sync-dialog__runtime-error'),
    'The missing-fields dialog must include scoped professional styling.'
);
assertSyncDialog(
    str_contains($template, 'plugins.generic.scholar9Review.sync.field.journalName.help')
        && str_contains($template, '{$syncSystem.journalId|escape}')
        && !str_contains($template, 'name="profile[journal_id]"'),
    'The journal title must be explicit while stable OJS identity remains visible and immutable.'
);
assertSyncDialog(
    str_contains($locale, 'plugins.generic.scholar9Review.sync.missing.title')
        && str_contains($locale, 'plugins.generic.scholar9Review.sync.submit')
        && str_contains($locale, 'plugins.generic.scholar9Review.sync.pending.title')
        && str_contains($locale, 'plugins.generic.scholar9Review.sync.approved.title')
        && str_contains($locale, 'plugins.generic.scholar9Review.sync.field.locked')
        && str_contains($locale, 'plugins.generic.scholar9Review.sync.error.connectivity')
        && str_contains($locale, 'plugins.generic.scholar9Review.sync.missing.frequencyHelp'),
    'Every sync-dialog label must be localized.'
);
assertSyncDialog(
    str_contains($settingsTemplate, 'plugins.generic.scholar9Review.settings.setup.title')
        && str_contains($settingsTemplate, 'plugins.generic.scholar9Review.settings.setup.request')
        && str_contains($locale, 'plugins.generic.scholar9Review.settings.setup.credentials')
        && str_contains($styles, '.s9-settings-guide'),
    'Plugin Settings must explain where credentials are requested, approved and entered.'
);

fwrite(STDOUT, "Scholar9 native sync dialog tests passed.\n");
