<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exospace Gallery - Turn Images into Immersive 3D Exhibitions</title>
    <script src="https://cdn.tailwindcss.com"></script>
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
        .card-hover {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(102, 126, 234, 0.3);
        }
        .pulse-glow {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: .8; }
        }
    </style>
</head>
<body class="bg-gray-900 text-gray-100">

    <!-- Navigation -->
    <nav class="fixed w-full bg-gray-900/95 backdrop-blur-sm z-50 border-b border-gray-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center">
                    <span class="text-2xl font-bold gradient-text">Exospace</span>
                </div>
                <div class="hidden md:flex items-center space-x-8">
                    <a href="#features" class="text-gray-300 hover:text-white transition">Features</a>
                    <a href="#pricing" class="text-gray-300 hover:text-white transition">Pricing</a>
                    <a href="#contact" class="text-gray-300 hover:text-white transition">Contact</a>
                </div>
                <div class="flex items-center space-x-4">
                    @auth
                        <a href="{{ route('dashboard') }}" class="text-gray-300 hover:text-white transition">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="text-gray-300 hover:text-white transition">Login</a>
                        <a href="{{ route('register') }}" class="bg-gradient-to-r from-purple-600 to-indigo-600 px-6 py-2 rounded-lg font-semibold hover:from-purple-700 hover:to-indigo-700 transition">
                            Get Started
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-gradient pt-32 pb-20 px-4">
        <div class="max-w-7xl mx-auto text-center">
            <h1 class="text-5xl md:text-7xl font-bold mb-6 leading-tight">
                Turn Images into<br>
                <span class="gradient-text">Immersive 3D Exhibitions</span>
            </h1>
            <p class="text-xl md:text-2xl text-gray-300 mb-12 max-w-3xl mx-auto">
                The easiest way for artists and galleries to create virtual museums.<br>
                No coding required. Works on any device.
            </p>
            
            <!-- CHANGE A: Hero CTA buttons -->
            <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                <a href="{{ route('register') }}" class="bg-gradient-to-r from-purple-600 to-indigo-600 px-8 py-4 rounded-lg text-lg font-semibold hover:from-purple-700 hover:to-indigo-700 transition transform hover:scale-105">
                    Start Free Trial
                </a>
                <a href="/gallery/demo" class="border border-purple-500 bg-gray-800/60 px-8 py-4 rounded-lg text-lg font-semibold hover:border-purple-400 hover:bg-gray-800 transition transform hover:scale-105">
                    🎨 View Live Demo
                </a>
            </div>
            
            <!-- CHANGE B: Hero Demo Teaser -->
            <div class="mt-16 relative">
                <a href="/gallery/demo" class="block group">
                    <div class="bg-gradient-to-br from-gray-800 to-gray-900 rounded-xl shadow-2xl border border-gray-700 group-hover:border-purple-500 transition-colors duration-300 overflow-hidden">
                        <!-- Simulated 3D Gallery Scene -->
                        <div class="aspect-video relative bg-gradient-to-br from-gray-900 via-gray-800 to-[#1a1050] flex items-center justify-center overflow-hidden">
                            <!-- Back Wall -->
                            <div class="absolute inset-0 flex items-center justify-center gap-6 px-12">
                                <!-- Frame 1 -->
                                <div class="w-1/4 aspect-[3/4] border-2 border-gray-600 rounded bg-gradient-to-br from-[#3b2f5e] to-[#1a1050] flex items-end justify-center pb-2 shadow-lg">
                                    <span class="text-xs text-gray-500">Abstract I</span>
                                </div>
                                <!-- Frame 2 (center, bigger) -->
                                <div class="w-1/3 aspect-[3/4] border-2 border-purple-600 rounded bg-gradient-to-br from-[#4a3080] to-[#2d1b69] flex items-end justify-center pb-2 shadow-xl ring-2 ring-purple-600/30">
                                    <span class="text-xs text-purple-300">The Void</span>
                                </div>
                                <!-- Frame 3 -->
                                <div class="w-1/4 aspect-[3/4] border-2 border-gray-600 rounded bg-gradient-to-br from-[#1a3060] to-[#0f2040] flex items-end justify-center pb-2 shadow-lg">
                                    <span class="text-xs text-gray-500">Cosmos</span>
                                </div>
                            </div>
                            <!-- Floor line -->
                            <div class="absolute bottom-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-gray-600 to-transparent"></div>
                            <!-- Overlay CTA -->
                            <div class="absolute inset-0 flex items-center justify-center bg-black/0 group-hover:bg-black/40 transition-colors duration-300">
                                <div class="opacity-0 group-hover:opacity-100 transition-opacity duration-300 bg-purple-600 text-white px-6 py-3 rounded-lg font-semibold shadow-lg">
                                    Enter 3D Gallery →
                                </div>
                            </div>
                        </div>
                        <!-- Caption -->
                        <div class="px-6 py-4 flex items-center justify-between">
                            <div>
                                <p class="text-gray-200 font-medium">Live Demo Gallery</p>
                                <p class="text-gray-500 text-sm">Walk through an interactive 3D exhibition — no account needed</p>
                            </div>
                            <span class="text-purple-400 text-sm font-medium group-hover:translate-x-1 transition-transform duration-200 inline-block">Try it now →</span>
                        </div>
                    </div>
                </a>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="py-20 px-4 bg-gray-900">
        <div class="max-w-7xl mx-auto">
            <div class="text-center mb-16">
                <h2 class="text-4xl md:text-5xl font-bold mb-4">Why Choose Exospace?</h2>
                <p class="text-xl text-gray-400">Everything you need to showcase art in virtual reality</p>
            </div>
            
            <div class="grid md:grid-cols-3 gap-8">
                <!-- Feature 1 -->
                <div class="bg-gray-800 p-8 rounded-xl border border-gray-700 card-hover">
                    <div class="bg-purple-600 w-14 h-14 rounded-lg flex items-center justify-center mb-6">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold mb-4">Instant Setup</h3>
                    <p class="text-gray-400">Upload your images and get a fully rendered 3D gallery in seconds. Our AI automatically arranges your artwork for optimal viewing.</p>
                </div>

                <!-- Feature 2 -->
                <div class="bg-gray-800 p-8 rounded-xl border border-gray-700 card-hover">
                    <div class="bg-indigo-600 w-14 h-14 rounded-lg flex items-center justify-center mb-6">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold mb-4">Fully Customizable</h3>
                    <p class="text-gray-400">Choose from multiple wall textures, frame styles, lighting presets, and floor materials. Make it yours with complete creative control.</p>
                </div>

                <!-- Feature 3 -->
                <div class="bg-gray-800 p-8 rounded-xl border border-gray-700 card-hover">
                    <div class="bg-blue-600 w-14 h-14 rounded-lg flex items-center justify-center mb-6">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <h3 class="text-2xl font-bold mb-4">Works Everywhere</h3>
                    <p class="text-gray-400">Desktop, mobile, tablet, or VR headset - your galleries work seamlessly across all devices using cutting-edge WebGL technology.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section id="pricing" class="py-20 px-4 bg-gray-800">
        <div class="max-w-7xl mx-auto">
            <div class="text-center mb-16">
                <h2 class="text-4xl md:text-5xl font-bold mb-4">Simple, Transparent Pricing</h2>
                <p class="text-xl text-gray-400">Choose the plan that works for you</p>
            </div>
            
            <div class="grid md:grid-cols-3 gap-8">
                <!-- Free Plan -->
                <div class="bg-gray-900 p-8 rounded-xl border border-gray-700">
                    <h3 class="text-2xl font-bold mb-2">Free Trial</h3>
                    <div class="mb-6">
                        <span class="text-5xl font-bold">$0</span>
                        <span class="text-gray-400">/month</span>
                    </div>
                    <ul class="space-y-4 mb-8">
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>1 Gallery</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Up to 10 Images</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Basic Customization</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Public Gallery Link</span>
                        </li>
                    </ul>
                    <a href="{{ route('register') }}" class="block w-full bg-gray-700 text-center px-6 py-3 rounded-lg font-semibold hover:bg-gray-600 transition">
                        Sign Up Free
                    </a>
                </div>

                <!-- Pro Plan -->
                <div class="bg-gradient-to-br from-purple-900 to-indigo-900 p-8 rounded-xl border-2 border-purple-500 relative">
                    <div class="absolute -top-4 left-1/2 transform -translate-x-1/2">
                        <span class="bg-purple-500 px-4 py-1 rounded-full text-sm font-semibold pulse-glow">MOST POPULAR</span>
                    </div>
                    <h3 class="text-2xl font-bold mb-2">Professional</h3>
                    <div class="mb-6">
                        <span class="text-5xl font-bold">$29</span>
                        <span class="text-gray-300">/month</span>
                    </div>
                    <ul class="space-y-4 mb-8">
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-400 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Unlimited Galleries</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-400 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Unlimited Images</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-400 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Custom Domain Support</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-400 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Advanced Analytics</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-400 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Priority Support</span>
                        </li>
                    </ul>
                    <a href="{{ route('register') }}" class="block w-full bg-white text-purple-900 text-center px-6 py-3 rounded-lg font-semibold hover:bg-gray-100 transition">
                        Start 14-Day Trial
                    </a>
                </div>

                <!-- Enterprise Plan -->
                <div class="bg-gray-900 p-8 rounded-xl border border-gray-700">
                    <h3 class="text-2xl font-bold mb-2">Enterprise</h3>
                    <div class="mb-6">
                        <span class="text-5xl font-bold">Custom</span>
                    </div>
                    <ul class="space-y-4 mb-8">
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>White-label Solution</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Self-hosted Options</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Custom Integrations</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>Dedicated Support</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-6 h-6 text-green-500 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span>SLA Guarantee</span>
                        </li>
                    </ul>
                    <a href="#contact" class="block w-full bg-gray-700 text-center px-6 py-3 rounded-lg font-semibold hover:bg-gray-600 transition">
                        Contact Sales
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="py-20 px-4 bg-gray-900">
        <div class="max-w-4xl mx-auto text-center">
            <h2 class="text-4xl md:text-5xl font-bold mb-6">Ready to Get Started?</h2>
            <p class="text-xl text-gray-400 mb-8">Join hundreds of artists and galleries already using Exospace</p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                <a href="{{ route('register') }}" class="bg-gradient-to-r from-purple-600 to-indigo-600 px-8 py-4 rounded-lg text-lg font-semibold hover:from-purple-700 hover:to-indigo-700 transition transform hover:scale-105">
                    Start Your Free Trial
                </a>
                <a href="mailto:support@exospace.gallery" class="border border-gray-600 px-8 py-4 rounded-lg text-lg font-semibold hover:border-purple-500 hover:bg-gray-800 transition">
                    Contact Support
                </a>
            </div>
            <p class="text-gray-500 mt-6">Questions? Email us at <a href="mailto:support@exospace.gallery" class="text-purple-400 hover:text-purple-300">support@exospace.gallery</a></p>
        </div>
    </section>

    <!-- CHANGE C: Replace Footer with includes -->
    <!-- Cookie Banner -->
    @include('layouts.partials.cookie-banner')

    <!-- Footer -->
    @include('layouts.partials.footer')

</body>
</html>