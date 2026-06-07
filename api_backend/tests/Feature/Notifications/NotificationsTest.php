<?php

/**
 * Notifications — current-user scoping, mark-as-read, and unread count.
 */

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_only_returns_my_notifications(): void
    {
        $me  = $this->actingAsRole('manager');
        $other = User::factory()->create();

        Notification::factory()->count(2)->state(['user_id' => $me->id])->create();
        Notification::factory()->state(['user_id' => $other->id])->create();

        $data = $this->getJson('/api/v1/notifications')->assertOk()->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $row) {
            $this->assertSame($me->id, $row['user_id']);
        }
    }

    public function test_unread_only_filter_and_unread_count(): void
    {
        $me = $this->actingAsRole('technician');
        Notification::factory()->state(['user_id' => $me->id])->create();
        Notification::factory()->state(['user_id' => $me->id])->read()->create();

        $unread = $this->getJson('/api/v1/notifications?unread_only=1')->assertOk()->json('data');
        $this->assertCount(1, $unread);
        $this->assertNull($unread[0]['read_at']);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
    }

    public function test_mark_as_read_is_idempotent(): void
    {
        $me = $this->actingAsRole('admin');
        $n  = Notification::factory()->state(['user_id' => $me->id])->create();

        $first = $this->postJson("/api/v1/notifications/{$n->id}/read")
            ->assertOk()->json('data');
        $this->assertNotNull($first['read_at']);

        $this->postJson("/api/v1/notifications/{$n->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $n->id);
    }

    public function test_mark_all_as_read(): void
    {
        $me = $this->actingAsRole('manager');
        Notification::factory()->count(3)->state(['user_id' => $me->id])->create();

        $this->postJson('/api/v1/notifications/mark-all-read')
            ->assertOk()
            ->assertJsonPath('data.marked', 3);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_cannot_mark_or_delete_someone_elses_notification(): void
    {
        $this->actingAsRole('technician');
        $otherUser = User::factory()->create();
        $n = Notification::factory()->state(['user_id' => $otherUser->id])->create();

        $this->postJson("/api/v1/notifications/{$n->id}/read")->assertNotFound();
        $this->deleteJson("/api/v1/notifications/{$n->id}")->assertNotFound();
    }

    public function test_destroy_removes_notification(): void
    {
        $me = $this->actingAsRole('admin');
        $n  = Notification::factory()->state(['user_id' => $me->id])->create();

        $this->deleteJson("/api/v1/notifications/{$n->id}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('notifications', ['id' => $n->id]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }
}
