<?php
/**
 * Exospace 3D Gallery - Web Installer
 * Version: 1.0.2 - Path & Session Fixed
 * Built for Envato Marketplace
 */
session_start();
// ============================================
// CONFIGURATION
// ============================================
define('INSTALL_LOCK_FILE', __DIR__ . '/../../storage/.installed');
define('ENV_FILE', __DIR__ . '/../../.env');
define('ENV_EXAMPLE_FILE', __DIR__ . '/../../.env.example');
// ============================================
// SECURITY: Prevent Re-installation
// ============================================
if (file_exists(INSTALL_LOCK_FILE)) {
    die('
    <!DOCTYPE html>
    <html>
    <head>
        <title>Already Installed</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-gray-900 text-white h-screen flex items-center justify-center">
        <div class="text-center">
            <h1 class="text-4xl font-bold text-red-500 mb-4">⚠️ Already Installed</h1>
            <p class="text-gray-400 mb-4">Exospace has already been installed on this server.</p>
            <p class="text-sm text-gray-600">To reinstall, delete the file: <code>storage/.installed</code></p>
            <a href="/" class="mt-6 inline-block bg-blue-600 px-6 py-2 rounded">Go to Application</a>
        </div>
    </body>
    </html>
    ');
}
// ============================================
// INSTALLATION STEPS
// ============================================
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$errors = [];
$success = '';
// ============================================
// STEP 1: REQUIREMENTS CHECK
// ============================================
if ($step === 1) {
    $requirements = [
        'PHP 8.2+' => version_compare(PHP_VERSION, '8.2.0', '>='),
        'BCMath Extension' => extension_loaded('bcmath'),
        'Ctype Extension' => extension_loaded('ctype'),
        'Fileinfo Extension' => extension_loaded('fileinfo'),
        'JSON Extension' => extension_loaded('json'),
        'Mbstring Extension' => extension_loaded('mbstring'),
        'OpenSSL Extension' => extension_loaded('openssl'),
        'PDO Extension' => extension_loaded('pdo'),
        'PDO SQLite Driver' => extension_loaded('pdo_sqlite'),
        'Tokenizer Extension' => extension_loaded('tokenizer'),
        'XML Extension' => extension_loaded('xml'),
        'GD Extension' => extension_loaded('gd'),
        'cURL Extension' => extension_loaded('curl'),
    ];
    $permissions = [
        'storage/' => is_writable(__DIR__ . '/../../storage'),
        'bootstrap/cache/' => is_writable(__DIR__ . '/../../bootstrap/cache'),
        'database/' => is_writable(__DIR__ . '/../../database'),
        'public/assets/' => is_writable(__DIR__ . '/../assets'),
    ];
    $allRequirementsMet = !in_array(false, $requirements);
    $allPermissionsOk = !in_array(false, $permissions);
}
// ============================================
// STEP 2: DATABASE CONFIGURATION
// ============================================
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbType = $_POST['db_type'] ?? 'mysql';
    $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $appUrl = rtrim(trim($_POST['app_url'] ?? ''), '/');
    $appName = trim($_POST['app_name'] ?? 'Exospace');
    // Validation
    if (empty($dbName)) {
        $errors[] = 'Database name is required.';
    }
    if ($dbType !== 'sqlite' && empty($dbUser)) {
        $errors[] = 'Database username is required for MySQL.';
    }
    if (empty($appUrl)) {
        $errors[] = 'Application URL is required.';
    }
    // Test Database Connection
    if (empty($errors)) {
        try {
            if ($dbType === 'sqlite') {
                // SQLite Connection
                $dbPath = __DIR__ . '/../../database/' . $dbName;
                
                // Create file if it doesn't exist
                if (!file_exists($dbPath)) {
                    touch($dbPath);
                    chmod($dbPath, 0644);
                }
                
                $dsn = "sqlite:$dbPath";
                $pdo = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                
                // Test write capability
                $pdo->exec("CREATE TABLE IF NOT EXISTS test_table (id INTEGER)");
                $pdo->exec("DROP TABLE test_table");
                
                // Test if database is empty (SQLite check)
                $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll();
                if (count($tables) > 0) {
                    $errors[] = 'Warning: Database is not empty. Installation may fail if tables already exist.';
                }
                
            } else {
                // MySQL/MariaDB Connection
                $dsn = "$dbType:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                
                // Test if database is empty or ready (MySQL check)
                $tables = $pdo->query("SHOW TABLES")->fetchAll();
                if (count($tables) > 0) {
                    $errors[] = 'Warning: Database is not empty. Installation may fail if tables already exist.';
                }
            }
            
            if (empty($errors)) {
                // Connection successful - store in session
                $_SESSION['db_config'] = [
                    'type' => $dbType,
                    'host' => $dbHost,
                    'port' => $dbPort,
                    'name' => $dbName,
                    'user' => $dbUser,
                    'pass' => $dbPass,
                    'app_url' => $appUrl,
                    'app_name' => $appName,
                ];
                
                header('Location: ?step=3');
                exit;
            }
            
        } catch (PDOException $e) {
            $errors[] = 'Database Connection Failed: ' . $e->getMessage();
            if ($dbType === 'mysql') {
                $errors[] = 'Tip: If you don\'t have MySQL installed locally, select SQLite instead.';
            }
        }
    }
}
// ============================================
// STEP 3: LICENSE & ADMIN ACCOUNT
// ============================================
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $purchaseCode = trim($_POST['purchase_code'] ?? '');
    $adminName = trim($_POST['admin_name'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPassword = $_POST['admin_password'] ?? '';
    $adminPasswordConfirm = $_POST['admin_password_confirm'] ?? '';
    $installDemo = isset($_POST['install_demo']);
    // Validation
    if (empty($purchaseCode) || strlen($purchaseCode) < 20) {
        $errors[] = 'Valid Envato Purchase Code is required (min 20 characters).';
    }
    if (empty($adminName)) {
        $errors[] = 'Admin name is required.';
    }
    if (empty($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid admin email is required.';
    }
    if (empty($adminPassword) || strlen($adminPassword) < 8) {
        $errors[] = 'Admin password must be at least 8 characters.';
    }
    if ($adminPassword !== $adminPasswordConfirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (empty($errors)) {
        $_SESSION['admin_config'] = [
            'name' => $adminName,
            'email' => $adminEmail,
            'password' => $adminPassword,
            'purchase_code' => $purchaseCode,
            'install_demo' => $installDemo,
        ];
        
        header('Location: ?step=4');
        exit;
    }
}
// ============================================
// STEP 4: INSTALLATION PROCESS
// ============================================
if ($step === 4) {
    if (!isset($_SESSION['db_config']) || !isset($_SESSION['admin_config'])) {
        header('Location: ?step=1');
        exit;
    }
    $dbConfig = $_SESSION['db_config'];
    $adminConfig = $_SESSION['admin_config'];
    try {
        // 1. Read .env.example
        if (!file_exists(ENV_EXAMPLE_FILE)) {
            throw new Exception('.env.example file not found!');
        }
        
        $envContent = file_get_contents(ENV_EXAMPLE_FILE);
        // 2. Replace App Configuration
        $envContent = preg_replace('/APP_NAME=.*/m', "APP_NAME=\"{$dbConfig['app_name']}\"", $envContent);
        $envContent = preg_replace('/APP_URL=.*/m', "APP_URL={$dbConfig['app_url']}", $envContent);
        $envContent = preg_replace('/APP_ENV=.*/m', 'APP_ENV=production', $envContent);
        $envContent = preg_replace('/APP_DEBUG=.*/m', 'APP_DEBUG=false', $envContent);
        // 3. Replace Database Configuration
        $envContent = preg_replace('/DB_CONNECTION=.*/m', "DB_CONNECTION={$dbConfig['type']}", $envContent);
        
        if ($dbConfig['type'] === 'sqlite') {
            // SQLite: Comment out MySQL fields, set DB_DATABASE path
            $dbPath = __DIR__ . '/../../database/' . $dbConfig['name'];
            $envContent = preg_replace('/DB_DATABASE=.*/m', "DB_DATABASE=\"{$dbPath}\"", $envContent);
            $envContent = preg_replace('/# DB_HOST=.*/m', '# DB_HOST=127.0.0.1', $envContent);
            $envContent = preg_replace('/# DB_PORT=.*/m', '# DB_PORT=3306', $envContent);
            $envContent = preg_replace('/# DB_USERNAME=.*/m', '# DB_USERNAME=root', $envContent);
            $envContent = preg_replace('/# DB_PASSWORD=.*/m', '# DB_PASSWORD=', $envContent);
        } else {
            // MySQL: Set all database fields
            $envContent = preg_replace('/# DB_HOST=.*/m', "DB_HOST={$dbConfig['host']}", $envContent);
            $envContent = preg_replace('/# DB_PORT=.*/m', "DB_PORT={$dbConfig['port']}", $envContent);
            $envContent = preg_replace('/DB_DATABASE=.*/m', "DB_DATABASE={$dbConfig['name']}", $envContent);
            $envContent = preg_replace('/# DB_USERNAME=.*/m', "DB_USERNAME={$dbConfig['user']}", $envContent);
            $envContent = preg_replace('/# DB_PASSWORD=.*/m', "DB_PASSWORD={$dbConfig['pass']}", $envContent);
        }
        // 4. Generate APP_KEY
        $appKey = 'base64:' . base64_encode(random_bytes(32));
        $envContent = preg_replace('/APP_KEY=.*/m', "APP_KEY={$appKey}", $envContent);
        // 5. Write .env file
        if (!file_put_contents(ENV_FILE, $envContent)) {
            throw new Exception('Failed to write .env file. Check permissions.');
        }
        // 6. Store success flag
        $_SESSION['env_created'] = true;
        $_SESSION['installation_step'] = 'complete';
        // 7. Save data for Laravel (NEW: Sync across different session handlers)
        $installData = [
            'db_config' => $_SESSION['db_config'],
            'admin_config' => $_SESSION['admin_config']
        ];
        file_put_contents(__DIR__ . '/../../storage/install_data.json', json_encode($installData));
    } catch (Exception $e) {
        $errors[] = 'Installation Error: ' . $e->getMessage();
    }
}
// ============================================
// HTML OUTPUT
// ============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exospace Installer - Step <?php echo $step; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .step-indicator { display: flex; justify-content: space-between; margin-bottom: 2rem; }
        .step-item { flex: 1; text-align: center; position: relative; }
        .step-item::before { content: ''; position: absolute; top: 1.5rem; left: 0; right: 0; height: 2px; background: #374151; z-index: -1; }
        .step-item:first-child::before { left: 50%; }
        .step-item:last-child::before { right: 50%; }
        .step-circle { width: 3rem; height: 3rem; border-radius: 50%; background: #374151; color: #9ca3af; margin: 0 auto 0.5rem; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .step-item.active .step-circle { background: #3b82f6; color: white; }
        .step-item.completed .step-circle { background: #10b981; color: white; }
        .requirement-row { display: flex; justify-content: space-between; padding: 0.75rem; border-bottom: 1px solid #374151; }
        .status-ok { color: #10b981; }
        .status-fail { color: #ef4444; }
        #mysql_fields { transition: all 0.3s ease; }
    </style>
</head>
<body class="bg-gray-900 text-white min-h-screen py-8">
    <div class="max-w-3xl mx-auto px-4">
        
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-blue-500 to-purple-600 bg-clip-text text-transparent mb-2">
                EXOSPACE INSTALLER
            </h1>
            <p class="text-gray-400">3D Virtual Gallery System</p>
        </div>
        <!-- Progress Steps -->
        <div class="step-indicator">
            <div class="step-item <?php echo $step >= 1 ? 'active' : ''; ?>">
                <div class="step-circle">1</div>
                <div class="text-sm">Requirements</div>
            </div>
            <div class="step-item <?php echo $step >= 2 ? 'active' : ''; ?> <?php echo $step > 2 ? 'completed' : ''; ?>">
                <div class="step-circle">2</div>
                <div class="text-sm">Database</div>
            </div>
            <div class="step-item <?php echo $step >= 3 ? 'active' : ''; ?> <?php echo $step > 3 ? 'completed' : ''; ?>">
                <div class="step-circle">3</div>
                <div class="text-sm">Admin</div>
            </div>
            <div class="step-item <?php echo $step >= 4 ? 'active' : ''; ?>">
                <div class="step-circle">4</div>
                <div class="text-sm">Complete</div>
            </div>
        </div>
        <!-- Error Messages -->
        <?php if (!empty($errors)): ?>
            <div class="bg-red-900/50 border border-red-500 rounded-lg p-4 mb-6">
                <h3 class="font-bold mb-2">⚠️ Errors Found:</h3>
                <ul class="list-disc list-inside space-y-1">
                    <?php foreach ($errors as $error): ?>
                        <li class="text-sm"><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <!-- Main Content Card -->
        <div class="bg-gray-800 rounded-lg shadow-2xl p-8">
            <?php if ($step === 1): ?>
                <!-- STEP 1: REQUIREMENTS -->
                <h2 class="text-2xl font-bold mb-6">System Requirements Check</h2>
                
                <div class="mb-6">
                    <h3 class="text-lg font-semibold mb-3 text-blue-400">PHP Extensions</h3>
                    <div class="bg-gray-900 rounded p-4">
                        <?php foreach ($requirements as $name => $status): ?>
                            <div class="requirement-row">
                                <span><?php echo $name; ?></span>
                                <span class="<?php echo $status ? 'status-ok' : 'status-fail'; ?>">
                                    <?php echo $status ? '✓ Installed' : '✗ Missing'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mb-6">
                    <h3 class="text-lg font-semibold mb-3 text-blue-400">File Permissions</h3>
                    <div class="bg-gray-900 rounded p-4">
                        <?php foreach ($permissions as $path => $status): ?>
                            <div class="requirement-row">
                                <span><?php echo $path; ?></span>
                                <span class="<?php echo $status ? 'status-ok' : 'status-fail'; ?>">
                                    <?php echo $status ? '✓ Writable' : '✗ Not Writable'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if ($allRequirementsMet && $allPermissionsOk): ?>
                    <a href="?step=2" class="block w-full bg-blue-600 hover:bg-blue-700 text-center py-3 rounded-lg font-semibold transition">
                        Continue to Database Setup →
                    </a>
                <?php else: ?>
                    <div class="bg-yellow-900/50 border border-yellow-600 rounded p-4 text-sm">
                        ⚠️ Please fix the issues above before continuing. Contact your hosting provider if needed.
                    </div>
                <?php endif; ?>
            <?php elseif ($step === 2): ?>
                <!-- STEP 2: DATABASE CONFIGURATION -->
                <h2 class="text-2xl font-bold mb-6">Database Configuration</h2>
                
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-2">Application Name</label>
                        <input type="text" name="app_name" value="<?php echo htmlspecialchars($_POST['app_name'] ?? 'Exospace'); ?>" 
                               class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-2 focus:outline-none focus:border-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">Application URL</label>
                        <input type="url" name="app_url" value="<?php echo htmlspecialchars($_POST['app_url'] ?? 'http://' . $_SERVER['HTTP_HOST']); ?>" 
                               placeholder="https://yourdomain.com"
                               class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-2 focus:outline-none focus:border-blue-500" required>
                        <p class="text-xs text-gray-400 mt-1">No trailing slash. Example: https://gallery.example.com</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">Database Type</label>
                        <select name="db_type" id="db_type" class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-2 focus:outline-none focus:border-blue-500" onchange="toggleDatabaseFields()">
                            <option value="sqlite" <?php echo (isset($_POST['db_type']) && $_POST['db_type'] === 'sqlite') ? 'selected' : ''; ?>>SQLite (Local Development)</option>
                            <option value="mysql" <?php echo (!isset($_POST['db_type']) || $_POST['db_type'] === 'mysql') ? 'selected' : ''; ?>>MySQL</option>
                            <option value="mariadb">MariaDB</option>
                        </select>
                        <p class="text-xs text-gray-400 mt-1" id="db_type_hint">
                            SQLite is recommended for local development. Use MySQL/MariaDB for production.
                        </p>
                    </div>
                    <!-- MySQL/MariaDB Fields -->
                    <div id="mysql_fields" style="display: none;">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-2">Database Host</label>
                                <input type="text" name="db_host" value="<?php echo htmlspecialchars($_POST['db_host'] ?? '127.0.0.1'); ?>" 
                                       class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-2 focus:outline-none focus:border-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-2">Database Port</label>
                                <input type="text" name="db_port" value="<?php echo htmlspecialchars($_POST['db_port'] ?? '3306'); ?>" 
                                       class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-2 focus:outline-none focus:border-blue-500">
                            </div>
                        </div>
                        <div class="mt-4">
                            <label class="block text-sm font-medium mb-2">Database Username</label>
                            <input type="text" name="db_user" value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>" 
                                   placeholder="root"
                                   class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-2 focus:outline-none focus:border-blue-500">
                        </div>
                        <div class="mt-4">
                            <label class="block text-sm font-medium mb-2">Database Password</label>
                            <input type="password" name="db_pass" value="<?php echo htmlspecialchars($_POST['db_pass'] ?? ''); ?>" 
                                   class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-2 focus:outline-none focus:border-blue-500">
                            <p class="text-xs text-gray-400 mt-1">Leave empty if no password is set</p>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2" id="db_name_label">Database Name</label>
                        <input type="text" name="db_name" id="db_name" value="<?php echo htmlspecialchars($_POST['db_name'] ?? 'database.sqlite'); ?>" 
                               placeholder="exospace_db"
                               class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-2 focus:outline-none focus:border-blue-500" required>
                        <p class="text-xs text-gray-400 mt-1" id="db_name_hint">Filename for SQLite database (stored in database/ folder)</p>
                    </div>
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 py-3 rounded-lg font-semibold transition">
                        Test Connection & Continue →
                    </button>
                </form>
                <script>
                    function toggleDatabaseFields() {
                        const dbType = document.getElementById('db_type').value;
                        const mysqlFields = document.getElementById('mysql_fields');
                        const dbNameInput = document.getElementById('db_name');
                        const dbNameLabel = document.getElementById('db_name_label');
                        const dbNameHint = document.getElementById('db_name_hint');
                        const dbTypeHint = document.getElementById('db_type_hint');
                        
                        if (dbType === 'sqlite') {
                            mysqlFields.style.display = 'none';
                            dbNameInput.value = 'database.sqlite';
                            dbNameInput.placeholder = 'database.sqlite';
                            dbNameLabel.textContent = 'Database Filename';
                            dbNameHint.textContent = 'Filename for SQLite database (stored in database/ folder)';
                            dbTypeHint.textContent = 'SQLite: Simple file-based database, perfect for development.';
                        } else {
                            mysqlFields.style.display = 'block';
                            dbNameInput.value = 'exospace_db';
                            dbNameInput.placeholder = 'exospace_db';
                            dbNameLabel.textContent = 'Database Name';
                            dbNameHint.textContent = 'The database must already exist on your MySQL server.';
                            dbTypeHint.textContent = 'MySQL/MariaDB: Recommended for production environments.';
                        }
                    }
                    
                    // Initialize on page load
                    toggleDatabaseFields();
                </script>
            <?php elseif ($step === 3): ?>
                <!-- STEP 3: ADMIN & LICENSE -->
                <h2 class="text-2xl font-bold mb-6">License & Administrator Account</h2>
                
                <form method="POST" class="space-y-4">
                    <div class="bg-blue-900/30 border border-blue-700 rounded p-4 mb-4">
                        <h3 class="font-semibold mb-2">📝 Envato Purchase Code</h3>
                        <p class="text-sm text-gray-300 mb-3">Enter your Envato purchase code. <a href="https://help.market.envato.com/hc/en-us/articles/202822600" target="_blank" class="text-blue-400 underline">Where do I find this?</a></p>
                        <input type="text" name="purchase_code" value="<?php echo htmlspecialchars($_POST['purchase_code'] ?? ''); ?>" 
                               placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                               class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-2 focus:outline-none focus:border-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">Administrator Name</label>
                        <input type="text" name="admin_name" value="<?php echo htmlspecialchars($_POST['admin_name'] ?? ''); ?>" 
                               placeholder="John Doe"
                               class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-2 focus:outline-none focus:border-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">Administrator Email</label>
                        <input type="email" name="admin_email" value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>" 
                               placeholder="admin@example.com"
                               class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-2 focus:outline-none focus:border-blue-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">Administrator Password</label>
                        <input type="password" name="admin_password" 
                               class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-2 focus:outline-none focus:border-blue-500" 
                               minlength="8" required>
                        <p class="text-xs text-gray-400 mt-1">Minimum 8 characters</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">Confirm Password</label>
                        <input type="password" name="admin_password_confirm" 
                               class="w-full bg-gray-700 border border-gray-600 rounded px-4 py-2 focus:outline-none focus:border-blue-500" required>
                    </div>
                    <div class="bg-gray-900 rounded p-4">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" name="install_demo" value="1" checked class="mr-3 w-5 h-5">
                            <div>
                                <span class="font-medium">Install Demo Gallery</span>
                                <p class="text-xs text-gray-400">Includes sample 3D gallery with placeholder images</p>
                            </div>
                        </label>
                    </div>
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 py-3 rounded-lg font-semibold transition">
                        Proceed to Installation →
                    </button>
                </form>
            <?php elseif ($step === 4): ?>
                <!-- STEP 4: INSTALLATION COMPLETE -->
                <?php if (empty($errors) && isset($_SESSION['env_created'])): ?>
                    <div class="text-center">
                        <div class="text-6xl mb-4">✓</div>
                        <h2 class="text-3xl font-bold mb-4 text-green-400">Environment Configuration Complete!</h2>
                        <p class="text-gray-300 mb-6">Database credentials have been saved. Click below to finalize the installation.</p>
                        
                        <div class="bg-gray-900 rounded p-4 mb-6 text-left">
                            <h3 class="font-semibold mb-2">What happens next:</h3>
                            <ul class="text-sm space-y-2 text-gray-300">
                                <li>✓ Database tables will be created</li>
                                <li>✓ Administrator account will be set up</li>
                                <li>✓ Storage links will be configured</li>
                                <?php if ($_SESSION['admin_config']['install_demo']): ?>
                                    <li>✓ Demo gallery will be installed</li>
                                <?php endif; ?>
                                <li>✓ Installer will be removed for security</li>
                            </ul>
                        </div>
                        <a href="<?php echo $_SESSION['db_config']['app_url']; ?>/finalize-installation" 
                           class="inline-block bg-green-600 hover:bg-green-700 px-8 py-3 rounded-lg font-semibold transition">
                            Finalize Installation
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <!-- Footer -->
        <div class="text-center mt-8 text-sm text-gray-500">
            <p>Exospace 3D Gallery v1.0 - Built for Envato</p>
        </div>
    </div>
</body>
</html>