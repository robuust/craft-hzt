<?php

namespace robuust\hzt\services;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\Assets;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\Section;
use robuust\hzt\models\Settings;
use robuust\hzt\Plugin;
use RuntimeException;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Import published, upcoming HZT events as Craft entries.
 */
class Events extends Component
{
    private Settings $settings;
    private Section $section;
    private EntryType $entryType;

    /**
     * Resolve the configured section and entry type when the importer is used.
     */
    public function init(): void
    {
        parent::init();

        $this->settings = Plugin::getInstance()->getSettings();

        if (!$this->settings->validate(['sectionHandle', 'entryTypeHandle', 'eventIdField'])) {
            throw new InvalidConfigException('Configure the HZT section, entry type, and event ID field in config/hzt.php.');
        }

        $entries = Craft::$app->getEntries();
        $section = $entries->getSectionByHandle($this->settings->sectionHandle);
        $entryType = $entries->getEntryTypeByHandle($this->settings->entryTypeHandle);

        if ($section === null || $entryType === null || !in_array($entryType->id, array_column($section->getEntryTypes(), 'id'), true)) {
            throw new InvalidConfigException('The HZT section must exist and have the configured entry type assigned.');
        }

        $this->section = $section;
        $this->entryType = $entryType;
    }

    /**
     * Fetch every API page before importing or cleaning up entries.
     *
     * @return list<array<string, mixed>>
     */
    public function getEvents(): array
    {
        $events = [];
        $page = 1;

        do {
            $response = Plugin::getInstance()->api->request('GET', 'events', [
                'query' => ['page' => $page, 'per_page' => 100],
            ]);
            $data = Json::decode((string) $response->getBody());
            $events = array_merge($events, $data['data']);
            ++$page;
        } while ($page <= $data['meta']['last_page']);

        return $events;
    }

    /**
     * Find an imported entry by its HZT event ID.
     *
     * @param int $eventId
     *
     * @return Entry|null
     */
    public function getEntry(int $eventId): ?Entry
    {
        $query = Entry::find()->section($this->section)->type($this->entryType)->status(null);
        $query->{$this->settings->eventIdField} = $eventId;

        return $query->one();
    }

    /**
     * List only entries carrying an HZT event ID in the configured section.
     *
     * @return list<Entry>
     */
    public function getEntries(): array
    {
        $query = Entry::find()->section($this->section)->type($this->entryType)->status(null);
        $query->{$this->settings->eventIdField} = ':notempty:';

        return $query->all();
    }

    /**
     * Create or update an entry using the configured API-to-field mapping.
     *
     * @param array<string, mixed> $event
     *
     * @return Entry
     */
    public function importEvent(array $event): Entry
    {
        $entry = $this->getEntry($event['id']);

        if ($entry === null) {
            $entry = new Entry();
            $entry->sectionId = $this->section->id;
            $entry->typeId = $this->entryType->id;
            $entry->enabled = true;
        }

        $entry->title = $event['name'];

        foreach ($this->settings->fieldMapping as $handle => $path) {
            $value = $event;

            foreach (explode('.', $path) as $segment) {
                $value = $value[$segment];
            }

            $entry->setFieldValue($handle, $value);
        }

        $entry->setFieldValue($this->settings->eventIdField, $event['id']);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException('Could not save HZT event '.$event['id'].': '.Json::encode($entry->getErrors()));
        }

        if ($this->settings->imagesField !== '') {
            $entry->setFieldValue($this->settings->imagesField, $this->importImages($entry, $event['course']['images']));

            if (!Craft::$app->getElements()->saveElement($entry)) {
                throw new RuntimeException('Could not save images for HZT event '.$event['id'].': '.Json::encode($entry->getErrors()));
            }
        }

        return $entry;
    }

    /**
     * Delete an imported entry only when its event is absent from the full feed.
     *
     * @param Entry     $entry
     * @param list<int> $eventIds
     *
     * @return bool
     */
    public function cleanupEvent(Entry $entry, array $eventIds): bool
    {
        if (in_array((int) $entry->{$this->settings->eventIdField}, $eventIds, true)) {
            return false;
        }

        if (!Craft::$app->getElements()->deleteElement($entry)) {
            throw new RuntimeException('Could not delete imported HZT entry '.$entry->id.'.');
        }

        return true;
    }

    /**
     * Import course images, reusing attached assets with identical contents.
     *
     * @param Entry                      $entry
     * @param list<array<string, mixed>> $images
     *
     * @return list<int>
     */
    private function importImages(Entry $entry, array $images): array
    {
        $field = $entry->getFieldLayout()->getFieldByHandle($this->settings->imagesField);

        if (!$field instanceof Assets) {
            throw new InvalidConfigException('The HZT imagesField must refer to an Assets field on the configured entry type.');
        }

        $currentAssets = $entry->{$this->settings->imagesField}->all();
        $client = Craft::createGuzzleClient(['connect_timeout' => 10, 'timeout' => 30]);
        $assetIds = [];

        foreach ($images as $image) {
            $content = (string) $client->get($image['url'])->getBody();

            foreach ($currentAssets as $asset) {
                if ($asset->getContents() === $content) {
                    $assetIds[] = $asset->id;
                    continue 2;
                }
            }

            $tempPath = AssetsHelper::tempFilePath(pathinfo($image['file_name'], PATHINFO_EXTENSION));

            try {
                FileHelper::writeToFile($tempPath, $content);
                $folderId = $field->resolveDynamicPathToFolderId($entry);
                $folder = Craft::$app->getAssets()->getFolderById($folderId);
                $asset = new Asset();
                $asset->tempFilePath = $tempPath;
                $asset->setFilename($image['file_name']);
                $asset->newFolderId = $folderId;
                $asset->setVolumeId($folder->volumeId);
                $asset->avoidFilenameConflicts = true;
                $asset->setScenario(Asset::SCENARIO_CREATE);

                if (!Craft::$app->getElements()->saveElement($asset)) {
                    throw new RuntimeException('Could not save HZT image: '.Json::encode($asset->getErrors()));
                }

                $assetIds[] = $asset->id;
            } finally {
                FileHelper::unlink($tempPath);
            }
        }

        return $assetIds;
    }
}
