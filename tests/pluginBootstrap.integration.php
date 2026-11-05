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
fwrite(STDOUT, "Scholar9 plugin entry point and migration load successfully.\n");
