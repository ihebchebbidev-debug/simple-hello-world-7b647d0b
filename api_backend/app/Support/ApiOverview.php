<?php

/**
 * Static API reference catalogue.
 *
 * Single source of truth for the interactive docs page (resources/views/api-docs.blade.php)
 * and the JSON overview returned by `GET /api`. New endpoints should be appended
 * here so they appear automatically in the developer console.
 */

declare(strict_types=1);

namespace App\Support;

final class ApiOverview
{
    /** @return array<string, mixed> */
    public static function payload(): array
    {
        return [
            'name' => config('app.name'),
            'description' => 'AgriSync REST API',
            'version' => 'v1',
            'status' => 'ok',
            'auth' => [
                'type' => 'Bearer token',
                'header' => 'Authorization: Bearer {token}',
            ],
            'roles' => [
                'admin' => 'Full access. Inherits manager and technician permissions.',
                'manager' => 'Read access for operational configuration and reporting.',
                'technician' => 'Read access for mobile field workflows.',
            ],
            'endpoints' => self::endpoints(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function endpoints(): array
    {
        return [
            self::endpoint('Health', 'GET', '/api/health', 'Check service health.', false),

            self::endpoint('Auth', 'POST', '/api/auth/login', 'Login and receive a Sanctum bearer token.', false,
                ['email' => 'admin@agrisync.test', 'password' => 'Password123!', 'device_name' => 'api-docs']),
            self::endpoint('Auth', 'POST', '/api/auth/forgot-password', 'Send a password-reset email. Always 200.', false,
                ['email' => 'admin@agrisync.test']),
            self::endpoint('Auth', 'POST', '/api/auth/reset-password', 'Consume a reset token and set a new password.', false,
                ['email' => 'admin@agrisync.test', 'token' => 'reset-token', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!']),
            self::endpoint('Auth', 'GET', '/api/auth/me', 'Return the current authenticated user profile.'),
            self::endpoint('Auth', 'PATCH', '/api/auth/me', 'Update the authenticated user profile (name, email, language, password).', true,
                ['name' => 'Updated Name', 'preferred_lang' => 'fr', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!', 'current_password' => 'OldPassword123!']),
            self::endpoint('Auth', 'POST', '/api/auth/logout', 'Revoke the current bearer token.'),
            self::endpoint('Auth', 'POST', '/api/auth/logout-all', 'Revoke all bearer tokens for the current user.'),

            self::endpoint('Users', 'GET', '/api/users', 'List users. Admin only.', true, null,
                ['role' => 'admin|manager|technician', 'is_active' => 'true|false', 'search' => 'name or email', 'per_page' => '25']),
            self::endpoint('Users', 'POST', '/api/users', 'Create a user. Admin only.', true,
                ['name' => 'Field Manager', 'email' => 'field.manager@example.com', 'password' => 'Password123!', 'roles' => ['manager'], 'preferred_lang' => 'fr']),
            self::endpoint('Users', 'GET', '/api/users/{id}', 'View a user. Admin only.'),
            self::endpoint('Users', 'PUT', '/api/users/{id}', 'Update a user. Admin only.', true,
                ['name' => 'Updated Name', 'roles' => ['manager'], 'is_active' => true]),
            self::endpoint('Users', 'DELETE', '/api/users/{id}', 'Deactivate a user. Admin only.'),

            self::endpoint('Plots', 'GET', '/api/plots', 'List plots for technicians, managers, and admins.', true, null,
                ['is_active' => 'true|false', 'crop_type' => 'Olive', 'search' => 'plot name', 'per_page' => '25']),
            self::endpoint('Plots', 'POST', '/api/plots', 'Create a plot. Admin only.', true,
                ['name' => 'North Olive Plot', 'surface_area_ha' => 4.25, 'crop_type' => 'Olive', 'variety' => 'Picholine', 'season_start_date' => '2026-01-15', 'is_active' => true]),
            self::endpoint('Plots', 'GET', '/api/plots/{plot}', 'View a plot by id.'),
            self::endpoint('Plots', 'PUT', '/api/plots/{plot}', 'Update a plot. Admin only.', true,
                ['name' => 'Updated Plot', 'surface_area_ha' => 4.5, 'season_start_date' => '2026-01-15']),
            self::endpoint('Plots', 'DELETE', '/api/plots/{plot}', 'Deactivate a plot. Admin only.'),

            self::endpoint('Fertilizers', 'GET', '/api/fertilizers', 'List fertilizers for technicians, managers, and admins.', true, null,
                ['is_active' => 'true|false', 'unit' => 'kg', 'search' => 'fertilizer name', 'per_page' => '25']),
            self::endpoint('Fertilizers', 'POST', '/api/fertilizers', 'Create a fertilizer with N/P/K percentage fields. Admin only.', true,
                ['name' => 'NPK 15-15-15', 'unit' => 'kg', 'n_percent' => 15, 'p_percent' => 15, 'k_percent' => 15, 'is_active' => true]),
            self::endpoint('Fertilizers', 'GET', '/api/fertilizers/{fertilizer}', 'View a fertilizer by id.'),
            self::endpoint('Fertilizers', 'PUT', '/api/fertilizers/{fertilizer}', 'Update fertilizer details and N/P/K percentages. Admin only.', true,
                ['name' => 'Updated NPK', 'unit' => 'kg', 'n_percent' => 16, 'p_percent' => 12, 'k_percent' => 18]),
            self::endpoint('Fertilizers', 'DELETE', '/api/fertilizers/{fertilizer}', 'Deactivate a fertilizer. Admin only.'),

            self::endpoint('Pesticides', 'GET', '/api/pesticides', 'List pesticides. Technician/Manager/Admin.', true, null,
                ['is_active' => 'true|false', 'unit' => 'L', 'search' => 'name', 'per_page' => '25']),
            self::endpoint('Pesticides', 'POST', '/api/pesticides', 'Create a pesticide. Admin only.', true,
                ['name' => 'Copper Sulfate', 'unit' => 'L', 'chemical_composition' => 'CuSO4 25%', 'is_active' => true]),
            self::endpoint('Pesticides', 'GET', '/api/pesticides/{pesticide}', 'View a pesticide by id.'),
            self::endpoint('Pesticides', 'PUT', '/api/pesticides/{pesticide}', 'Update a pesticide. Admin only.', true,
                ['name' => 'Updated Pesticide', 'chemical_composition' => 'Updated formula']),
            self::endpoint('Pesticides', 'DELETE', '/api/pesticides/{pesticide}', 'Deactivate a pesticide. Admin only.'),

            self::endpoint('Water Config', 'GET', '/api/water-config', 'List water configurations.', true, null,
                ['is_active' => 'true|false', 'unit' => 'm3', 'per_page' => '25']),
            self::endpoint('Water Config', 'POST', '/api/water-config', 'Create a water configuration. Admin only.', true,
                ['unit' => 'm3', 'is_active' => true]),
            self::endpoint('Water Config', 'GET', '/api/water-config/{waterConfig}', 'View a water configuration by id.'),
            self::endpoint('Water Config', 'PUT', '/api/water-config/{waterConfig}', 'Update a water configuration. Admin only.', true,
                ['unit' => 'litres']),
            self::endpoint('Water Config', 'DELETE', '/api/water-config/{waterConfig}', 'Deactivate a water configuration. Admin only.'),

            self::endpoint('Labor Config', 'GET', '/api/labor-config', 'List labor configurations.', true, null,
                ['is_active' => 'true|false', 'per_page' => '25']),
            self::endpoint('Labor Config', 'POST', '/api/labor-config', 'Create a labor configuration. Admin only.', true,
                ['is_active' => true]),
            self::endpoint('Labor Config', 'GET', '/api/labor-config/{laborConfig}', 'View a labor configuration by id.'),
            self::endpoint('Labor Config', 'PUT', '/api/labor-config/{laborConfig}', 'Update a labor configuration. Admin only.', true,
                ['is_active' => true]),
            self::endpoint('Labor Config', 'DELETE', '/api/labor-config/{laborConfig}', 'Deactivate a labor configuration. Admin only.'),

            self::endpoint('Prices', 'GET', '/api/prices', 'List price history entries. Filter by entity, date, or current_only.', true, null,
                ['entity_type' => 'water|fertilizer|pesticide|labor', 'entity_id' => 'uuid', 'active_on' => '2026-01-01', 'current_only' => 'true', 'per_page' => '25']),
            self::endpoint('Prices', 'POST', '/api/prices', 'Create a price entry. Admin only.', true,
                ['entity_type' => 'water', 'entity_id' => null, 'price_per_unit' => 1.25, 'unit' => 'm3', 'effective_from' => '2026-01-01', 'effective_to' => null]),
            self::endpoint('Prices', 'GET', '/api/prices/{priceHistory}', 'View a price entry by id.'),
            self::endpoint('Prices', 'PUT', '/api/prices/{priceHistory}', 'Update a price entry. Admin only.', true,
                ['price_per_unit' => 1.5, 'effective_to' => '2026-12-31']),
            self::endpoint('Prices', 'DELETE', '/api/prices/{priceHistory}', 'Delete a price entry. Admin only.'),

            // ── Operations ────────────────────────────────────────────────────

            self::endpoint('Irrigation', 'GET', '/api/irrigation-operations', 'List irrigation operations. All roles.', true, null,
                ['plot_id' => 'uuid', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'per_page' => '25']),
            self::endpoint('Irrigation', 'POST', '/api/irrigation-operations',
                'Record an irrigation. Price and unit are auto-resolved from current config. Technician/Admin.', true,
                ['plot_id' => 'uuid', 'operation_date' => '2026-04-25', 'water_quantity' => 120.5]),
            self::endpoint('Irrigation', 'GET',    '/api/irrigation-operations/{irrigationOperation}', 'View an irrigation operation.'),
            self::endpoint('Irrigation', 'PUT',    '/api/irrigation-operations/{irrigationOperation}', 'Update an irrigation operation. Admin only.', true,
                ['water_quantity' => 130.0]),
            self::endpoint('Irrigation', 'DELETE', '/api/irrigation-operations/{irrigationOperation}', 'Delete an irrigation operation. Admin only.'),

            self::endpoint('Fertilization', 'GET', '/api/fertilization-operations', 'List fertilization operations. All roles.', true, null,
                ['plot_id' => 'uuid', 'fertilizer_id' => 'uuid', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'per_page' => '25']),
            self::endpoint('Fertilization', 'POST', '/api/fertilization-operations',
                'Record a fertilization. NPK and price are auto-snapshotted from fertilizer config. Technician/Admin.', true,
                ['plot_id' => 'uuid', 'fertilizer_id' => 'uuid', 'operation_date' => '2026-04-25', 'quantity_applied' => 50.0]),
            self::endpoint('Fertilization', 'GET',    '/api/fertilization-operations/{fertilizationOperation}', 'View a fertilization operation.'),
            self::endpoint('Fertilization', 'PUT',    '/api/fertilization-operations/{fertilizationOperation}', 'Update a fertilization operation. Admin only.', true,
                ['quantity_applied' => 55.0]),
            self::endpoint('Fertilization', 'DELETE', '/api/fertilization-operations/{fertilizationOperation}', 'Delete a fertilization operation. Admin only.'),

            self::endpoint('Phytosanitary', 'GET', '/api/phytosanitary-operations', 'List phytosanitary operations. All roles.', true, null,
                ['plot_id' => 'uuid', 'pesticide_id' => 'uuid', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'per_page' => '25']),
            self::endpoint('Phytosanitary', 'POST', '/api/phytosanitary-operations',
                'Record a pesticide application. Price auto-snapshotted. Technician/Admin.', true,
                ['plot_id' => 'uuid', 'pesticide_id' => 'uuid', 'operation_date' => '2026-04-25', 'quantity_applied' => 2.5, 'target_pest' => 'Botrytis', 'remarks' => null]),
            self::endpoint('Phytosanitary', 'GET',    '/api/phytosanitary-operations/{phytosanitaryOperation}', 'View a phytosanitary operation.'),
            self::endpoint('Phytosanitary', 'PUT',    '/api/phytosanitary-operations/{phytosanitaryOperation}', 'Update a phytosanitary operation. Admin only.', true,
                ['target_pest' => 'Updated pest', 'remarks' => 'Updated remarks']),
            self::endpoint('Phytosanitary', 'DELETE', '/api/phytosanitary-operations/{phytosanitaryOperation}', 'Delete a phytosanitary operation. Admin only.'),

            self::endpoint('Harvest', 'GET', '/api/harvest-operations', 'List harvest operations. All roles.', true, null,
                ['plot_id' => 'uuid', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'per_page' => '25']),
            self::endpoint('Harvest', 'POST', '/api/harvest-operations',
                'Record a harvest. Daily labor rate auto-snapshotted from price config. Technician/Admin.', true,
                ['plot_id' => 'uuid', 'operation_date' => '2026-11-10', 'num_workers' => 12, 'days_worked' => 2.5, 'quantity_harvested' => 3500.0]),
            self::endpoint('Harvest', 'GET',    '/api/harvest-operations/{harvestOperation}', 'View a harvest operation.'),
            self::endpoint('Harvest', 'PUT',    '/api/harvest-operations/{harvestOperation}', 'Update a harvest operation. Admin only.', true,
                ['quantity_harvested' => 3600.0]),
            self::endpoint('Harvest', 'DELETE', '/api/harvest-operations/{harvestOperation}', 'Delete a harvest operation. Admin only.'),

            // ── Campaigns ────────────────────────────────────────────────────
            self::endpoint('Campaigns', 'GET', '/api/campaigns', 'List campaigns. Technician/Manager/Admin.', true, null,
                ['is_active' => 'true|false', 'search' => 'name', 'per_page' => '25']),
            self::endpoint('Campaigns', 'POST', '/api/campaigns', 'Create a campaign. Admin only.', true,
                ['name' => 'Campagne 2026-A', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true]),
            self::endpoint('Campaigns', 'GET',    '/api/campaigns/{campaign}', 'View a campaign by id.'),
            self::endpoint('Campaigns', 'PUT',    '/api/campaigns/{campaign}', 'Update a campaign. Admin only.', true,
                ['name' => 'Campagne renommée']),
            self::endpoint('Campaigns', 'DELETE', '/api/campaigns/{campaign}', 'Deactivate a campaign. Admin only.'),

            // ── Pests ────────────────────────────────────────────────────────
            self::endpoint('Pests', 'GET', '/api/pests', 'List pests. Technician/Manager/Admin.', true, null,
                ['is_active' => 'true|false', 'category' => 'insect|fungus|weed', 'search' => 'name', 'per_page' => '25']),
            self::endpoint('Pests', 'POST', '/api/pests', 'Create a pest. Admin only.', true,
                ['name' => 'Mildiou', 'scientific_name' => 'Plasmopara viticola', 'category' => 'fungus', 'is_active' => true]),
            self::endpoint('Pests', 'GET',    '/api/pests/{pest}', 'View a pest by id.'),
            self::endpoint('Pests', 'PUT',    '/api/pests/{pest}', 'Update a pest. Admin only.', true,
                ['description' => 'Updated description']),
            self::endpoint('Pests', 'DELETE', '/api/pests/{pest}', 'Deactivate a pest. Admin only.'),

            // ── Notifications (current user) ─────────────────────────────────
            self::endpoint('Notifications', 'GET', '/api/notifications', 'List my notifications.', true, null,
                ['unread_only' => 'true|false', 'type' => 'info|warning|posting_failed', 'per_page' => '25']),
            self::endpoint('Notifications', 'GET', '/api/notifications/unread-count', 'Return my unread notification count.'),
            self::endpoint('Notifications', 'POST', '/api/notifications/mark-all-read', 'Mark all my notifications as read.'),
            self::endpoint('Notifications', 'POST', '/api/notifications/{notification}/read', 'Mark a notification as read.'),
            self::endpoint('Notifications', 'DELETE', '/api/notifications/{notification}', 'Delete one of my notifications.'),

            // ── Dashboard ────────────────────────────────────────────────────
            self::endpoint('Dashboard', 'GET', '/api/dashboard/stats', 'KPI counts and current-month totals.'),
            self::endpoint('Dashboard', 'GET', '/api/dashboard/recent-activity', 'Latest operations across all types.', true, null,
                ['limit' => '10']),

            // ── Offline sync queue ────────────────────────────────────────────

            self::endpoint('Postings', 'POST', '/api/postings',
                'Submit an offline-queued operation. Idempotent by client_id. Technician/Admin.', true,
                ['client_id' => 'client-uuid', 'operation_type' => 'irrigation', 'payload' => ['plot_id' => 'uuid', 'operation_date' => '2026-04-25', 'water_quantity' => 120.5], 'submitted_at' => '2026-04-25T08:00:00Z', 'device_info' => ['platform' => 'android']]),
            self::endpoint('Postings', 'POST', '/api/postings/bulk',
                'Submit a batch of offline-queued operations. Idempotent per client_id. Technician/Admin.', true,
                ['postings' => [['client_id' => 'a', 'operation_type' => 'irrigation', 'payload' => ['plot_id' => 'uuid', 'operation_date' => '2026-04-25', 'water_quantity' => 120.5]]]]),
            self::endpoint('Postings', 'GET', '/api/postings', 'List all postings. Admin only.', true, null,
                ['operation_type' => 'irrigation|fertilization|phytosanitary|harvest', 'status' => 'pending|synced|failed|conflict', 'per_page' => '25']),
            self::endpoint('Postings', 'GET',  '/api/postings/{posting}', 'View a posting. Technician/Admin.'),
            self::endpoint('Postings', 'POST', '/api/postings/{posting}/retry', 'Retry a failed posting. Admin only.'),

            // ── Reports ───────────────────────────────────────────────────────

            self::endpoint('Reports', 'GET', '/api/reports/irrigation',
                'Irrigation report: monthly water usage per ha + cumulative since season start. Manager/Admin.', true, null,
                ['plot_ids[]' => 'uuid', 'campaign_id' => 'uuid', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31']),
            self::endpoint('Reports', 'GET', '/api/reports/fertilization',
                'Fertilization report: monthly and cumulative N/P/K per hectare. Manager/Admin.', true, null,
                ['plot_ids[]' => 'uuid', 'campaign_id' => 'uuid', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31']),
            self::endpoint('Reports', 'GET', '/api/reports/phytosanitary',
                'Phytosanitary report: treatment log per plot with pesticide details. Manager/Admin.', true, null,
                ['plot_ids[]' => 'uuid', 'campaign_id' => 'uuid', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'q' => 'keyword']),
            self::endpoint('Reports', 'GET', '/api/reports/harvest',
                'Harvest report: yield log per plot with worker-day totals. Manager/Admin.', true, null,
                ['plot_ids[]' => 'uuid', 'campaign_id' => 'uuid', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31']),
            self::endpoint('Reports', 'GET', '/api/reports/production-cost',
                'Production cost report: per-plot breakdown of irrigation, fertilization, phytosanitary, and harvest costs. Manager/Admin.', true, null,
                ['plot_ids[]' => 'uuid', 'campaign_id' => 'uuid', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31']),

            // ── System Logs (admin audit trail) ──────────────────────────────
            self::endpoint('Logs', 'GET', '/api/logs',
                'List system audit log entries. Admin only.', true, null,
                ['level' => 'info|warn|error|debug', 'category' => 'auth|sync|admin|operation|system', 'action' => 'string', 'entity_type' => 'string', 'entity_id' => 'uuid', 'user_id' => 'uuid', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'search' => 'keyword', 'per_page' => '50']),
            self::endpoint('Logs', 'GET', '/api/logs/stats',
                'Aggregated counts (total, last 24h, by level, by category). Admin only.'),
            self::endpoint('Logs', 'GET', '/api/logs/{systemLog}',
                'View a single audit log entry. Admin only.'),
            self::endpoint('Logs', 'POST', '/api/logs',
                'Record an audit log entry (used by the app itself; admin only when called externally).', true,
                ['level' => 'info', 'category' => 'admin', 'action' => 'plot.deactivated', 'entity_type' => 'plot', 'entity_id' => 'uuid', 'details' => ['reason' => 'end of season']]),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>|null  $query
     * @return array<string, mixed>
     */
    private static function endpoint(
        string $group,
        string $method,
        string $path,
        string $description,
        bool $requiresAuth = true,
        ?array $body = null,
        ?array $query = null,
    ): array {
        return [
            'group' => $group,
            'method' => $method,
            'path' => $path,
            'description' => $description,
            'requires_auth' => $requiresAuth,
            'body' => $body,
            'query' => $query,
        ];
    }
}
