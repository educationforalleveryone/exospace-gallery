<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonInterface;

/**
 * ITERATION 6 — the platform's release calendar, single source of truth.
 *
 * The changelog data lived as a private array inside ChangelogController —
 * fine while the only consumer was the public /changelog page. Iteration 6
 * annotates the Master Control TTFE trend chart with release markers so an
 * operator can see whether a release moved the headline metric ("did TTFE
 * drop after the publish-workflow rework shipped?"). Two copies of the
 * release list would drift, so the data moved here and BOTH consumers read
 * this service.
 *
 * Keep entries newest-first. The version numbering matches iteration
 * milestones (v1.7 = Iteration 017) and the date is the PRODUCTION DEPLOY
 * date — annotation truth depends on it being real, not aspirational.
 */
class ReleaseCalendar
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function releases(): array
    {
        return [
            [
                'version'   => 'v1.7',
                'date'      => '2026-07-04',
                'title'     => 'Subscriptions, Dunning & Invoicing',
                'highlights' => ['Recurring billing', 'PDF invoices', 'Failed payment recovery'],
                'features' => [
                    'Recurring subscription billing — choose between one-time purchase or monthly subscription',
                    'Dunning management — 3-email sequence recovers failed payments automatically',
                    'Customer invoicing — PDF invoices generated for every transaction, downloadable from billing portal',
                    'In-app notifications — bell icon shows billing, subscription, and system alerts',
                    'Feature flags — safe rollout/disable of features without code changes',
                    'Admin impersonation — super-admins can "Login As User" to debug issues',
                    'Public status page — /status shows real-time system health',
                ],
                'improvements' => [
                    'Billing portal redesigned with subscription status, cancel/reactivate, and invoice download',
                    'Feature comparison table on pricing page for easy plan comparison',
                    'Logged-out pricing flow deep-links to checkout after registration (1 fewer step)',
                    'Mobile tour button no longer intercepted by look-zone',
                    'Touch target sizes meet WCAG 2.5.5 Level AAA (44×44px)',
                    'MFA now available to all users (opt-in via profile page)',
                    'PII retention policy: customer data anonymized after 18 months (GDPR)',
                    'Dusk browser tests + MySQL CI job for better test coverage',
                ],
                'fixes' => [
                    'Fixed MFA session timestamp not enforced for regular users',
                    'Fixed tour button unclickable on mobile (z-index conflict)',
                    'Fixed touch targets below WCAG minimum size',
                ],
            ],
            [
                'version'   => 'v1.6',
                'date'      => '2026-07-04',
                'title'     => 'Performance, Scaling & Security',
                'highlights' => ['DB read replicas', 'Cache tags', 'Partitioned transactions'],
                'features' => [
                    'DB read/write splitting — SELECT queries route to read replicas',
                    'Cache tags for bulk invalidation — analytics caches refresh instantly on gallery updates',
                    'Transactions table partitioned by month for faster date-range queries',
                    'Hotwire Turbo Drive for SPA-like admin navigation',
                    'Cloudflare Turnstile captcha on public forms (contact, newsletter, RSVP)',
                    'Preflight check runs in CI — catches config regressions before deploy',
                ],
                'improvements' => [
                    'OG image generation 2× faster with Imagick',
                    'User::currentTeam() now eager-loadable (N+1 fixed)',
                    'Persistent MySQL connections (5-15ms saved per request)',
                    'Webhook lock TTL increased to 120s for better contention handling',
                    'Gallery events page N+1 query fixed (withCount cache)',
                    'Custom-domain lookup cached per gallery (was per-request)',
                    'Team::memberRole() memoized per request',
                    'Redis cache block_for=5 (no busy-poll on locks)',
                ],
                'fixes' => [
                    'Fixed image dimension cap (50MP) prevents OOM on huge uploads',
                    'Fixed per-user plan-change lock prevents race conditions',
                    'Fixed DNS verification parallelized via queued jobs (was sequential)',
                ],
            ],
            [
                'version'   => 'v1.5',
                'date'      => '2026-07-04',
                'title'     => 'Security Hardening & Accessibility',
                'highlights' => ['Signed invitation URLs', 'PII removal', 'WCAG compliance'],
                'features' => [
                    'Team invitations now use signed URLs (HMAC with APP_KEY)',
                    'Ban reason sanitized before reflecting in session error',
                    'Webhook endpoint throttled (60/min) against flood attacks',
                    'Coupon + affiliate URL params validated against allowlists',
                    'Feature comparison table on pricing page',
                    'Discover page loading overlay during pagination',
                ],
                'improvements' => [
                    'Removed customer_email PII from 2Checkout buy URL',
                    'Tour HUD ARIA labels for screen readers',
                    'Crosshair aria-hidden + sr-only text alternative',
                    'Keyboard focus indicators (:focus-visible) in 3D gallery',
                    'Dynamic <html lang> in 11 standalone views',
                    'Plan-expiring email deep-links to /billing/upgrade/{plan}',
                    'Same-plan renewal allowed when plan expires within 30 days',
                    'Plan limits centralized in config/plans.php',
                ],
                'fixes' => [
                    'Fixed modal focus trapping (Tab cycles within modal)',
                    'Fixed error toasts not announced immediately (role=alert)',
                    'Fixed newsletter form missing accessible label',
                    'Removed dead PendingUpgrade::isClaimable() method',
                    'Removed unused Sanctum package',
                ],
            ],
            [
                'version'   => 'v1.0',
                'date'      => '2026-06-30',
                'title'     => 'Initial Launch',
                'highlights' => ['3D gallery viewer', '11 venues', '2Checkout billing'],
                'features' => [
                    'Immersive 3D gallery viewer with WASD movement + guided tour',
                    '11 distinct 3D venue templates (White Cube, Industrial Loft, Crystal Cathedral, etc.)',
                    'One-time purchase billing via 2Checkout (Pro $29, Studio $99)',
                    'Custom domains for Studio plan (gallery.yourname.com)',
                    'White-label branding (no Exospace watermark)',
                    'Background music for Pro+ galleries',
                    'Exhibition scheduling (open/close dates)',
                    'PIN protection for private galleries',
                    'Analytics dashboard (views, dwell time, top artworks, referrers)',
                    'Team collaboration (invite editors + viewers)',
                    'Artist profiles with public pages',
                    'Gallery events with RSVP',
                    'Newsletter signup per gallery',
                    'Sitemap + RSS feed for SEO',
                    'Open Graph images per gallery + per artwork',
                    'QR codes for physical marketing',
                    'GDPR data export (profile, galleries, transactions)',
                    'CAN-SPAM unsubscribe + physical address in all emails',
                ],
                'improvements' => [],
                'fixes' => [],
            ],
        ];
    }

    /**
     * Minimal (version, date) pairs — newest first.
     *
     * @return array<int, array{version: string, date: string}>
     */
    public static function releaseDates(): array
    {
        return array_map(
            fn (array $r) => ['version' => (string) $r['version'], 'date' => (string) $r['date']],
            self::releases(),
        );
    }

    /**
     * Releases whose deploy date falls within [$from, $to] inclusive.
     *
     * Used by the Master Control trend chart: annotations only make sense
     * inside the charted window, and a 26-week chart must not draw eight
     * crowded lines. Releases sharing a date (v1.5–v1.7 all shipped
     * 2026-07-04) are merged into one annotation labelled
     * "v1.5 · v1.6 · v1.7" — one deploy event, one marker.
     *
     * @return array<int, array{version: string, date: string}> oldest first
     */
    public static function between(CarbonInterface $from, CarbonInterface $to): array
    {
        $byDate = [];

        foreach (self::releaseDates() as $release) {
            try {
                $date = \Carbon\Carbon::parse($release['date'])->startOfDay();
            } catch (\Throwable) {
                continue; // malformed entry — never break the dashboard
            }

            if ($date->between($from->copy()->startOfDay(), $to->copy()->endOfDay())) {
                $byDate[$date->toDateString()][] = $release['version'];
            }
        }

        ksort($byDate);

        $out = [];
        foreach ($byDate as $date => $versions) {
            $out[] = [
                'version' => implode(' · ', $versions),
                'date'    => $date,
            ];
        }

        return $out;
    }
}
