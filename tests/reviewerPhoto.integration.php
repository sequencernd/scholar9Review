<?php

declare(strict_types=1);

/**
 * Optional read-only live smoke test for Scholar9 reviewer profile photos.
 *
 * Usage: php tests/reviewerPhoto.integration.php [reviewer search text]
 * The script never prints API credentials or complete profile-photo URLs.
 */

$ojsRoot = dirname(__DIR__, 4);
defined('INDEX_FILE_LOCATION') || define('INDEX_FILE_LOCATION', $ojsRoot . '/index.php');
chdir($ojsRoot);
require $ojsRoot . '/lib/pkp/includes/bootstrap.php';

// Keep this read-only diagnostic isolated from OJS's filesystem cache. This
// avoids CLI permission noise and prevents cached integration settings from
// being included in filesystem exception traces.
config([
    'cache.stores.scholar9_test' => ['driver' => 'array'],
    'cache.default' => 'scholar9_test',
]);

$plugin = require dirname(__DIR__) . '/index.php';
$query = trim((string) ($argv[1] ?? 'Darshan'));
$onlyContextId = isset($argv[2]) ? (int) $argv[2] : 0;
if (mb_strlen($query) < 2) {
    fwrite(STDERR, "Reviewer search text must contain at least two characters.\n");
    exit(2);
}

$contexts = \APP\core\Application::getContextDAO()->getAll();
$tested = 0;
while ($context = $contexts->next()) {
    $contextId = (int) $context->getId();
    if ($onlyContextId > 0 && $contextId !== $onlyContextId) {
        continue;
    }
    $client = new \APP\plugins\generic\scholar9Review\Scholar9ApiClient($plugin, $contextId);
    if (!$client->isConfigured()) {
        continue;
    }

    $tested++;
    $response = $client->searchReviewers($query, 'all');
    $reviewers = is_array($response['data'] ?? null) ? $response['data'] : [];
    $reviewer = $reviewers[0] ?? null;
    $photoUrl = is_array($reviewer) ? trim((string) ($reviewer['photo_url'] ?? '')) : '';
    $scheme = strtolower((string) parse_url($photoUrl, PHP_URL_SCHEME));
    $validPhoto = filter_var($photoUrl, FILTER_VALIDATE_URL)
        && in_array($scheme, ['http', 'https'], true);

    printf(
        "[%s] context=%d path=%s reviewer=%s photo=%s host=%s\n",
        $reviewer && $validPhoto ? 'PASS' : 'FAIL',
        $contextId,
        (string) $context->getPath(),
        $reviewer ? 'found' : 'missing',
        $validPhoto ? 'valid' : 'missing-or-invalid',
        $validPhoto ? (string) parse_url($photoUrl, PHP_URL_HOST) : '-'
    );

    if (!$reviewer || !$validPhoto) {
        exit(3);
    }

    $image = curl_init($photoUrl);
    curl_setopt_array($image, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($image);
    $imageStatus = (int) curl_getinfo($image, CURLINFO_RESPONSE_CODE);
    $imageType = strtolower((string) curl_getinfo($image, CURLINFO_CONTENT_TYPE));
    curl_close($image);
    printf(
        "[%s] profile image response=%d content-type=%s\n",
        $imageStatus >= 200 && $imageStatus < 400 && str_starts_with($imageType, 'image/') ? 'PASS' : 'FAIL',
        $imageStatus,
        $imageType ?: '-'
    );

    $cachedRows = \Illuminate\Support\Facades\DB::select(
        'SELECT submission_id, review_round_id, s9_paper_id, reviewers_cache FROM scholar9_reviews WHERE journal_id = ?',
        [$contextId]
    );
    foreach ($cachedRows as $cachedRow) {
        $cachedReviewers = json_decode((string) ($cachedRow->reviewers_cache ?? ''), true);
        $cachedReviewer = is_array($cachedReviewers) ? ($cachedReviewers[0] ?? null) : null;
        $cachedPhoto = is_array($cachedReviewer) ? trim((string) ($cachedReviewer['photo_url'] ?? '')) : '';
        printf(
            "[CACHE] submission=%d round=%d reviewer=%s photo=%s\n",
            (int) $cachedRow->submission_id,
            (int) $cachedRow->review_round_id,
            $cachedReviewer ? 'present' : 'missing',
            $cachedPhoto !== '' ? 'present' : 'missing'
        );
        $paperId = trim((string) ($cachedRow->s9_paper_id ?? ''));
        if ($paperId !== '') {
            $statusResponse = $client->getReviewStatus($paperId);
            $assignments = is_array($statusResponse['data'] ?? null) ? $statusResponse['data'] : [];
            if (isset($assignments['reviewer'])) {
                $assignments = [$assignments];
            }
            $remotePhoto = trim((string) ($assignments[0]['reviewer']['photo_url'] ?? ''));
            printf(
                "[STATUS API] submission=%d reviewer-photo=%s cache-current=%s\n",
                (int) $cachedRow->submission_id,
                $remotePhoto !== '' ? 'present' : 'missing',
                $remotePhoto !== '' && hash_equals($remotePhoto, $cachedPhoto) ? 'yes' : 'no'
            );
            if ($cachedPhoto !== '') {
                $cachedImage = curl_init($cachedPhoto);
                curl_setopt_array($cachedImage, [
                    CURLOPT_NOBODY => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                curl_exec($cachedImage);
                $cachedStatus = (int) curl_getinfo($cachedImage, CURLINFO_RESPONSE_CODE);
                curl_close($cachedImage);
                printf("[CACHE IMAGE] submission=%d response=%d\n", (int) $cachedRow->submission_id, $cachedStatus);
            }
        }
    }
}

if (!$tested) {
    fwrite(STDERR, "No configured Scholar9 journal context was found.\n");
    exit(4);
}

printf("Live reviewer photo smoke test passed for %d configured journal(s).\n", $tested);
