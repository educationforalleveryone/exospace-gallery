<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Gallery;
use App\Models\GalleryImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Exception;

class InstallerController extends Controller
{
    private $lockFile = 'storage/.installed';

    /**
     * Main finalization route
     */
    public function finalize()
    {
        // Check if already installed
        if (file_exists(base_path($this->lockFile))) {
            return redirect('/')->with('error', 'Application already installed.');
        }

        // Try to recover data from temp file if session is empty
        $tempFile = storage_path('install_data.json');
        if (!session()->has('db_config') && file_exists($tempFile)) {
            $data = json_decode(file_get_contents($tempFile), true);
            session(['db_config' => $data['db_config'] ?? null]);
            session(['admin_config' => $data['admin_config'] ?? null]);
        }

        // Check if session data exists
        if (!session()->has('db_config') || !session()->has('admin_config')) {
            return redirect('/install')->with('error', 'Invalid installation session.');
        }

        try {
            // Step 1: Run Migrations
            $this->runMigrations();

            // Step 2: Create Admin User
            $admin = $this->createAdminUser();

            // Step 3: Install Demo Data (if requested)
            if (session('admin_config.install_demo')) {
                $this->installDemoData($admin);
            }

            // Step 4: Storage Link
            $this->createStorageLink();

            // Step 5: Save Purchase Code to Settings
            $this->savePurchaseCode();

            // Step 6: Create Installation Lock
            $this->createLockFile();

            // Step 7: Clean up session and temp file
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
            session()->flush();

            // Success page
            return view('installer.complete', [
                'admin_email' => session('admin_config.email'),
                'login_url' => url('/login')
            ]);

        } catch (Exception $e) {
            return $this->handleError($e);
        }
    }

    /**
     * Run database migrations
     */
    private function runMigrations()
    {
        try {
            Artisan::call('migrate:fresh', ['--force' => true]);
            
            // Verify tables were created (Database agnostic)
            $tables = \Illuminate\Support\Facades\Schema::getTableListing();
            if (count($tables) < 5) {
                throw new Exception('Migration failed - insufficient tables created');
            }

        } catch (Exception $e) {
            throw new Exception('Migration Error: ' . $e->getMessage());
        }
    }

    /**
     * Create administrator account
     */
    private function createAdminUser()
    {
        try {
            $adminConfig = session('admin_config');

            $user = User::create([
                'name' => $adminConfig['name'],
                'email' => $adminConfig['email'],
                'password' => Hash::make($adminConfig['password']),
                'email_verified_at' => now(),
            ]);

            return $user;

        } catch (Exception $e) {
            throw new Exception('Admin Creation Error: ' . $e->getMessage());
        }
    }

    /**
     * Install demo gallery data
     */
    private function installDemoData($admin)
    {
        try {
            // Create demo gallery
            $gallery = Gallery::create([
                'user_id' => $admin->id,
                'title' => 'Welcome to Exospace',
                'slug' => 'welcome-demo-' . uniqid(),
                'description' => 'This is a sample 3D gallery showcasing the power of Exospace. Upload your own images to create immersive virtual exhibitions!',
                'wall_texture' => 'white',
                'frame_style' => 'modern',
                'lighting_preset' => 'bright',
                'floor_material' => 'wood',
                'is_active' => true,
                'view_count' => 0,
            ]);

            // Create demo images directory
            $demoDir = storage_path('app/public/galleries/' . $gallery->id);
            if (!File::exists($demoDir)) {
                File::makeDirectory($demoDir, 0755, true);
            }

            // Create placeholder images (simple colored rectangles)
            $this->createDemoImages($gallery, $demoDir);

        } catch (Exception $e) {
            // Demo data is optional, log error but don't fail installation
            \Log::warning('Demo data installation failed: ' . $e->getMessage());
        }
    }

    /**
     * Create demo placeholder images
     */
    private function createDemoImages($gallery, $directory)
    {
        $demoImages = [
            ['name' => 'Abstract Art 1', 'orientation' => 'landscape', 'width' => 1920, 'height' => 1080],
            ['name' => 'Portrait Study', 'orientation' => 'portrait', 'width' => 1080, 'height' => 1920],
            ['name' => 'Square Canvas', 'orientation' => 'square', 'width' => 1080, 'height' => 1080],
            ['name' => 'Panorama View', 'orientation' => 'landscape', 'width' => 2560, 'height' => 1080],
        ];

        foreach ($demoImages as $index => $imageData) {
            // Create a simple colored image using GD
            $img = imagecreatetruecolor($imageData['width'], $imageData['height']);
            $colors = [
                imagecolorallocate($img, 59, 130, 246),  // Blue
                imagecolorallocate($img, 139, 92, 246),  // Purple
                imagecolorallocate($img, 236, 72, 153),  // Pink
                imagecolorallocate($img, 34, 197, 94),   // Green
            ];
            imagefill($img, 0, 0, $colors[$index]);

            // Add text
            $white = imagecolorallocate($img, 255, 255, 255);
            $text = "Demo Image " . ($index + 1);
            imagestring($img, 5, $imageData['width']/2 - 50, $imageData['height']/2, $text, $white);

            // Save image
            $filename = 'demo-' . ($index + 1) . '.jpg';
            $filepath = $directory . '/' . $filename;
            imagejpeg($img, $filepath, 90);
            imagedestroy($img);

            // Create database record
            GalleryImage::create([
                'gallery_id' => $gallery->id,
                'filename' => $filename,
                'original_name' => $imageData['name'] . '.jpg',
                'path' => 'storage/galleries/' . $gallery->id . '/' . $filename,
                'mime_type' => 'image/jpeg',
                'size' => filesize($filepath),
                'width' => $imageData['width'],
                'height' => $imageData['height'],
                'orientation' => $imageData['orientation'],
                'position_order' => $index,
                'title' => $imageData['name'],
                'description' => 'Sample artwork for demonstration purposes',
            ]);
        }
    }

    /**
     * Create storage symbolic link
     */
    private function createStorageLink()
    {
        try {
            if (!File::exists(public_path('storage'))) {
                Artisan::call('storage:link');
            }
        } catch (Exception $e) {
            // Non-critical, log warning
            \Log::warning('Storage link creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Save purchase code to database
     */
    private function savePurchaseCode()
    {
        try {
            $purchaseCode = session('admin_config.purchase_code');
            
            DB::table('settings')->insert([
                'key' => 'envato_purchase_code',
                'value' => $purchaseCode,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('settings')->insert([
                'key' => 'installation_date',
                'value' => now()->toDateTimeString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        } catch (Exception $e) {
            \Log::warning('Settings save failed: ' . $e->getMessage());
        }
    }

    /**
     * Create installation lock file
     */
    private function createLockFile()
    {
        $lockPath = base_path($this->lockFile);
        $lockContent = json_encode([
            'installed_at' => now()->toDateTimeString(),
            'version' => '1.0.0',
            'installer_ip' => request()->ip(),
        ], JSON_PRETTY_PRINT);

        File::put($lockPath, $lockContent);

        // Also try to delete the installer directory for security
        $installerPath = public_path('install');
        if (File::exists($installerPath)) {
            try {
                File::deleteDirectory($installerPath);
            } catch (Exception $e) {
                // Log but don't fail
                \Log::warning('Could not delete installer directory: ' . $e->getMessage());
            }
        }
    }

    /**
     * Handle installation errors
     */
    private function handleError(Exception $e)
    {
        // Rollback: Delete .env if it exists
        $envPath = base_path('.env');
        if (File::exists($envPath)) {
            File::delete($envPath);
        }

        // Clear session and temp file
        $tempFile = storage_path('install_data.json');
        if (file_exists($tempFile)) {
            @unlink($tempFile);
        }
        session()->flush();

        return view('installer.error', [
            'error' => $e->getMessage(),
            'trace' => config('app.debug') ? $e->getTraceAsString() : null,
        ]);
    }
}