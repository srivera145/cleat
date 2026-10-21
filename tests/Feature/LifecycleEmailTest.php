<?php

declare(strict_types=1);

namespace Cleat\Tests\Feature;

use Cleat\Enums\SubscriptionStatus;
use Cleat\Events\TrialEnding;
use Cleat\Tests\Support\TestCase;

/** 0.1.3: the trial-ending notice and the subscription-canceled email. */
final class LifecycleEmailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->monthlyPrice(amount: 1200);
    }

    public function test_trial_ending_notice_goes_out_once_three_days_before(): void
    {
        $this->at('2026-03-01 09:00:00');
        $subscription = $this->customer()->newSubscription('premium-monthly')->withTrialDays(14)->create();

        $this->assertSame(0, $this->runAt('2026-03-12 08:59:00')->trialEndingNotices, 'more than 3 days left');
        $this->assertSame(1, $this->runAt('2026-03-12 09:00:00')->trialEndingNotices);
        $this->assertSame(0, $this->runAt('2026-03-12 09:00:00')->trialEndingNotices, 'claimed once');
        $this->assertSame(0, $this->runAt('2026-03-14 09:00:00')->trialEndingNotices);

        $this->assertCount(1, $this->eventsOf(TrialEnding::class));
        $this->assertNotNull($subscription->refresh()->trial_ending_notified_at);
        $mail = $this->mailer->to('jane@example.com');
        $this->assertCount(1, $mail);
        $this->assertSame('Your Premium trial ends on Mar 15, 2026', $mail[0]['subject']);
        $this->assertStringContainsString('March 15, 2026', $mail[0]['html']);
        $this->assertStringContainsString('$12.00', $mail[0]['html']);
        $this->assertStringContainsString('Visa ending', $mail[0]['html']);
        $this->assertStringContainsString("Cancel before the trial ends and you won't be charged.", (string) $mail[0]['text']);
    }

    public function test_no_notice_for_a_trial_set_to_cancel_or_when_turned_off(): void
    {
        $this->at('2026-03-01 09:00:00');
        $canceling = $this->customer('a@example.com')->newSubscription('premium-monthly')->withTrialDays(14)->create();
        $canceling->cancel();
        $this->mailer->clear();

        $this->assertSame(0, $this->runAt('2026-03-13 09:00:00')->trialEndingNotices);
        $this->assertSame([], $this->mailer->to('a@example.com'));

        $this->reconfigure(['trial_ending_days' => null]);
        $this->at('2026-03-01 09:00:00');
        $this->customer('b@example.com')->newSubscription('premium-monthly')->withTrialDays(14)->create();
        $this->assertSame(0, $this->runAt('2026-03-14 09:00:00')->trialEndingNotices);

        // Email off (trial_ending_days back to its default): the events fire, for b
        // and c, and nothing is sent.
        $this->reconfigure(['emails' => ['trial_ending' => false]]);
        $this->at('2026-03-01 09:00:00');
        $this->customer('c@example.com')->newSubscription('premium-monthly')->withTrialDays(14)->create();
        $this->assertSame(2, $this->runAt('2026-03-14 09:00:00')->trialEndingNotices);
        $this->assertCount(2, $this->eventsOf(TrialEnding::class));
        $this->assertSame([], $this->mailer->to('b@example.com'));
        $this->assertSame([], $this->mailer->to('c@example.com'));
    }

    public function test_cancel_at_period_end_sends_the_canceled_email_once(): void
    {
        $this->at('2026-01-10 00:00:00');
        $subscription = $this->customer()->newSubscription('premium-monthly')->create();
        $this->mailer->clear();

        $subscription->cancel();
        $subscription->cancel(); // asking twice does not send twice

        $mail = $this->mailer->to('jane@example.com');
        $this->assertCount(1, $mail);
        $this->assertSame('Your Premium subscription is canceled', $mail[0]['subject']);
        $this->assertStringContainsString('stays active until', $mail[0]['html']);
        $this->assertStringContainsString('February 10, 2026', $mail[0]['html']);
        $this->assertStringContainsString('reactivate', (string) $mail[0]['text']);

        // When the period actually ends, no second email: the customer was told the date.
        $this->runAt('2026-02-10 00:00:00');
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->refresh()->status);
        $this->assertCount(1, $this->mailer->to('jane@example.com'));
    }

    public function test_cancel_now_and_a_final_dunning_cancel_send_the_ended_email(): void
    {
        $this->at('2026-01-10 00:00:00');
        $now = $this->customer()->newSubscription('premium-monthly')->create();
        $this->mailer->clear();
        $now->cancelNow();
        $mail = $this->mailer->to('jane@example.com');
        $this->assertCount(1, $mail);
        $this->assertStringContainsString('ended on', (string) $mail[0]['text']);

        // A subscription that never started (declined first payment) gets no email when canceled.
        $declined = $this->customer('d@example.com', 'tok_decline');
        try {
            $declined->newSubscription('premium-monthly')->create();
        } catch (\Cleat\Exceptions\PaymentFailedException) {
        }
        $this->mailer->clear();
        $declined->subscription()?->cancelNow();
        $this->assertSame([], $this->mailer->to('d@example.com'));

        // The last failed retry with final_action canceled.
        $this->reconfigure(['automation' => ['final_action' => 'canceled', 'retry_attempts' => [2]]]);
        $this->at('2026-01-01 00:00:00');
        $bob = $this->customer('bob@example.com');
        $subscription = $bob->newSubscription('premium-monthly')->create();
        $this->gateway->setPaymentProfileToken($bob->gateway_customer_id, $bob->gateway_payment_id, 'tok_decline');
        $this->runAt('2026-02-01 00:00:00');
        $this->runAt('2026-02-03 00:00:00');
        $this->assertSame(SubscriptionStatus::Canceled, $subscription->refresh()->status);
        $subjects = array_column($this->mailer->to('bob@example.com'), 'subject');
        $this->assertContains('Your Premium subscription is canceled', $subjects);
    }

    public function test_canceled_email_can_be_turned_off(): void
    {
        $this->reconfigure(['emails' => ['subscription_canceled' => false]]);
        $this->at('2026-01-10 00:00:00');
        $subscription = $this->customer()->newSubscription('premium-monthly')->create();
        $this->mailer->clear();
        $subscription->cancel();
        $subscription->cancelNow();
        $this->assertSame([], $this->mailer->to('jane@example.com'));
    }

    public function test_config_rejects_bad_values(): void
    {
        foreach ([['trial_ending_days' => 0], ['trial_ending_days' => 31], ['trial_ending_days' => '3'], ['emails' => ['trial_ending' => 'yes']]] as $bad) {
            try {
                $this->reconfigure($bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (\Cleat\Exceptions\CleatException $e) {
                $this->assertStringContainsString('Invalid Cleat config', $e->getMessage());
            }
        }
    }
}
