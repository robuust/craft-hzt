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
    private const MAX_PAGES = 1000;

    private Settings $settings;
    private Section $section;
    private EntryType $entryType;

    /**
     * @var array<int, array<string, int>> asset IDs indexed by folder and content hash
     */
    private array $assetIdsByFolder = [];

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
            if ($page > self::MAX_PAGES) {
                throw new RuntimeException('The HZT event feed exceeds the limit of '.self::MAX_PAGES.' pages.');
            }

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
     * Create or update an entry using the configured API-to-field mapping, preserving nullable nested values.
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
        $event['schedule'] = $this->formatSchedule($event);

        foreach ($this->settings->fieldMapping as $handle => $path) {
            $value = $event;

            foreach (explode('.', $path) as $segment) {
                if ($value === null) {
                    break;
                }

                $value = $value[$segment];
            }

            $entry->setFieldValue($handle, $value);
        }

        $entry->setFieldValue($this->settings->eventIdField, $event['id']);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException('Could not save HZT event '.$event['id'].': '.Json::encode($entry->getErrors()));
        }

        if ($this->settings->imagesField !== '') {
            $entry->setFieldValue($this->settings->imagesField, $this->importAssets($entry, $this->settings->imagesField, $event['course']['images']));
        }

        if ($this->settings->brochureField !== '') {
            $brochure = $event['course']['brochure'];
            $entry->setFieldValue($this->settings->brochureField, $this->importAssets($entry, $this->settings->brochureField, $brochure === null ? [] : [$brochure]));
        }

        if ($this->settings->imagesField !== '' || $this->settings->brochureField !== '') {
            if (!Craft::$app->getElements()->saveElement($entry)) {
                throw new RuntimeException('Could not save assets for HZT event '.$event['id'].': '.Json::encode($entry->getErrors()));
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
     * Format the effective schedule in Dutch without shifting its calendar date or times.
     *
     * @param array<string, mixed> $event
     *
     * @return string
     */
    private function formatSchedule(array $event): string
    {
        $formatter = Craft::$app->getI18n()->getLocaleById('nl-NL')->getFormatter();
        $date = substr($event['start_date'], 0, 10);
        $schedule = $event['is_continuous']
            ? 'Elke '.$formatter->asDate($date, 'EEEE')
            : $formatter->asDate($date, 'd MMMM y');

        if ($event['start_time'] !== null && $event['end_time'] !== null) {
            $schedule .= ', van '.substr($event['start_time'], 0, 5).' tot '.substr($event['end_time'], 0, 5);
        }

        return $schedule;
    }

    /**
     * Import course files, reusing identical attached assets or upload-folder assets.
     *
     * @param Entry                      $entry
     * @param string                     $fieldHandle
     * @param list<array<string, mixed>> $files
     *
     * @return list<int>
     */
    private function importAssets(Entry $entry, string $fieldHandle, array $files): array
    {
        $field = $entry->getFieldLayout()->getFieldByHandle($fieldHandle);

        if (!$field instanceof Assets) {
            throw new InvalidConfigException('The HZT field "'.$fieldHandle.'" must refer to an Assets field on the configured entry type.');
        }

        if ($files === []) {
            return [];
        }

        $folderId = $field->resolveDynamicPathToFolderId($entry);
        $folder = Craft::$app->getAssets()->getFolderById($folderId);

        if (!isset($this->assetIdsByFolder[$folderId])) {
            $this->assetIdsByFolder[$folderId] = [];

            foreach (Asset::find()->folderId($folderId)->status(null)->all() as $asset) {
                $hash = hash('sha256', $asset->getContents());
                $this->assetIdsByFolder[$folderId][$hash] ??= $asset->id;
            }
        }

        $assetsByHash = $this->assetIdsByFolder[$folderId];

        foreach ($entry->{$fieldHandle}->all() as $asset) {
            $assetsByHash[hash('sha256', $asset->getContents())] = $asset->id;
        }

        $client = Craft::createGuzzleClient(['connect_timeout' => 10, 'timeout' => 30]);
        $assetIds = [];

        foreach ($files as $file) {
            $content = (string) $client->get($file['url'])->getBody();
            $hash = hash('sha256', $content);

            if (isset($assetsByHash[$hash])) {
                $assetIds[$hash] = $assetsByHash[$hash];
                continue;
            }

            $tempPath = AssetsHelper::tempFilePath(pathinfo($file['file_name'], PATHINFO_EXTENSION));

            try {
                FileHelper::writeToFile($tempPath, $content);
                $asset = new Asset();
                $asset->tempFilePath = $tempPath;
                $asset->setFilename($file['file_name']);
                $asset->newFolderId = $folderId;
                $asset->setVolumeId($folder->volumeId);
                $asset->avoidFilenameConflicts = true;
                $asset->setScenario(Asset::SCENARIO_CREATE);

                if (!Craft::$app->getElements()->saveElement($asset)) {
                    throw new RuntimeException('Could not save HZT asset: '.Json::encode($asset->getErrors()));
                }

                $assetsByHash[$hash] = $asset->id;
                $this->assetIdsByFolder[$folderId][$hash] = $asset->id;
                $assetIds[$hash] = $asset->id;
            } finally {
                FileHelper::unlink($tempPath);
            }
        }

        return array_values($assetIds);
    }
}
