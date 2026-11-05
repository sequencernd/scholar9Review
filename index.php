<?php

/**
 * Scholar9 Review Plugin for OJS 3.4 / 3.5
 * Install to: plugins/generic/scholar9Review/
 *
 * Explicit require_once for every class file so OJS's PSR-4 autoloader
 * does not need to locate files in subdirectories (classes/ pages/).
 */

$pluginDir = dirname(__FILE__);

require_once $pluginDir . '/classes/Scholar9ReviewDAO.php';
require_once $pluginDir . '/classes/Scholar9ReviewSchemaMigration.php';
require_once $pluginDir . '/classes/Scholar9ApiClient.php';
require_once $pluginDir . '/classes/Scholar9SettingsForm.php';
require_once $pluginDir . '/classes/Scholar9SyncService.php';
require_once $pluginDir . '/pages/Scholar9ReviewHandler.php';
require_once $pluginDir . '/Scholar9ReviewPlugin.php';

return new \APP\plugins\generic\scholar9Review\Scholar9ReviewPlugin();
