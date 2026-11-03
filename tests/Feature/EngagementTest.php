<?php

namespace Tests\Feature;

use App\Models\NewsletterSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EngagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_is_persisted_and_honeypot_rejects_spam(): void
    {
        $this->postJson('/api/contact', ['name' => 'Buyer', 'email' => 'buyer@example.com', 'message' => 'Please help with my order.'])->assertCreated();
        $this->assertDatabaseHas('store_messages', ['email' => 'buyer@example.com', 'handled' => false]);
        $this->postJson('/api/contact', ['name' => 'Spam', 'email' => 'spam@example.com', 'message' => 'Some spam message', 'website' => 'spam'])->assertUnprocessable();
    }

    public function test_newsletter_requires_consent_confirmation_and_supports_unsubscribe(): void
    {
        Mail::fake();
        $this->postJson('/api/newsletter', ['email' => 'reader@example.com'])->assertUnprocessable();
        $this->postJson('/api/newsletter', ['email' => 'reader@example.com', 'consent' => true])->assertOk();
        $subscription = NewsletterSubscription::firstOrFail();
        $this->assertNull($subscription->confirmed_at);
        $this->get(route('newsletter.confirm', ['token' => $subscription->token]))->assertOk()->assertSee('Subscription confirmed');
        $this->assertNotNull($subscription->fresh()->confirmed_at);
        $this->get(route('newsletter.unsubscribe', ['token' => $subscription->token]))->assertOk();
        $this->assertNotNull($subscription->fresh()->unsubscribed_at);
    }
}
