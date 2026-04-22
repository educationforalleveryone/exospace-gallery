# Exospace 3D Gallery - Changelog

## Version 2.0.0 - April 22, 2026 (SaaS Launch Update)

### 🗄️ Database Migration to MySQL
- **Production Database**: Migrated from SQLite to MySQL 8 for production reliability, concurrent user support, and SaaS-grade performance.
- **Coolify Integration**: Database now provisioned and managed via Coolify on DigitalOcean with automatic backups.
- **Config Hardening**: Updated `config/database.php` default fallback from `sqlite` to `mysql` to prevent silent misconfiguration.
- **Auto-Migration on Deploy**: Added `php artisan migrate --force` to `nixpacks.toml` build phase so new migrations run automatically on every deployment.

### 💳 2Checkout Payment Integration (Complete)
- **Product ID Mapping**: Webhook now maps 2Checkout product IDs to specific plans. Previously hardcoded all purchases to `pro` regardless of what was bought.
- **Dual Product Support**: Separate product IDs for Pro ($29) and Studio ($99) plans, each with correct limits enforced.
- **Unknown Product Handling**: Unrecognized product IDs are flagged and logged for manual review without failing the webhook (returns 200 to prevent 2Checkout retries).
- **Refund Fix**: Refund handler now correctly downgrades both `pro` and `studio` plan users (previously only checked for `pro`).
- **New Config Keys**: Added `TWOCHECKOUT_PRODUCT_ID_PRO` and `TWOCHECKOUT_PRODUCT_ID_STUDIO` environment variables.
- **Live Checkout Modals**: Replaced "coming soon" placeholder modal with two separate, functional modals — one per plan — linking directly to 2Checkout checkout URLs.

### 🧾 Transaction History
- **New `transactions` Table**: All successful purchases and refunds are now permanently recorded with invoice ID, sale ID, product ID, plan, amount, currency, customer details, and status.
- **Refund Tracking**: Refund webhook updates transaction status to `refunded` for audit trail.
- **Dispute Protection**: Transaction records provide proof of purchase for chargeback disputes.

### 📧 Email Verification
- **Mandatory Verification**: Enabled `MustVerifyEmail` on the `User` model. New registrations now require email verification before accessing the dashboard.
- **Registration Flow**: After registering, users are redirected to the verification notice page instead of directly to the dashboard.
- **Welcome Email Updated**: CTA button now links to `/email/verify` instead of `/dashboard` to match the new flow. Removed dynamic plan limits from welcome email body (was showing incorrect free plan limits).

### 📋 Legal Pages — 2Checkout Compliance
- **Contact Page**: Replaced `[Your Street Address]` placeholder with real registered address (27 Innovation Drive, Suite 4B, Islamabad).
- **Terms of Service**: Corrected "subscription" and "auto-renew" language to accurately reflect one-time lifetime purchase model.
- **Refund Policy**: Removed subscription-cycle references. Clarified that all plans are one-time purchases with no pro-rated refunds.
- **Consistency**: All legal pages now consistently describe Pro and Studio as lifetime licenses with no recurring billing.

### 🔧 Deployment Improvements
- **nixpacks.toml**: Added `php artisan migrate --force` to build phase for automatic schema updates on deploy.
- **Queue Worker**: Confirmed background queue worker runs on startup via `docker-start.sh` for email processing.

---

## Version 1.6.0 - February 11, 2026 (Immersive Mobile & Audio Update)

### 📱 Mobile & Touch Support
- **Touch Controls**: Full mobile support with virtual joystick for movement and touch-pad for looking around.
- **Adaptive UI**: Automatically detects mobile devices and switches to touch-optimized interface.
- **Visual Feedback**: Dynamic joystick UI that follows thumb position.
- **Proximity Interaction**: "Double-tap" replaced with proximity-based interaction hints for better usability.

### 🔊 Audio Immersion System
- **Footstep SFX**: Dynamic footstep sounds that react to movement speed (walking vs sprinting).
- **Interactive UI**: Crisp sound effects for UI interactions (clicks, focus mode).
- **Pitch Variation**: Subtle pitch randomization for footsteps to prevent audio fatigue.
- **Smart Audio Engine**: System automatically manages audio buffers and playback rates.

### 🌫️ Visual Enhancements
- **Atmospheric Fog**: Added subtle depth fog (`0x0a0a0a`) to soften distant geometry and reduce visual harshness.
- **Cinematic Tone Mapping**: Switched to `ACESFilmicToneMapping` for more realistic light handling and contrast.
- **Exposure Balancing**: Optimized exposure settings (0.8) to prevent highlight blowout in bright scenes.

### 💡 Lighting & Performance
- **Proximity Light Boost**: Significantly increased intensity of artwork proximity lights (3.5x) for better visibility.
- **Optimized Loops**: Refined the animation loop to handle audio and physics updates efficiently.

---

## Version 1.5.0 - February 9, 2026 (Realism Update)

### 🎥 Momentum Camera System
- **Physics-Based Movement**: Implemented a new camera system with weight, friction (`damping`), and acceleration for a premium feel.
- **Cinematic Lean**: Camera now subtly tilts (banks) into turns, adding a dynamic, high-end motion effect.
- **Heavy Stop**: Removed the "slippery" feeling; camera now slides to a precise, weighted stop.
- **Smooth Acceleration**: Movement ramps up smoothly instead of instant start/stop.

### 🎨 Tactile Art System
- **Canvas Texture Simulation**: All artworks now feature a realistic woven canvas texture using normal mapping.
- **Smart Grain Scaling**: Texture grain automatically scales based on artwork dimensions to ensure consistent detail density.
- **Lighting Interaction**: Canvas bumps catch dynamic lights, adding depth and realism to flat images.

### 💡 Visual Overhaul
- **Lighting Rebalance**: Drastically reduced intensity of "Bright" and "Dramatic" presets to prevent washout and improve contrast.
- **Atmospheric Fog**: Added subtle depth fog (`0x0a0a0a`) to soften distant geometry.
- **Tone Mapping**: Switched to `ACESFilmicToneMapping` with optimized exposure (0.8) for more cinematic color reproduction.

---

## Version 1.4.1 - February 9, 2026 (Artwork Focus Zoom)

### 🔍 Artwork Focus Mode
- **Immersive Zoom**: Press E while looking at an artwork to smoothly zoom in and center the view on it.
- **Cinematic Animation**: GSAP-powered camera transitions with easing for a polished experience.
- **Exit Focus**: Press E again or ESC to smoothly return to the previous position.
- **Movement Lock**: Player movement is disabled during focus mode to prevent accidental repositioning.

### 🎯 Crosshair Visual Feedback
- **Dynamic Crosshair**: Crosshair changes appearance when hovering over an artwork.
- **Focus Indicator**: Visual indicator shown when entering focus mode.

### 🔧 Technical Improvements
- **Local GSAP Library**: GSAP animation library now served from local assets (`/js/gsap.min.js`) for improved reliability.
- **Animation Stability**: Fixed GSAP animation cleanup and tween management.
- **Camera Quaternion Restoration**: Proper camera rotation restoration when exiting focus mode.

---

## Version 1.4.0 - February 7, 2026 (Ambient Audio, Studio Branding & Super Admin)

### 🎵 Ambient Audio for Galleries
- **Background Music**: Galleries can now have optional ambient audio that plays during the 3D experience.
- **Audio Upload**: Upload MP3/WAV files through the gallery edit page (Studio plan feature).
- **Seamless Playback**: Audio loops continuously while visitors explore the gallery.
- **User Control**: Visitors can mute/unmute audio from the gallery interface.

### 🏷️ Studio Branding (Custom Logo)
- **White-Label Support**: Studio plan users can upload a custom logo to replace the Exospace branding.
- **Logo Display**: Custom logos appear in the gallery viewer header for branded experiences.
- **Flexible Formats**: Supports PNG, JPG, and SVG logo uploads.

### 👑 Super Admin Panel
- **Platform Management**: New super admin dashboard for system-wide administration at `/master-control`.
- **User Management**: View all registered users with their gallery counts and plan details.
- **Plan Management**: Upgrade or downgrade any user's plan directly from the admin panel.
- **User Deletion**: Safely delete users with cascade cleanup of all galleries, images, audio, and logos.
- **Gallery Oversight**: View and manage any user's galleries, toggle active status.
- **Platform Statistics**: Dashboard showing total users, galleries, images, views, and plan distribution.

### 💳 2Checkout Webhook Integration (Initial)
- **Automated Upgrades**: Webhook handler automatically upgrades users upon successful payment.
- **Hash Verification**: Secure IPN validation using MD5 hash with configurable secret word.
- **Refund Handling**: Automatic plan downgrade when refunds are processed.
- **Comprehensive Logging**: All webhook events logged for debugging and auditing.

### 🏠 Enhanced Onboarding Dashboard
- **Plan Status Card**: Dashboard now shows current plan, upgrade prompts, and feature limits.
- **Upgrade Awareness**: Visual indicators when users are on the free plan with prominent upgrade CTAs.
- **Navigation Upgrade Link**: Persistent upgrade button in the main navigation for free users.
- **Galleries Remaining**: Clear display of remaining gallery slots based on plan limits.

### 🍪 Cookie Consent Improvements
- **Comprehensive Coverage**: Cookie consent banner now appears consistently across all public pages.
- **Gallery Viewer Support**: Cookie consent properly integrated into the 3D gallery viewer.
- **Script Loading Control**: Cookie consent decision controls analytics and third-party script loading.

### 🔧 Database Schema Updates
- **New Column**: `galleries.audio_path` — Stores path to ambient audio file.
- **New Column**: `galleries.custom_logo_path` — Stores path to custom branding logo.
- **New Column**: `users.is_super_admin` — Boolean flag for super admin access (indexed).

### 🐛 Bug Fixes
- Fixed logout functionality for session consistency.
- Corrected audio playback initialization in the 3D viewer.
- Fixed dashboard blade template issues.
- Resolved onboarding flow inconsistencies.

---

## Version 1.3.4 - February 6, 2026 (Gallery UX Overhaul)

### 🎭 Entrance Curtain Screen
- **Exhibition Landing Page**: New cinematic entrance screen shown before entering 3D galleries.
- **Gallery Preview**: Displays title, description, artwork count, and view count before entering.
- **One-Click Entry**: Prominent "Enter Exhibition" button with smooth fade transition to loading screen.
- **Control Hints**: Displays WASD and mouse control instructions before entering.

### 🔗 Gallery Sharing System
- **Share Modal**: New share functionality in admin gallery list with copy-to-clipboard URL.
- **Visual Feedback**: "Copied!" confirmation when URL is copied.
- **Keyboard Support**: ESC key closes share and upgrade modals.

### 🎨 Admin Gallery Cards Redesign
- **Statistics Display**: Each gallery card now shows image count and view count with icons.
- **Three-Column Actions**: Reorganized action buttons (View, Share, Edit) in a grid layout.
- **Improved Layout**: Better visual hierarchy with bordered stat sections.

---

## Version 1.3.3 - February 6, 2026 (Email Queue System)

### 📧 Email Infrastructure
- **Resend API Integration**: Switched email provider to Resend (`resend/resend-laravel`) for improved deliverability.
- **Queue-Based Emails**: All emails now processed via Laravel queues (`ShouldQueue`) for non-blocking user experience.
- **Welcome Email Template**: Professional onboarding email sent to new users.

### 🐳 Docker & Deployment
- **Background Queue Worker**: Updated `docker-start.sh` to automatically start queue worker alongside PHP-FPM and Nginx.

### 🐛 Bug Fixes
- Fixed welcome page navigation and footer alignment issues.
- Corrected pricing and contact page layout inconsistencies.

---

## Version 1.3.2 - February 1, 2026 (User Plans & Marketing Pages)

### 💎 User Subscription System
- **Tiered Access**: Implemented Free, Pro, and Studio plans with enforced resource limits.
- **Plan Helpers**: Added `isPro()` and `canCreateGallery()` helper methods to User model.

### 💰 Pricing Page
- **Plan Comparison**: Detailed pricing page at `/pricing` comparing features across all tiers.

### 📞 Contact Page
- **Support Portal**: New dedicated contact page at `/contact` with inquiry form.

---

## Version 1.3.1 - January 31, 2026 (2Checkout Compliance & Security)

### 💳 2Checkout Payment Readiness
- **Payment Security Page**: New `/payment-security` page detailing PCI DSS compliance, SSL encryption, and data handling.
- **Refund Policy Page**: Added comprehensive refund policy at `/refund-policy` with 14-day money-back guarantee.

### 🔒 Security Enhancements
- **Security Headers Middleware**: Added global middleware enforcing `X-Frame-Options`, `Strict-Transport-Security`, `X-Content-Type-Options`, `X-Permitted-Cross-Domain-Policies`, and `Referrer-Policy`.

### 🍪 Cookie Consent Banner
- **GDPR Compliance**: Interactive cookie consent banner with Accept/Decline options, stored for 365 days.

### 🏢 Company Pages
- **About Us Page**: New `/about` page with company story, mission, and team profiles.
- **Global Footer**: Reusable footer partial with navigation links, company info, and trust badges.

### 🎮 Demo Gallery Redirect
- **Smart Demo Link**: `/gallery/demo` now automatically redirects to the first active gallery.

---

## Version 1.3.0 - January 31, 2026 (Dark Mode & Landing Page)

### 🌙 Dark Mode Implementation
- **Complete Dark Theme**: Redesigned admin panel, dashboard, and authentication pages with a cohesive dark color scheme.

### 🏠 Welcome Page Redesign
- **Modern Landing Page**: Complete overhaul with hero section, features grid, and footer.

### 📜 Legal Pages
- **Privacy Policy**: Added comprehensive privacy policy at `/privacy`.
- **Terms of Service**: Added terms of service at `/terms`.

---

## Version 1.2.0 - January 24, 2026 (Performance & UX Overhaul)

### ⚡ Performance Breakthroughs
- **Proximity-Based Lighting Engine**: Lights now smoothly fade in/out based on player position, reducing active light count by 96%.
- **Variable Speed Control**: Added speed multipliers (1×, 2×, 4×, 8×) accessible via number keys.
- **Smart Collision**: Robust boundary detection for high-speed navigation.

### 📂 Media & Storage
- **Increased Upload Limits**: Bumped maximum file size to 10MB per image.
- **Upload Stability**: Fixed filesystem race conditions.

---

## Version 1.1.0 - January 2026

### 🎨 New Features
- **Batch Delete Images**: Select and delete multiple images at once.
- **Increased Upload Limit**: Up to 100 images per gallery.
- **Dynamic Lighting System**: Automatically adjusts lighting based on gallery size.

### 🐛 Bug Fixes
- Fixed black screen issue when displaying 20+ images.
- Fixed disappearing images during simultaneous uploads.
- Fixed WebGL shader errors caused by excessive texture units.

---

## Version 1.0.0 - January 2026
- Initial release
- 3D gallery creation with customizable walls, floors, and lighting
- Image upload with automatic optimization
- Public sharing with unique URLs
- Responsive controls (WASD + Mouse)