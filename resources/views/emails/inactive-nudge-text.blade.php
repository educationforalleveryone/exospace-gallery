EXOSPACE

Ready to create your first exhibition?

Hi {{ $user->name }},

You joined Exospace a while ago but haven't published a gallery yet. Creating your first 3D exhibition takes just a few minutes:

1. Upload your images
2. Pick a venue (White Cube, Industrial Loft, Dark Museum, and more)
3. Toggle "Active" to publish
4. Share the link with your audience

That's it — no coding required. Your visitors get an immersive, walkable 3D gallery that works on any device.

CREATE YOUR FIRST GALLERY:

{{ config('app.url') }}/admin/galleries/create

Need help? Reply to this email or visit our Help Center: {{ config('app.url') }}/contact

---

You're receiving this email because you signed up for Exospace and opted in to product tips.
Unsubscribe: {{ \Illuminate\Support\Facades\URL::signedRoute('unsubscribe.show', ['user' => $user->id]) }}

© {{ date('Y') }} Exospace Gallery. All rights reserved.
@if(config('app.business_address'))
{{ config('app.business_address') }}
@endif
Manage your billing: {{ config('app.url') }}/billing
