{{-- resources/views/layouts/partials/footer.blade.php --}}
<footer class="bg-gray-950 border-t border-gray-800 py-12 px-4">
    <div class="max-w-7xl mx-auto">
        <div class="grid md:grid-cols-5 gap-8 mb-8">
            <!-- Brand -->
            <div class="md:col-span-2">
                <h3 class="text-xl font-bold gradient-text mb-4">Exospace</h3>
                <p class="text-gray-400 text-sm">Creating immersive 3D gallery experiences for the modern web. Built for artists, curators, and galleries.</p>
                <div class="mt-4 flex items-center gap-3">
                    <span class="inline-flex items-center gap-1 bg-gray-800 border border-gray-700 text-green-400 text-xs font-semibold px-2.5 py-1 rounded">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 1a3 3 0 00-3 3v8a3 3 0 006 0V4a3 3 0 00-3-3zM19 10v2a7 7 0 01-14 0v-2m7 7v4m-4 0h8" /></svg>
                        SSL Secured
                    </span>
                    <span class="inline-flex items-center gap-1 bg-gray-800 border border-gray-700 text-indigo-400 text-xs font-semibold px-2.5 py-1 rounded">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                        Powered by 2Checkout
                    </span>
                </div>
            </div>
            <!-- Product -->
            <div>
                <h4 class="font-semibold mb-4 text-gray-200">Product</h4>
                <ul class="space-y-2 text-gray-400 text-sm">
                    <li><a href="#features" class="hover:text-white transition">Features</a></li>
                    <li><a href="#pricing" class="hover:text-white transition">Pricing</a></li>
                    <li><a href="/gallery/demo" class="hover:text-white transition">Live Demo</a></li>
                    <li><a href="{{ route('register') }}" class="hover:text-white transition">Get Started</a></li>
                </ul>
            </div>
            <!-- Company -->
            <div>
                <h4 class="font-semibold mb-4 text-gray-200">Company</h4>
                <ul class="space-y-2 text-gray-400 text-sm">
                    <li><a href="/about" class="hover:text-white transition">About Us</a></li>
                    <li><a href="#contact" class="hover:text-white transition">Contact</a></li>
                    <li><a href="mailto:support@exospace.gallery" class="hover:text-white transition">Support</a></li>
                </ul>
            </div>
            <!-- Legal -->
            <div>
                <h4 class="font-semibold mb-4 text-gray-200">Legal</h4>
                <ul class="space-y-2 text-gray-400 text-sm">
                    <li><a href="/privacy" class="hover:text-white transition">Privacy Policy</a></li>
                    <li><a href="/terms" class="hover:text-white transition">Terms of Service</a></li>
                    <li><a href="/refund-policy" class="hover:text-white transition">Refund Policy</a></li>
                    <li><a href="/payment-security" class="hover:text-white transition">Payment Security</a></li>
                </ul>
            </div>
        </div>

        <!-- Bottom Bar -->
        <div class="border-t border-gray-800 pt-8 flex flex-col md:flex-row md:items-start justify-between gap-4">
            <div class="text-gray-500 text-sm">
                <p>&copy; {{ date('Y') }} Exospace Gallery Ltd. All rights reserved.</p>
                <p class="mt-1">Registered Address: 27 Innovation Drive, Suite 4B, Islamabad, Islamabad Capital Territory 44000, Pakistan</p>
            </div>
            <div class="text-gray-500 text-sm text-left md:text-right">
                <p>Support: <a href="mailto:support@exospace.gallery" class="text-purple-400 hover:text-purple-300 transition">support@exospace.gallery</a></p>
                <p class="mt-1">Phone: <a href="tel:+92311234567890" class="text-purple-400 hover:text-purple-300 transition">+92 311 234 5678</a></p>
            </div>
        </div>
    </div>
</footer>