<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SeoPage;
use Illuminate\Console\Command;

/**
 * SEO OS (Iteration 5): scaffold a new SEO page (landing or editorial).
 *
 * Creates a DRAFT with a sensible starter block structure. The page is
 * NOT published — publishing is a deliberate human action
 * (update status='published'), which keeps "no unreviewed content goes
 * indexable" enforced by default.
 *
 *   php artisan seo:make-page virtual-galleries --type=landing --title="Virtual Galleries"
 *   php artisan seo:make-page how-to-curate --type=editorial --title="How to Curate"
 *
 * Blocks are edited afterwards (tinker/admin UI) — this command only
 * provides the skeleton.
 */
class SeoMakePage extends Command
{
    protected $signature = 'seo:make-page
                            {slug : URL slug, e.g. virtual-galleries}
                            {--type=landing : landing|editorial}
                            {--title= : Page title (defaults to humanized slug)}
                            {--publish : Publish immediately (default: draft)}';

    protected $description = 'Scaffold a draft SEO landing/editorial page with starter blocks';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $type = (string) $this->option('type');
        $title = (string) ($this->option('title') ?: ucwords(str_replace(['-', '_'], ' ', $slug)));

        if (!in_array($type, SeoPage::TYPES, true)) {
            $this->error("Invalid type '{$type}'. Use: landing or editorial.");

            return self::FAILURE;
        }

        if (SeoPage::query()->where('slug', \Illuminate\Support\Str::slug($slug))->exists()) {
            $this->error("A page with slug '{$slug}' already exists.");

            return self::FAILURE;
        }

        $page = SeoPage::create([
            'type'     => $type,
            'slug'     => $slug,
            'title'    => $title,
            'status'   => 'draft',
            'blocks'   => $this->starterBlocks($type, $title),
            'author_id' => optional(auth()->user())->id,
        ]);

        $url = $page->public_url;
        $this->info("Created {$type} page: {$page->title}");
        $this->line("  URL (once published): {$url}");
        $this->line("  Preview now: {$url}?preview={$page->previewToken()}");
        $this->newLine();
        $this->line('Publish with:');
        $this->line("  php artisan tinker >>> App\\Models\\SeoPage::find({$page->id})->update(['status' => 'published'])");

        return self::SUCCESS;
    }

    /**
     * Starter blocks: hero → explanatory text → LIVE exhibitions (the
     * anti-thin-content block) → CTA. Editors replace copy; the structure
     * guarantees real-content linkage.
     *
     * @return array<int, array<string, mixed>>
     */
    private function starterBlocks(string $type, string $title): array
    {
        $blocks = [
            [
                'type' => 'hero',
                'data' => [
                    'eyebrow' => config('seo.site_name', 'Exospace'),
                    'title' => $title,
                    'subtitle' => 'Replace this subtitle with a factual, specific description of what this page covers.',
                    'cta_text' => 'Create your exhibition',
                    'cta_url' => url('/register'),
                ],
            ],
            [
                'type' => 'text',
                'data' => [
                    'heading' => 'About this page',
                    'body' => "Write the page's core content here. Keep it factual and useful: what the topic is, how Exospace relates to it, and what the visitor should do next.\n\nAvoid keyword stuffing — durable rankings come from genuinely useful pages linked to real content.",
                ],
            ],
        ];

        if ($type === 'landing') {
            $blocks[] = [
                'type' => 'exhibitions',
                'data' => ['heading' => 'Live 3D exhibitions', 'subtitle' => 'Real exhibitions created on the platform.'],
            ];
        }

        $blocks[] = [
            'type' => 'cta',
            'data' => [
                'title' => 'Build your own virtual exhibition',
                'text' => 'Upload your images, pick a venue, and share a link — no coding required.',
                'button_text' => 'Get started free',
                'button_url' => url('/register'),
            ],
        ];

        return $blocks;
    }
}
