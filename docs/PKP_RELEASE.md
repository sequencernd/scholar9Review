# Release and PKP Gallery submission

Official references:
- https://docs.pkp.sfu.ca/dev/plugin-guide/en/release
- https://github.com/pkp/plugin-gallery/blob/main/README.md
- https://github.com/pkp/plugin-gallery/blob/main/plugins.xsd

## Verification before publication

Run `composer validate --no-check-publish` and `composer test` in a standalone plugin source checkout. Test the installer and upgrade path on the exact OJS version to be declared. This repository's CI tests use fixtures, not a complete OJS/Scholar9 installation. Current local OJS code reports 3.5.0.5; do not claim OJS 3.4 or other versions without checking them.

For the end-to-end check, use a test journal and Scholar9 account to verify enable/disable, settings, credential-free access requests and duplicates, credential approval/configuration, review request and reviewer replacement, both message directions without page reload, file access/expiry, ratings, revision status, visibility gates/public links and multi-journal isolation. Verify the additional notification arrives at inquiry@ojscloud.com using working OJS mail configuration.

The copyright holder/maintainer is SEQUENCE RESEARCH & DEVELOPMENT PRIVATE LIMITED. Gallery support contact defaults to the existing inquiry@ojscloud.com address. Original Composer contributor credit remains intact. Confirm these public details before publication.

## Prepare the standalone repository

Do not initialize a nested repository in the live OJS installation. Clone https://github.com/sequencernd/scholar9Review.git into a separate working directory, then copy this plugin's contents including .gitignore, .gitattributes and .github. Do not copy the generated .release directory.

Commit the clean source to main and push to origin. Run the CI workflow and inspect the results before release. Set version.xml to the final four-number version and actual publication date before building. Never replace an existing public tag or Gallery asset with different content.

## Build the installation asset

From the plugin root in PowerShell:

```powershell
./tools/prepare-release.ps1 -OjsVersion 3.5.0.5
```

The script produces `.release/scholar9Review-1.3.5.1.tar.gz`, `.release/CHECKSUMS.txt` and `.release/gallery-entry.xml`. It refuses to overwrite an existing archive. Runtime files are included using an explicit allow-list, with a single scholar9Review/ directory. Tests, stubs, editor files, Git files and release tooling are excluded. No runtime Composer install is required.

## Publish on GitHub

After verification, create/push tag v1.3.5.1 from the reviewed commit. In GitHub Releases create a release for that tag and attach the generated tar.gz and CHECKSUMS.txt. The README links to Releases. Download the published asset and verify its MD5 matches CHECKSUMS.txt before proposing it to PKP. GitHub source archives are source downloads; use the attached installation asset.

## Submit to PKP

Fork https://github.com/pkp/plugin-gallery and create a submission branch. Insert the `<plugin>` element from gallery-entry.xml inside the existing `<plugins>` element in plugins.xml; do not replace the complete Gallery file or add another XML declaration/root. Validate the modified document against plugins.xsd. Do not assign certification; PKP assigns it after review.

Open a pull request explaining the external Scholar9 account/API dependency, integration features, license, data flow (including the access-request notification), exact tested OJS version, tests run and archive URL/checksum. Follow PKP review comments. Acceptance and certification are controlled by PKP. Once listed, never modify the release asset: publish a new version for changes.

## Current release preparation status

Source tests, package structure and schema checks can be run locally. A release is not published merely because an archive/XML file exists. Record the real public commit/tag/release/PR URLs when these steps have been completed. Do not claim fresh-install, upgrade, mail delivery or live Scholar9 end-to-end verification from fixture tests.
