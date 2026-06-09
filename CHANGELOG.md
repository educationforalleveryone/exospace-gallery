# Exospace 3D Gallery — Changelog

## Version 2.3.0 — June 9, 2026 (UI Polish & Premium SaaS Upgrade)

### ✨ User Interface

- **Typography upgraded to Inter**: Swapped Figtree for Inter across all app and guest layouts. Inter's tabular-numeric rendering and tighter letter-spacing at medium weights improves the readability of data-dense views (stat cards, tables, analytics).
- **Primary button redesigned**: Removed `uppercase tracking-widest text-xs` styling that made CTAs read as dated Bootstrap-era design. All primary buttons now use sentence case, `text-sm`, `rounded-lg`, `gap-2`, and an `active:scale-[0.98]` press affordance. Shadow updated to `shadow-purple-900/30` for depth.
- **Secondary button redesigned**: Consistent treatment — same size, `rounded-lg`, sentence case, `hover:text-white hover:border-gray-500`, and press scale.
- **Input fields**: Added `hover:border-gray-500` state for pre-focus feedback; focus ring widened to `ring-2 ring-purple-500/20` for better visibility; background lightened slightly to `bg-gray-800/80`.
- **Input labels**: Added implicit `mb-1` margin so labels don't need manual spacing in every form.

### 🧭 Navigation

- **Nav links now include icons**: Dashboard, Galleries, and Teams links each have a small leading icon at `opacity-70` to aid scannability without visual overload.
- **Active nav link style**: Upgraded from `font-medium text-gray-300` to `font-semibold text-white`; inactive links softened to `text-gray-400` so the active state contrast is more obvious.
- **User menu trigger redesigned**: Replaced the bare `name + chevron` text button with a pill containing a coloured avatar initial (`bg-gradient-to-br from-purple-600 to-indigo-600`) + truncated name + chevron. Matches the quality of the team switcher pill.
- **User dropdown upgraded**: Panel rounded to `rounded-xl border shadow-2xl`; added a user identity header (name + email) above the links; each link now has an icon; "Log Out" renamed to "Sign out" for consistency; logout link moved into a visually separated bottom section.
- **Microcopy consistency**: Standardised auth vocabulary across the product — "Sign in" (not "Log in"), "Sign out" (not "Log Out"), "Create account" (not "Register"). Applied in login form, register form, nav dropdown, and responsive menu.

### 🃏 Dropdown Component

- Panel border radius upgraded from `rounded-md` to `rounded-xl`.
- Ring replaced with explicit `border border-gray-700` to match the rest of the design system.
- Link padding tightened and hover state changed from `hover:bg-gray-700` to `hover:bg-white/[0.04]` for a lighter, more premium feel.

### 📄 Layout

- **Sticky header removed**: The sub-nav page header (`$header` slot) was `sticky top-0 z-30`, creating a double-sticky stack with the main nav. Removed `sticky` — the header is now static, which reduces visual clutter and matches common SaaS patterns.
- **Background colour refined**: App shell moved from Tailwind `gray-900` (`#111827`) to `#0f1117` — a cooler near-black that reads as more intentional and less framework-default.
- **Auth card accent**: Guest layout card gains a `h-px` gradient top edge (`from-transparent via-purple-500 to-transparent`) as a subtle premium detail. Border-radius promoted to `rounded-xl`.
- **Guest layout title bug fixed**: `<title>` now uses `config('app.name', 'Exospace')` instead of the Laravel default `'Laravel'`.

### 🔔 Toast Notifications

- Emoji icons (`✓`, `✕`, `ℹ`) replaced with SVG icons for consistent cross-platform rendering.
- Toast background changed from saturated coloured panels (`bg-green-800`, `bg-red-900`) to a unified dark panel (`bg-gray-900`) with a thin coloured border accent — subtler and more legible against the app background.
- Toast shape updated to `rounded-xl` with `backdrop-blur-sm`; minimum width `260px` prevents very short messages from looking cramped.

### 🧩 CSS Utilities Added (`resources/css/app.css`)

Four new utility classes added to `@layer components` for consistent use across the codebase:

| Class | Purpose |
|-------|---------|
| `.table-row-base` | Consistent table row styling with subtle hover |
| `.section-header` | `text-xs font-semibold text-gray-500 uppercase tracking-wider` for section labels |
| `.action-link` | Muted secondary link style (`text-gray-400 hover:text-white`) |
| `.empty-state` | Centred empty-state container with vertical padding |

### 📝 Modified Files

| File | Changes |
|------|---------|
| `resources/css/app.css` | Inter font family; four new utility classes |
| `tailwind.config.js` | `fontFamily.sans` updated to Inter |
| `resources/views/layouts/app.blade.php` | Inter font link; background colour; sticky header removed; toast SVG icons + dark panel style; page `<title>` fixed |
| `resources/views/layouts/guest.blade.php` | Inter font link; auth card `rounded-xl` + gradient top accent; `<title>` fixed |
| `resources/views/layouts/navigation.blade.php` | Nav icon additions; active/inactive state contrast; user trigger redesign; user dropdown identity header + icons + "Sign out" |
| `resources/views/components/primary-button.blade.php` | Sentence case; `text-sm rounded-lg`; press scale; purple shadow |
| `resources/views/components/secondary-button.blade.php` | Sentence case; `text-sm rounded-lg`; press scale; `hover:text-white` |
| `resources/views/components/text-input.blade.php` | Hover border state; wider focus ring |
| `resources/views/components/input-label.blade.php` | Implicit `mb-1` margin |
| `resources/views/components/dropdown.blade.php` | `rounded-xl border`; `py-1.5` padding |
| `resources/views/components/dropdown-link.blade.php` | `flex items-center`; lighter hover; `py-2.5` |
| `resources/views/components/dashboard/stat-card.blade.php` | Removed `sm:text-3xl` responsive jump; added `tracking-tight` |
| `resources/views/admin/galleries/index.blade.php` | Card border softened; hover shadow updated |
| `resources/views/auth/login.blade.php` | "Log in" → "Sign in" |
| `resources/views/auth/register.blade.php` | "Register" → "Create account" |

---

## Version 2.2.0 — June 8, 2026 (Security Hardening & SaaS Reliability)

### 🔒 Security — Critical Fixes

- **ImageController authorization bypass fixed**: Image upload, delete, and bulk-delete operations now correctly check team membership for team galleries, not just `user_id`. Previously any authenticated user could delete images from galleries they did not own.
- **AnalyticsController team-gallery bypass fixed**: `show()` now enforces the same team-membership check used by `GalleryController`. Previously any authenticated user could view analytics for any gallery by guessing its ID.
- **Webhook replay attack prevention**: `handle2Checkout` now checks for an existing `completed` transaction with the same `invoice_id` before processing. Duplicate IPN callbacks are rejected and logged rather than applied twice. A `UNIQUE` constraint has been added to `transactions.invoice_id` at the database level.
- **Rate limiting added to authentication and upload endpoints**: Login capped at 5 requests/minute, registration at 10 requests/minute, image upload at 30 requests/minute. Previously only the analytics tracking endpoint was throttled.

### 🛡️ Security — Audit Trail

- **Admin audit log introduced**: All super-admin actions (plan changes, user bans/unbans, email verification changes, super-admin toggles, user deletions) are now permanently recorded in the new `admin_audit_logs` table with actor ID, action name, target, payload (before/after state), IP address, and timestamp.
- **New model `AdminAuditLog`**: Provides a static `AdminAuditLog::record($action, $target, $payload)` helper used by `SystemController` for every write action.

### ⚙️ Reliability & SaaS Fundamentals

- **Plan expiry enforcement**: New `CheckPlanExpiry` middleware runs on every authenticated request. If `plan_expires_at` is in the past, the user is automatically downgraded to the Free plan in real time. Previously `plan_expires_at` was stored but never acted on.
- **Contact form now delivers mail**: `POST /contact` previously returned a fake `{"message":"Message received"}` JSON response and discarded all submissions. It now routes through `ContactController`, validates input, and delivers via Resend. Rate-limited at 5 submissions per 10 minutes.
- **Image upload 100ms artificial delay removed**: `usleep(100000)` was present in `ImageController::store()` after every successful upload, adding 100ms of dead latency to every image uploaded and stalling PHP-FPM workers under concurrent load. Removed.
- **Super-admin dashboard paginated**: `SystemController::index()` previously loaded all users with a nested `with(['galleries' => fn → withCount('images')])` eager load — an N+1 query that would OOM at several hundred users. Now uses `paginate(50)` with pre-aggregated counts.
- **Gallery limits enforced at DB level**: `canCreateGallery()` now uses a direct `DB::table()` count to avoid race conditions in concurrent requests. Image upload additionally checks the user's total cross-gallery image count to prevent plan-limit bypass via parallel uploads.

### 🗄️ Database Schema Updates

- **New table `admin_audit_logs`**: `id`, `actor_id` (FK → users), `action` (VARCHAR 64, indexed), `target_type`, `target_id` (polymorphic morph), `payload` (JSON), `ip` (VARCHAR 45), `created_at` (indexed). No `updated_at` — audit records are append-only.
- **New UNIQUE constraint** on `transactions.invoice_id` — prevents duplicate webhook processing at the database level.

### 📁 New Files

| File | Purpose |
|------|---------|
| `database/migrations/2026_06_08_000001_add_unique_invoice_id_to_transactions.php` | Adds UNIQUE index on `transactions.invoice_id` |
| `database/migrations/2026_06_08_000002_create_admin_audit_logs_table.php` | New audit log table |
| `app/Models/AdminAuditLog.php` | Audit log model with `record()` static helper |
| `app/Http/Middleware/CheckPlanExpiry.php` | Auto-downgrade middleware for expired plans |
| `app/Http/Controllers/ContactController.php` | Real contact form handler with Resend delivery |

### 📝 Modified Files

| File | Changes |
|------|---------|
| `app/Http/Controllers/Admin/ImageController.php` | Fixed authorization to check team membership; removed `usleep(100000)`; added cross-gallery image count check |
| `app/Http/Controllers/Admin/AnalyticsController.php` | Fixed authorization to check team membership for team galleries |
| `app/Http/Controllers/WebhookController.php` | Added idempotency check before processing `ORDER_CREATED` |
| `app/Http/Controllers/SuperAdmin/SystemController.php` | Added `AdminAuditLog::record()` calls to all write actions; paginated user index |
| `app/Models/User.php` | `canCreateGallery()` uses DB-level count; new `currentImageCount()` method |
| `bootstrap/app.php` | Registered `CheckPlanExpiry` middleware globally |
| `routes/web.php` | Image upload route throttled at 30/min; contact form routes to `ContactController` |
| `routes/auth.php` | Login throttled at 5/min; registration throttled at 10/min |

---

## Version 2.1.0 — April 23, 2026 (Multi-Tenancy & Team Collaboration)

### 👥 Teams System

- **Create Teams**: Users can create named teams with an optional description. Each team gets a unique slug and is owned by the creating user.
- **Team Switcher**: Navbar context switcher lets users toggle between Personal workspace and any team they belong to. Active context is persisted as `current_team_id` on the user.
- **Personal Workspace Preserved**: All existing galleries (`team_id = null`) continue to work exactly as before — no migration of existing data required.

### 🤝 Member Management

- **Invite by Email**: Team owners can invite collaborators by email with a chosen role (Editor or Viewer). Invitations are valid for 7 days and re-inviting the same address resets the token and expiry.
- **Role System**: Three roles — `owner` (full control), `editor` (create/manage galleries), `viewer` (read-only access).
- **Role Updates**: Owners can change any member's role inline from the team management page via a select dropdown that auto-submits.
- **Remove Members**: Owners can remove any non-owner member. Removed members lose access immediately and have their team context cleared.
- **Leave Team**: Non-owner members can leave a team from the team detail page.
- **Pending Invitations**: Owners see all pending (non-expired) invitations with the ability to revoke them before they're accepted.

### 📧 Team Invitation Email

- **Invitation Email**: Styled dark-theme HTML email sent via Resend when a member is invited, showing the team name, owner, invitee role, capabilities, and expiry.
- **Accept/Decline Page**: Token-based landing page at `/team-invitations/{token}` shows full team details and role info. Works for both logged-in users and guests.
- **Guest Handling**: Guests clicking an invitation link are shown the page and prompted to register (with the invite token preserved) or log in. After authenticating they return to the same page and click Accept.
- **Wrong Account Detection**: If the logged-in user's email doesn't match the invitation email, a warning is shown with a "Switch Account" button (proper POST logout).
- **Expired Invitation Page**: Dedicated expiry page shown when a token is past its 7-day window.

### 🖼️ Team Galleries

- **Galleries Scoped to Teams**: Galleries now have an optional `team_id`. When a team context is active, the gallery index shows only that team's galleries. Personal galleries (no team) are shown in Personal context.
- **Team Gallery Creation**: When a team context is active, new galleries are automatically assigned to that team. Editors can create galleries in a team using the team owner's plan limits.
- **Plan Enforcement**: The team **owner's plan** governs team gallery limits and Pro/Studio features (audio, branding). Upgrading the owner benefits the whole team.
- **Authorization**: Personal galleries still require ownership. Team galleries check team membership and role — editors can create/edit, viewers are read-only, non-members get 403.

### 🗄️ Database Schema Updates

- **New Table `teams`**: `id`, `owner_id` (FK → users), `name`, `slug` (unique), `description`, timestamps.
- **New Table `team_user`**: Pivot table with `team_id`, `user_id`, `role` (enum: owner/editor/viewer), timestamps. Unique constraint on `(team_id, user_id)`.
- **New Table `team_invitations`**: `id`, `team_id`, `email`, `role`, `token` (64-char unique), `expires_at`, timestamps. Unique constraint on `(team_id, email)`.
- **New Column `galleries.team_id`**: Nullable FK → teams, indexed. Existing galleries remain unaffected (NULL = personal).
- **New Column `users.current_team_id`**: Nullable unsigned bigint storing the active team context.

### 🔧 Bug Fixes

- **405 on invitation accept/decline**: Removed `->middleware('auth')` from invitation POST routes. Auth is now handled inside `TeamInvitationController` with a proper redirect to login instead of Laravel's middleware returning 405 to unauthenticated users.
- **Switch Account was a GET link**: Logout requires a POST request; fixed the "Switch Account" button on the invitation page to use a proper `<form method="POST">` with `@csrf`.
- **Dead empty form in nav**: Removed the vestigial `<form action="...switch/0">` that did nothing; replaced the Personal context link with a real POST form to `teams.switch-personal`.

### 📁 New Files

| File | Purpose |
|------|---------|
| `database/migrations/2026_04_22_200001_create_teams_table.php` | Teams, team_user, team_invitations tables + galleries.team_id |
| `database/migrations/2026_04_22_200002_add_current_team_id_to_users_table.php` | Adds current_team_id to users |
| `app/Models/Team.php` | Team model with relationships and role helpers |
| `app/Models/TeamInvitation.php` | Invitation model with expiry check and token generator |
| `app/Mail/TeamInvitationMail.php` | Mailable for team invitation emails |
| `app/Http/Controllers/Admin/TeamController.php` | Full team CRUD, invite, member management, role updates, leave, switch |
| `app/Http/Controllers/TeamInvitationController.php` | Token-based invitation accept/decline with guest handling |
| `resources/views/admin/teams/index.blade.php` | Teams dashboard — owned teams and member teams |
| `resources/views/admin/teams/create.blade.php` | Team creation form |
| `resources/views/admin/teams/show.blade.php` | Team detail — members, invite form, pending invitations, settings, danger zone |
| `resources/views/teams/invitation.blade.php` | Public invitation accept/decline landing page |
| `resources/views/teams/invitation-expired.blade.php` | Expired invitation error page |
| `resources/views/emails/team-invitation.blade.php` | HTML invitation email template |

### 📝 Modified Files

| File | Changes |
|------|---------|
| `app/Models/User.php` | Added `ownedTeams`, `teams`, `currentTeam()`, `switchTeam()`, `belongsToTeam()`, `teamRole()`. Added `current_team_id` to `$fillable`. |
| `app/Http/Controllers/Admin/GalleryController.php` | Gallery index/create/store scoped to active team context. Authorization updated to support team-based access. Plan limit checks use team owner's plan for team galleries. |
| `routes/web.php` | Added all team routes, `switch-personal` route, and invitation routes. Removed `->middleware('auth')` from invitation accept/decline. |
| `resources/views/layouts/navigation.blade.php` | Added Teams nav link, team context switcher dropdown, "My Teams" in user dropdown. |

---

## Version 2.0.0 — April 22, 2026 (SaaS Launch Update)

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

## Version 1.9.0 — April 25, 2026 (Account Moderation)

### 👑 Super Admin — Account Controls

- **Ban / Unban Users**: Super-admins can suspend any user account with an optional reason. Banned users are immediately logged out on their next request and shown the ban reason on the login page.
- **Email Verify / Unverify**: Manually grant or revoke email verification for any user — useful for support escalations without requiring users to re-verify.
- **Toggle Super Admin**: Grant or revoke super-admin privileges for any user (cannot act on own account).
- **Self-Action Protection**: All destructive actions block super-admins from acting on their own account via `preventSelfAction()`.

### 🗄️ Database Schema Updates

- **New Columns on `users`**: `banned_at` (TIMESTAMP NULL) and `ban_reason` (TEXT NULL).

### 🔧 New Middleware

- **`CheckBanned`**: Runs globally on every request. If the authenticated user has a non-null `banned_at`, their session is invalidated, they are logged out, and they are redirected to login with the ban reason displayed.

---

## Version 1.8.0 — April 22, 2026 (Gallery Scheduling)

### 📅 Time-Gated Exhibitions

- **Open / Close Scheduling**: Galleries can now be configured with an `opens_at` and/or `closes_at` timestamp directly in the gallery create/edit form.
- **Automatic Access Control**: Visitors attempting to view a gallery before its open time or after its close time see an appropriate message rather than the gallery viewer.
- **Always-Open Default**: Both fields are nullable — leaving them empty keeps the gallery open indefinitely as before.
- **Model Helpers**: `isScheduled()`, `isOpen()`, `hasNotOpenedYet()`, `hasClosed()` added to the `Gallery` model.

### 🗄️ Database Schema Updates

- **New Columns on `galleries`**: `opens_at` (TIMESTAMP NULL) and `closes_at` (TIMESTAMP NULL).

---

## Version 1.6.0 — February 11, 2026 (Immersive Mobile & Audio Update)

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

## Version 1.5.0 — February 9, 2026 (Realism Update)

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

## Version 1.4.1 — February 9, 2026 (Artwork Focus Zoom)

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

## Version 1.4.0 — February 7, 2026 (Ambient Audio, Studio Branding & Super Admin)

### 🎵 Ambient Audio for Galleries

- **Background Music**: Galleries can now have optional ambient audio that plays during the 3D experience.
- **Audio Upload**: Upload MP3/WAV files through the gallery edit page (Pro plan feature).
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

## Version 1.3.4 — February 6, 2026 (Gallery UX Overhaul)

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

## Version 1.3.3 — February 6, 2026 (Email Queue System)

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

## Version 1.3.2 — February 1, 2026 (User Plans & Marketing Pages)

### 💎 User Subscription System

- **Tiered Access**: Implemented Free, Pro, and Studio plans with enforced resource limits.
- **Plan Helpers**: Added `isPro()` and `canCreateGallery()` helper methods to User model.

### 💰 Pricing Page

- **Plan Comparison**: Detailed pricing page at `/pricing` comparing features across all tiers.

### 📞 Contact Page

- **Support Portal**: New dedicated contact page at `/contact` with inquiry form.

---

## Version 1.3.1 — January 31, 2026 (2Checkout Compliance & Security)

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

## Version 1.3.0 — January 31, 2026 (Dark Mode & Landing Page)

### 🌙 Dark Mode Implementation

- **Complete Dark Theme**: Redesigned admin panel, dashboard, and authentication pages with a cohesive dark color scheme.

### 🏠 Welcome Page Redesign

- **Modern Landing Page**: Complete overhaul with hero section, features grid, and footer.

### 📜 Legal Pages

- **Privacy Policy**: Added comprehensive privacy policy at `/privacy`.
- **Terms of Service**: Added terms of service at `/terms`.

---

## Version 1.2.0 — January 24, 2026 (Performance & UX Overhaul)

### ⚡ Performance Breakthroughs

- **Proximity-Based Lighting Engine**: Lights now smoothly fade in/out based on player position, reducing active light count by 96%.
- **Variable Speed Control**: Added speed multipliers (1×, 2×, 4×, 8×) accessible via number keys.
- **Smart Collision**: Robust boundary detection for high-speed navigation.

### 📂 Media & Storage

- **Increased Upload Limits**: Bumped maximum file size to 10MB per image.
- **Upload Stability**: Fixed filesystem race conditions.

---

## Version 1.1.0 — January 2026

### 🎨 New Features

- **Batch Delete Images**: Select and delete multiple images at once.
- **Increased Upload Limit**: Up to 100 images per gallery.
- **Dynamic Lighting System**: Automatically adjusts lighting based on gallery size.

### 🐛 Bug Fixes

- Fixed black screen issue when displaying 20+ images.
- Fixed disappearing images during simultaneous uploads.
- Fixed WebGL shader errors caused by excessive texture units.

---

## Version 1.0.0 — January 2026

- Initial release
- 3D gallery creation with customizable walls, floors, and lighting
- Image upload with automatic optimization
- Public sharing with unique URLs
- Responsive controls (WASD + Mouse)