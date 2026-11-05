<?php

namespace APP\plugins\generic\scholar9Review;

use APP\core\Application;
use APP\facades\Repo;
use APP\issue\Collector as IssueCollector;
use APP\journal\Journal;
use PKP\facades\Locale;
use PKP\security\Role;
use PKP\submission\PKPSubmission;
use PKP\userGroup\UserGroup;

/**
 * Builds the canonical Scholar9 journal profile exclusively from OJS data.
 *
 * Connection credentials and review defaults remain plugin settings. Journal
 * identity/profile data is read from the OJS journal configuration, editorial
 * masthead, published issues and published-article metadata at sync time.
 */
class OjsJournalMetadataProvider
{
    private const MAX_ISSUES = 250;
    private const MAX_SUBMISSIONS = 250;
    private const MAX_SUBJECTS = 50;
    private const MAX_BOARD_MEMBERS = 100;

    /** @return array<string, mixed> */
    public function build($context, $request): array
    {
        $contextId = (int) $context->getId();
        $primaryLocale = (string) ($context->getPrimaryLocale() ?: 'en');
        $path = $this->cleanText($context->getPath(), 60);
        $baseUrl = \rtrim((string) $request->getBaseUrl(), '/');
        $journalUrl = $baseUrl . '/index.php/' . \rawurlencode($path);
        $name = $this->cleanText($this->localized($context, 'name', $primaryLocale), 200);
        // A journal title that consists entirely of unsafe markup is discarded.
        // The OJS path remains a safe, journal-specific fallback so a poisoned
        // optional/title setting cannot inject content or block access requests.
        if ($name === '') {
            $name = $path !== '' ? $path : 'Journal ' . $contextId;
        }

        $contactName = $this->cleanText(
            $context->getData('contactName') ?: $context->getData('supportName'),
            200
        );
        $contactEmail = \strtolower($this->cleanText(
            $context->getData('contactEmail') ?: $context->getData('supportEmail'),
            200
        ));
        if (!\filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $contactEmail = '';
        }

        $publisher = $this->cleanText(
            $context->getData('publisherInstitution')
                ?: $this->localized($context, 'contactAffiliation', $primaryLocale)
                ?: $name,
            200
        );
        $country = $this->resolveCountryName((string) $context->getData('country'));
        $localeCodes = $this->acceptedLocaleCodes($context, $primaryLocale);
        $policyText = $this->collectPolicyText($context, $primaryLocale);
        $issueFacts = $this->collectIssueFacts($contextId);
        $configuredIssueFrequency = $this->extractIssueFrequency(
            \implode("\n", [
                (string) $this->localized($context, 'publicationSchedule', $primaryLocale),
                (string) $this->localized($context, 'description', $primaryLocale),
                (string) $this->localized($context, 'about', $primaryLocale),
                $policyText,
            ])
        );
        if ($configuredIssueFrequency !== '') {
            // An explicit OJS journal statement describes the current schedule
            // and is therefore more authoritative than historical averages.
            $issueFacts['issue_frequency'] = $configuredIssueFrequency;
        }
        [$impactFactor, $impactAgency] = $this->extractImpactFactor($policyText);
        $indexingAgencies = $this->extractIndexingAgencies($policyText);

        $copyrightHolder = (string) $context->getData('copyrightHolderType');
        $authorsRetainCopyright = match ($copyrightHolder) {
            'author' => true,
            'context', 'other' => false,
            default => null,
        };

        $publishingMode = (int) $context->getData('publishingMode');
        $publicationFee = (float) ($context->getData('publicationFee') ?: 0);
        $slug = \strtolower((string) \preg_replace('/[^a-z0-9_-]+/i', '_', $path));
        $slug = \trim($slug, '_-') ?: 'journal_' . $contextId;
        $externalId = 'ojs_' . \hash('sha256', \strtolower($baseUrl) . '|' . $contextId);

        return [
            'owner_name' => $contactName ?: $publisher,
            'owner_email' => $contactEmail,
            'org_name' => $publisher,
            'journal_id' => $externalId,
            'use_case' => 'OJS journal integration for Scholar9 transparent peer review and editorial synchronisation.',
            'api_slug' => \mb_substr($slug, 0, 60),
            'source_system' => 'ojs',
            'source_url' => $journalUrl,
            'source_context_id' => (string) $contextId,
            'ojs_version' => $this->getOjsVersion(),
            'journal' => [
                'name' => $name,
                'short_name' => $this->cleanText(
                    $this->localized($context, 'acronym', $primaryLocale)
                        ?: $this->localized($context, 'abbreviation', $primaryLocale),
                    50
                ),
                'path' => $path,
                'url' => $journalUrl,
                'description' => $this->cleanText(
                    $this->localized($context, 'description', $primaryLocale)
                        ?: $this->localized($context, 'about', $primaryLocale),
                    4000
                ),
                'photo_url' => $this->journalImageUrl($context, $primaryLocale, $baseUrl),
                'publisher' => $publisher,
                'contact_name' => $contactName,
                'contact_email' => $contactEmail,
                'contact_phone' => $this->cleanText(
                    $context->getData('contactPhone') ?: $context->getData('supportPhone'),
                    50
                ),
                'contact_address' => $this->cleanText($context->getData('mailingAddress'), 1000),
                'online_issn' => $this->cleanText($context->getData('onlineIssn'), 20),
                'print_issn' => $this->cleanText($context->getData('printIssn'), 20),
                'primary_locale' => \mb_substr($primaryLocale, 0, 20),
                'supported_locales' => $localeCodes,
                'languages' => $this->resolveLocaleNames($localeCodes),
                'first_publication_year' => $issueFacts['first_publication_year'],
                'issn_assignee_country' => $country,
                'impact_factor' => $impactFactor,
                'impact_factor_assignee' => $impactAgency,
                'issue_frequency' => $issueFacts['issue_frequency'],
                'subjects' => $this->collectSubjects($contextId),
                'editorial_board' => $this->collectEditorialBoard(
                    $context,
                    $contactName,
                    $contactEmail
                ),
                'country' => $country,
                // OJS core stores the postal address as one journal-level field.
                // Do not guess structured location components from free text.
                'state' => '',
                'city' => '',
                'postal_code' => '',
                'open_access' => $publishingMode === Journal::PUBLISHING_MODE_OPEN,
                'open_access_license' => $this->cleanHttpUrl($context->getData('licenseUrl'))
                    ?: $this->cleanText($this->localized($context, 'licenseTerms', $primaryLocale), 500),
                'cope_guidelines' => $this->detectPolicy($policyText, '/(?:publicationethics\.org|\bCOPE\b)/iu'),
                'authors_retain_copyright' => $authorsRetainCopyright,
                'peer_review' => true,
                'plagiarism_checks' => $this->detectPolicy(
                    $policyText,
                    '/(?:\bplagiarism\b|\bsimilarity\s+(?:check|screen|report))/iu'
                ),
                'publication_platform' => 'Open Journal Systems (OJS). The Public Knowledge Project',
                'apc_charged' => $publicationFee > 0,
                'preservation_policy' => (bool) (
                    $context->getData('enableLockss') || $context->getData('enableClockss')
                ),
                'doi_enabled' => (bool) $context->getData('enableDois'),
                // OJS does not define conference charges as journal metadata.
                'conference_charges' => '',
                'indexing_agencies' => $indexingAgencies,
            ],
        ];
    }

    /**
     * Return the essential OJS journal fields that must be completed before
     * Scholar9 can receive an access request or update an existing journal.
     *
     * These checks deliberately use the native OJS values as well as the
     * sanitized payload. Safe display fallbacks (for example, the journal
     * path) must not make an incomplete journal look provision-ready.
     *
     * @param array<string, mixed> $payload
     * @return string[] Human-readable field labels suitable for an OJS error.
     */
    public function missingRequiredFields($context, array $payload): array
    {
        $primaryLocale = (string) ($context->getPrimaryLocale() ?: 'en');
        $journal = \is_array($payload['journal'] ?? null) ? $payload['journal'] : [];
        $missing = [];

        $nativeTitle = $this->cleanText($this->localized($context, 'name', $primaryLocale), 200);
        if ($nativeTitle === '') {
            $missing[] = 'Journal title';
        }
        if ($this->cleanText($journal['short_name'] ?? '', 50) === '') {
            $missing[] = 'Journal acronym or abbreviation';
        }

        $nativePublisher = $this->cleanText(
            $context->getData('publisherInstitution')
                ?: $this->localized($context, 'contactAffiliation', $primaryLocale),
            200
        );
        if ($nativePublisher === '') {
            $missing[] = 'Publisher or institution';
        }
        if ($this->cleanText($journal['contact_name'] ?? '', 200) === '') {
            $missing[] = 'Primary contact name';
        }
        if (!\filter_var((string) ($journal['contact_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            $missing[] = 'Valid primary contact email';
        }
        if ($this->cleanText($journal['description'] ?? '', 4000) === '') {
            $missing[] = 'Journal description or about text';
        }
        if ($this->cleanText($journal['contact_address'] ?? '', 1000) === '') {
            $missing[] = 'Journal mailing address';
        }
        if ($this->cleanText($journal['country'] ?? '', 100) === '') {
            $missing[] = 'Journal country';
        }
        if (
            $this->cleanText($journal['online_issn'] ?? '', 20) === ''
            && $this->cleanText($journal['print_issn'] ?? '', 20) === ''
        ) {
            $missing[] = 'Online ISSN or Print ISSN';
        }
        if ($this->cleanText($journal['first_publication_year'] ?? '', 4) === '') {
            $missing[] = 'Year of first publication (from published OJS issues)';
        }
        if ($this->cleanText($journal['issue_frequency'] ?? '', 50) === '') {
            $missing[] = 'Issue frequency';
        }
        if (empty($journal['languages']) || !\is_array($journal['languages'])) {
            $missing[] = 'Accepted manuscript language';
        }

        return $missing;
    }

    /**
     * Apply journal-manager edits to the generated profile.
     *
     * Only fields shown in the review form are accepted. Stable OJS identity,
     * source URLs, context IDs and version information always come from OJS
     * and cannot be replaced by submitted form data.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function applyEditableOverrides(array $payload, array $input): array
    {
        $journal = \is_array($payload['journal'] ?? null) ? $payload['journal'] : [];

        // Only the reviewed fields below may override the generated profile
        // for this synchronization. Stable OJS identity and routing metadata
        // remain authoritative and cannot be replaced by submitted form data.
        $limits = [
            'description' => 4000,
            'publisher' => 200,
            'contact_name' => 200,
            'contact_email' => 200,
            'contact_phone' => 50,
            'contact_address' => 1000,
            'online_issn' => 20,
            'print_issn' => 20,
            'first_publication_year' => 4,
            'issn_assignee_country' => 100,
            'impact_factor' => 30,
            'impact_factor_assignee' => 200,
            'issue_frequency' => 50,
            'country' => 100,
            'state' => 100,
            'city' => 100,
            'postal_code' => 30,
            'open_access_license' => 500,
            'publication_platform' => 200,
            'conference_charges' => 100,
        ];
        foreach ($limits as $key => $limit) {
            if (\array_key_exists($key, $input)) {
                $journal[$key] = $this->cleanText($input[$key], $limit);
            }
        }

        $journal['contact_email'] = \strtolower((string) ($journal['contact_email'] ?? ''));
        foreach (['online_issn', 'print_issn'] as $key) {
            $journal[$key] = \strtoupper((string) ($journal[$key] ?? ''));
        }

        foreach ([
            'open_access',
            'cope_guidelines',
            'authors_retain_copyright',
            'peer_review',
            'plagiarism_checks',
            'apc_charged',
            'preservation_policy',
            'doi_enabled',
        ] as $key) {
            if (\array_key_exists($key, $input)) {
                $journal[$key] = $this->nullableBoolean($input[$key]);
            }
        }

        foreach (['languages' => 20, 'subjects' => self::MAX_SUBJECTS] as $key => $maxItems) {
            if (\array_key_exists($key, $input)) {
                $journal[$key] = $this->cleanList($input[$key], $maxItems, 200);
            }
        }

        if (\array_key_exists('editorial_board', $input)) {
            $journal['editorial_board'] = $this->cleanEditorialBoard($input['editorial_board']);
        }

        if (\array_key_exists('indexing_agencies', $input)) {
            $agencies = [];
            foreach ((array) $input['indexing_agencies'] as $name => $url) {
                $safeName = $this->cleanText($name, 50);
                $safeUrl = $this->cleanHttpUrl($url);
                if ($safeName !== '' && $safeUrl !== '') {
                    $agencies[$safeName] = $safeUrl;
                }
            }
            $journal['indexing_agencies'] = $agencies;
        }

        if (\array_key_exists('api_slug', $input)) {
            $slug = \strtolower($this->cleanText($input['api_slug'], 60));
            $payload['api_slug'] = \preg_match('/^[a-z0-9_-]+$/', $slug) ? $slug : '';
        }

        // These fields have distinct meanings. The journal title is never
        // inferred from the contact/applicant name.
        $payload['owner_name'] = (string) ($journal['contact_name'] ?? '');
        $payload['owner_email'] = (string) ($journal['contact_email'] ?? '');
        $payload['org_name'] = (string) ($journal['publisher'] ?? '');
        $payload['journal'] = $journal;

        return $payload;
    }

    /**
     * Validate the reviewed profile that will actually be transmitted.
     *
     * @param array<string, mixed> $payload
     * @return string[]
     */
    public function validateReviewedPayload(array $payload): array
    {
        $journal = \is_array($payload['journal'] ?? null) ? $payload['journal'] : [];
        $errors = [];
        $required = [
            'name' => 'Journal title',
            'short_name' => 'Journal acronym or abbreviation',
            'publisher' => 'Publisher or institution',
            'contact_name' => 'Primary contact name',
            'description' => 'Journal description or about text',
            'contact_address' => 'Journal mailing address',
            'country' => 'Journal country',
            'first_publication_year' => 'Year of first publication',
            'issue_frequency' => 'Issue frequency',
        ];
        foreach ($required as $key => $label) {
            if ($this->cleanText($journal[$key] ?? '', 4000) === '') {
                $errors[] = $label;
            }
        }

        if (!\filter_var((string) ($journal['contact_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid primary contact email';
        }
        if (empty($journal['languages']) || !\is_array($journal['languages'])) {
            $errors[] = 'Accepted manuscript language';
        }

        $onlineIssn = \strtoupper((string) ($journal['online_issn'] ?? ''));
        $printIssn = \strtoupper((string) ($journal['print_issn'] ?? ''));
        if ($onlineIssn === '' && $printIssn === '') {
            $errors[] = 'Online ISSN or Print ISSN';
        }
        foreach (['Online ISSN' => $onlineIssn, 'Print ISSN' => $printIssn] as $label => $issn) {
            if ($issn !== '' && !\preg_match('/^\d{4}-\d{3}[\dX]$/', $issn)) {
                $errors[] = $label . ' must use the format 1234-567X';
            }
        }

        $year = (int) ($journal['first_publication_year'] ?? 0);
        if ($year !== 0 && ($year < 1600 || $year > (int) \date('Y') + 1)) {
            $errors[] = 'Year of first publication is invalid';
        }
        if (!\preg_match('/^[a-z0-9_-]+$/', (string) ($payload['api_slug'] ?? ''))) {
            $errors[] = 'API slug may contain only lowercase letters, numbers, underscores and hyphens';
        }

        return \array_values(\array_unique($errors));
    }

    private function nullableBoolean($value): ?bool
    {
        if ($value === '' || $value === null) {
            return null;
        }
        return \in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }

    /** @return string[] */
    private function cleanList($value, int $maxItems, int $maxLength): array
    {
        $items = \is_array($value) ? $value : \preg_split('/[\r\n,]+/u', (string) $value);
        $clean = [];
        foreach ((array) $items as $item) {
            $item = $this->cleanText($item, $maxLength);
            if ($item !== '' && !\in_array($item, $clean, true)) {
                $clean[] = $item;
            }
            if (\count($clean) >= $maxItems) {
                break;
            }
        }
        return $clean;
    }

    /** @return array<int, array{name: string, role?: string, email?: string}> */
    private function cleanEditorialBoard($value): array
    {
        $lines = \is_array($value) ? $value : \preg_split('/[\r\n]+/u', (string) $value);
        $members = [];
        foreach ((array) $lines as $line) {
            $parts = \array_map('trim', \explode('|', (string) $line, 3));
            $role = $this->cleanText($parts[0] ?? '', 100);
            $name = $this->cleanText($parts[1] ?? ($parts[0] ?? ''), 200);
            $email = \strtolower($this->cleanText($parts[2] ?? '', 200));
            if ($name === '') {
                continue;
            }
            $member = ['name' => $name];
            if ($role !== '' && $role !== $name) {
                $member['role'] = $role;
            }
            if (\filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $member['email'] = $email;
            }
            $members[] = $member;
            if (\count($members) >= self::MAX_BOARD_MEMBERS) {
                break;
            }
        }
        return $members;
    }

    /** @return array{first_publication_year: string, issue_frequency: string} */
    protected function collectIssueFacts(int $contextId): array
    {
        $years = [];
        try {
            $collector = Repo::issue()->getCollector()
                ->filterByContextIds([$contextId])
                ->filterByPublished(true)
                ->orderBy(IssueCollector::ORDERBY_DATE_PUBLISHED);
            $collector->count = self::MAX_ISSUES;

            foreach ($collector->getMany() as $issue) {
                $year = (int) $issue->getData('year');
                $datePublished = (string) $issue->getData('datePublished');
                if ($datePublished !== '' && \preg_match('/^(\d{4})-/', $datePublished, $match)) {
                    $year = (int) $match[1];
                }
                if ($year >= 1600 && $year <= (int) \date('Y') + 1) {
                    $years[] = $year;
                }
            }
        } catch (\Throwable $e) {
            \error_log('[Scholar9] Unable to read published OJS issues: ' . $e->getMessage());
        }

        return [
            'first_publication_year' => $years ? (string) \min($years) : '',
            'issue_frequency' => self::inferIssueFrequency($years),
        ];
    }

    public static function inferIssueFrequency(array $years): string
    {
        $years = \array_values(\array_filter(\array_map('intval', $years), static fn(int $year): bool => $year > 0));
        if (\count($years) < 2) {
            return '';
        }

        $counts = \array_count_values($years);
        $first = \min($years);
        $last = \max($years);
        $average = \array_sum($counts) / \max(1, $last - $first + 1);

        return match (true) {
            $average >= 40 => 'Weekly',
            $average >= 18 => 'Bi-Weekly',
            $average >= 9 => 'Monthly',
            $average >= 5 => 'Bi-Monthly',
            $average >= 3 => 'Quarterly',
            $average >= 1.5 => 'Semiannual',
            default => 'Yearly',
        };
    }

    /**
     * Read an explicitly declared publication schedule from native OJS text.
     *
     * OJS 3.5 does not expose a structured issue-frequency selector. Journal
     * managers can declare the schedule in the Journal Description or About
     * text. Only clearly labelled statements are accepted so unrelated words
     * such as "monthly newsletter" cannot silently alter synchronized data.
     */
    public function extractIssueFrequency(string $text): string
    {
        $plain = \mb_strtolower($this->cleanText($text, 30000));
        if ($plain === '') {
            return '';
        }

        $frequency = '(weekly|bi[\s-]?weekly|fortnightly|monthly|bi[\s-]?monthly|every\s+two\s+months|quarterly|semi[\s-]?annual(?:ly)?|bi[\s-]?annual(?:ly)?|twice\s+(?:a|per)\s+year|annual(?:ly)?|yearly)';
        $patterns = [
            '/(?:publication|publishing|issue)\s+frequency\s*(?:is|of)?\s*[:=\-]?\s*' . $frequency . '/iu',
            '/frequency\s+of\s+publication\s*(?:is)?\s*[:=\-]?\s*' . $frequency . '/iu',
            '/(?:the\s+)?(?:journal|issues?)\s+(?:(?:is|are)\s+)?published\s+' . $frequency . '/iu',
            '/(?:the\s+)?journal\s+publishes\s+' . $frequency . '/iu',
        ];

        $match = null;
        foreach ($patterns as $pattern) {
            if (\preg_match($pattern, $plain, $candidate)) {
                $match = $candidate[1] ?? null;
                break;
            }
        }
        if (!\is_string($match) || $match === '') {
            return '';
        }

        $normalized = (string) \preg_replace('/[\s-]+/u', '', \mb_strtolower($match));
        return match ($normalized) {
            'weekly' => 'Weekly',
            'biweekly', 'fortnightly' => 'Bi-Weekly',
            'monthly' => 'Monthly',
            'bimonthly', 'everytwomonths' => 'Bi-Monthly',
            'quarterly' => 'Quarterly',
            'semiannual', 'semiannually', 'biannual', 'biannually',
            'twiceayear', 'twiceperyear' => 'Semiannual',
            'annual', 'annually', 'yearly' => 'Yearly',
            default => '',
        };
    }

    /** @return string[] */
    protected function collectSubjects(int $contextId): array
    {
        $counts = [];
        try {
            $collector = Repo::submission()->getCollector()
                ->filterByContextIds([$contextId])
                ->filterByStatus([PKPSubmission::STATUS_PUBLISHED]);
            $collector->count = self::MAX_SUBMISSIONS;

            foreach ($collector->getMany() as $submission) {
                $publication = $submission->getCurrentPublication();
                if (!$publication) {
                    continue;
                }
                foreach (['subjects', 'disciplines', 'keywords'] as $field) {
                    foreach ($this->flattenTerms($publication->getData($field)) as $term) {
                        $term = $this->cleanText($term, 200);
                        if ($term !== '') {
                            $key = \mb_strtolower($term);
                            $counts[$key] = ['value' => $term, 'count' => ($counts[$key]['count'] ?? 0) + 1];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            \error_log('[Scholar9] Unable to read published OJS subjects: ' . $e->getMessage());
        }

        \uasort($counts, static fn(array $a, array $b): int => $b['count'] <=> $a['count'] ?: \strcmp($a['value'], $b['value']));
        return \array_values(\array_slice(\array_column($counts, 'value'), 0, self::MAX_SUBJECTS));
    }

    /** @return string[] */
    private function flattenTerms($value, int $depth = 0): array
    {
        if ($depth > 5 || $value === null || $value === '') {
            return [];
        }
        if (\is_string($value) || \is_numeric($value)) {
            return [(string) $value];
        }
        if (\is_object($value)) {
            foreach (['getLocalizedData', 'getData'] as $method) {
                if (\method_exists($value, $method)) {
                    try {
                        $name = $value->{$method}('name');
                        if ($name) {
                            return $this->flattenTerms($name, $depth + 1);
                        }
                    } catch (\Throwable) {
                        // Continue to traversable/object-property fallbacks.
                    }
                }
            }
            if ($value instanceof \Traversable) {
                $value = \iterator_to_array($value);
            } else {
                $value = \get_object_vars($value);
            }
        }
        if (!\is_array($value)) {
            return [];
        }
        if (\array_key_exists('name', $value)) {
            return $this->flattenTerms($value['name'], $depth + 1);
        }

        $terms = [];
        foreach ($value as $child) {
            $terms = \array_merge($terms, $this->flattenTerms($child, $depth + 1));
        }
        return $terms;
    }

    /** @return array<int, array{name: string, role?: string, email?: string}> */
    protected function collectEditorialBoard($context, string $fallbackName, string $fallbackEmail): array
    {
        $members = [];
        try {
            $roles = UserGroup::withContextIds([(int) $context->getId()])
                ->masthead(true)
                ->excludeRoles([Role::ROLE_ID_REVIEWER])
                ->get();
            $savedOrder = (array) $context->getData('mastheadUserGroupIds');
            $roles = $roles->sortBy(static function ($role) use ($savedOrder): int {
                $position = \array_search($role->id, $savedOrder, true);
                return $position === false ? PHP_INT_MAX : (int) $position;
            });
            $idsByRole = Repo::userGroup()->getMastheadUserIdsByRoleIds($roles->all(), (int) $context->getId());

            foreach ($roles as $role) {
                $roleName = $this->cleanText($role->getLocalizedData('name'), 100);
                foreach (($idsByRole[$role->id] ?? []) as $userId) {
                    $user = Repo::user()->get((int) $userId);
                    if (!$user) {
                        continue;
                    }
                    $memberName = $this->cleanText($user->getFullName(), 200);
                    $email = \strtolower($this->cleanText($user->getData('email'), 200));
                    if (!\filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $email = '';
                    }
                    if ($memberName !== '') {
                        $members[] = \array_filter(['name' => $memberName, 'role' => $roleName, 'email' => $email]);
                    }
                    if (\count($members) >= self::MAX_BOARD_MEMBERS) {
                        break 2;
                    }
                }
            }
        } catch (\Throwable $e) {
            \error_log('[Scholar9] Unable to read the OJS editorial masthead: ' . $e->getMessage());
        }

        if (!$members && $fallbackName !== '') {
            $members[] = \array_filter([
                'name' => $fallbackName,
                'role' => 'Journal contact',
                'email' => \filter_var($fallbackEmail, FILTER_VALIDATE_EMAIL) ? $fallbackEmail : '',
            ]);
        }

        return $members;
    }

    protected function collectPolicyText($context, string $locale): string
    {
        $parts = [];
        foreach ([
            'about', 'description', 'authorGuidelines', 'reviewGuidelines', 'submissionChecklist',
            'copyrightNotice', 'openAccessPolicy', 'licenseTerms', 'additionalHomeContent', 'pageFooter',
        ] as $field) {
            $value = $this->localized($context, $field, $locale);
            if (\is_array($value)) {
                $value = \implode(' ', \array_map('strval', $value));
            }
            if ((string) $value !== '') {
                $parts[] = (string) $value;
            }
        }
        return \implode("\n", $parts);
    }

    /** @return array{0: string, 1: string} */
    protected function extractImpactFactor(string $policyText): array
    {
        $plain = $this->cleanText($policyText, 20000);
        if (!\preg_match('/\b(?:journal\s+)?impact\s+factor(?:\s*\([^)]*\))?\s*[:=\-]\s*([0-9]+(?:\.[0-9]+)?)/iu', $plain, $match)) {
            return ['', ''];
        }
        $agency = '';
        if (\preg_match('/\bClarivate\b|\bWeb\s+of\s+Science\b/iu', $plain)) {
            $agency = 'Clarivate / Web of Science';
        }
        return [\mb_substr($match[1], 0, 30), $agency];
    }

    /** @return array<string, string> */
    protected function extractIndexingAgencies(string $policyText): array
    {
        $decoded = \html_entity_decode($policyText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        \preg_match_all('~https?://[^\s"\'<>]+~iu', $decoded, $matches);
        $agencies = [];
        foreach (\array_slice($matches[0] ?? [], 0, 200) as $candidate) {
            $url = $this->cleanHttpUrl(\rtrim($candidate, '.,;:!?)\]'));
            if ($url === '') {
                continue;
            }
            $host = \strtolower((string) \parse_url($url, PHP_URL_HOST));
            $path = \strtolower((string) \parse_url($url, PHP_URL_PATH));
            $agency = match (true) {
                (bool) \preg_match('/(^|\.)scholar\.google\./', $host) => 'Google Scholar',
                $host === 'scopus.com' || \str_ends_with($host, '.scopus.com') => 'Scopus',
                $this->hostMatches($host, 'webofscience.com') || $this->hostMatches($host, 'clarivate.com') => 'Web of Science',
                $host === 'doaj.org' || \str_ends_with($host, '.doaj.org') => 'DOAJ',
                $this->hostMatches($host, 'ncbi.nlm.nih.gov') && \str_contains($path, 'pubmed') => 'PubMed',
                default => null,
            };
            if ($agency && !isset($agencies[$agency])) {
                $agencies[$agency] = $url;
            }
        }
        return $agencies;
    }

    private function hostMatches(string $host, string $expected): bool
    {
        return $host === $expected || \str_ends_with($host, '.' . $expected);
    }

    protected function detectPolicy(string $policyText, string $pattern): ?bool
    {
        $plain = $this->cleanText($policyText, 20000);
        return \preg_match($pattern, $plain) ? true : null;
    }

    /** @return string[] */
    protected function acceptedLocaleCodes($context, string $primaryLocale): array
    {
        $locales = $context->getData('supportedSubmissionLocales');
        if (!\is_array($locales) || !$locales) {
            $locales = $context->getData('supportedLocales');
        }
        if (!\is_array($locales) || !$locales) {
            $locales = [$primaryLocale];
        }
        $locales[] = $primaryLocale;
        $locales = \array_map(static fn($locale): string => \mb_substr(\trim((string) $locale), 0, 20), $locales);
        return \array_values(\array_unique(\array_filter($locales)));
    }

    /** @param string[] $localeCodes @return string[] */
    protected function resolveLocaleNames(array $localeCodes): array
    {
        try {
            $names = Locale::getFormattedDisplayNames($localeCodes);
            $resolved = [];
            foreach ($localeCodes as $locale) {
                $resolved[] = $this->cleanText($names[$locale] ?? $locale, 100);
            }
            return \array_values(\array_unique(\array_filter($resolved)));
        } catch (\Throwable) {
            return $localeCodes;
        }
    }

    protected function resolveCountryName(string $countryCode): string
    {
        $countryCode = \strtoupper(\trim($countryCode));
        if ($countryCode === '') {
            return '';
        }
        try {
            foreach (Locale::getCountries() as $country) {
                if (\strtoupper((string) $country->getAlpha2()) === $countryCode) {
                    return $this->cleanText($country->getLocalName(), 100);
                }
            }
        } catch (\Throwable) {
            // Preserve the validated OJS country code as a safe fallback.
        }
        return $this->cleanText($countryCode, 100);
    }

    protected function journalImageUrl($context, string $locale, string $baseUrl): string
    {
        foreach (['pageHeaderLogoImage', 'journalThumbnail', 'homepageImage'] as $field) {
            $image = $this->localized($context, $field, $locale);
            if (!\is_array($image) || empty($image['uploadName'])) {
                continue;
            }
            $uploadName = \basename((string) $image['uploadName']);
            if (\preg_match('/\.(?:jpe?g|png|webp)$/i', $uploadName)) {
                return $baseUrl . '/public/journals/' . (int) $context->getId() . '/' . \rawurlencode($uploadName);
            }
        }
        return '';
    }

    protected function localized($context, string $field, string $locale)
    {
        try {
            return $context->getLocalizedData($field, $locale) ?: $context->getLocalizedData($field);
        } catch (\Throwable) {
            return $context->getData($field);
        }
    }

    protected function getOjsVersion(): string
    {
        return Application::get()->getCurrentVersion()->getVersionString();
    }

    public function cleanText($value, int $max = 2000): string
    {
        if (\is_array($value)) {
            $value = \implode(' ', \array_filter(\array_map('strval', $value)));
        }
        if (\is_object($value)) {
            return '';
        }
        $value = \html_entity_decode((string) ($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = \preg_replace('~<(script|style|iframe|object|embed|svg|math)[^>]*>.*?</\\1\s*>~is', '', $value) ?? '';
        $value = \strip_tags($value);
        $value = \preg_replace('~(?:javascript\s*:[^\s<>"\']+|data\s*:\s*text/html[^\s<>"\']*|<\?)~iu', '', $value) ?? '';
        $value = \preg_replace('/\bon[a-z]+\s*=/iu', '', $value) ?? '';
        $value = \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value) ?? '';
        $value = \preg_replace('/\s+/u', ' ', $value) ?? '';
        return \mb_substr(\trim($value), 0, $max);
    }

    public function cleanHttpUrl($value): string
    {
        $url = \trim((string) ($value ?? ''));
        if ($url === '' || \strlen($url) > 500 || !\filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }
        $scheme = \strtolower((string) \parse_url($url, PHP_URL_SCHEME));
        return \in_array($scheme, ['http', 'https'], true) ? $url : '';
    }
}
