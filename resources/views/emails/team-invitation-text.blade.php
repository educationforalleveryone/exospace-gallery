EXOSPACE

Hi there,

{{ $invitation->team->owner->name }} has invited you to join the team "{{ $invitation->team->name }}" on Exospace as {{ ucfirst($invitation->role) }}.
@if($invitation->team->description)
"{{ $invitation->team->description }}"
@endif

As an {{ $invitation->role }}, you'll be able to
@if($invitation->role === 'editor')
create and manage galleries within this team.
@else
view all galleries in this team.
@endif

View and respond to the invitation:
{{ $invitationLink }}

This invitation expires on {{ $invitation->expires_at->format('F j, Y \a\t g:i A') }}.

If you don't have an Exospace account yet, you'll be prompted to create one after clicking Accept. Make sure to register with this email address: {{ $invitation->email }}

You received this because {{ $invitation->email }} was invited to join a team. If you weren't expecting this, you can safely ignore this email.

{{ config('app.name') }}
