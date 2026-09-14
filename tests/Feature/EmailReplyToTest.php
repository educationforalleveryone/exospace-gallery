<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailReplyToTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbound_email_gets_a_replyable_default_reply_to(): void
    {
        Mail::raw('hello', function ($msg) {
            $msg->to('recipient@example.com');
        });

        $replyTo = Mail::mailer('array')->getSymfonyTransport()->messages()[0]
            ->getOriginalMessage()->getReplyTo();

        $this->assertNotEmpty($replyTo, 'Sent mail must carry a Reply-To when the From mailbox is a no-reply address.');
        $this->assertSame(config('mail.reply_to.address'), $replyTo[0]->getAddress());
    }

    public function test_mailable_sent_through_the_array_mailer_carries_default_reply_to(): void
    {
        $user = \App\Models\User::factory()->create();

        \Mail::to($user->email)->send(new \App\Mail\PasswordChangedNoticeMail($user));

        $replyTo = Mail::mailer('array')->getSymfonyTransport()->messages()[0]
            ->getOriginalMessage()->getReplyTo();

        $this->assertNotEmpty($replyTo);
        $this->assertSame(config('mail.reply_to.address'), $replyTo[0]->getAddress());
    }

    public function test_explicit_reply_to_recipient_is_preserved_alongside_the_default(): void
    {
        // The contact form replies to the visitor's own address — that
        // address must survive on the message alongside the default.
        Mail::raw('hello', function ($msg) {
            $msg->to('recipient@example.com')->replyTo('visitor@example.com', 'Visitor');
        });

        $replyTo = Mail::mailer('array')->getSymfonyTransport()->messages()[0]
            ->getOriginalMessage()->getReplyTo();
        $addresses = array_map(fn ($a) => $a->getAddress(), $replyTo);

        $this->assertContains('visitor@example.com', $addresses);
        $this->assertContains(config('mail.reply_to.address'), $addresses);
    }
}
