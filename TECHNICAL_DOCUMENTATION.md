# Exospace 3D Gallery — Technical Documentation

> **Version:** 1.3.4  
> **Last Updated:** February 6, 2026  
> **Document Type:** Comprehensive Technical Reference

---

## Table of Contents

1. [Project Overview](#project-overview)
2. [Architecture & System Design](#architecture--system-design)
3. [Technology Stack](#technology-stack)
4. [Core Features](#core-features)
5. [Data Model & Database Schema](#data-model--database-schema)
6. [Backend Architecture](#backend-architecture)
7. [3D Rendering Engine](#3d-rendering-engine)
8. [Image Processing Pipeline](#image-processing-pipeline)
9. [User Interface Components](#user-interface-components)
10. [Security Considerations](#security-considerations)
11. [Design Decisions & Rationale](#design-decisions--rationale)
12. [Use Cases](#use-cases)
13. [File Structure Reference](#file-structure-reference)
14. [Deployment & Cloud Hosting](#deployment--cloud-hosting)

---

## Project Overview

### What is Exospace?

**Exospace** is a web-based 3D virtual gallery platform that enables users to create immersive, first-person walkable exhibitions. Users upload their artwork images through an admin panel, and the system automatically generates a fully navigable 3D gallery room rendered in real-time using WebGL.

### Purpose & Vision

The platform solves the problem of digital art presentation by transforming static image collections into interactive, museum-like experiences. Key goals include:

- **Accessibility**: No 3D modeling or coding knowledge required
- **Immersion**: First-person navigation with WASD controls mimics real gallery visits
- **Customization**: Configurable wall textures, floor materials, lighting presets, and frame styles
- **Performance**: Optimized for modern browsers without plugins

### Target Users

| User Type | Use Case |
|-----------|----------|
| **Artists** | Showcase portfolios in immersive environments |
| **Galleries** | Create virtual exhibitions for remote viewing |
| **Museums** | Digital extensions of physical collections |
| **Educators** | Interactive art history presentations |
| **Photographers** | Premium presentation of photography work |

---

## Architecture & System Design

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                         CLIENT (Browser)                             │
├─────────────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────┐  │
│  │   Admin Panel   │  │  Gallery Viewer │  │   Authentication    │  │
│  │   (Livewire)    │  │   (Three.js)    │  │   (Laravel Breeze)  │  │
│  └────────┬────────┘  └────────┬────────┘  └──────────┬──────────┘  │
└───────────┼────────────────────┼─────────────────────┼──────────────┘
            │                    │                     │
            ▼                    ▼                     ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         SERVER (Laravel 12)                          │
├─────────────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────┐  │
│  │   Controllers   │  │    Services     │  │      Models         │  │
│  │  (Admin CRUD)   │  │ (ImageProcess)  │  │ (Gallery, User...)  │  │
│  └────────┬────────┘  └────────┬────────┘  └──────────┬──────────┘  │
└───────────┼────────────────────┼─────────────────────┼──────────────┘
            │                    │                     │
            ▼                    ▼                     ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      DATA & STORAGE LAYER                            │
├─────────────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────────┐  │
│  │ SQLite/MySQL DB │  │  File Storage   │  │   Static Assets     │  │
│  │  (Eloquent ORM) │  │  (public/disk)  │  │   (Textures, JS)    │  │
│  └─────────────────┘  └─────────────────┘  └─────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

### Request Flow

```mermaid
sequenceDiagram
    participant User
    participant Browser
    participant Laravel
    participant Database
    participant FileStorage

    User->>Browser: Navigate to /gallery/{slug}
    Browser->>Laravel: GET /gallery/{slug}
    Laravel->>Database: Query Gallery with Images
    Database-->>Laravel: Gallery Data
    Laravel-->>Browser: Blade View + JSON Payload
    Browser->>Browser: Initialize Three.js Scene
    Browser->>FileStorage: Load Textures & Images
    FileStorage-->>Browser: Image Assets
    Browser->>Browser: Render 3D Gallery
    User->>Browser: WASD Navigation
    Browser->>Browser: Update Camera Position
```

---

## Technology Stack

### Backend Technologies

| Technology | Version | Purpose |
|------------|---------|---------|
| **PHP** | 8.2+ | Server-side language |
| **Laravel** | 12.x | MVC framework |
| **Livewire** | 4.x | Reactive UI components |
| **Laravel Sanctum** | 4.2 | API authentication |
| **Laravel Breeze** | 2.3 | Authentication scaffolding |
| **Intervention Image** | 3.11 | Image processing & manipulation |
| **Spatie Media Library** | 11.17 | Media file management |
| **Resend Laravel** | 1.1 | Transactional email API |

### Frontend Technologies

| Technology | Version | Purpose |
|------------|---------|---------|
| **Three.js** | 0.160.0 | WebGL 3D rendering engine |
| **GSAP** | 3.14.2 | Animation library |
| **Alpine.js** | 3.4.2 | Lightweight reactivity |
| **Tailwind CSS** | 4.x | Utility-first CSS framework |
| **Vite** | 7.x | Asset bundling |
| **Axios** | 1.11 | HTTP client |

### Database & Storage

| Component | Technology | Notes |
|-----------|------------|-------|
| **Primary Database** | SQLite (dev) / MySQL (prod) | Eloquent ORM |
| **File Storage** | Laravel Filesystem | `public` disk |
| **Caching** | Laravel Cache | File-based default |

### Development Tools

| Tool | Purpose |
|------|---------|
| **PHPUnit** | Backend testing |
| **Laravel Pint** | Code style fixer |
| **Laravel Pail** | Real-time log viewer |
| **Laravel Sail** | Docker environment |
| **Concurrently** | Parallel dev processes |

---

## Core Features

### 1. Gallery Management

**Capability**: Full CRUD operations for virtual galleries with customization options.

| Feature | Description |
|---------|-------------|
| Create Gallery | Title, description, and style configuration |
| Edit Gallery | Update settings and manage images |
| Delete Gallery | Cascade deletion of images |
| Toggle Active | Enable/disable public visibility |
| View Analytics | Track view counts |

**Customization Options**:

```php
// Available configurations from database schema
'wall_texture' => ['white', 'concrete', 'brick', 'wood']
'frame_style'  => ['modern', 'classic', 'minimal']
'lighting_preset' => ['bright', 'moody', 'dramatic']
'floor_material' => ['wood', 'marble', 'concrete']
```

### 2. Dark Mode Theme

**Capability**: Complete dark color scheme across all authenticated and public pages.

| Component | Implementation |
|-----------|-----------------|
| Dashboard | Dark gray cards with gradient accents |
| Admin Panel | Consistent dark theme with purple/indigo highlights |
| Auth Pages | Dark backgrounds with subtle glass effects |
| Blade Components | All buttons, modals, inputs styled for dark mode |

**Color Palette**:

| Element | Color Code | Usage |
|---------|------------|-------|
| Background | `gray-900` | Main page background |
| Cards | `gray-800` | Content containers |
| Borders | `gray-700` | Dividers and card borders |
| Text Primary | `gray-100` | Headings and primary text |
| Text Secondary | `gray-400` | Descriptions and labels |
| Accent Primary | `purple-600` | CTAs and highlights |
| Accent Secondary | `indigo-600` | Gradients and secondary actions |

### 3. Image Upload System

**Capability**: Drag-and-drop batch upload with automatic processing.

| Feature | Specification |
|---------|---------------|
| Supported Formats | JPEG, PNG, JPG, WEBP |
| Max File Size | 10MB per image |
| Max Images/Gallery | 100 |
| Auto-Resize | 2048×2048 max for WebGL compatibility |
| Thumbnail Generation | 400×400 for admin UI |
| Orientation Detection | Portrait, Landscape, Square |

### 4. 3D Gallery Viewer

**Capability**: Real-time WebGL-rendered virtual gallery with first-person navigation.

| Feature | Implementation |
|---------|----------------|
| First-Person Controls | PointerLockControls (Three.js) |
| Movement | WASD keys with variable speed (1×/2×/4×/8×) |
| Sprint | Left Shift key |
| Look Around | Mouse movement |
| Artwork Info | Press E to view details |
| Collision Detection | Room boundary constraints |

**Entrance Curtain Screen**:

Before entering the 3D experience, visitors see a cinematic landing screen featuring:
- Gallery title and description
- Artwork count and view statistics
- "Enter Exhibition" button with smooth fade transition
- WASD/mouse control hints

This deferred loading approach improves perceived performance and gives users context before immersion.

### 5. Dynamic Lighting System

**Capability**: Proximity-based artwork illumination.

| Preset | Ambient | Spotlight | Fill Light |
|--------|---------|-----------|------------|
| **Bright** | 0.7 | 1.2 | 0.5 |
| **Moody** | 0.4 | 0.8 | 0.3 |
| **Dramatic** | 0.25 | 1.5 | 0.15 |

 The system activates spotlights only for artworks within 5 meters of the player, with smooth fade-in/fade-out transitions.

### 6. Smart Room Sizing

**Capability**: Automatic room dimension calculation based on artwork count.

```javascript
// Algorithm from view.blade.php
const imagesPerWall = Math.ceil(imageCount / 4);
const calculatedWallLength = (imagesPerWall * spacing) + spacing;
const wallLength = Math.max(minWallLength, calculatedWallLength);
```

This ensures:
- No empty wall space
- Even distribution across 4 walls
- Minimum room dimensions for small galleries

### 7. Landing Page

**Capability**: Professional marketing landing page for user acquisition.

| Section | Content |
|---------|---------|
| Hero | Tagline, CTA buttons, preview placeholder |
| Features | 3-column grid: Instant Setup, Customizable, Cross-device |
| Pricing | Free Trial, Professional ($29/mo), Enterprise tiers |
| Contact | CTA section with email support |
| Pricing | Free Trial, Professional ($29/mo), Enterprise tiers |
| Contact | CTA section with email support |
| Footer | Product links, company info, legal pages |

### 8. User Subscription System

**Capability**: Tiered access control limiting galleries and image uploads.

| Feature | Free | Pro | Studio |
|---------|------|-----|--------|
| Max Galleries | 1 | 5 | Unlimited |
| Max Images/Gallery | 10 | 50 | 100 |
| Analytics | Basic | Advanced | Advanced |
| Support | Community | Email | Priority |

### 9. Legal Pages

**Capability**: Comprehensive legal and compliance pages for regulatory adherence and payment processor requirements.

| Page | Route | Description |
|------|-------|-------------|
| Privacy Policy | `/privacy` | Data collection, usage, and protection policies |
| Terms of Service | `/terms` | User agreement, acceptable use, liability |
| Refund Policy | `/refund-policy` | 14-day money-back guarantee, refund process via 2Checkout |
| Payment Security | `/payment-security` | PCI DSS compliance, SSL encryption, payment data handling |
| Payment Security | `/payment-security` | PCI DSS compliance, SSL encryption, payment data handling |
| About Us | `/about` | Company story, mission, team profiles, contact info |
| Contact Us | `/contact` | Inquiry form for support and sales |
| Pricing | `/pricing` | Detailed plan comparison and checkout |

### 10. Security Headers

**Capability**: Server-side middleware enforcing security best practices on all HTTP responses.

| Header | Value | Purpose |
|--------|-------|---------|
| `X-Frame-Options` | `DENY` | Prevents clickjacking by blocking iframe embedding |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | Forces HTTPS for 1 year |
| `X-Content-Type-Options` | `nosniff` | Blocks MIME type sniffing |
| `X-Permitted-Cross-Domain-Policies` | `none` | Restricts Flash/PDF cross-domain access |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Controls referrer information sharing |

**Implementation**: `app/Http/Middleware/SecurityHeaders.php` registered globally in `bootstrap/app.php`.

### 11. Cookie Consent Banner

**Capability**: GDPR-compliant cookie consent system with user preference persistence.

| Feature | Implementation |
|---------|-----------------|
| Consent UI | Fixed bottom banner with Accept/Decline buttons |
| Storage | Browser cookie `exospace_cookie_consent` (365-day expiry) |
| Reactivity | Alpine.js component with fade-in animation |
| Privacy Link | Links to `/privacy` policy page |

**Location**: `resources/views/layouts/partials/cookie-banner.blade.php`

### 12. Demo Gallery Redirect

**Capability**: Smart demo link that automatically routes to the first available gallery.

| Route | Behavior |
|-------|----------|
| `/gallery/demo` | Redirects to first active gallery's public view |
| (No galleries) | Redirects to homepage with error flash message |

**Use Case**: Provides a stable demo URL for marketing and documentation even as gallery slugs change.

### 13. Email Queue System

**Capability**: Asynchronous email delivery with professional templates via Resend API.

| Component | Implementation |
|-----------|----------------|
| Provider | Resend API (`resend/resend-laravel`) |
| Queue Backend | Laravel Queue (`database` or `redis` driver) |
| Welcome Email | `App\Mail\WelcomeEmail` implements `ShouldQueue` |
| Template | `resources/views/emails/welcome.blade.php` |

**Welcome Email Features**:
- Personalized greeting with user's name
- Dynamic plan limits display (galleries, images)
- Branded styling with gradient accents
- Direct CTA link to dashboard

**Queue Worker Configuration**:
```bash
# Production (docker-start.sh)
php artisan queue:work --tries=3 --timeout=90 --sleep=3 &

# Development (composer dev)
php artisan queue:listen --tries=1
```

### 14. Gallery Sharing

**Capability**: One-click URL sharing for galleries with clipboard integration.

| Feature | Implementation |
|---------|----------------|
| Share Modal | Modal overlay in admin gallery index |
| Copy URL | Clipboard API with fallback for older browsers |
| Visual Feedback | "Copied!" confirmation with 2-second reset |
| Keyboard Support | ESC key dismisses modal |

**Location**: `resources/views/admin/galleries/index.blade.php`

---

## Data Model & Database Schema

### Entity Relationship Diagram

```mermaid
erDiagram
    User ||--o{ Gallery : owns
    Gallery ||--o{ GalleryImage : contains
    
    User {
        bigint id PK
        string name
        string email UK
        timestamp email_verified_at
        string password
        string remember_token
        timestamps created_at
        timestamps created_at
        timestamps updated_at
        enum plan
        int max_galleries
        int max_images
    }
    
    Gallery {
        bigint id PK
        bigint user_id FK
        string title
        string slug UK
        text description
        boolean is_active
        enum wall_texture
        enum frame_style
        enum lighting_preset
        enum floor_material
        int view_count
        timestamps created_at
        timestamps updated_at
    }
    
    GalleryImage {
        bigint id PK
        bigint gallery_id FK
        string filename
        string original_name
        string path
        string mime_type
        int size
        int width
        int height
        enum orientation
        int position_order
        enum wall_position
        string title
        text description
        timestamps created_at
        timestamps updated_at
    }
```

### Table: `users`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | BIGINT | PK, AUTO | Primary key |
| `name` | VARCHAR(255) | NOT NULL | User's full name |
| `email` | VARCHAR(255) | UNIQUE | User's email address |
| `password` | VARCHAR(255) | NOT NULL | Hashed password |
| `plan` | ENUM | DEFAULT 'free' | free/pro/studio |
| `max_galleries` | INT UNSIGNED | DEFAULT 1 | Gallery limit |
| `max_images` | INT UNSIGNED | DEFAULT 10 | Images per gallery limit |
| `created_at` | TIMESTAMP | NULLABLE | Creation time |
| `updated_at` | TIMESTAMP | NULLABLE | Last update time |

### Table: `galleries`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | BIGINT | PK, AUTO | Primary key |
| `user_id` | BIGINT | FK → users | Owner reference |
| `title` | VARCHAR(255) | NOT NULL | Gallery name |
| `slug` | VARCHAR(255) | UNIQUE | URL-safe identifier |
| `description` | TEXT | NULLABLE | Gallery description |
| `is_active` | BOOLEAN | DEFAULT true | Public visibility |
| `wall_texture` | ENUM | DEFAULT 'white' | Wall material |
| `frame_style` | ENUM | DEFAULT 'modern' | Frame appearance |
| `lighting_preset` | ENUM | DEFAULT 'bright' | Light configuration |
| `floor_material` | ENUM | DEFAULT 'wood' | Floor texture |
| `view_count` | INT UNSIGNED | DEFAULT 0 | Analytics counter |

### Table: `gallery_images`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | BIGINT | PK, AUTO | Primary key |
| `gallery_id` | BIGINT | FK → galleries | Parent gallery |
| `filename` | VARCHAR(255) | NOT NULL | Processed filename |
| `original_name` | VARCHAR(255) | NOT NULL | Upload filename |
| `path` | VARCHAR(500) | NOT NULL | Storage path |
| `mime_type` | VARCHAR(100) | NOT NULL | File MIME type |
| `size` | INT UNSIGNED | NOT NULL | File size (bytes) |
| `width` | INT UNSIGNED | NOT NULL | Image width (px) |
| `height` | INT UNSIGNED | NOT NULL | Image height (px) |
| `orientation` | ENUM | NOT NULL | portrait/landscape/square |
| `position_order` | INT UNSIGNED | DEFAULT 0 | Display order |
| `wall_position` | ENUM | NULLABLE | Wall assignment |
| `title` | VARCHAR(255) | NULLABLE | Artwork title |
| `description` | TEXT | NULLABLE | Artwork description |

---

## Backend Architecture

### Controller Structure

```
app/Http/Controllers/
├── Admin/
│   ├── DashboardController.php    # Admin home
│   ├── GalleryController.php      # Gallery CRUD (Resource)
│   └── ImageController.php        # Image upload/delete
├── Auth/                          # Laravel Breeze controllers
├── GalleryViewController.php      # Public gallery display
├── InstallerController.php        # First-run setup
└── ProfileController.php          # User profile management
```

### Route Definitions

```php
// Public Routes
Route::get('/gallery/{slug}', [GalleryViewController::class, 'show'])
    ->name('gallery.view');

// Admin Routes (auth required)
Route::middleware(['auth', 'verified'])->prefix('admin')->group(function () {
    Route::resource('galleries', GalleryController::class);
    Route::post('galleries/{gallery}/images', [ImageController::class, 'store']);
    Route::delete('images/{image}', [ImageController::class, 'destroy']);
    Route::post('images/bulk-delete', [ImageController::class, 'bulkDestroy']);
});
```

### Service Layer

**ImageProcessingService** handles all image manipulation:

```php
class ImageProcessingService
{
    public function process(UploadedFile $file, int $galleryId): array
    {
        // 1. Generate unique filename
        // 2. Create storage directories
        // 3. Resize to max 2048×2048 (WebGL limit)
        // 4. Generate 400×400 thumbnail
        // 5. Save as JPEG (85% quality main, 80% thumbnail)
        // 6. Return metadata array
    }
    
    public function delete(string $path): void
    {
        // Delete main image and associated thumbnail
    }
}
```

---

## 3D Rendering Engine

### GalleryScene Class

The core 3D engine (`view.blade.php`) implements a complete WebGL gallery system:

```javascript
class GalleryScene {
    constructor() {
        this.scene = new THREE.Scene();
        this.camera = new THREE.PerspectiveCamera(75, aspect, 0.1, 100);
        this.renderer = new THREE.WebGLRenderer({ antialias: true });
        this.controls = new PointerLockControls(this.camera, document.body);
    }
}
```

### Rendering Pipeline

```
1. Asset Loading
   ├── Wall Texture (configured type)
   ├── Floor Texture (configured type)
   └── Artwork Images (async parallel)

2. Scene Construction
   ├── Create Room Geometry
   │   ├── Floor (PlaneGeometry)
   │   ├── 4 Walls (BoxGeometry)
   │   └── Ceiling (PlaneGeometry)
   ├── Place Artworks
   │   ├── Calculate positions per wall
   │   ├── Create frames (BoxGeometry)
   │   └── Apply artwork textures
   └── Setup Lighting
       ├── Ambient Light (global)
       ├── Hemisphere Light (sky/ground)
       ├── Directional Light (sun)
       └── Point Lights (per artwork, proximity-based)

3. Animation Loop (60 FPS)
   ├── updateMovement()        # WASD controls + collision
   ├── updateProximityLighting()  # Dynamic spotlight activation
   ├── checkArtworkFocus()     # Raycasting for E key info
   └── renderer.render()       # Draw frame
```

### Configuration Constants

```javascript
const CONFIG = {
    camera: {
        fov: 75,           // Field of view
        near: 0.1,         // Near clipping plane
        far: 100,          // Far clipping plane
        height: 1.6        // Eye level (meters)
    },
    movement: {
        baseSpeed: 0.1,
        speedMultipliers: [1, 2, 4, 8],
        sprintMultiplier: 1.5
    },
    room: {
        wallHeight: 4,         // 4 meters
        artworkSpacing: 3.5,   // Between artworks
        minWallLength: 8,      // Minimum room size
        wallDepth: 0.3         // Wall thickness
    },
    lighting: {
        proximityDistance: 5   // Light activation radius
    }
};
```

### Texture Management

```javascript
const TEXTURE_PATHS = {
    walls: {
        white: '/assets/textures/walls/white.jpg',
        concrete: '/assets/textures/walls/concrete.jpg',
        brick: '/assets/textures/walls/brick.jpg',
        wood: '/assets/textures/walls/wood.jpg'
    },
    floors: {
        wood: '/assets/textures/floors/wood.jpg',
        marble: '/assets/textures/floors/marble.jpg',
        concrete: '/assets/textures/floors/concrete.jpg'
    }
};
```

---

## Image Processing Pipeline

### Upload Flow

```mermaid
flowchart TD
    A[User Drops Image] --> B{Validate File}
    B -->|Invalid| C[Return Error 422]
    B -->|Valid| D{Check Gallery Limit}
    D -->|≥100 images| E[Return Limit Error]
    D -->|<100 images| F[ImageProcessingService.process]
    F --> G[Read with Intervention Image]
    G --> H{Dimensions > 2048?}
    H -->|Yes| I[Scale Down to 2048]
    H -->|No| J[Keep Original Size]
    I --> J
    J --> K[Save as JPEG 85%]
    K --> L[Create 400×400 Thumbnail]
    L --> M[Save Thumbnail JPEG 80%]
    M --> N[Calculate Orientation]
    N --> O[Store in Database]
    O --> P[Return Success JSON]
```

### Validation Rules

```php
$validator = Validator::make($request->all(), [
    'file' => 'required|file|image|mimes:jpeg,png,jpg,webp|max:10240'
]);
```

### Storage Structure

```
storage/app/public/
└── galleries/
    └── {gallery_id}/
        ├── abc123.jpg           # Main image (max 2048×2048)
        └── thumbnails/
            └── abc123.jpg       # Thumbnail (400×400)
```

---

## User Interface Components

### Admin Panel Views

| View | Path | Purpose |
|------|------|---------|
| Dashboard | `admin/dashboard.blade.php` | Overview & quick actions |
| Gallery List | `admin/galleries/index.blade.php` | Paginated gallery list |
| Create Gallery | `admin/galleries/create.blade.php` | New gallery form |
| Edit Gallery | `admin/galleries/edit.blade.php` | Settings + image manager |

### Blade Components

| Component | Purpose |
|-----------|---------|
| `layouts/app.blade.php` | Main authenticated layout |
| `layouts/guest.blade.php` | Unauthenticated layout |
| `layouts/navigation.blade.php` | Top navigation bar |
| `components/*.blade.php` | Reusable UI elements |

### 3D Viewer UI Elements

```html
<!-- Loading Screen -->
<div id="loader">...</div>

<!-- UI Overlay -->
<div id="ui-layer">
    <!-- Header with title -->
    <!-- Speed indicator (1x/2x/4x/8x) -->
    <!-- Control instructions -->
    <!-- Artwork info panel -->
    <!-- Crosshair -->
</div>

<!-- 3D Canvas -->
<div id="canvas-container"></div>
```

---

## Security Considerations

### Authentication & Authorization

| Layer | Implementation |
|-------|----------------|
| Authentication | Laravel Breeze (session-based) |
| Password Hashing | Bcrypt (Laravel default) |
| CSRF Protection | Automatic via `@csrf` directive |
| Route Protection | `auth` and `verified` middleware |

### Gallery Ownership Verification

```php
// Every admin action verifies ownership
if ($gallery->user_id !== Auth::id()) {
    abort(403);
}
```

### File Upload Security

| Check | Implementation |
|-------|----------------|
| MIME Validation | `mimes:jpeg,png,jpg,webp` |
| Size Limit | `max:10240` (10MB) |
| Extension Validation | Whitelist approach |
| File Storage | Outside web root (symbolic link) |

### Public Gallery Access

- Galleries must have `is_active = true` to be viewable
- Slug-based URLs prevent ID enumeration
- View count incremented securely via Eloquent

---

## Design Decisions & Rationale

### Why Laravel 12?

| Decision | Rationale |
|----------|-----------|
| Modern PHP 8.2+ features | Type safety, performance optimizations |
| Eloquent ORM | Rapid development, relationship management |
| Blade templating | Server-side rendering with component support |
| Built-in authentication | Security best practices out of the box |

### Why Three.js (not Unity/Unreal)?

| Decision | Rationale |
|----------|-----------|
| Browser-native | No plugins or downloads required |
| Lightweight | ~600KB vs multi-MB game engines |
| Mobile support | WebGL works on modern phones |
| Easy integration | JavaScript ecosystem compatibility |
| CDN delivery | Import maps for module loading |

### Why SQLite for Development?

| Decision | Rationale |
|----------|-----------|
| Zero configuration | Works immediately after clone |
| Portable | Single file, easy to backup/reset |
| Production flexibility | MySQL/PostgreSQL supported via config |

### Why 2048×2048 Image Limit?

| Decision | Rationale |
|----------|-----------|
| WebGL texture limits | Many devices cap at 2048 |
| Memory efficiency | Prevents browser crashes |
| Load time optimization | Faster gallery initialization |

### Why Proximity Lighting?

| Decision | Rationale |
|----------|-----------|
| Performance | Only 1 active spotlight at a time |
| Immersion | Mimics museum spotlights |
| Visual feedback | Indicates focus area to user |

---

## Use Cases

### Use Case 1: Artist Portfolio

**Actor**: Independent Artist  
**Goal**: Showcase artwork professionally online

**Flow**:
1. Register account
2. Create new gallery with "Moody" lighting preset
3. Upload 20 high-resolution artwork images
4. Share public URL on social media
5. Monitor view count for engagement metrics

### Use Case 2: Virtual Exhibition

**Actor**: Gallery Owner  
**Goal**: Extend physical exhibition to remote viewers

**Flow**:
1. Create gallery matching physical space style
2. Upload exhibition catalog images in order
3. Set wall texture to match actual venue
4. Embed gallery URL on museum website
5. Track visitor analytics

### Use Case 3: Educational Presentation

**Actor**: Art History Teacher  
**Goal**: Create interactive learning material

**Flow**:
1. Create gallery titled "Renaissance Masters"
2. Upload artworks with detailed descriptions
3. Share URL with students
4. Students navigate and press E for artwork info
5. Class discusses observations in real-time

### Use Case 4: Photography Showcase

**Actor**: Professional Photographer  
**Goal**: Premium portfolio presentation

**Flow**:
1. Create gallery with "Dramatic" lighting
2. Select "brick" wall texture for industrial aesthetic
3. Upload curated photo collection
4. Use 8× speed to give clients a virtual tour
5. Client experiences immersive viewing

---

## File Structure Reference

```
exospace/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/
│   │   │   │   ├── DashboardController.php
│   │   │   │   ├── GalleryController.php
│   │   │   │   └── ImageController.php
│   │   │   ├── Auth/
│   │   │   ├── GalleryViewController.php
│   │   │   ├── InstallerController.php
│   │   │   └── ProfileController.php
│   │   └── Requests/
│   ├── Models/
│   │   ├── Gallery.php
│   │   ├── GalleryImage.php
│   │   ├── Setting.php
│   │   └── User.php
│   ├── Mail/
│   │   └── WelcomeEmail.php          # Queued welcome email
│   ├── Providers/
│   ├── Services/
│   │   └── ImageProcessingService.php
│   └── View/
├── bootstrap/
├── config/
├── database/
│   ├── migrations/
│   │   ├── create_users_table.php
│   │   ├── create_galleries_table.php
│   │   ├── create_gallery_images_table.php
│   │   └── create_settings_table.php
│   ├── factories/
│   └── seeders/
├── documentation/
│   └── index.html           # User documentation
├── public/
│   └── assets/
│       └── textures/
│           ├── walls/
│           └── floors/
├── resources/
│   ├── css/
│   ├── js/
│   └── views/
│       ├── admin/
│       │   ├── dashboard.blade.php
│       │   └── galleries/
│       ├── auth/
│       ├── components/
│       ├── emails/
│       │   └── welcome.blade.php     # Welcome email template
│       ├── gallery/
│       │   └── view.blade.php    # 3D Engine
│       ├── layouts/
│       │   └── partials/
│       │       ├── cookie-banner.blade.php  # GDPR consent
│       │       └── footer.blade.php         # Global footer
│       ├── pages/
│       │   ├── about.blade.php
│       │   ├── contact.blade.php
│       │   ├── pricing.blade.php
│       │   ├── privacy.blade.php
│       │   ├── refund.blade.php
│       │   ├── security.blade.php
│       │   └── terms.blade.php
│       └── profile/
├── routes/
│   ├── web.php              # Main routes
│   └── auth.php             # Auth routes
├── storage/
├── tests/
├── .env.example
├── composer.json
├── docker-start.sh          # Cloud deployment startup script
├── nixpacks.toml             # Nixpacks/Railway configuration
├── package.json
├── tailwind.config.js
└── vite.config.js
```

---

## Quick Start for Developers

### Local Development Setup

```bash
# 1. Clone and install dependencies
composer install
npm install

# 2. Environment setup
cp .env.example .env
php artisan key:generate

# 3. Database
php artisan migrate

# 4. Storage link (for public file access)
php artisan storage:link

# 5. Run development servers
composer run dev
# This runs: php artisan serve + queue:listen + pail + npm run dev
```

### Key Configuration Points

| File | Purpose |
|------|---------|
| `.env` | Environment variables (DB, app key) |
| `config/filesystems.php` | Storage disk configuration |
| `tailwind.config.js` | CSS framework customization |
| `vite.config.js` | Asset bundling configuration |
| `nixpacks.toml` | Nixpacks/Railway deployment config |
| `docker-start.sh` | Container startup script |

---

## Deployment & Cloud Hosting

### Nixpacks Configuration

The project includes first-class support for **Nixpacks**-based deployment platforms like [Railway](https://railway.app), [Render](https://render.com), and similar services.

**File:** `nixpacks.toml`

```toml
[phases.setup]
nixPkgs = ["php", "nginx", "nodejs", "phpPackages.composer"]

[phases.build]
cmds = [
    "npm install",
    "npm run build",
    "composer install --no-dev --optimize-autoloader",
    "php artisan config:cache",
    "php artisan route:cache",
    "php artisan view:cache"
]

[start]
cmd = "bash docker-start.sh"
```

#### Build Phases Explained

| Phase | Actions |
|-------|---------|
| **Setup** | Installs PHP, Nginx, Node.js, and Composer via Nix packages |
| **Build** | Compiles frontend assets, installs PHP dependencies, caches Laravel config |
| **Start** | Executes container startup script |

### Container Startup Script

**File:** `docker-start.sh`

```bash
#!/bin/bash

# 1. Configure PHP upload limits
cat > /assets/php-fpm-overrides.conf << 'EOF'
upload_max_filesize = 50M
post_max_size = 50M
memory_limit = 512M
max_execution_time = 300
EOF

# 2. Patch Nginx to allow 50MB uploads
if grep -q "client_max_body_size" /assets/nginx.template.conf; then
    sed -i 's/client_max_body_size [^;]*;/client_max_body_size 50M;/g' /assets/nginx.template.conf
else
    sed -i 's/server {/server {\n    client_max_body_size 50M;/g' /assets/nginx.template.conf
fi

# 3. Start PHP-FPM and Nginx
node /assets/scripts/prestart.mjs /assets/nginx.template.conf /nginx.conf && \
(php-fpm -y /assets/php-fpm.conf -d upload_max_filesize=50M -d post_max_size=50M -d memory_limit=512M & nginx -c /nginx.conf)
```

#### PHP/Nginx Configuration

| Setting | Value | Purpose |
|---------|-------|---------|
| `upload_max_filesize` | 50MB | Allow large artwork uploads |
| `post_max_size` | 50MB | Match upload limit |
| `memory_limit` | 512MB | Handle image processing |
| `max_execution_time` | 300s | Long operations (batch uploads) |
| `client_max_body_size` | 50MB | Nginx request body limit |

### Deployment to Railway

```bash
# 1. Install Railway CLI
npm install -g @railway/cli

# 2. Login and initialize
railway login
railway init

# 3. Set environment variables
railway variables set APP_KEY=base64:...
railway variables set APP_ENV=production
railway variables set DB_CONNECTION=mysql
# ... other env vars

# 4. Deploy
railway up
```

### Environment Variables for Production

| Variable | Example | Required |
|----------|---------|----------|
| `APP_KEY` | `base64:xxxxx` | ✅ |
| `APP_ENV` | `production` | ✅ |
| `APP_DEBUG` | `false` | ✅ |
| `DB_CONNECTION` | `mysql` | ✅ |
| `DB_HOST` | `mysql.railway.internal` | ✅ |
| `DB_DATABASE` | `railway` | ✅ |
| `DB_USERNAME` | `root` | ✅ |
| `DB_PASSWORD` | `***` | ✅ |
| `FILESYSTEM_DISK` | `public` | ⚠️ |
| `RESEND_API_KEY` | `re_xxxxx` | ✅ (for emails) |
| `MAIL_MAILER` | `resend` | ✅ (for emails) |

> [!WARNING]
> For persistent file storage in production, configure an S3-compatible storage driver (AWS S3, DigitalOcean Spaces, etc.) instead of local filesystem.

---

## Appendix: API Reference

### Admin Gallery Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/galleries` | List user's galleries |
| GET | `/admin/galleries/create` | Create form |
| POST | `/admin/galleries` | Store new gallery |
| GET | `/admin/galleries/{id}/edit` | Edit form |
| PUT | `/admin/galleries/{id}` | Update gallery |
| DELETE | `/admin/galleries/{id}` | Delete gallery |

### Admin Image Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/admin/galleries/{id}/images` | Upload image |
| DELETE | `/admin/images/{id}` | Delete single image |
| POST | `/admin/images/bulk-delete` | Bulk delete |

### Public Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/` | Welcome/Landing page |
| GET | `/gallery/{slug}` | View 3D gallery |
| GET | `/gallery/demo` | Smart redirect to first active gallery |
| GET | `/dashboard` | User dashboard |
| GET | `/privacy` | Privacy Policy page |
| GET | `/terms` | Terms of Service page |
| GET | `/refund-policy` | Refund Policy page |
| GET | `/payment-security` | Payment Security page |
| GET | `/about` | About Us page |
| GET | `/pricing` | Pricing page |
| GET | `/contact` | Contact page |

---

*Document generated for Exospace 3D Gallery v1.3.4*
