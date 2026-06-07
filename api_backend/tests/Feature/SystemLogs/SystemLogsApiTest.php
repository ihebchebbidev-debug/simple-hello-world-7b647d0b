<?php

/**
 * Smoke tests for the /api/logs endpoint added alongside the SystemLog model.
 * Covers RBAC (admin only), pagination payload shape, and the stats endpoint.
 */

declare(strict_types=1);

namespace Tests\Feature\SystemLogs;

use App\Models\SystemLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemLogsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_list_logs(): void
    {
        $this->actingAsRole('worker');

        $this->getJson('/api/logs')->assertForbidden();
    }

    public function test_admin_can_list_logs(): void
    {
        $admin = $this->actingAsRole('admin');

        SystemLog::record('test.event', 'hello world', ['x' => 1], $admin->id);

        $resp = $this->getJson('/api/logs')->assertOk();
        $resp->assertJsonStructure(['data' => [['id', 'event', 'message', 'context', 'created_at']]]);
    }

    public function test_admin_can_fetch_log_stats(): void
    {
        $this->actingAsRole('admin');
        SystemLog::record('a.b', 'm1');
        SystemLog::record('a.b', 'm2');
        SystemLog::record('c.d', 'm3');

        $this->getJson('/api/logs/stats')->assertOk();
    }
}
