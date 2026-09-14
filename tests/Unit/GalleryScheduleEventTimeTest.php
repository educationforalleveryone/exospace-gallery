<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\GalleryScheduleEvent;
use Tests\TestCase;

class GalleryScheduleEventTimeTest extends TestCase
{
    public function test_from_event_local_time_normalises_declared_wall_clock_to_utc(): void
    {
        $utc = GalleryScheduleEvent::fromEventLocalTime('2026-07-01T18:00', 'America/New_York');

        $this->assertSame('2026-07-01 22:00', $utc->format('Y-m-d H:i'));
        $this->assertSame('UTC', $utc->timezoneName);
    }

    public function test_from_event_local_time_respects_explicit_offsets(): void
    {
        $utc = GalleryScheduleEvent::fromEventLocalTime('2026-07-01T18:00:00+02:00', 'America/New_York');

        $this->assertSame('2026-07-01 16:00', $utc->format('Y-m-d H:i'));
    }

    public function test_from_event_local_time_falls_back_to_utc_for_unknown_identifiers(): void
    {
        $utc = GalleryScheduleEvent::fromEventLocalTime('2026-07-01T18:00', 'Mars/Olympus');

        $this->assertSame('2026-07-01 18:00', $utc->format('Y-m-d H:i'));
    }

    public function test_resolve_timezone_handles_blank_values(): void
    {
        $this->assertSame('UTC', GalleryScheduleEvent::resolveTimezone(null)->getName());
        $this->assertSame('UTC', GalleryScheduleEvent::resolveTimezone('')->getName());
        $this->assertSame('Europe/Berlin', GalleryScheduleEvent::resolveTimezone('Europe/Berlin')->getName());
    }

    public function test_render_accessors_convert_without_mutating_the_model(): void
    {
        $event = new GalleryScheduleEvent([
            'starts_at' => '2026-07-01 22:00:00',
            'ends_at'   => '2026-07-02 01:00:00',
            'timezone'  => 'America/New_York',
        ]);

        $start = $event->startsAtInEventTimezone();
        $end = $event->endsAtInEventTimezone();

        $this->assertSame('2026-07-01 18:00', $start->format('Y-m-d H:i'));
        $this->assertSame('2026-07-01 21:00', $end->format('Y-m-d H:i'));
        $this->assertSame('2026-07-01 22:00', $event->starts_at->format('Y-m-d H:i'), 'the stored instant stays untouched');
    }

    public function test_schedule_label_renders_a_same_day_range_without_repeating_the_day(): void
    {
        $event = new GalleryScheduleEvent([
            'starts_at' => '2026-07-01 22:00:00', // 6:00 PM EDT
            'ends_at'   => '2026-07-02 01:00:00', // 9:00 PM EDT, same day
            'timezone'  => 'America/New_York',
        ]);

        $label = $event->scheduleLabel();

        $this->assertSame('Wednesday, July 1, 2026 at 6:00 PM EDT – 9:00 PM EDT', $label);
    }

    public function test_schedule_label_repeats_the_day_for_multi_day_ranges(): void
    {
        $event = new GalleryScheduleEvent([
            'starts_at' => '2026-07-02 03:00:00', // 11:00 PM EDT on July 1
            'ends_at'   => '2026-07-03 04:00:00', // 12:00 AM EDT on July 3
            'timezone'  => 'America/New_York',
        ]);

        $this->assertSame(
            'Wednesday, July 1, 2026 at 11:00 PM EDT – Friday, July 3, 2026 at 12:00 AM EDT',
            $event->scheduleLabel(),
        );
    }

    public function test_schedule_label_handles_unknown_stored_timezones(): void
    {
        $event = new GalleryScheduleEvent([
            'starts_at' => '2026-07-01 18:00:00',
            'timezone'  => 'Not/AZone',
        ]);

        $this->assertSame('Wednesday, July 1, 2026 at 6:00 PM UTC', $event->scheduleLabel());
    }

    public function test_external_location_url_accepts_only_http_schemes(): void
    {
        $event = new GalleryScheduleEvent(['location_url' => 'https://zoom.us/j/1']);
        $this->assertSame('https://zoom.us/j/1', $event->externalLocationUrl());

        $event = new GalleryScheduleEvent(['location_url' => 'http://meet.example.com/room']);
        $this->assertSame('http://meet.example.com/room', $event->externalLocationUrl());

        $event = new GalleryScheduleEvent(['location_url' => 'javascript:alert(1)']);
        $this->assertNull($event->externalLocationUrl());

        $event = new GalleryScheduleEvent(['location_url' => 'ftp://files.example.com/talk']);
        $this->assertNull($event->externalLocationUrl());

        $event = new GalleryScheduleEvent(['location_url' => null]);
        $this->assertNull($event->externalLocationUrl());
    }
}
