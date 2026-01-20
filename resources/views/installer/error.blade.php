<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Error - Exospace</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-white min-h-screen flex items-center justify-center p-4">
    <div class="max-w-2xl mx-auto">
        
        <!-- Error Icon -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-24 h-24 bg-red-600 rounded-full mb-4">
                <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </div>
            <h1 class="text-4xl font-bold mb-2 text-red-500">Installation Failed</h1>
            <p class="text-gray-400">An error occurred during the installation process</p>
        </div>

        <!-- Error Details -->
        <div class="bg-red-900/30 border border-red-700 rounded-lg p-6 mb-6">
            <h3 class="text-lg font-semibold mb-3 text-red-400">Error Details:</h3>
            <div class="bg-gray-900 rounded p-4">
                <code class="text-red-300 text-sm">{{ $error }}</code>
            </div>
        </div>

        @if($trace)
        <!-- Stack Trace (Debug Mode) -->
        <details class="bg-gray-800 rounded-lg p-4 mb-6">
            <summary class="cursor-pointer font-semibold text-gray-300 mb-2">View Stack Trace (Debug Mode)</summary>
            <pre class="text-xs text-gray-500 overflow-auto max-h-64 p-4 bg-gray-900 rounded"><code>{{ $trace }}</code></pre>
        </details>
        @endif

        <!-- Recovery Steps -->
        <div class="bg-gray-800 rounded-lg p-6 mb-6">
            <h3 class="text-lg font-semibold mb-3 text-yellow-400">🔧 Recovery Steps:</h3>
            <ol class="list-decimal list-inside space-y-2 text-sm text-gray-300">
                <li>Check that your database credentials are correct</li>
                <li>Ensure your database user has CREATE, ALTER, and DROP permissions</li>
                <li>Verify that the database exists and is empty</li>
                <li>Check file permissions on <code class="text-blue-400">storage/</code> and <code class="text-blue-400">bootstrap/cache/</code></li>
                <li>Review PHP error logs for additional details</li>
                <li>Ensure all required PHP extensions are installed</li>
            </ol>
        </div>

        <!-- Common Issues -->
        <div class="bg-blue-900/30 border border-blue-700 rounded-lg p-6 mb-6">
            <h3 class="text-lg font-semibold mb-3 text-blue-400">💡 Common Issues:</h3>
            <ul class="list-disc list-inside space-y-2 text-sm text-gray-300">
                <li><strong>Database Connection Failed:</strong> Check host, port, username, and password</li>
                <li><strong>Access Denied:</strong> Ensure database user has sufficient privileges</li>
                <li><strong>Permission Denied:</strong> Run <code class="text-blue-400">chmod -R 775 storage bootstrap/cache</code></li>
                <li><strong>Migration Failed:</strong> Database might not be empty - try a fresh database</li>
            </ul>
        </div>

        <!-- Action Buttons -->
        <div class="flex gap-4 justify-center">
            <a href="/install" class="bg-blue-600 hover:bg-blue-700 px-8 py-3 rounded-lg font-semibold transition">
                ← Try Again
            </a>
            <a href="https://support.example.com" target="_blank" class="bg-gray-700 hover:bg-gray-600 px-8 py-3 rounded-lg font-semibold transition">
                Contact Support
            </a>
        </div>

        <!-- Automatic Rollback Notice -->
        <div class="mt-8 text-center text-sm text-gray-500">
            <p>⚠️ Installation has been rolled back to prevent partial installation.</p>
            <p class="mt-1">The .env file has been removed. You can safely restart the installation.</p>
        </div>

    </div>
</body>
</html>