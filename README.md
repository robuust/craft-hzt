# HZT plugin for Craft CMS

Import Huisartsenzorg Twente events into Craft entries and submit participant registrations to the HZT API. Based on the structure of [craft-jobylon](https://github.com/robuust/craft-jobylon).

## Requirements

- Craft CMS 5.0 or later and PHP 8.2 or later.
- An HZT Passport client credentials client. The current API does not require scopes.
- An existing Craft section and entry type with a Number field for the HZT event ID.

## Installation

The plugin is currently installed directly from GitHub:

```sh
composer config repositories.hzt vcs https://github.com/robuust/craft-hzt
composer require robuust/craft-hzt:dev-develop
php craft plugin/install hzt
```

## Configuration

Set `HZT_HOST`, `HZT_CLIENT_ID`, and `HZT_CLIENT_SECRET` in your Craft project's environment. `HZT_HOST` is the API application's base URL, without `/api/v1` or `/oauth/token`. Keep credentials out of tracked files.

Create `config/hzt.php` in the consuming Craft project:

```php
<?php

use craft\helpers\App;

return [
    'host' => App::env('HZT_HOST'),
    'apiVersion' => 'v1',
    'clientId' => App::env('HZT_CLIENT_ID'),
    'clientSecret' => App::env('HZT_CLIENT_SECRET'),
    'sectionHandle' => 'events',
    'entryTypeHandle' => 'event',
    'eventIdField' => 'hztEventId', // Number
    'imagesField' => 'images', // Optional Assets field; omit to skip image import
    'fieldMapping' => [
        // Craft field handle => API response path
        'short_description' => 'course.short_description',
        'description' => 'course.description',
        'video_url' => 'course.video_url',
        'start_date' => 'start_date',
        'end_date' => 'end_date',
        'start_time' => 'start_time',
        'end_time' => 'end_time',
        'is_continuous' => 'is_continuous',
        'max_participants' => 'max_participants',
        'meetings_count' => 'meetings_count',
        'location_name' => 'location.display_name',
        'location_address' => 'location.address',
    ],
];
```

Use your actual section, entry type, and field handles. The plugin does not create sections, fields, or volumes. Only mapped fields are updated; the entry title always uses the event's `name`, and `eventIdField` always uses its `id`.

Mappings support dot-separated API paths and preserve the API values, including nulls, booleans, arrays, and ISO dates. Choose compatible field types: Date fields for dates, Lightswitch for `is_continuous`, Number for counts, and text fields for descriptions, URLs, and location text. The API's `start_time` and `end_time` are time-only strings; map them to Plain Text fields. Nullable values need optional fields. When mapping an entire object or array, its structure must match the destination field's input format.

When `imagesField` is configured, course images are downloaded into that Assets field's configured upload location. Images are matched by a SHA-256 hash of their contents against attached assets and existing assets in the same upload folder, including assets no longer attached to an entry. Duplicate images in one response are linked only once, and entries using the same upload folder can share assets. Existing upload-folder assets are indexed once per importer service instance; newly imported assets are added immediately. Old assets are unlinked when removed from the API response; their files remain in the volume. Existing duplicate assets are not deleted.

Settings use Craft's standard [plugin configuration](https://craftcms.com/docs/5.x/extend/plugin-settings.html).

## Import

```sh
php craft hzt/import
```

The importer fetches all pages from `GET /api/v1/events` before saving entries, with a hard limit of 1,000 pages (100 events per page). If the feed exceeds this limit, it throws an exception without returning a partial feed, so neither import nor cleanup proceeds. The API currently returns published, upcoming events with their course information, images, location, and effective schedule. Entries are matched by the configured HZT ID field, within the configured section and entry type. New entries are enabled; existing entries retain their enabled state. Unmapped fields remain untouched. Entry queries use Craft's current site, which is normally the primary site in the CLI.

Schedule the command using the consuming project's scheduler or cron. The interval determines how quickly website content reflects API changes.

To delete imported entries whose events have disappeared from the feed:

```sh
php craft hzt/import/cleanup
```

Cleanup is a separate command. It fetches all API pages successfully before deleting anything and considers only entries with an HZT ID in the configured section and entry type. An empty successful feed removes all matching entries. The feed excludes past, unpublished, and archived events, so their entries are also removed. Use a dedicated section for imported content.

## Participant registration

Submit forms to `hzt/participants/register` with the HZT event ID and the API's participant fields. This replaces `hzt/push/participant`; update existing form actions when upgrading. Use `csrfInput()` and `redirectInput()` so Craft can validate the submission and success redirect. API credentials stay on the server.

```twig
<form method="post">
    {{ csrfInput() }}
    {{ actionInput('hzt/participants/register') }}
    {{ redirectInput('registration/thanks') }}
    {{ hiddenInput('event_id', entry.hztEventId) }}

    {% if error is defined %}
        <p role="alert">{{ error }}</p>
    {% endif %}

    {% for messages in errors|default([]) %}
        {% for message in messages %}
            <p role="alert">{{ message }}</p>
        {% endfor %}
    {% endfor %}

    <label for="first_name">First name</label>
    <input id="first_name" name="first_name" autocomplete="given-name" required>

    <label for="last_name">Last name</label>
    <input id="last_name" name="last_name" autocomplete="family-name" required>

    <label for="email">Email</label>
    <input id="email" name="email" type="email" autocomplete="email" required>

    <label for="phone">Phone</label>
    <input id="phone" name="phone" type="tel" autocomplete="tel" required>

    <label for="date_of_birth">Date of birth</label>
    <input id="date_of_birth" name="date_of_birth" type="date" required>

    {{ hiddenInput('referrer_type', 'general-practitioner') }}

    <label for="general_practice_name">General practice</label>
    <input id="general_practice_name" name="general_practice_name" required>

    <label for="general_practitioner_name">General practitioner</label>
    <input id="general_practitioner_name" name="general_practitioner_name" required>

    <button type="submit">Register</button>
</form>
```

For a practice nurse referral, use `referrer_type=poh-ggz` and replace the two general practitioner fields with `practice_nurse_name`. Required fields and event capacity are validated by the HZT API. Failed HTML submissions expose `error` and `errors` to the original template; use `craft.app.request.getBodyParam()` to repopulate individual fields where needed.

JSON submissions receive HTTP 201 with `{"status":"registered"}` on success, and HTTP 422 with `message` and field-level `errors` for validation or capacity errors. Unknown events return 404, rate limits return 429, and connection or integration authentication failures return 502 with a generic message. Upstream request details and credentials are not returned to the browser. Registrations are not automatically retried after timeouts, because each successful API submission creates a new participant.

## API services

PHP integrations can use the same services:

```php
use robuust\hzt\Plugin;

$plugin = Plugin::getInstance();
$events = $plugin->events->getEvents();
$status = $plugin->api->request('GET', 'status');
$result = $plugin->participants->createParticipant($eventId, $participantFields);
```

Access tokens are cached separately by host and client credentials, expire shortly before the API's `expires_in`, and are renewed once after an API 401 response. API redirects are rejected to keep credentials on the configured host. Import failures stop the command; cleanup is never run implicitly.
