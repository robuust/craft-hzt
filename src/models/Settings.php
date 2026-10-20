<?php

namespace robuust\hzt\models;

use craft\base\Model;

/**
 * API credentials and Craft entry field mapping.
 */
class Settings extends Model
{
    public string $host = '';
    public string $apiVersion = 'v1';
    public string $clientId = '';
    public string $clientSecret = '';
    public string $sectionHandle = '';
    public string $entryTypeHandle = '';
    public string $eventIdField = '';
    public string $imagesField = '';

    /**
     * @var array<string, string> craft field handles mapped to API paths
     */
    public array $fieldMapping = [];

    /**
     * Validate the API connection and required import configuration.
     *
     * @return array<int, array<mixed>>
     */
    public function rules(): array
    {
        return [
            [['host', 'apiVersion', 'clientId', 'clientSecret', 'sectionHandle', 'entryTypeHandle', 'eventIdField'], 'required'],
            [['host'], 'url', 'validSchemes' => ['https', 'http']],
        ];
    }
}
