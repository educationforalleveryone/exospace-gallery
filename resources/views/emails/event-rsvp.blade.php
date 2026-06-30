<x-mail::message>
# New RSVP for your event

Someone just RSVP'd to your gallery event.

**Event:** {{ $eventTitle }}
**Starts:** {{ $eventStarts->format('M j, Y \a\t g:i A') }} ({{ $eventStarts->timezoneName }})

**Visitor:**
- Name: {{ $name }}
- Email: {{ $email }}

<x-mail::button :url="$eventsUrl">
  View all RSVPs
</x-mail::button>

This RSVP was captured automatically by Exospace. The visitor will receive a confirmation email shortly.

<x-mail::panel>
Curator tip: RSVPs are marketing assets. Reach out to attendees before the event to build rapport, and follow up after to convert them into newsletter subscribers or paying collectors.
</x-mail::panel>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
