<?php

declare(strict_types=1);

/** Load the packaged entry point against real OJS classes without database writes. */
defined('PKP_STRICT_MODE') || define('PKP_STRICT_MODE', false);
require_once dirname(__DIR__, 4) . '/lib/pkp/lib/vendor/autoload.php';
$plugin = require dirname(__DIR__) . '/index.php';
if (!$plugin instanceof \PKP\plugins\GenericPlugin) {
    throw new RuntimeException('Plugin entry point did not return an OJS GenericPlugin.');
}
if (!$plugin->getInstallMigration() instanceof \Illuminate\Database\Migrations\Migration) {
    throw new RuntimeException('Plugin installation migration is unavailable.');
}
// Exercise installation and reinstallation in a disposable in-memory database.
$database = new \Illuminate\Database\Capsule\Manager();
$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$database->setAsGlobal();
$database->bootEloquent();
\Illuminate\Support\Facades\DB::swap($database->getDatabaseManager());
\Illuminate\Support\Facades\Schema::swap($database->schema());
$migration = $plugin->getInstallMigration();
$migration->up();
$migration->up();
if (!\Illuminate\Support\Facades\Schema::hasTable('scholar9_reviews')) {
    throw new RuntimeException('Installation did not create the review table.');
}
foreach (['reviewer_id', 'reviewers_cache', 'full_review'] as $column) {
    if (!\Illuminate\Support\Facades\Schema::hasColumn('scholar9_reviews', $column)) {
        throw new RuntimeException('Missing installed column: ' . $column);
    }
}
$dao = new \APP\plugins\generic\scholar9Review\Scholar9ReviewDAO();
$id = $dao->insert(['journal_id' => 1, 'submission_id' => 10, 's9_paper_id' => 'smoke-paper']);
$record = $dao->getBySubmission(1, 10);
if (!$record || (int) $record['id'] !== $id || $dao->getBySubmission(2, 10) !== null) {
    throw new RuntimeException('Installed database failed record/tenant isolation check.');
}
// Simulate a previous schema, then exercise the additive upgrade path.
\Illuminate\Support\Facades\Schema::table('scholar9_reviews', function ($table): void {
    $table->dropColumn(['reviewer_id', 'reviewers_cache', 'full_review']);
});
$migration->up();
foreach (['reviewer_id', 'reviewers_cache', 'full_review'] as $column) {
    if (!\Illuminate\Support\Facades\Schema::hasColumn('scholar9_reviews', $column)) {
        throw new RuntimeException('Upgrade did not restore column: ' . $column);
    }
}
if (!$dao->getBySubmission(1, 10)) {
    throw new RuntimeException('Upgrade lost the existing review record.');
}
$migration->down();
if (\Illuminate\Support\Facades\Schema::hasTable('scholar9_reviews')) {
    throw new RuntimeException('Migration rollback did not remove the disposable table.');
}
fwrite(STDOUT, "Scholar9 entry point, install, idempotency, upgrade and rollback checks passed.\n");
