# Exospace 3D Gallery - Changelog

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
- **Resend API Integration**: Switched email provider to Resend (`resend/resend-laravel`) for improved deliverability and analytics.
- **Queue-Based Emails**: All emails now processed via Laravel queues (`ShouldQueue`) for non-blocking user experience.
- **Welcome Email Template**: Professional onboarding email sent to new users featuring:
  - Personalized greeting with user's name
  - Plan feature highlights (gallery limits, image limits)
  - Direct CTA to create first gallery
  - Consistent brand styling with gradient accents

### 🐳 Docker & Deployment
- **Background Queue Worker**: Updated `docker-start.sh` to automatically start queue worker (`php artisan queue:work --tries=3 --timeout=90`) alongside PHP-FPM and Nginx.
- **Production-Ready Email**: Queue processing ensures email sending doesn't slow down user registration flow.

### 🔧 Bug Fixes
- Fixed welcome page navigation and footer alignment issues.
- Corrected pricing and contact page layout inconsistencies.
- Updated route definitions for commercial pages.

---

## Version 1.3.2 - February 1, 2026 (User Plans & Marketing Pages)

### 💎 User Subscription System
- **Tiered Access**: Implemented Free, Pro, and Studio plans.
- **Resource Limits**: Enforced limits on total galleries and images per gallery based on user plan.
- **Plan Helpers**: Added `isPro()` and `canCreateGallery()` helper methods to User model.

### 💰 Pricing Page
- **Plan Comparison**: Detailed pricing page at `/pricing` comparing features across tiers.
- **Checkout Integration**: Direct links to payment processing for Pro and Studio upgrades.

### 📞 Contact Page
- **Support Portal**: New dedicated contact page at `/contact` with inquiry form.
- **Direct Communication**: Streamlined channel for sales and support queries.

---

## Version 1.3.1 - January 31, 2026 (2Checkout Compliance & Demo Gallery)

### 💳 2Checkout Payment Integration
- **Payment Processor Ready**: Full compliance with 2Checkout (Verifone) merchant requirements.
- **Payment Security Page**: New `/payment-security` page detailing PCI DSS compliance, SSL encryption, and data handling policies.
- **Refund Policy Page**: Added comprehensive refund policy at `/refund-policy` with 14-day money-back guarantee details.

### 🔒 Security Enhancements
- **Security Headers Middleware**: Added global middleware enforcing:
  - `X-Frame-Options: DENY` (clickjacking protection)
  - `Strict-Transport-Security` with 1-year max-age (HSTS)
  - `X-Content-Type-Options: nosniff` (MIME-type sniffing prevention)
  - `X-Permitted-Cross-Domain-Policies: none`
  - `Referrer-Policy: strict-origin-when-cross-origin`

### 🍪 Cookie Consent Banner
- **GDPR Compliance**: Interactive cookie consent banner with Accept/Decline options.
- **Persistent Consent**: Consent choice saved in browser for 365 days.
- **Alpine.js Integration**: Smooth fade-in animation with modern styling.

### 🏢 Company Pages
- **About Us Page**: New `/about` page with company story, mission statement, and team profiles.
- **Global Footer**: Reusable footer partial with navigation links, company info, and trust badges (SSL Secured, Powered by 2Checkout).

### 🎮 Demo Gallery Redirect
- **Smart Demo Link**: `/gallery/demo` now automatically redirects to the first active gallery.
- **Graceful Fallback**: Returns to homepage with error message if no galleries exist.

---

## Version 1.3.0 - January 31, 2026 (Dark Mode & Landing Page Update)

### 🌙 Dark Mode Implementation
- **Complete Dark Theme**: Redesigned the entire admin panel, dashboard, and authentication pages with a cohesive dark color scheme.
- **Dark UI Components**: Updated all Blade components (buttons, modals, inputs, dropdowns, navigation) with dark theme styling.
- **Improved Readability**: Optimized text contrast and visual hierarchy for dark backgrounds.

### 🏠 Welcome Page Redesign
- **Modern Landing Page**: Complete overhaul with hero section, features grid, pricing tiers, and contact section.
- **Responsive Navigation**: Fixed navigation with glassmorphism effect and authentication-aware links.
- **Feature Showcase**: Three-column feature cards highlighting instant setup, customization, and cross-device support.
- **Pricing Section**: Free, Professional ($29/mo), and Enterprise tier presentation.

### 📜 Legal Pages
- **Privacy Policy**: Added comprehensive privacy policy page at `/privacy`.
- **Terms of Service**: Added terms of service page at `/terms`.
- **Footer Links**: Integrated legal page links in the welcome page footer.

### 🍎 Mac Compatibility
- **AppServiceProvider Fix**: Updated application boot process for macOS compatibility.

### 🎨 Enhanced Dashboard
- **Statistics Cards**: Added visual stats grid showing total galleries, views, and images.
- **Quick Actions**: Prominent call-to-action for creating new galleries.
- **Recent Galleries List**: Shows latest 5 galleries with image count, view count, and quick edit links.

---

## Version 1.2.0 - January 24, 2026 (Performance & UX Overhaul)

### ⚡ Performance Breakthroughs
- **Proximity-Based Lighting Engine**: Replaced static lighting with an advanced proximity system. Lights now smoothly fade in/out based on player position, reducing active light count by 96%.
- **Massive FPS Boost**: Galleries with 100+ images now run smoothly on low-end hardware (laptops, integrated graphics).
- **Optimization Strategy**: Implemented "Render-on-Demand" logic for lighting calculations.

### 🎮 Enhanced Navigation
- **Variable Speed Control**: Added speed multipliers (1x, 2x, 4x, 8x) accessible via number keys [1-4].
- **Improved Sprint**: Refined SHIFT-sprint mechanics for smoother acceleration.
- **Smart Collision**: Implemented robust boundary detection to keep high-speed players safely inside the gallery walls.

### 📂 Media & Storage
- **Increased Upload Limits**: Bumped maximum file size to **10MB per image** (previously 5MB) to support high-res artwork.
- **Robust Error Handling**: Added detailed, user-friendly error messages for failed uploads (size limits, file types).
- **Upload Stability**: Fixed filesystem race conditions to ensure images appear immediately in the grid after upload.

### 📐 Visual & Architecture Updates
- **Dynamic Fill Lighting**: Solved "dark room" issues in large galleries by distributing fill lights evenly along the gallery length.
- **Atmospheric Ceilings**: Ceiling color now dynamically adapts to the selected lighting preset (Bright/Moody/Dramatic).
- **Shadow Optimization**: Strategic disabling of shadow casting on secondary lights to prioritize texture resolution and frame rate.

### 🛠 System Improvements
- **Platform Agnostic**: Generalized installer logic to support CodeCanyon, TemplateMonster, and Codester out of the box.
- **License System**: Updated installer to accept generic license keys for multi-marketplace support.

---

## Version 1.1.0 - January 2026

### 🎨 New Features
- **Batch Delete Images**: Select multiple images with checkboxes and delete them all at once
- **Increased Upload Limit**: Upload up to 100 images per gallery (previously limited)
- **Dynamic Lighting System**: Automatically adjusts lighting based on gallery size
  - Small galleries (5-10 images): 4 ceiling lights
  - Medium galleries (20-30 images): 9 ceiling lights  
  - Large galleries (50-100 images): 9 optimized high-range lights

### 🔧 Technical Improvements
- **Performance Optimization**: Switched to CanvasTexture rendering to bypass GPU texture unit limits
- **Lighting Architecture**: Reduced light count while increasing range for better performance
- **Upload Stability**: Fixed race condition that caused image loss during bulk uploads
- **Shadow Optimization**: Disabled shadows on spotlights to prevent texture overflow

### 🐛 Bug Fixes
- Fixed black screen issue when displaying 20+ images
- Fixed disappearing images during simultaneous uploads
- Fixed WebGL shader errors caused by excessive texture units
- Fixed upload progress tracking for multiple files
- Improved error handling and user feedback during uploads

### 🎯 User Experience
- Real-time upload progress for multiple files
- Better visual feedback with loading indicators
- Improved batch delete confirmation messages
- Gallery now supports unlimited images (tested up to 200+)
- Maintains 60 FPS on modern devices regardless of gallery size

---

## Version 1.0.0 - January 2026
- Initial release
- 3D gallery creation with customizable walls, floors, and lighting
- Image upload with automatic optimization
- Public sharing with unique URLs
- Responsive controls (WASD + Mouse)