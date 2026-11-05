# Scholar9 Transparent Peer Review for OJS

Connect your Open Journal Systems journal with [Scholar9](https://scholar9.com) to manage reviewer discovery, peer-review communication, files, ratings and transparent review links from the OJS editorial workflow.

**Version:** 1.3.5.1

**Compatibility:** OJS 3.4.0-5 (3.4.0.5) and later, including OJS 3.5

**Maintainer:** SEQUENCE RESEARCH & DEVELOPMENT PRIVATE LIMITED

**License:** GPL-2.0-or-later

## Features

- Synchronize journal information with Scholar9 and request API access from OJS.
- Find, assign and change Scholar9 reviewers.
- Send a manuscript and an optional plagiarism report for peer review.
- Exchange messages and share files in individual reviewer conversations.
- Receive conversation and review updates through webhooks and automatic polling.
- Display reviewer profile photographs when supplied by Scholar9.
- View review progress, recommendations, revision requests and star ratings.
- Submit publisher ratings after a reviewer completes their assessment.
- Control individual open-review pages and the full-review page when the required assessments are complete.
- Keep journal settings, submissions and reviewer conversations separated in multi-journal installations.

## Requirements

- **OJS 3.4.0-5 or later.**
- PHP 8.1 or later, meeting the PHP requirements of your installed OJS version.
- PHP cURL and JSON support, together with the extensions required by OJS.
- An internet connection from the OJS server to Scholar9.
- Approved Scholar9 API credentials for each connected journal: **Client ID**, **Client Secret** and **Journal Slug**.
- HTTPS and a valid SSL certificate for production use.
- A publicly reachable OJS callback URL for incoming Scholar9 webhooks.
- Configured OJS outgoing email for API-access request notifications.

Scholar9 account approval and any applicable service plan are managed separately by Scholar9. No additional Composer installation or frontend build is required to use the packaged plugin.

## Installation

### Upload through OJS

1. Download the packaged `scholar9Review-1.3.5.1.tar.gz` file from [GitHub Releases](https://github.com/sequencernd/scholar9Review/releases).
2. Sign in to OJS as a **Site Administrator**.
3. Open **Settings > Website > Plugins**.
4. Select **Upload a New Plugin** and upload the `.tar.gz` file.
5. Complete the installation and locate **Scholar9 Transparent Peer Review** under **Installed Plugins**.
6. Enable the plugin for the journal and open **Settings** beside its name.

The installation archive must contain one top-level directory named `scholar9Review`. Use the packaged release asset for installation.

### Manual installation

1. Download or clone the plugin source.
2. Place the plugin files in the following directory:

   ```text
   plugins/generic/scholar9Review/
   ```

3. Confirm that `index.php`, `Scholar9ReviewPlugin.php` and `version.xml` are directly inside that directory.
4. Complete your OJS version's plugin installation process.
5. Open **Settings > Website > Plugins**, enable **Scholar9 Transparent Peer Review**, and open its settings.

## Connect your journal to Scholar9

### Request API access

1. Open the plugin's **Settings**.
2. Set **Scholar9 Base URL** to `https://scholar9.com`, or the origin provided by your Scholar9 service administrator. Do not append `/api`, a version path or a journal slug.
3. If credentials have not been issued, leave **Client ID**, **Client Secret** and **Journal Slug** blank, then save.
4. Select **Sync to Scholar9** from the plugin actions.
5. Review the journal information. Complete the required profile fields and correct any validation errors.
6. Submit the request for Scholar9 approval.
7. Wait for the Scholar9 administrator to approve the application and provide your credentials.

The journal name, short name and OJS source identity come from the journal configuration. Editable profile fields include ISSNs, publisher and contact information. Changes made in the synchronization form apply to the profile sent to Scholar9.

An application that is already pending does not need to be submitted again. If credentials have already been issued, use those credentials to complete the connection.

### Configure approved credentials

1. Return to the plugin's **Settings**.
2. Enter the issued **Client ID**, **Client Secret** and **Journal Slug** together.
3. Keep **Verify SSL** enabled.
4. Configure the available review defaults, including review type and deadline.
5. Enable **Auto Sync** to receive incoming updates through the configured integration.
6. Save the settings and confirm the connection.
7. Use **Sync to Scholar9** when you need to send updated journal information.

Use the same approved credential set for the journal's supported plugin operations. Configure each journal separately in a multi-journal OJS installation.

## Send a submission for peer review

1. Open a submission in the OJS editorial dashboard.
2. Select **Review with Scholar9** from the workflow menu.
3. Start a Scholar9 review request using the available review action.
4. Select the manuscript and, if required, a plagiarism report.
5. Choose the review parameters and search for reviewers by name or expertise.
6. Select the reviewers and confirm the request.
7. Return to **Review with Scholar9** to follow their progress and communicate with them.

## Messages, files and reviewer management

- Select a reviewer card to open that reviewer's conversation.
- Enter your message and select **Send Message**.
- Use **Send File** to share an available submission file.
- Use **Change reviewer** in the toolbar to open the reviewer search and replacement options.
- Use the **Sync** action to retrieve the latest available conversation and status updates immediately.
- With automatic synchronization configured, incoming updates appear in the review workspace without reloading the full page.

Reviewer cards show the current review status and, when available, the latest revision request, such as **Under Review / Clarification**. Structured revision requests receive their corresponding indicators; ordinary messages keep their standard appearance.

## Ratings and public review links

After a reviewer submits their assessment, review their recommendation and star ratings in the conversation. Complete the publisher assessment when **Rate this reviewer** becomes available. Check the scores and optional feedback before submitting; a submitted publisher rating cannot be edited through this form.

The **Open review** control becomes available when the required reviewer and publisher assessments for that reviewer are complete. The **Full review** control becomes available when the required assessments are complete for the applicable reviewers.

When enabled, the corresponding external link opens the public Scholar9 review page. Review your journal's disclosure policy and the intended content before enabling public access.

## Upgrading

1. Back up your OJS database and files.
2. Download the new packaged release and review its compatibility information.
3. Use OJS's available plugin upgrade action or the supported upgrade procedure for your OJS version.
4. Reopen the plugin settings and confirm the journal connection and synchronization configuration.

Keep the existing journal credentials unless Scholar9 has explicitly replaced them.

## External services and privacy

This plugin connects to the Scholar9 origin configured by the journal manager. Depending on the action performed, it sends journal, publisher and contact information; selected submission and review information; shared-file links; messages; reviewer assignments; ratings; and review visibility changes. Scholar9 returns reviewer details and review updates to OJS.

When Scholar9 accepts a new API-access application, OJS also sends an application notification to **inquiry@ojscloud.com**. This includes the application reference, journal and publisher details, applicant name and email, source URLs, OJS context and version, ISSNs, country, use case and request time. API secrets, access tokens and webhook secrets are excluded. Duplicate applications do not resend the notification, and a mail-delivery failure does not cancel an accepted application.

Credentials are stored in the journal's OJS plugin settings and used for server-to-server authentication. Signed shared-file links expire. Disabling the plugin does not delete existing integration data or remote Scholar9 records. Contact the service operator regarding remote data retention or deletion, and ensure your journal's privacy notice covers the integration.

## Troubleshooting

| Issue | What to check |
| --- | --- |
| Plugin actions are unavailable | Confirm that the plugin is enabled for the current journal and that your OJS account has the necessary permissions. |
| Unable to connect to Scholar9 | Check the base URL, server connectivity and the issued Client ID, Client Secret and Journal Slug. |
| SSL certificate error | Correct the server certificate or PHP CA configuration and keep SSL verification enabled. |
| API request is already pending | Wait for approval or enter the credentials already issued for the journal. |
| New messages or statuses are delayed | Confirm Auto Sync, webhook reachability and service connectivity; use Sync to retrieve updates immediately. |
| Application notification is not delivered | Check the OJS outgoing email configuration and server mail logs. |
| A public review control is unavailable | Check that the required reviewer and publisher assessments have been completed. |

## Support

- **Issues and feature requests:** [GitHub Issues](https://github.com/sequencernd/scholar9Review/issues)
- **Service and integration enquiries:** [inquiry@ojscloud.com](mailto:inquiry@ojscloud.com)
- **Private security reports:** [SECURITY.md](SECURITY.md)

When reporting an issue, include the plugin version, OJS version, PHP version and clear reproduction steps. Remove credentials, tokens, confidential manuscripts and private review information from screenshots and logs.

## License

Copyright (c) 2026 **SEQUENCE RESEARCH & DEVELOPMENT PRIVATE LIMITED**.

This plugin is licensed under the **GNU General Public License, version 2 or, at your option, any later version (GPL-2.0-or-later)**. See [LICENSE](LICENSE) for the full terms.
