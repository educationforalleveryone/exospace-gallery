<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Complete - Exospace</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-white min-h-screen flex items-center justify-center">
    <div class="max-w-2xl mx-auto px-4 text-center">
        
        <!-- Success Animation -->
        <div class="mb-8">
            <div class="inline-flex items-center justify-center w-24 h-24 bg-green-600 rounded-full mb-4">
                <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h1 class="text-4xl font-bold mb-2 bg-gradient-to-r from-green-400 to-blue-500 bg-clip-text text-transparent">
                Installation Complete!
            </h1>
            <p class="text-gray-400">Exospace 3D Gallery has been successfully installed</p>
        </div>

        <!-- Installation Summary -->
        <div class="bg-gray-800 rounded-lg p-6 mb-8 text-left">
            <h2 class="text-xl font-semibold mb-4 text-green-400">✓ Installation Summary</h2>
            <div class="space-y-3 text-sm">
                <div class="flex justify-between border-b border-gray-700 pb-2">
                    <span class="text-gray-400">Database Migration</span>
                    <span class="text-green-400 font-medium">Success</span>
                </div>
                <div class="flex justify-between border-b border-gray-700 pb-2">
                    <span class="text-gray-400">Administrator Account</span>
                    <span class="text-green-400 font-medium">Created</span>
                </div>
                <div class="flex justify-between border-b border-gray-700 pb-2">
                    <span class="text-gray-400">Storage Links</span>
                    <span class="text-green-400 font-medium">Configured</span>
                </div>
                <div class="flex justify-between border-b border-gray-700 pb-2">
                    <span class="text-gray-400">Security Lock</span>
                    <span class="text-green-400 font-medium">Enabled</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-400">Installer Cleanup</span>
                    <span class="text-green-400 font-medium">Complete</span>
                </div>
            </div>
        </div>

        <!-- Login Credentials -->
        <div class="bg-blue-900/30 border border-blue-700 rounded-lg p-6 mb-8">
            <h3 class="text-lg font-semibold mb-3 text-blue-400">🔐 Administrator Credentials</h3>
            <div class="bg-gray-900 rounded p-4 text-left space-y-2">
                <div class="flex justify-between">
                    <span class="text-gray-400">Email:</span>
                    <span class="font-mono text-blue-400">{{ $admin_email }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-400">Password:</span>
                    <span class="text-gray-500 text-sm">The password you created</span>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-3">⚠️ Please save these credentials in a secure location</p>
        </div>

        <!-- Next Steps -->
        <div class="bg-gray-800 rounded-lg p-6 mb-8 text-left">
            <h3 class="text-lg font-semibold mb-3">🚀 Next Steps</h3>
            <ol class="list-decimal list-inside space-y-2 text-sm text-gray-300">
                <li>Login to your admin panel</li>
                <li>Create your first 3D gallery</li>
                <li>Upload your artwork images</li>
                <li>Customize wall textures and lighting</li>
                <li>Share the public gallery link</li>
            </ol>
        </div>

        <!-- Security Notice -->
        <div class="bg-yellow-900/30 border border-yellow-600 rounded-lg p-4 mb-8">
            <p class="text-sm text-yellow-200">
                🔒 <strong>Security Notice:</strong> The installer has been automatically removed. 
                The installation lock file is located at <code class="text-yellow-400">storage/.installed</code>
            </p>
        </div>

        <!-- Action Buttons -->
        <div class="flex gap-4 justify-center">
            <a href="{{ $login_url }}" class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 px-8 py-3 rounded-lg font-semibold transition shadow-lg">
                Go to Admin Login
            </a>
            <a href="/" class="bg-gray-700 hover:bg-gray-600 px-8 py-3 rounded-lg font-semibold transition">
                View Homepage
            </a>
        </div>

        <!-- Footer -->
        <div class="mt-12 text-sm text-gray-500">
            <p>Thank you for choosing Exospace 3D Gallery</p>
            <p class="mt-2">Need help? Check the documentation or contact support</p>
        </div>
    </div>
</body>
</html>