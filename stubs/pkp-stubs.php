<?php

/**
 * OJS / PKP-lib class stubs for IDE resolution.
 * Autoloaded via autoload-dev only — never shipped in production.
 * Parameters prefixed with _ are intentionally unused (stub bodies are empty).
 */

namespace PKP\plugins {

    abstract class GenericPlugin
    {
        public function register(string $_category, string $_path, ?int $_mainContextId = null): bool { return true; }
        public function getEnabled(?int $_contextId = null): bool { return true; }
        public function getName(): string { return ''; }
        public function getDisplayName(): string { return ''; }
        public function getDescription(): string { return ''; }
        public function getActions($_request, string $_verb): array { return []; }
        public function manage($_args, $_request): \PKP\core\JSONMessage { return new \PKP\core\JSONMessage(true); }
        public function getSetting(int $_contextId, string $_name): mixed { return null; }
        public function updateSetting(int $_contextId, string $_name, mixed $_value, string $_type = 'string'): void {}
        public function getPluginPath(): string { return ''; }
        public function getTemplateResource(string $_template): string { return ''; }
    }

    class PluginRegistry
    {
        public static function getPlugin(string $_category, string $_pluginName): ?GenericPlugin { return null; }
        public static function loadCategory(string $_category, bool $_enabled = true, ?int $_mainContextId = null): array { return []; }
    }
}

namespace PKP\core {

    class Hook
    {
        public static function add(string $_hookName, callable $_callback): void {}
        public static function call(string $_hookName, array &$_args = []): bool { return false; }
    }

    class JSONMessage
    {
        public function __construct(bool $_status = true, mixed $_content = null) {}
    }

    class PKPRequest
    {
        public function getContext(): mixed { return null; }
        public function getUser(): mixed { return null; }
        public function getRouter(): mixed { return null; }
        public function getBaseUrl(): string { return ''; }
        public function getUserVar(string $_key): mixed { return null; }
        public function getRequestMethod(): string { return 'GET'; }
        public function checkCSRF(): bool { return true; }
    }
}

namespace PKP\linkAction {

    class LinkAction
    {
        public function __construct(string $_id, ?\PKP\linkAction\request\LinkActionRequest $_action = null, string $_title = '', ?string $_image = null) {}
    }
}

namespace PKP\linkAction\request {

    abstract class LinkActionRequest {}

    class AjaxModal extends LinkActionRequest
    {
        public function __construct(
            string $_url,
            string $_title = '',
            string $_modalStyle = 'basic',
            bool $_canClose = true,
            ?string $_closeOnFormSuccessId = null,
            array $_closeCleanVueInstances = []
        ) {}
    }
}

namespace PKP\notification {

    class NotificationManager
    {
        public function createTrivialNotification(int $_userId, int $_notificationType, array $_params = []): void {}
    }

    class PKPNotification
    {
        public const NOTIFICATION_TYPE_SUCCESS = 0x0000001;
        public const NOTIFICATION_TYPE_WARNING = 0x0000002;
        public const NOTIFICATION_TYPE_ERROR   = 0x0000004;
    }
}

namespace PKP\form {

    abstract class Form
    {
        public function __construct(string $_template, bool $_callHooks = true, mixed $_requiredLocale = null) {}
        public function addCheck(\PKP\form\validation\FormValidator $_check): void {}
        public function initData(): void {}
        public function readInputData(): void {}
        public function readUserVars(array $_vars): void {}
        public function getData(string $_key): mixed { return null; }
        public function setData(string $_key, mixed $_value): void {}
        public function validate(array $_callHooks = []): bool { return true; }
        public function execute(mixed ...$_functionParams): void {}
        public function fetch($_request, ?string $_template = null, bool $_display = false): string { return ''; }
        public function display($_request, ?string $_template = null): void {}
    }
}

namespace PKP\form\validation {

    class FormValidator
    {
        public const FORM_VALIDATOR_REQUIRED_VALUE = 'required';
        public const FORM_VALIDATOR_OPTIONAL_VALUE = 'optional';

        public function __construct(\PKP\form\Form $_form, string $_field, string $_type, string $_message, mixed $_validator = null) {}
    }

    class FormValidatorUrl extends FormValidator
    {
        public function __construct(\PKP\form\Form $_form, string $_field, string $_type, string $_message) {}
    }

    class FormValidatorPost extends FormValidator
    {
        public function __construct(\PKP\form\Form $_form) {}
    }

    class FormValidatorCSRF extends FormValidator
    {
        public function __construct(\PKP\form\Form $_form) {}
    }
}

namespace PKP\handler {

    abstract class PKPHandler
    {
        public function authorize($_request, array &$_args, array $_roleAssignments): bool { return true; }
    }
}

namespace APP\core {

    class Application
    {
        public static function get(): static { return new static(); }
        public function getRequest(): \PKP\core\PKPRequest { return new \PKP\core\PKPRequest(); }
    }
}

namespace APP\template {

    class TemplateManager
    {
        public static function getManager($_request = null): static { return new static(); }
        public function assign(array|string $_smartyKeys, mixed $_value = null): void {}
        public function getTemplateVars(string $_varName): mixed { return null; }
        public function fetch(string $_resource, ?string $_cacheId = null, ?string $_compileId = null, bool $_display = false): string { return ''; }
        public function display(string $_template, string $_cacheId = '', string $_compileId = '', ?object $_parent = null): void {}
    }
}

namespace APP\facades {

    class Repo
    {
        public static function submission(): \APP\submission\Repository { return new \APP\submission\Repository(); }
        public static function user(): \PKP\user\Repository { return new \PKP\user\Repository(); }
    }
}

namespace APP\submission {

    class Repository
    {
        public function get(int $_id): mixed { return null; }
    }
}

namespace PKP\user {

    class Repository
    {
        public function get(int $_id): mixed { return null; }
    }
}
