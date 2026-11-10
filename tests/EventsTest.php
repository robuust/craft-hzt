<?php

namespace robuust\hzt\tests;

use Craft;
use craft\config\GeneralConfig;
use craft\console\Application;
use craft\elements\Entry;
use craft\i18n\I18N;
use craft\services\Config;
use craft\services\Elements;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use robuust\hzt\models\Settings;
use robuust\hzt\services\Events;

class EventsTest extends TestCase
{
    private mixed $previousApp;

    /**
     * Preserve the application's global reference before each isolated import.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousApp = Craft::$app;
    }

    /**
     * Leave any surrounding Craft application intact.
     */
    protected function tearDown(): void
    {
        Craft::$app = $this->previousApp;

        parent::tearDown();
    }

    /**
     * Import linked course content separately from the calendar title and preserve raw API values.
     *
     * @param string $date
     * @param string $schedule
     */
    #[DataProvider('continuousSchedules')]
    public function testImportsDutchWeeklyScheduleAndLinkedCourseName(string $date, string $schedule): void
    {
        $event = $this->event(['start_date' => $date]);
        [$entry, $fields] = $this->importEvent($event);

        self::assertSame('Calendar title for the Tuesday group', $entry->title);
        self::assertSame('Linked course name', $fields['course_name']);
        self::assertSame($schedule, $fields['schedule']);
        self::assertSame($date, $fields['start_date']);
        self::assertSame('09:00:00', $fields['start_time']);
        self::assertSame('10:00:00', $fields['end_time']);
        self::assertTrue($fields['is_continuous']);
        self::assertSame(355, $fields['event_id']);
    }

    /**
     * Cover both Dutch weekdays and source offsets across winter and summer time.
     *
     * @return array<string, array{string, string}>
     */
    public static function continuousSchedules(): array
    {
        return [
            'winter Tuesday' => ['2026-11-10T00:00:00+01:00', 'Elke dinsdag, van 09:00 tot 10:00'],
            'summer Sunday' => ['2026-06-07T00:00:00+02:00', 'Elke zondag, van 09:00 tot 10:00'],
        ];
    }

    /**
     * Format the effective fixed meeting date and include only a complete time range.
     *
     * @param string|null $startTime
     * @param string|null $endTime
     * @param string      $schedule
     */
    #[DataProvider('fixedSchedules')]
    public function testFormatsFixedScheduleWithoutShiftingTheCalendarDate(?string $startTime, ?string $endTime, string $schedule): void
    {
        $event = $this->event([
            'is_continuous' => false,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
        [, $fields] = $this->importEvent($event);

        self::assertSame($schedule, $fields['schedule']);
        self::assertSame($event['start_date'], $fields['start_date']);
        self::assertSame($startTime, $fields['start_time']);
        self::assertSame($endTime, $fields['end_time']);
        self::assertFalse($fields['is_continuous']);
    }

    /**
     * Preserve valid date-only schedules when legacy meetings lack either time.
     *
     * @return array<string, array{string|null, string|null, string}>
     */
    public static function fixedSchedules(): array
    {
        return [
            'complete range' => ['09:00:00', '10:00:00', '10 november 2026, van 09:00 tot 10:00'],
            'missing start' => [null, '10:00:00', '10 november 2026'],
            'missing end' => ['09:00:00', null, '10 november 2026'],
            'missing both' => [null, null, '10 november 2026'],
        ];
    }

    /**
     * Supply the API's effective schedule and distinct calendar and course names.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function event(array $overrides = []): array
    {
        return array_replace([
            'id' => 355,
            'name' => 'Calendar title for the Tuesday group',
            'course' => ['name' => 'Linked course name'],
            'is_continuous' => true,
            'start_date' => '2026-11-10T00:00:00+01:00',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ], $overrides);
    }

    /**
     * Exercise the real importer and Craft formatter while isolating entry lookup and persistence.
     *
     * @param array<string, mixed> $event
     *
     * @return array{Entry, array<string, mixed>}
     */
    private function importEvent(array $event): array
    {
        $fields = [];
        $entry = $this->getMockBuilder(Entry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setFieldValue'])
            ->getMock();
        $entry->expects(self::atLeastOnce())->method('setFieldValue')->willReturnCallback(function (string $handle, mixed $value) use (&$fields): void {
            $fields[$handle] = $value;
        });

        $elements = $this->createMock(Elements::class);
        $elements->expects(self::once())->method('saveElement')->with($entry)->willReturn(true);

        $config = $this->createStub(Config::class);
        $config->method('getGeneral')->willReturn(new GeneralConfig());

        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getI18n', 'getConfig', 'getElements', 'getTimeZone'])
            ->getMock();
        $app->language = 'en-US';
        Craft::$app = $app;
        $app->method('getTimeZone')->willReturn('America/Los_Angeles');
        $app->expects(self::atLeastOnce())->method('getI18n')->willReturn(new I18N());
        $app->method('getConfig')->willReturn($config);
        $app->method('getElements')->willReturn($elements);

        $settings = new Settings([
            'eventIdField' => 'event_id',
            'fieldMapping' => [
                'course_name' => 'course.name',
                'schedule' => 'schedule',
                'start_date' => 'start_date',
                'start_time' => 'start_time',
                'end_time' => 'end_time',
                'is_continuous' => 'is_continuous',
            ],
        ]);
        $importer = $this->getMockBuilder(Events::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEntry'])
            ->getMock();
        $importer->expects(self::once())->method('getEntry')->with($event['id'])->willReturn($entry);
        (new ReflectionProperty(Events::class, 'settings'))->setValue($importer, $settings);

        return [$importer->importEvent($event), $fields];
    }
}
