<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ContactController sends via Mail::raw(), which Mail::fake() does not
     * record (MailFake::raw is a no-op). The send is therefore observed by
     * mocking the Mailer and running the controller's message closure against
     * a real Message object — this exercises the actual recipient/reply-to/
     * subject wiring, not just a send count.
     */
    private function captureRawMail(): Email
    {
        $captured = new Email();

        Mail::shouldReceive('raw')->once()->andReturnUsing(
            function (string $text, \Closure $callback) use ($captured): void {
                $callback(new Message($captured));
            }
        );

        return $captured;
    }

    #[Test]
    public function contact_form_validates_required_fields(): void
    {
        Mail::shouldReceive('raw')->never();

        $this->post('/contact', [])
            ->assertSessionHasErrors(['name', 'email', 'message']);
    }

    #[Test]
    public function contact_form_rejects_oversized_input(): void
    {
        Mail::shouldReceive('raw')->never();

        $this->post('/contact', [
            'name'    => str_repeat('a', 101),
            'email'   => 'visitor@example.com',
            'message' => str_repeat('m', 5001),
        ])->assertSessionHasErrors(['name', 'message']);
    }

    #[Test]
    public function contact_form_rejects_invalid_email(): void
    {
        Mail::shouldReceive('raw')->never();

        $this->post('/contact', [
            'name'    => 'Visitor',
            'email'   => 'not-an-email',
            'message' => 'Hello there',
        ])->assertSessionHasErrors('email');
    }

    #[Test]
    public function contact_form_delivers_to_the_configured_recipient_with_reply_to(): void
    {
        config(['services.contact_form.email' => 'desk@example.com']);

        $captured = $this->captureRawMail();

        $this->post('/contact', [
            'name'    => 'Visitor',
            'email'   => 'visitor@example.com',
            'subject' => 'support',
            'message' => 'I need a hand with my gallery.',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame('desk@example.com', $captured->getTo()[0]->getAddress());
        // The visitor must be able to reply: their address rides as Reply-To.
        $this->assertSame('visitor@example.com', $captured->getReplyTo()[0]->getAddress());
    }

    #[Test]
    public function contact_form_falls_back_to_the_from_address_when_no_recipient_configured(): void
    {
        config([
            // An empty env value lands as an existing-but-blank key — exactly
            // the misconfiguration that would otherwise 500 every submission.
            'services.contact_form.email' => '',
            'mail.from.address'           => 'noreply@example.test',
        ]);

        $captured = $this->captureRawMail();

        $this->post('/contact', [
            'name'    => 'Visitor',
            'email'   => 'visitor@example.com',
            'message' => 'Hello',
        ])->assertRedirect();

        $this->assertSame('noreply@example.test', $captured->getTo()[0]->getAddress());
    }

    #[Test]
    public function contact_form_reports_failure_instead_of_claiming_success(): void
    {
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('transport down'));

        $this->post('/contact', [
            'name'    => 'Visitor',
            'email'   => 'visitor@example.com',
            'message' => 'Hello',
        ])->assertSessionHasErrors('message')->assertSessionMissing('status');
    }

    #[Test]
    public function contact_form_json_client_gets_machine_readable_contract(): void
    {
        Mail::shouldReceive('raw')->never();

        $this->postJson('/contact', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        config(['services.contact_form.email' => 'desk@example.com']);

        $this->captureRawMail();

        $this->postJson('/contact', [
            'name'    => 'Visitor',
            'email'   => 'visitor@example.com',
            'message' => 'Hello',
        ])->assertOk()->assertJsonPath('message', 'Message sent successfully.');
    }

    #[Test]
    public function contact_form_subject_line_never_carries_header_breaks(): void
    {
        config(['services.contact_form.email' => 'desk@example.com']);

        $captured = $this->captureRawMail();

        $this->post('/contact', [
            'name'    => "Visitor\r\nBcc: victim@example.com",
            'email'   => 'visitor@example.com',
            'message' => 'Hello',
        ])->assertRedirect();

        $subject = $captured->getHeaders()->get('Subject')?->getBody() ?? '';

        $this->assertStringNotContainsString("\r", $subject);
        $this->assertStringNotContainsString("\n", $subject);
    }
}
