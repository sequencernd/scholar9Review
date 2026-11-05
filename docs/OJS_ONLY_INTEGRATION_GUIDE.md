> Historical reference. This document describes an earlier integration design. Use the current README for installation and configuration, and docs/PKP_RELEASE.md for release procedures.

# Scholar9 OJS-Only Integration Guide

Plugin 1.3.2 integrates with Scholar9's existing external API and requires no Scholar9 application changes.

## API contract

The plugin uses Scholar9's existing registration, OAuth, journal-list, reviewer, review, message, rating and webhook endpoints. It does not call an unsupported journal-update endpoint and does not create a journal when checking an existing connection.

## Setup and API access

1. Copy the plugin to `plugins/generic/scholar9Review` and enable it for the target journal.
2. Open plugin **Settings** and enter only the Scholar9 origin, such as `https://scholar9.example`.
3. Before credentials are issued, leave Client ID, Client Secret and Journal Slug empty.
4. Complete the journal's native OJS profile under **Settings > Journal** and **Settings > Website**.
5. Select **Sync to Scholar9**. The native modal lists any required OJS fields that are missing.
6. Confirm the request. OJS sends only Scholar9's supported registration fields: owner name/email, organisation, stable journal identity, use case and preferred API slug.
7. Complete Scholar9's existing approval workflow and obtain Client ID, the one-time Client Secret and API slug.
8. Enter all three in the same OJS journal's plugin Settings and save.
9. Select **Sync to Scholar9** again. OJS authenticates and verifies connectivity with Scholar9's journal-list endpoint.

Scholar9 intentionally returns `DUPLICATE` when an application already exists for an email/journal pair. The plugin handles that as a successful no-op, making retries safe.

The authenticated Sync action is connection verification. Scholar9's existing API has no in-place journal update route. The plugin will not call journal creation as a substitute because that would create a duplicate and a new credential set.

## Issue frequency in OJS 3.5

OJS 3.5 does not provide a dedicated structured issue-frequency selector. The plugin obtains this value from native OJS content in either of these ways:

1. Under **Settings > Journal**, add an explicit sentence to **Description** or **About the Journal**, for example `Publication frequency: Quarterly`, and save the journal settings.
2. Alternatively, publish at least two correctly dated issues under **Issues**. The plugin calculates the frequency from the published issue history.

Explicit schedules support Weekly, Bi-Weekly/Fortnightly, Monthly, Bi-Monthly, Quarterly, Semiannual/Twice a Year and Yearly/Annual wording. A clearly labelled schedule takes precedence over historical inference because it describes the journal's current policy. Unrelated text such as “monthly newsletter” is ignored.

## Review workflow

1. Open an OJS submission and select **Review with Scholar9**.
2. Select the manuscript, optional plagiarism file and review parameters.
3. Search and select up to five Scholar9 reviewers.
4. Send the review.
5. Use **Send Message**, **Send File**, **Change reviewer** and **Sync conversation** inside the same tab.

Every local review row and file lookup is scoped by OJS context and submission. Signed file URLs are additionally bound to the file and an expiry time.

## Security

- CSRF and editor/manager authorization checks protect actions.
- Registration uses an explicit allow-list matching Scholar9's validation contract.
- Messages remove executable markup, event attributes, PHP tags, unsafe schemes and control characters.
- File paths are resolved by OJS repositories; client paths are never trusted.
- OAuth tokens are isolated by context, origin, slug and credential fingerprint.
- Webhooks use HMAC-SHA256 verification.
- Browser rendering uses escaped plain text.

## Production connectivity

The Scholar9 origin must be reachable from the OJS web server. Hosted OJS cannot normally reach `localhost` or private LAN addresses. For a timeout, verify the public HTTPS hostname, outbound firewall, destination port, reverse-proxy API routing and the CA bundle used by Apache PHP.

## Tests

From the plugin directory:

```bash
composer test
php tests/metadataProvider.integration.php <journal-path>
```

Optional configured, read-only checks:

```bash
php tests/reviewerPhoto.integration.php <reviewer-search-text>
php tests/reviewState.integration.php <context-id> <submission-id>
php tests/connection.integration.php <context-id>
```

From Scholar9, run its unchanged baseline suite:

```bash
php artisan test
```

## Acceptance checklist

1. Scholar9 has no plugin-specific uncommitted code.
2. Each OJS journal has independent settings and stable identity.
3. Missing profile values appear inside the native OJS modal.
4. A credential-free retry does not create a duplicate application.
5. Issued credentials verify the configured namespace.
6. Submission A never shows Submission B files, reviewers or messages.
7. Reviewer search, assignment, message, file, reviewer change, manual sync, rating and webhook operations work.
8. Malicious or executable input is not sent or rendered.
