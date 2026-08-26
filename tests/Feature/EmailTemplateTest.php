<?php

declare(strict_types=1);

/**
 * Iteration-004 regression tests for B-9 (email table layout) and B-10 (preheader).
 *
 * Run: php artisan test --filter=EmailTemplateTest
 */

namespace Tests\Feature;

use App\Mail\WelcomeEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_b9_email_layout_uses_table_based_layout(): void
    {
        // B-9 FIX: the shared email layout should use <table role="presentation">
        $layoutPath = resource_path('views/emails/partials/layout.blade.php');
        $this->assertFileExists($layoutPath,
            'B-9: Shared email layout must exist at resources/views/emails/partials/layout.blade.php');

        $layout = file_get_contents($layoutPath);
        $this->assertStringContainsString('<table role="presentation"', $layout,
            'B-9: Email layout must use <table role="presentation"> for Outlook compatibility.');
        $this->assertStringNotContainsString('linear-gradient', $layout,
            'B-9: Email layout must NOT use linear-gradient (Outlook doesn\'t support it).');
        $this->assertStringNotContainsString('background-clip: text', $layout,
            'B-9: Email layout must NOT use background-clip: text (Outlook doesn\'t support it).');
    }

    public function test_b9_email_layout_has_inline_css(): void
    {
        // B-9 FIX: CSS should be inline (style="" attributes), not in <style> blocks
        // (Gmail strips <style> tags in <head>). The layout CAN have a <style> block
        // for dark mode (Apple Mail only), but the main styling must be inline.
        $layout = file_get_contents(resource_path('views/emails/partials/layout.blade.php'));

        // Check that the body has inline style
        $this->assertStringContainsString('style="', $layout,
            'B-9: Email layout must have inline CSS (style="" attributes).');
    }

    public function test_b9_welcome_email_uses_shared_layout(): void
    {
        // B-9 FIX: the welcome email should @extends the shared layout
        $welcome = file_get_contents(resource_path('views/emails/welcome.blade.php'));
        $this->assertStringContainsString("@extends('emails.partials.layout')", $welcome,
            'B-9: Welcome email must @extends the shared email layout.');
    }

    public function test_b10_welcome_email_has_preheader(): void
    {
        // B-10 FIX: the welcome email should have a preheader section
        $welcome = file_get_contents(resource_path('views/emails/welcome.blade.php'));
        $this->assertStringContainsString('@section(\'preheader\')', $welcome,
            'B-10: Welcome email must have a @section(\'preheader\') block.');

        // The preheader should be hidden (display:none or max-height:0)
        $this->assertStringContainsString('display:none', $welcome,
            'B-10: Preheader must be hidden from view (display:none).');
        $this->assertStringContainsString('max-height:0', $welcome,
            'B-10: Preheader must have max-height:0 for Gmail compatibility.');
    }

    public function test_b10_preheader_text_is_meaningful(): void
    {
        // B-10 FIX: the preheader text should be a meaningful preview (not empty)
        $welcome = file_get_contents(resource_path('views/emails/welcome.blade.php'));

        // Extract the preheader text.
        // ITERATION-1 FIX: the original regex had an unescaped ')' inside the
        // alternation group — preg_match failed to COMPILE ("unmatched closing
        // parenthesis") on every run. Escape the paren properly.
        preg_match('/@section\(\'preheader\'\)(.*?)(?:@endsection|@stop)/s', $welcome, $matches);
        $this->assertNotEmpty($matches, 'B-10: Preheader section must exist.');

        // ITERATION-1 FIX: capture group 1 holds the section body (group 2
        // was the now non-capturing alternation).
        $preheaderContent = $matches[1] ?? '';
        // The preheader should contain actual text (not just empty div tags)
        $this->assertStringContainsString('Your 3D gallery', $preheaderContent,
            'B-10: Preheader text should be meaningful (e.g. "Your 3D gallery awaits...").');
    }

    public function test_b9_email_layout_has_business_address_in_footer(): void
    {
        // B-9 / CAN-SPAM FIX: the email footer should include the business address
        $layout = file_get_contents(resource_path('views/emails/partials/layout.blade.php'));
        $this->assertStringContainsString('business_address', $layout,
            'B-9: Email layout footer must include the business address (CAN-SPAM compliance).');
    }

    public function test_b9_email_layout_has_unsubscribe_link_placeholder(): void
    {
        // B-9 / CAN-SPAM FIX: the email footer should support an unsubscribe link
        $layout = file_get_contents(resource_path('views/emails/partials/layout.blade.php'));
        $this->assertStringContainsString('unsubscribeUrl', $layout,
            'B-9: Email layout footer must support an unsubscribe link (CAN-SPAM compliance).');
    }

    public function test_b9_welcome_email_renders_without_errors(): void
    {
        // B-9 FIX: the welcome email should render without errors when passed a User
        $user = User::factory()->create(['name' => 'Test User']);

        $rendered = view('emails.welcome', ['user' => $user])->render();

        $this->assertStringNotContainsString('Undefined variable', $rendered,
            'B-9: Welcome email should render without "Undefined variable" errors.');
        $this->assertStringContainsString('Welcome to Exospace, Test User!', $rendered,
            'B-9: Welcome email should contain the user\'s name.');
        $this->assertStringContainsString('EXOSPACE', $rendered,
            'B-9: Welcome email should contain the brand logo.');
    }
}
