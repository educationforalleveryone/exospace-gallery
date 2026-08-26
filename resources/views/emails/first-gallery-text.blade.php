EXOSPACE

Your gallery "{{ $gallery->title }}" is ready!

Great work, {{ $user->name }}! You've created your first 3D gallery on Exospace. It starts as a private draft — now let's hang some artwork and publish it.

Next steps:
1. Upload your images — JPEG, PNG, or WebP up to 10MB each
2. Add titles, prices and artist credits to each artwork
3. Preview the exhibition in 3D, then hit "Publish" to make it public
4. Share the link with your audience

UPLOAD YOUR ARTWORK:

{{ config('app.url') }}/admin/galleries/{{ $gallery->id }}/edit

Need help? Reply to this email or visit our Help Center: {{ config('app.url') }}/contact

---

© {{ date('Y') }} Exospace Gallery. All rights reserved.
Manage your billing: {{ config('app.url') }}/billing
