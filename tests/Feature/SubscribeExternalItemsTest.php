<?php

namespace Tests\Feature;

use App\Department;
use App\Events\SubscriptionInitiated;
use App\Events\SubscriptionProcessed;
use App\Listeners\InformConcerned;
use App\Listeners\ProcessSubscription;
use App\Mail\SubscriptionCompleted;
use App\Mail\SubscriptionPrepared;
use App\Subscription;
use App\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscribeExternalItemsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function user_can_subscribe_to_items()
    {
        $this->withExceptionHandling();

        $deviceListProvidedThroughApi = [
            'item_id' => 123,
           'item_name' => 'Some Random Item',
        ];

        $user = factory(User::class)->create();

        $this->actingAs($user)->post(route('items.subscriptions.store'), $deviceListProvidedThroughApi);

        $this->assertSame(1, $user->fresh()->subscriptions()->count());
    }

    /**
     * @test
     */
    public function item_can_be_subscribed_once()
    {
        Event::fake();
        $this->withExceptionHandling();
        $user = factory(User::class)->create();

        $subscription = factory(Subscription::class)->create([
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
                ->post(route('items.subscriptions.store'), $subscription->toArray())
                ->assertStatus(401);
    }

    /**
     * @test
     */
    public function it_dispatches_event_on_subscription()
    {
        Event::fake();

        $user = factory(User::class)->create();

        $this->actingAs($user);

        $user->subscribe($itemId = 1, $itemCode = 'some-random-code')->announce();

        $subscription = Subscription::first();

        Event::assertDispatched(SubscriptionInitiated::class, function ($event) use ($subscription) {
            return $event->subscription->subscription_id === $subscription->subscription_id;
        });
    }

    /**
     * @test
     */
    public function it_notifies_the_concerned_when_asked_for_subscription()
    {
        Mail::fake();

        $user = factory(User::class)->make();

        $this->actingAs($user);

        $subscription = factory(Subscription::class)->make();

        (new ProcessSubscription())->handle(new SubscriptionInitiated($subscription));

        Mail::assertSent(SubscriptionPrepared::class, function ($mail) use ($subscription, $user) {
            return $mail->hasTo($user->department->head);
        });
    }

    /**
     * @test
     */
    public function guests_can_complete_the_subscription()
    {
        $department = factory(Department::class)->make();
        $this->withoutExceptionHandling();

        $subscription = factory(Subscription::class)->create([
            'subscription_code' => Str::uuid(),
        ]);

        $this->assertNull($subscription->approved_by);

        $this->get(route('items.subscriptions.update', [$subscription]));

        $this->assertNotNull($subscription->fresh()->approved_by);
    }

    /**
     * @test
     */
    public function guests_can_not_complete_the_subscription_twice()
    {
        $department = factory(Department::class)->make();

        $this->withExceptionHandling();

        $subscription = factory(Subscription::class)->create([
            'subscription_code' => Str::uuid(),
            'approved_at' => now(),
            'approved_by' => $department->head
        ]);

        $this->get(route('items.subscriptions.update', [$subscription]))
            ->assertStatus(403);
    }

    /**
     * @test
     */
    public function it_dispatches_event_on_approval()
    {
        $this->withoutExceptionHandling();
        Event::fake();

        $subscription = factory(Subscription::class)->create([
            'subscription_code' => Str::uuid(),
        ]);

        $this->get(route('items.subscriptions.update', [$subscription]));

        Event::assertDispatched(SubscriptionProcessed::class, function ($event) use ($subscription) {
            return $event->subscription->approved_by === $subscription->fresh()->approved_by;
        });
    }

    /**
     * @test
     */
    public function it_notifies_the_seeker_after_approval()
    {
        $this->withoutExceptionHandling();

        Mail::fake();

        $subscription = factory(Subscription::class)->make([
            'subscription_code' => Str::uuid(),
        ]);

        (new InformConcerned())->handle(new SubscriptionProcessed($subscription));

        Mail::assertSent(SubscriptionCompleted::class, function ($mail) use ($subscription) {
            return $mail->hasTo($subscription->user->email);
        });
    }

    /**
     * @test
     */
    public function department_head_can_reject_subscription()
    {
        $this->withoutExceptionHandling();
        Event::fake();

        $subscription = factory(Subscription::class)->create([
            'subscription_code' => Str::uuid(),
            'requested_at' => now(),
        ]);

        $this->get(route('items.subscriptions.reject', [$subscription]));

        $this->assertDatabaseMissing('subscriptions', $subscription->toArray());
    }

    /**
     * @test
     */
    public function it_dispatches_event_on_rejection()
    {
        Event::fake();

        $this->withoutExceptionHandling();

        $subscription = factory(Subscription::class)->create([
            'subscription_code' => Str::uuid(),
            'requested_at' => now(),
        ]);

        $this->get(route('items.subscriptions.reject', [$subscription]));

        Event::assertDispatched(SubscriptionProcessed::class, function ($event) use ($subscription) {
            return $event->subscription->requested_at = $subscription->requested_at;
        });
    }

    /**
     * @test
     */
    public function it_notifies_the_seeker_after_rejection()
    {
        $this->withoutExceptionHandling();

        $user = factory(User::class)->create();

        Mail::fake();

        $subscription = factory(Subscription::class)->make([
            'subscription_code' => Str::uuid(),
            'requested_at' => null,
        ]);

        (new InformConcerned())->handle(new SubscriptionProcessed($subscription));

        Mail::assertSent(SubscriptionCompleted::class, function ($mail) use ($subscription, $user) {
            return $mail->hasTo($subscription->user->email);
        });
    }

    /**
     * @test
     */
    public function user_can_return_item()
    {
        $this->withoutExceptionHandling();

        $user = factory(User::class)->create();

        $subscription = factory(Subscription::class)->create([
            'user_id' => $user->id,
            'subscription_code' => Str::uuid(),
            'requested_at' => now(),
            'approved_at' => now(),
        ]);

        $this->assertNull($subscription->returned_at);

        $this->actingAs($user)->post(route('items.subscriptions.destroy', [$subscription]));

        $this->assertNotNull($subscription->fresh()->returned_at);
    }

    /**
     * @test
     */
    public function user_can_not_return_same_item_twice()
    {
        $this->withExceptionHandling();

        $user = factory(User::class)->create();

        $subscription = factory(Subscription::class)->create([
            'user_id' => $user->id,
            'subscription_code' => Str::uuid(),
            'requested_at' => now(),
            'approved_at' => now(),
            'returned_at' => now()
        ]);

        $this->actingAs($user)->post(route('items.subscriptions.destroy', [$subscription]))
            ->assertStatus(200);
    }


    /**
     * @test
     */
    public function it_dispatches_event_when_an_item_is_returned()
    {
        Event::fake();

        $this->withoutExceptionHandling();

        $user = factory(User::class)->create();

        $subscription = factory(Subscription::class)->create([
            'user_id' => $user->id,
            'subscription_code' => Str::uuid(),
            'requested_at' => now(),
            'approved_at' => now(),
        ]);

        $this->actingAs($user)->post(route('items.subscriptions.destroy', [$subscription]));

        Event::assertDispatched(SubscriptionInitiated::class, function ($event) use ($subscription) {
            return $event->subscription->item_name === $subscription->fresh()->item_name;
        });
    }

    /**
     * @test
     */
    public function it_notifies_the_authority_when_item_is_returned()
    {
        $this->withoutExceptionHandling();

        $user = factory(User::class)->make();

        $this->actingAs($user);

        Mail::fake();

        $subscription = factory(Subscription::class)->create([
            'subscription_code' => Str::uuid(),
            'requested_at' => now(),
            'returned_at' => now(),
        ]);

        (new ProcessSubscription())->handle(new SubscriptionInitiated($subscription));

        Mail::assertSent(SubscriptionPrepared::class, function ($mail) use ($subscription, $user) {
            return $mail->hasTo($mail->user->department->head);
        });
    }
}
