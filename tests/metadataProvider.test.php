<?php

declare(strict_types=1);

defined('PKP_STRICT_MODE') || define('PKP_STRICT_MODE', true);

require_once dirname(__DIR__, 4) . '/lib/pkp/lib/vendor/autoload.php';
require_once dirname(__DIR__) . '/classes/OjsJournalMetadataProvider.php';

use APP\plugins\generic\scholar9Review\OjsJournalMetadataProvider;

function assertMetadataValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

class MetadataTestContext
{
    public function __construct(private array $data)
    {
    }

    public function getId(): int
    {
        return 17;
    }

    public function getPath(): string
    {
        return 'medical-journal';
    }

    public function getPrimaryLocale(): string
    {
        return 'en';
    }

    public function getData(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function getLocalizedData(string $key, ?string $locale = null): mixed
    {
        $value = $this->data[$key] ?? null;
        if (is_array($value) && $locale !== null && array_key_exists($locale, $value)) {
            return $value[$locale];
        }
        if (is_array($value) && array_key_exists('en', $value)) {
            return $value['en'];
        }
        return $value;
    }
}

class MetadataTestRequest
{
    public function getBaseUrl(): string
    {
        return 'https://ojs.example.test';
    }
}

class TestMetadataProvider extends OjsJournalMetadataProvider
{
    protected function collectIssueFacts(int $contextId): array
    {
        return ['first_publication_year' => '2018', 'issue_frequency' => 'Quarterly'];
    }

    protected function collectSubjects(int $contextId): array
    {
        return ['Clinical Medicine', 'Public Health'];
    }

    protected function collectEditorialBoard($context, string $fallbackName, string $fallbackEmail): array
    {
        return [['name' => 'Dr Editor', 'role' => 'Editor-in-Chief', 'email' => 'editor@example.test']];
    }

    protected function resolveCountryName(string $countryCode): string
    {
        return $countryCode === 'IN' ? 'India' : $countryCode;
    }

    protected function resolveLocaleNames(array $localeCodes): array
    {
        return ['English'];
    }

    protected function getOjsVersion(): string
    {
        return '3.5.0-test';
    }

    public function indexingFrom(string $text): array
    {
        return $this->extractIndexingAgencies($text);
    }
}

class ExplicitScheduleMetadataProvider extends TestMetadataProvider
{
    protected function collectIssueFacts(int $contextId): array
    {
        return ['first_publication_year' => '2026', 'issue_frequency' => ''];
    }
}

$context = new MetadataTestContext([
    'name' => ['en' => 'Journal <script>alert(1)</script> Medicine'],
    'acronym' => ['en' => 'JOM'],
    'description' => ['en' => '<p>Peer-reviewed medical research.</p><img src=x onerror=alert(1)>'],
    'publisherInstitution' => 'Example Publisher',
    'contactName' => 'Dr Editor',
    'contactEmail' => 'EDITOR@EXAMPLE.TEST',
    'contactPhone' => '+91 12345 67890',
    'mailingAddress' => "Research Campus\nNew Delhi",
    'onlineIssn' => '1234-567X',
    'printIssn' => '8765-4321',
    'country' => 'IN',
    'supportedSubmissionLocales' => ['en'],
    'publishingMode' => 0,
    'publicationFee' => 100,
    'copyrightHolderType' => 'author',
    'enableLockss' => true,
    'enableClockss' => false,
    'enableDois' => true,
    'licenseUrl' => 'https://creativecommons.org/licenses/by/4.0/',
    'about' => ['en' => 'We follow COPE. Impact Factor: 4.2 (Clarivate). Indexed at https://doaj.org/toc/1234-567X'],
    'authorGuidelines' => ['en' => 'Every manuscript receives a plagiarism similarity check.'],
]);

$provider = new TestMetadataProvider();
$payload = $provider->build($context, new MetadataTestRequest());
$journal = $payload['journal'];

assertMetadataValue([], $provider->missingRequiredFields($context, $payload), 'A complete OJS journal profile must pass pre-sync validation.');

assertMetadataValue('Journal Medicine', $journal['name'], 'Executable markup and its contents must be removed from OJS metadata.');
assertMetadataValue('Peer-reviewed medical research.', $journal['description'], 'HTML and event handlers must not survive the sync payload.');
assertMetadataValue('editor@example.test', $journal['contact_email'], 'Contact email must be normalized.');
assertMetadataValue('2018', $journal['first_publication_year'], 'First publication year must come from OJS issue history.');
assertMetadataValue('Quarterly', $journal['issue_frequency'], 'Frequency must come from OJS issue history.');
assertMetadataValue(['Clinical Medicine', 'Public Health'], $journal['subjects'], 'Subjects must come from published OJS metadata.');
assertMetadataValue(true, $journal['authors_retain_copyright'], 'Copyright ownership must come from OJS license settings.');
assertMetadataValue(true, $journal['cope_guidelines'], 'COPE must be detected only from the stored OJS policy.');
assertMetadataValue(true, $journal['plagiarism_checks'], 'Plagiarism checks must be detected from OJS policies.');
assertMetadataValue('4.2', $journal['impact_factor'], 'An explicitly labelled impact factor may be extracted from OJS journal text.');
assertMetadataValue('Clarivate / Web of Science', $journal['impact_factor_assignee'], 'Impact-factor agency must only be inferred from explicit OJS text.');
assertMetadataValue('India', $journal['country'], 'OJS country code must be resolved for Scholar9.');
assertMetadataValue('', $journal['state'], 'Structured location values must not be guessed from the OJS mailing address.');
assertMetadataValue('', $provider->cleanHttpUrl('javascript:alert(1)'), 'Unsafe URL schemes must be dropped.');
assertMetadataValue(
    'Safe',
    $provider->cleanText('Safe javascript:alert(1)'),
    'Executable URL-scheme tokens must be removed from synchronized text.'
);
assertMetadataValue('Monthly', OjsJournalMetadataProvider::inferIssueFrequency(array_fill(0, 12, 2025)), 'Issue frequency inference must be deterministic.');
assertMetadataValue(
    'Quarterly',
    $provider->extractIssueFrequency('<p>Publication frequency: Quarterly</p>'),
    'An explicitly labelled OJS publication schedule must be recognized.'
);
assertMetadataValue(
    'Semiannual',
    $provider->extractIssueFrequency('The journal is published twice a year.'),
    'A clearly worded publication schedule must be normalized for Scholar9.'
);
assertMetadataValue(
    '',
    $provider->extractIssueFrequency('Read our monthly newsletter for updates.'),
    'Unlabelled frequency words must not be treated as the journal publication schedule.'
);

$explicitSchedulePayload = (new ExplicitScheduleMetadataProvider())->build(
    new MetadataTestContext([
        'name' => ['en' => 'Schedule Test Journal'],
        'acronym' => ['en' => 'STJ'],
        'description' => ['en' => '<p>Publication frequency: Monthly</p>'],
        'publisherInstitution' => 'Example Publisher',
        'contactName' => 'Dr Editor',
        'contactEmail' => 'editor@example.test',
        'mailingAddress' => 'Research Campus',
        'onlineIssn' => '1234-567X',
        'country' => 'IN',
        'supportedSubmissionLocales' => ['en'],
        'publishingMode' => 0,
    ]),
    new MetadataTestRequest()
);
assertMetadataValue(
    'Monthly',
    $explicitSchedulePayload['journal']['issue_frequency'],
    'The synchronized journal payload must use an explicit native OJS publication schedule when history is insufficient.'
);
assertMetadataValue(
    ['DOAJ' => 'https://doaj.org/toc/1234-567X'],
    $provider->indexingFrom('Indexed: https://doaj.org/toc/1234-567X javascript:alert(1)'),
    'Only recognized HTTP(S) indexing profile URLs may be synchronized.'
);

$reviewed = $provider->applyEditableOverrides($payload, [
    'name' => 'International Journal of Medicine',
    'short_name' => 'HACKED',
    'publisher' => 'Example University Press',
    'contact_name' => 'Dr Sachin Makwana',
    'contact_email' => 'SACHIN@EXAMPLE.TEST',
    'contact_address' => 'Reviewed mailing address',
    'online_issn' => '9999-9999',
    'print_issn' => '1111-1111',
    'description' => '<script>alert(1)</script><p>Reviewed journal description.</p>',
    'api_slug' => 'international_medicine',
    'languages' => "English, Hindi",
    'subjects' => "Medicine\nPublic Health",
    'indexing_agencies' => [
        'DOAJ' => 'https://doaj.org/toc/1234-567X',
        'Unsafe' => 'javascript:alert(1)',
    ],
]);
assertMetadataValue(
    'Journal Medicine',
    $reviewed['journal']['name'],
    'The authoritative OJS journal title must ignore submitted overrides.'
);
assertMetadataValue(
    'Dr Sachin Makwana',
    $reviewed['owner_name'],
    'The reviewed contact name must be used for this Scholar9 synchronization.'
);
assertMetadataValue(
    'sachin@example.test',
    $reviewed['owner_email'],
    'The reviewed contact email must be normalized and used for this Scholar9 synchronization.'
);
assertMetadataValue(
    'Example University Press',
    $reviewed['org_name'],
    'The reviewed publisher must be used for this Scholar9 synchronization.'
);
assertMetadataValue(
    ['JOM', 'sachin@example.test', 'Reviewed mailing address', '9999-9999', '1111-1111'],
    [
        $reviewed['journal']['short_name'],
        $reviewed['journal']['contact_email'],
        $reviewed['journal']['contact_address'],
        $reviewed['journal']['online_issn'],
        $reviewed['journal']['print_issn'],
    ],
    'Only stable identity remains locked; reviewed ISSN and contact metadata must be accepted safely.'
);
assertMetadataValue(
    'Reviewed journal description.',
    $reviewed['journal']['description'],
    'Supplemental fields may still be safely reviewed for a single sync.'
);
assertMetadataValue(
    'ojs_' . hash('sha256', 'https://ojs.example.test|17'),
    $reviewed['journal_id'],
    'An editable review must not modify the stable OJS journal identity.'
);
assertMetadataValue(
    ['DOAJ' => 'https://doaj.org/toc/1234-567X'],
    $reviewed['journal']['indexing_agencies'],
    'Unsafe indexing URLs must be discarded at the OJS boundary.'
);
assertMetadataValue(
    [],
    $provider->validateReviewedPayload($reviewed),
    'A valid reviewed profile must pass final pre-transmission validation.'
);

$unsafeOnlyContext = new MetadataTestContext([
    'name' => ['en' => '<script>alert(1)</script>'],
    'contactName' => 'Editor',
    'contactEmail' => 'editor@example.test',
    'supportedSubmissionLocales' => ['en'],
    'publishingMode' => 0,
]);
$unsafeOnlyPayload = $provider->build($unsafeOnlyContext, new MetadataTestRequest());
assertMetadataValue(
    'medical-journal',
    $unsafeOnlyPayload['journal']['name'],
    'An unsafe-only journal title must be removed and replaced by the safe OJS journal path.'
);
assertMetadataValue(
    [
        'Journal title',
        'Journal acronym or abbreviation',
        'Publisher or institution',
        'Journal description or about text',
        'Journal mailing address',
        'Journal country',
        'Online ISSN or Print ISSN',
    ],
    $provider->missingRequiredFields($unsafeOnlyContext, $unsafeOnlyPayload),
    'Pre-sync validation must report every missing essential field and must not accept safe display fallbacks.'
);

echo "Scholar9 OJS metadata provider tests passed.\n";
