<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscription;
use App\Models\StoreMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EngagementController extends Controller
{
    public function contact(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            'website' => ['nullable', 'max:0'],
        ]);
        StoreMessage::create(['name' => $data['name'], 'email' => Str::lower($data['email']), 'message' => $data['message']]);

        return response()->json(['message' => 'Your message has been received. Our team will reply by email.'], 201);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:160'], 'consent' => ['accepted']]);
        if (app()->environment('production') && config('mail.default') !== 'smtp') {
            return response()->json(['message' => 'Email subscriptions are temporarily unavailable. Please try again later.'], 503);
        }
        $email = Str::lower(trim($data['email']));
        $subscription = NewsletterSubscription::firstOrCreate(['email' => $email], ['token' => Str::random(64)]);
        if ((! $subscription->confirmed_at || $subscription->unsubscribed_at) && (! $subscription->confirmation_sent_at || $subscription->confirmation_sent_at->lt(now()->subMinutes(5)))) {
            $subscription->forceFill(['token' => Str::random(64)])->save();
            $url = route('newsletter.confirm', ['token' => $subscription->token]);
            try {
                Mail::raw("Confirm your Franconia email subscription by opening this link:\n{$url}\n\nIf you did not request this, ignore this email. You will not be subscribed without confirmation.", function ($message) use ($email) {
                    $message->to($email)->subject('Confirm your Franconia subscription');
                });
                $subscription->forceFill(['confirmation_sent_at' => now()])->save();
            } catch (\Throwable $e) {
                report($e);

                return response()->json(['message' => 'We could not send the confirmation email. Please try again later.'], 503);
            }
        }

        return response()->json(['message' => 'If this address needs confirmation, we have emailed a confirmation link.']);
    }

    public function confirm(string $token)
    {
        $subscription = NewsletterSubscription::where('token', $token)->firstOrFail();
        $subscription->forceFill(['confirmed_at' => now(), 'unsubscribed_at' => null])->save();

        return response()->view('newsletter.confirmed', ['unsubscribeUrl' => route('newsletter.unsubscribe', ['token' => $token])])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function unsubscribe(string $token)
    {
        NewsletterSubscription::where('token', $token)->firstOrFail()->forceFill(['unsubscribed_at' => now()])->save();

        return response('You have been unsubscribed from Franconia marketing emails.')->header('Cache-Control', 'no-store');
    }
}
