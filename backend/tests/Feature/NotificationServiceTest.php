<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Platform\Notifications\NotificationLog;
use App\Platform\Notifications\NotificationPreference;
use App\Platform\Notifications\NotificationService;
use Tests\TenantTestCase;

class NotificationServiceTest extends TenantTestCase
{
    protected NotificationService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(NotificationService::class);
        $this->bindTenantContext(\Illuminate\Support\Str::uuid()->toString());

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_dispatch_sends_notification(): void
    {
        $this->service->dispatch('test.event', $this->user, [
            'subject' => 'Test Subject',
            'body' => 'Test body content',
        ]);

        $log = NotificationLog::where('user_id', $this->user->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame('mail', $log->channel);
        $this->assertNotNull($log->sent_at);
    }

    public function test_dispatch_respects_opt_out(): void
    {
        $this->service->setPreference($this->user->id, 'test.event', 'mail', false);

        $this->service->dispatch('test.event', $this->user, [
            'subject' => 'Should Not Send',
            'body' => 'This should not be sent',
        ]);

        $log = NotificationLog::where('user_id', $this->user->id)->first();
        $this->assertNull($log);
    }

    public function test_set_preference_creates_record(): void
    {
        $this->service->setPreference($this->user->id, 'approval.requested', 'mail', false);

        $pref = NotificationPreference::where('user_id', $this->user->id)->first();
        $this->assertNotNull($pref);
        $this->assertFalse($pref->mail_enabled);
        $this->assertSame('approval.requested', $pref->event);
    }

    public function test_set_preference_is_idempotent(): void
    {
        $this->service->setPreference($this->user->id, 'test.event', 'mail', false);
        $this->service->setPreference($this->user->id, 'test.event', 'mail', true);

        $count = NotificationPreference::where('user_id', $this->user->id)->count();
        $this->assertSame(1, $count);

        $pref = NotificationPreference::where('user_id', $this->user->id)->first();
        $this->assertTrue($pref->mail_enabled);
    }

    public function test_get_preferences_returns_all(): void
    {
        $this->service->setPreference($this->user->id, 'event.a', 'mail', true);
        $this->service->setPreference($this->user->id, 'event.b', 'mail', false);

        $prefs = $this->service->getPreferences($this->user->id);
        $this->assertCount(2, $prefs);
    }

    public function test_get_logs_returns_notifications(): void
    {
        $this->service->dispatch('event.a', $this->user, ['subject' => 'A', 'body' => 'Body A']);
        $this->service->dispatch('event.b', $this->user, ['subject' => 'B', 'body' => 'Body B']);

        $logs = $this->service->getLogs($this->user->id);
        $this->assertCount(2, $logs);
    }

    public function test_get_logs_filters_by_event(): void
    {
        $this->service->dispatch('event.a', $this->user, ['subject' => 'A', 'body' => 'Body A']);
        $this->service->dispatch('event.b', $this->user, ['subject' => 'B', 'body' => 'Body B']);

        $logs = $this->service->getLogs($this->user->id, 'event.a');
        $this->assertCount(1, $logs);
    }

    public function test_mark_read_sets_read_at(): void
    {
        $this->service->dispatch('test.event', $this->user, ['subject' => 'Test', 'body' => 'Body']);

        $log = NotificationLog::where('user_id', $this->user->id)->first();
        $this->assertNull($log->read_at);

        $result = $this->service->markRead($log->id);
        $this->assertTrue($result);

        $log->refresh();
        $this->assertNotNull($log->read_at);
    }
}
