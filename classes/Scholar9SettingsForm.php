<?php

namespace APP\plugins\generic\scholar9Review;

use APP\core\Application;
use PKP\form\Form;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;
use PKP\plugins\GenericPlugin;

class Scholar9SettingsForm extends Form
{
    private GenericPlugin $plugin;
    private int $contextId;

    private const FIELDS = [
        'baseUrl'             => 'string',
        'clientId'            => 'string',
        'clientSecret'        => 'string',
        'journalSlug'         => 'string',
        'webhookSecret'       => 'string',
        'defaultReviewType'   => 'string',
        'reviewDeadline'      => 'int',
        'autoSync'            => 'bool',
        'uploadFiles'         => 'bool',
        'uploadSupplementary' => 'bool',
        'verifySsl'           => 'bool',
    ];

    public function __construct(GenericPlugin $plugin, int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->plugin    = $plugin;
        $this->contextId = $contextId;

        $this->addCheck(new FormValidatorCustom(
            $this,
            'baseUrl',
            FormValidator::FORM_VALIDATOR_REQUIRED_VALUE,
            'plugins.generic.scholar9Review.settings.baseUrl.required',
            static function ($value): bool {
                $url = \parse_url(\trim((string) $value));
                if (!\is_array($url) || empty($url['scheme']) || empty($url['host'])) {
                    return false;
                }

                $scheme = \strtolower((string) $url['scheme']);
                if (!\in_array($scheme, ['http', 'https'], true)) {
                    return false;
                }

                if (isset($url['port']) && ($url['port'] < 1 || $url['port'] > 65535)) {
                    return false;
                }

                return empty($url['user'])
                    && empty($url['pass'])
                    && empty($url['query'])
                    && empty($url['fragment'])
                    && (!isset($url['path']) || $url['path'] === '' || $url['path'] === '/');
            }
        ));
        // Credentials are optional until Scholar9 approves an API-access
        // request. Validation below prevents partially entered credentials.
        $this->addCheck(new FormValidatorCustom(
            $this,
            'clientId',
            FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
            'plugins.generic.scholar9Review.settings.credentials.incomplete',
            fn($value): bool => \trim((string) $value) === ''
                || (
                    (bool) \preg_match('/^[A-Za-z0-9_-]{3,255}$/', \trim((string) $value))
                    &&
                    \trim((string) $this->getData('clientSecret')) !== ''
                    && (bool) \preg_match('/^[a-z0-9_-]+$/', \trim((string) $this->getData('journalSlug')))
                )
        ));
        $this->addCheck(new FormValidatorCustom(
            $this,
            'clientSecret',
            FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
            'plugins.generic.scholar9Review.settings.credentials.incomplete',
            fn($value): bool => \trim((string) $value) === ''
                || (
                    \trim((string) $this->getData('clientId')) !== ''
                    && \strlen((string) $value) <= 255
                    && !(bool) \preg_match('/[\x00-\x1F\x7F]/', (string) $value)
                )
        ));
        $this->addCheck(new FormValidatorCustom(
            $this,
            'journalSlug',
            FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
            'plugins.generic.scholar9Review.settings.journalSlug.required',
            static fn($value): bool => \trim((string) $value) === ''
                || (bool) \preg_match('/^[a-z0-9_-]{1,60}$/', \trim((string) $value))
        ));
        $this->addCheck(new FormValidatorCustom(
            $this,
            'webhookSecret',
            FormValidator::FORM_VALIDATOR_REQUIRED_VALUE,
            'plugins.generic.scholar9Review.settings.webhookSecret.invalid',
            static fn($value): bool => (bool) \preg_match('/^[a-f0-9]{32,128}$/i', \trim((string) $value))
        ));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public function initData(): void
    {
        $defaults = [
            'baseUrl' => Scholar9ApiClient::DEFAULT_BASE_URL,
            'defaultReviewType' => 'double_anonymous',
            'reviewDeadline' => 30,
            'autoSync' => true,
            'uploadFiles' => true,
            'uploadSupplementary' => false,
            'verifySsl' => true,
        ];
        foreach (self::FIELDS as $field => $_type) {
            $value = $this->plugin->getSetting($this->contextId, $field);
            $isUnsetBaseUrl = $field === 'baseUrl' && \trim((string) ($value ?? '')) === '';
            if (($value === null || $isUnsetBaseUrl) && \array_key_exists($field, $defaults)) {
                $value = $defaults[$field];
            }
            $this->setData($field, $value);
        }

        if (!$this->getData('webhookSecret')) {
            $this->setData('webhookSecret', \bin2hex(\random_bytes(32)));
        }
    }

    public function readInputData(): void
    {
        $this->readUserVars(\array_keys(self::FIELDS));
    }

    public function fetch($request, $template = null, $display = false): string
    {
        $templateMgr = \APP\template\TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'reviewTypes' => [
                'open' => __('plugins.generic.scholar9Review.settings.reviewType.open'),
                'single_anonymous' => __('plugins.generic.scholar9Review.settings.reviewType.singleAnonymous'),
                'double_anonymous' => __('plugins.generic.scholar9Review.settings.reviewType.doubleAnonymous'),
            ],
        ]);
        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionParams): void
    {
        foreach (self::FIELDS as $field => $type) {
            $value = $this->getData($field);
            if ($field === 'baseUrl') {
                $value = \rtrim((string) $value, '/');
            } elseif ($field === 'journalSlug') {
                $value = \trim((string) $value, " \t\n\r\0\x0B/");
            } elseif ($field === 'reviewDeadline') {
                $value = \max(1, \min(365, (int) $value));
            } elseif ($type === 'string') {
                $value = \html_entity_decode((string) ($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $value = \preg_replace('~<(script|style|iframe|object|embed|svg|math)[^>]*>.*?</\\1\s*>~is', '', $value) ?? '';
                $value = \strip_tags($value);
                $value = \preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value) ?? '';
                $value = \mb_substr(\trim($value), 0, 2000);
            }
            $this->plugin->updateSetting(
                $this->contextId,
                $field,
                match ($type) {
                    'bool'  => (bool) $value,
                    'int'   => (int)  $value,
                    default => \trim((string) ($value ?? '')),
                },
                $type
            );
        }

        $this->registerWebhook();

        parent::execute(...$functionParams);
    }

    private function registerWebhook(): void
    {
        try {
            $client = new Scholar9ApiClient($this->plugin, $this->contextId);
            if (!$client->isConfigured()) {
                return;
            }

            $request    = Application::get()->getRequest();
            $context    = $request->getContext();
            $journalPath = $context ? $context->getPath() : '';
            $webhookUrl  = $request->getBaseUrl() . '/index.php/' . $journalPath . '/scholar9review/webhook';
            $secret      = (string) ($this->plugin->getSetting($this->contextId, 'webhookSecret') ?? '');

            $client->registerWebhook($webhookUrl, [
                'review.assigned', 'review.status_updated', 'review.completed', 'message.new',
            ], $secret);
        } catch (\Throwable $e) {
            \error_log('[Scholar9] Webhook registration failed on settings save: ' . $e->getMessage());
        }
    }
}
