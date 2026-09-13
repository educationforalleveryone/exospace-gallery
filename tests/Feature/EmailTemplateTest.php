<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\WelcomeEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_layout_uses_table_based_layout(): void
    {
        // the shared email layout should use <table role="presentation">
        $layoutPath = resource_path('views/emails/partials/layout.blade.php');
        $this->assertFileExists($layoutPath,
            'Shared email layout must exist at resources/views/emails/partials/layout.blade.php');

        $layout = file_get_contents($layoutPath);

        $layoutWithoutComments = trim(preg_replace('~\{\{--.*?--\}\}~s', '', $layout));

        $this->assertStringContainsString('<table role="presentation"', $layoutWithoutComments,
            'Email layout must use <table role="presentation"> for Outlook compatibility.');
        $this->assertStringNotContainsString('linear-gradient', $layoutWithoutComments,
            'Email layout must NOT use linear-gradient (Outlook doesn\'t support it).');
        $this->assertStringNotContainsString('background-clip: text', $layoutWithoutComments,
            'Email layout must NOT use background-clip: text (Outlook doesn\'t support it).');
    }

    public function test_email_layout_has_inline_css(): void
    {
        $layout = file_get_contents(resource_path('views/emails/partials/layout.blade.php'));

        // Check that the body has inline style
        $this->assertStringContainsString('style="', $layout,
            'Email layout must have inline CSS (style="" attributes).');
    }

    public function test_welcome_email_uses_shared_layout(): void
    {
        // the welcome email should @extends the shared layout
        $welcome = file_get_contents(resource_path('views/emails/welcome.blade.php'));
        $this->assertStringContainsString("@extends('emails.partials.layout')", $welcome,
            'Welcome email must @extends the shared email layout.');
    }

    public function test_welcome_email_has_preheader(): void
    {
        // the welcome email should have a preheader section
        $welcome = file_get_contents(resource_path('views/emails/welcome.blade.php'));
        $this->assertStringContainsString('@section(\'preheader\')', $welcome,
            'Welcome email must have a @section(\'preheader\') block.');

        // The preheader should be hidden (display:none or max-height:0)
        $this->assertStringContainsString('display:none', $welcome,
            'Preheader must be hidden from view (display:none).');
        $this->assertStringContainsString('max-height:0', $welcome,
            'Preheader must have max-height:0 for Gmail compatibility.');
    }

    public function test_preheader_text_is_meaningful(): void
    {
        // the preheader text should be a meaningful preview (not empty)
        $welcome = file_get_contents(resource_path('views/emails/welcome.blade.php'));

        preg_match('/@section\(\'preheader\'\)(.*?)(?:@endsection|@stop)/s', $welcome, $matches);
        $this->assertNotEmpty($matches, 'Preheader section must exist.');

        $preheaderContent = $matches[1] ?? '';
        // The preheader should contain actual text (not just empty div tags)
        $this->assertStringContainsString('Your 3D gallery', $preheaderContent,
            'Preheader text should be meaningful (e.g. "Your 3D gallery awaits...").');
    }

    public function test_email_layout_has_business_address_in_footer(): void
    {
        // the email footer should include the business address
        $layout = file_get_contents(resource_path('views/emails/partials/layout.blade.php'));
        $this->assertStringContainsString('business_address', $layout,
            'Email layout footer must include the business address (CAN-SPAM compliance).');
    }

    public function test_email_layout_has_unsubscribe_link_placeholder(): void
    {
        // the email footer should support an unsubscribe link
        $layout = file_get_contents(resource_path('views/emails/partials/layout.blade.php'));
        $this->assertStringContainsString('unsubscribeUrl', $layout,
            'Email layout footer must support an unsubscribe link (CAN-SPAM compliance).');
    }

    public function test_welcome_email_renders_without_errors(): void
    {
        // the welcome email should render without errors when passed a User
        $user = User::factory()->create(['name' => 'Test User']);

        $rendered = view('emails.welcome', ['user' => $user])->render();

        $this->assertStringNotContainsString('Undefined variable', $rendered,
            'Welcome email should render without "Undefined variable" errors.');
        $this->assertStringContainsString('Welcome to Exospace, Test User!', $rendered,
            'Welcome email should contain the user\'s name.');
        $this->assertStringContainsString('EXOSPACE', $rendered,
            'Welcome email should contain the brand logo.');
    }
}
