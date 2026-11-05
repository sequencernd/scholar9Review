# Scholar9 Transparent Peer Review for OJS

Version **1.3.5.1** connects an OJS journal to Scholar9 for reviewer discovery, review communication, file sharing, ratings, status updates and controlled public review links.

Maintained by **SEQUENCE RESEARCH & DEVELOPMENT PRIVATE LIMITED**.

## Requirements and compatibility

- PHP 8.1 or newer with cURL, JSON and the extensions required by the installed OJS version.
- An OJS installation. Release preparation and local integration checks use **OJS 3.5.0-5**. The source contains legacy OJS 3.4 hooks, but this release does not claim validated 3.4 compatibility.
- A reachable Scholar9 service, normally `https://scholar9.com`.
- Approved Scholar9 API access for each journal: Client ID, Client Secret and journal slug. Service access and any plan are managed separately by Scholar9; the plugin license does not grant a service subscription.
- HTTPS with certificate verification enabled in production. Scholar9 must be able to reach the OJS webhook URL for incoming updates.
- Working OJS outgoing mail configuration for API-request notifications.

No separate Composer dependencies or frontend build are required to run the installed plugin. Composer and Node.js are used for development tests.

## Features

- Journal profile synchronization and API-access applications.
- Manuscript and optional plagiarism-report selection.
- Reviewer search, assignment and replacement.
- Per-reviewer messages, shared files and profile photographs when available.
- Signed webhooks and polling for conversation updates.
- Reviewer and publisher star ratings.
- Open-review and full-review visibility controls when their completion requirements are met.
- Revision indicators based on structured comment status; ordinary messages retain their normal appearance.
- Journal/submission isolation, short-lived response caches and authenticated API calls.

## Installation and upgrade

1. Back up the database and files before installing or upgrading.
2. Download the attached `scholar9Review-1.3.5.1.tar.gz` asset from [Releases](https://github.com/sequencernd/scholar9Review/releases). It contains one `scholar9Review/` directory.
3. As an OJS Site Administrator, open **Settings > Website > Plugins > Upload a New Plugin** and upload the archive. For an existing installation, use the available upgrade action or your established OJS plugin upgrade process.
4. Enable **Scholar9 Transparent Peer Review** for the journal and open its settings.
5. For manual installation, place the extracted directory at `plugins/generic/scholar9Review/`, then use the OJS-supported installation/upgrade process for your version.

Use the attached installation asset rather than the repository's automatically generated source archive. Do not overwrite a Gallery release asset after it is published.

## API access and configuration

1. Open plugin **Settings** and confirm the Scholar9 origin. Do not append `/api`, a version path or a journal slug.
2. If access has not been approved, leave credentials blank and save.
3. Select **Sync to Scholar9** and review the journal profile. Journal name, short name and source identity remain tied to OJS. Editable profile fields include ISSNs, publisher and contact information; corrections in this form apply to the transmitted profile.
4. Submit the API-access request. Scholar9 administrators approve the request and issue credentials through their existing workflow. Pending and duplicate requests are handled without creating another application.
5. Enter the issued Client ID, Client Secret and journal slug together. Keep SSL verification enabled and save.
6. Enable Auto Sync if incoming webhook updates are wanted. Confirm that Scholar9 can reach the registered callback.
7. Configure each OJS journal separately; credentials and review data are scoped to its context.

## Review workflow

1. Open a submission and choose **Review with Scholar9**.
2. Select the manuscript, optional plagiarism report, review criteria and reviewers, then submit the review request.
3. Select reviewer cards to change the displayed conversation.
4. Use **Send Message** or **Send File** to communicate. The toolbar provides **Change reviewer** and **Sync** actions.
5. Review incoming status, revision messages and ratings. Submit the publisher assessment when available.
6. Use visibility controls only when their completion requirements are met. Enabling a control makes the corresponding Scholar9 review page publicly available; inspect the intended content before enabling it.

## External services and data disclosure

The plugin communicates with the configured Scholar9 origin. It transmits journal/contact/publisher metadata when a manager submits a profile; selected manuscript/review metadata and file links when requesting a review; and messages, files, assignments, ratings or visibility changes when those actions are used. Scholar9 supplies reviewer information and review updates to OJS.

After Scholar9 accepts a new API-access application, OJS also sends a notification to **inquiry@ojscloud.com**. The email includes the application reference, journal and publisher details, applicant name/email, source URLs, OJS context/version, ISSNs, country, use case and request time. It excludes API secrets, access tokens and webhook secrets. Duplicate requests do not resend it. Mail-delivery failure is logged and does not cancel an accepted application.

Credentials are stored in the journal's OJS plugin settings and used for server-to-server authentication. Signed shared-file links expire. Disable the plugin to stop its workflow integration; disabling does not delete remote Scholar9 records or local integration data. Contact the service operator about remote retention or deletion and ensure your journal's privacy notice covers the integration.

## Support and security

- Bugs and feature requests: [GitHub Issues](https://github.com/sequencernd/scholar9Review/issues).
- Include plugin/OJS/PHP versions, reproduction steps and sanitized error information. Never post credentials, tokens, private manuscripts or personal review content.
- For private security reports, see [SECURITY.md](SECURITY.md).
- Connection errors: verify the public service URL, outbound connectivity, API credentials, HTTPS certificates and webhook reachability. A local/private OJS URL cannot receive callbacks from a remote service without suitable routing.

## Development and verification

From a source checkout, run:

```shell
composer validate --no-check-publish
composer test
```

The automated tests exercise API response handling, caches, handler security, synchronization, metadata, the native sync dialog and frontend workflow logic. They use fixtures and do not establish a complete end-to-end test against a production Scholar9 service.

When installed in an OJS test environment, the read-only metadata integration check is:

```shell
php plugins/generic/scholar9Review/tests/metadataProvider.integration.php --all
```

See [PKP_RELEASE.md](https://github.com/sequencernd/scholar9Review/blob/main/docs/PKP_RELEASE.md) for packaging, validation, GitHub publication and Gallery submission. Tests, stubs and release tooling stay in the source repository and are excluded from installation archives.

## License

Copyright (c) 2026 SEQUENCE RESEARCH & DEVELOPMENT PRIVATE LIMITED.

Licensed under GNU General Public License version 2 or, at your option, any later version (`GPL-2.0-or-later`). See [LICENSE](LICENSE) for the terms. Existing contributor credits are retained in the repository metadata.
