<?php

namespace robuust\hzt;

use robuust\hzt\models\Settings;
use robuust\hzt\services\Api;
use robuust\hzt\services\Events;
use robuust\hzt\services\Participants;

/**
 * HZT plugin.
 *
 * @property Api          $api
 * @property Events       $events
 * @property Participants $participants
 *
 * @method Settings getSettings()
 */
class Plugin extends \craft\base\Plugin
{
    /**
     * Register the API, event import, and participant services.
     */
    public function init(): void
    {
        parent::init();

        $this->setComponents([
            'api' => Api::class,
            'events' => Events::class,
            'participants' => Participants::class,
        ]);
    }

    /**
     * Create the settings loaded from config/hzt.php.
     *
     * @return Settings
     */
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }
}
