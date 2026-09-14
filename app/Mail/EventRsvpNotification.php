<?php

namespace App\Mail;

use App\Models\Gallery;
use App\Models\GalleryScheduleEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EventRsvpNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Gallery $gallery,
        public GalleryScheduleEvent $event,
        public array $rsvp, // ['name' => string, 'email' => string]
    ) {}

    public function envelope(): \Illuminate\Mail\Envelope
    {
        return new \Illuminate\Mail\Envelope(
            subject: "New RSVP for \"{$this->event->title}\" — {$this->rsvp['name']}",
        );
    }

    public function content(): \Illuminate\Mail\Content
    {
        return new \Illuminate\Mail\Content(
            markdown: 'emails.event-rsvp',
            with: [
                'galleryName' => $this->gallery->title,
                'eventTitle'  => $this->event->title,
                'eventStarts' => $this->event->startsAtInEventTimezone(),
                'name'        => $this->rsvp['name'],
                'email'       => $this->rsvp['email'],
                'galleryUrl'  => $this->gallery->public_url,
                'eventsUrl'   => route('admin.galleries.events.index', $this->gallery),
            ],
        );
    }
}
