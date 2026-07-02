EXOSPACE

Your gallery "{{ $gallery->title }}" is ready!

Great work, {{ $user->name }}! You've created your first 3D gallery on Exospace. Now let's add some artwork and make it live.

Next steps:
1. Upload your images — JPEG, PNG, or WebP up to 10MB each
2. Pick a venue (White Cube, Industrial Loft, Dark Museum, and more)
3. Toggle "Active" to publish your gallery
4. Share the link with your audience

UPLOAD YOUR ARTWORK:

{{ config('app.url') }}/admin/galleries/{{ $gallery->id }}/edit

Need help? Reply to this email or visit our Help Center: {{ config('app.url') }}/contact

---

© {{ date('Y') }} Exospace Gallery. All rights reserved.
Manage your billing: {{ config('app.url') }}/billing
