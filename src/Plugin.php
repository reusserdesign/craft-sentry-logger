<?php

namespace diginov\sentrylogger;

use diginov\sentrylogger\log\SentryTarget;
use diginov\sentrylogger\models\SettingsModel;
use diginov\sentrylogger\integrations\SentryTracer;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use craft\helpers\ArrayHelper;

class Plugin extends \craft\base\Plugin
{
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @inheritdoc
     */
    public bool $hasCpSettings = true;

    /**
     * @inheritdoc
     */
    public bool $hasCpSection = false;

    // Protected Properties
    // =========================================================================

    /**
     * @var bool
     */
    protected bool $isAdvancedConfig = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $dispatcher = Craft::$app->getLog();

        foreach ($dispatcher->targets as $target) {
            if ($target instanceof SentryTarget) {
                $this->isAdvancedConfig = true;
                break;
            }
        }

        if (!$this->isAdvancedConfig) {
            $settings = $this->getSettings();

            if ($settings && $settings->validate()) {
                $target = ArrayHelper::merge($settings->toArray(), ['class' => SentryTarget::class]);
                $target['dsn'] = App::parseEnv($target['dsn']);
                $target['tracesSampleRate'] = App::parseEnv($target['tracesSampleRate']);
                $target['release'] = App::parseEnv($target['release']);
                $target['environment'] = App::parseEnv($target['environment']);
                $dispatcher->targets['sentry'] = Craft::createObject($target);
            }
        }

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function () {
            SentryTracer::getInstance()->setup();
        });
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new SettingsModel();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        $settings = $this->getSettings();
        $overrides = Craft::$app->getConfig()->getConfigFromFile($this->handle);

        return Craft::$app->getView()->renderTemplate($this->handle.'/settings', [
            'plugin' => $this,
            'settings' => $settings,
            'overrides' => $overrides,
            'isAdvancedConfig' => $this->isAdvancedConfig,
        ]);
    }
}
