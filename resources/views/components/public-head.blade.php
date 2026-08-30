{{--
    Public page head (Task H09 / audit H34).

    Replaces cdn.tailwindcss.com + cdn.jsdelivr.net Alpine on standalone
    marketing/legal pages with the Vite-built CSS + JS from the admin app.

    Usage (at the top of a standalone page's <head>):
        <x-public-head
            title="Pricing — Exospace"
            description="..."
        />

    This component outputs:
      - charset, viewport, csrf-token
      - <x-seo> for title/description/canonical/OG/Twitter
      - Inter font from Bunny Fonts
      - @vite(['resources/css/app.css', 'resources/js/app.js'])
      - favicon + theme-color
      - global styles (smooth scroll, reduced-motion, focus-visible)

    It does NOT output the <nav>, <footer>, or <main> wrapper — those
    are page-specific. Use <x-public-layout> for the full shell, or
    include this component in a standalone page that manages its own
    nav/footer.
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">

<x-seo
    title="{{ $title ?? config('app.name', 'Exospace') }}"
    description="{{ $description ?? 'Create museum-quality 3D art exhibitions in minutes. Upload your images, pick a venue, share a link. Free to start.' }}"
    canonical-url="{{ $canonical ?? url()->current() }}"
/>

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])

<link rel="icon" href="{{ asset('favicon.ico') }}">
<meta name="theme-color" content="#0f1117">

<style>
    html { scroll-behavior: smooth; }
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
    }
    /* ITERATION-4: the *:focus-visible rule here duplicated (and slightly
       diverged from) app.css — the stylesheet is the single owner of the
       global focus ring. */
</style>
