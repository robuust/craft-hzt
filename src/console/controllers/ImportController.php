<?php

namespace robuust\hzt\console\controllers;

use craft\console\Controller;
use robuust\hzt\Plugin;
use yii\console\ExitCode;

/**
 * Import and clean up HZT event entries from the command line.
 */
class ImportController extends Controller
{
    /**
     * Import every published, upcoming event returned by the API.
     *
     * @return int
     */
    public function actionIndex(): int
    {
        $service = Plugin::getInstance()->events;
        $events = $service->getEvents();
        $this->stdout('Events found: '.count($events)."\n");

        foreach ($events as $event) {
            $service->importEvent($event);
        }

        $this->stdout('Events imported: '.count($events)."\n");

        return ExitCode::OK;
    }

    /**
     * Delete imported entries no longer present in a fully fetched API feed.
     *
     * @return int
     */
    public function actionCleanup(): int
    {
        $service = Plugin::getInstance()->events;
        $eventIds = array_column($service->getEvents(), 'id');
        $count = 0;

        foreach ($service->getEntries() as $entry) {
            if ($service->cleanupEvent($entry, $eventIds)) {
                ++$count;
            }
        }

        $this->stdout('Events cleaned up: '.$count."\n");

        return ExitCode::OK;
    }
}
