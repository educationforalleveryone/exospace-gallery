{{-- resources/views/pages/about.blade.php --}}
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us – Exospace Gallery</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style>
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .hero-gradient {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
        }
    </style>
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen">

    <!-- Nav -->
    <nav class="fixed w-full bg-gray-900/95 backdrop-blur-sm z-50 border-b border-gray-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <a href="/" class="text-2xl font-bold gradient-text">Exospace</a>
                <a href="/" class="text-sm text-gray-400 hover:text-gray-200 transition">← Back to Home</a>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <section class="hero-gradient pt-32 pb-16 px-4">
        <div class="max-w-4xl mx-auto text-center">
            <h1 class="text-4xl md:text-5xl font-bold mb-4">About <span class="gradient-text">Exospace</span></h1>
            <p class="text-gray-400 text-lg max-w-2xl mx-auto">We're building the future of how art is experienced online — one immersive gallery at a time.</p>
        </div>
    </section>

    <!-- Story -->
    <main class="max-w-4xl mx-auto px-4 pb-24">

        <section class="py-12 border-b border-gray-800">
            <h2 class="text-2xl font-semibold text-gray-100 mb-4">Our Story</h2>
            <div class="text-gray-300 leading-relaxed space-y-4">
                <p>Exospace was born from a simple frustration: the internet is full of stunning artwork, yet most of it is displayed in flat, lifeless image grids. We believed there had to be a better way — a way that honours the scale, the atmosphere, and the emotion of walking through a real gallery.</p>
                <p>In 2024, our team began experimenting with WebGL and Three.js to prototype what would become Exospace: a platform that transforms a simple folder of images into a fully immersive, walkable 3D exhibition. No VR headset required. No coding skills needed. Just upload and share.</p>
                <p>Today, Exospace is used by artists, photographers, curators, and creative studios around the world to present their work in ways that were previously only possible with expensive, custom software.</p>
            </div>
        </section>

        <section class="py-12 border-b border-gray-800">
            <h2 class="text-2xl font-semibold text-gray-100 mb-4">Our Mission</h2>
            <div class="text-gray-300 leading-relaxed space-y-4">
                <p>We believe that great art deserves great presentation. Our mission is to democratize immersive gallery experiences — making them accessible to every creator on the planet, regardless of technical skill or budget.</p>
                <p>We are committed to building software that is secure, reliable, and genuinely useful. Every feature we ship is designed to make your creative work shine.</p>
            </div>
        </section>

        <section class="py-12 border-b border-gray-800">
            <h2 class="text-2xl font-semibold text-gray-100 mb-6">Meet the Team</h2>
            <div class="grid sm:grid-cols-3 gap-6">
                <!-- Team Member 1 -->
                <div class="bg-gray-800 rounded-xl p-6 border border-gray-700 text-center">
                    <div class="w-20 h-20 mx-auto rounded-full bg-gradient-to-br from-purple-600 to-indigo-600 flex items-center justify-center mb-4">
                        <span class="text-2xl font-bold text-white">AK</span>
                    </div>
                    <h3 class="font-semibold text-gray-100">Ahmad Khan</h3>
                    <p class="text-purple-400 text-sm mb-2">Founder & CEO</p>
                    <p class="text-gray-400 text-sm">Vision, strategy, and making sure we never run out of coffee.</p>
                </div>
                <!-- Team Member 2 -->
                <div class="bg-gray-800 rounded-xl p-6 border border-gray-700 text-center">
                    <div class="w-20 h-20 mx-auto rounded-full bg-gradient-to-br from-indigo-600 to-blue-600 flex items-center justify-center mb-4">
                        <span class="text-2xl font-bold text-white">SR</span>
                    </div>
                    <h3 class="font-semibold text-gray-100">Sara Rahman</h3>
                    <p class="text-indigo-400 text-sm mb-2">Lead Engineer</p>
                    <p class="text-gray-400 text-sm">Builds the 3D rendering engine and keeps the platform blazing fast.</p>
                </div>
                <!-- Team Member 3 -->
                <div class="bg-gray-800 rounded-xl p-6 border border-gray-700 text-center">
                    <div class="w-20 h-20 mx-auto rounded-full bg-gradient-to-br from-blue-600 to-cyan-600 flex items-center justify-center mb-4">
                        <span class="text-2xl font-bold text-white">MJ</span>
                    </div>
                    <h3 class="font-semibold text-gray-100">Mohammed Jabbar</h3>
                    <p class="text-blue-400 text-sm mb-2">UX & Design</p>
                    <p class="text-gray-400 text-sm">Crafts the interfaces and ensures every interaction feels intuitive.</p>
                </div>
            </div>
        </section>

        <section class="py-12">
            <h2 class="text-2xl font-semibold text-gray-100 mb-4">Where We Are</h2>
            <div class="text-gray-300 leading-relaxed">
                <p>Exospace Gallery Ltd. is registered and headquartered in <strong class="text-gray-100">Islamabad, Pakistan</strong>. We are a remote-first company with team members contributing from across the globe.</p>
                <p class="mt-3 text-gray-500 text-sm">Registered Address: 27 Innovation Drive, Suite 4B, Islamabad, Islamabad Capital Territory 44000, Pakistan</p>
            </div>
        </section>
    </main>

    @include('layouts.partials.footer')
</body>
</html>