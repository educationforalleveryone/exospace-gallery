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
</style>
