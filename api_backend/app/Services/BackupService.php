<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BackupSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Handles full-database snapshot creation and point-in-time restoration.
 *
 * Snapshot strategy
 * ─────────────────
 * Every table listed in TABLES is serialised to a JSON array of rows and
 * stored in backup_snapshots.snapshot_data as a single LONGTEXT value.
 * Metadata (row counts, total size) is stored separately in the metadata
 * column so list queries never need to load the large payload.
 *
 * Restore strategy
 * ────────────────
 * 1. Delete existing rows in REVERSE dependency order (children first) so
 *    no FK violation occurs during deletion.
 * 2. Insert snapshot rows in FORWARD dependency order (parents first).
 * 3. The authenticated user performing the restore is kept alive throughout
 *    so their session is never invalidated.
 * 4. Only columns that exist in the current schema are written, making the
 *    restore forward-compatible with future migrations.
 * 5. Everything runs inside a single DB transaction; any failure rolls back
 *    the entire restore and marks the snapshot as restore_failed.
 */
final class BackupService
{
    /**
     * Tables included in every snapshot, listed in parent→child order.
     * The restore inserts in this order and deletes in the reverse order.
     *
     * Excluded intentionally:
     *   system_logs        – append-only audit trail; restoring it would corrupt history
     *   notifications      – ephemeral per-user events
     *   feedback_reports   – user feedback, not operational data
     *   personal_access_tokens – Sanctum tokens; never restore auth secrets
     */
    private const TABLES = [
        'users',
        'campaigns',
        'plots',
        'fertilizers',
        'pesticides',
        'pests',
        'water_config',
        'labor_config',
        'price_history',
        'postings',
        'user_roles',
        'irrigation_operations',
        'fertilization_operations',
        'phytosanitary_operations',
        'harvest_operations',
    ];

    // ── Snapshot creation ─────────────────────────────────────────────────────

    public function createSnapshot(User $createdBy, ?string $label = null): BackupSnapshot
    {
        $tables = [];
        $counts = [];
        $totalRecords = 0;

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)
                ->get()
                ->map(static fn (object $r): array => (array) $r)
                ->all();

            $tables[$table] = $rows;
            $counts[$table] = count($rows);
            $totalRecords += count($rows);
        }

        $payload = json_encode(
            ['version' => '1.0', 'tables' => $tables],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        $snapshot = new BackupSnapshot();
        $snapshot->id = (string) Str::uuid();
        $snapshot->label = $label ?? 'Checkpoint ' . now()->format('d/m/Y H:i');
        $snapshot->created_by = $createdBy->id;
        $snapshot->size_bytes = strlen($payload);
        $snapshot->status = 'ready';
        $snapshot->metadata = ['counts' => $counts, 'total_records' => $totalRecords];
        $snapshot->snapshot_data = $payload;   // raw JSON string, no Eloquent cast
        $snapshot->save();

        Log::info('backup.snapshot_created', [
            'snapshot_id'   => $snapshot->id,
            'created_by'    => $createdBy->id,
            'total_records' => $totalRecords,
            'size_bytes'    => $snapshot->size_bytes,
        ]);

        return $snapshot;
    }

    // ── Restoration ───────────────────────────────────────────────────────────

    public function restoreFromSnapshot(BackupSnapshot $snapshot, User $restoredBy): void
    {
        // Decode the raw JSON string (snapshot_data is NOT cast on the model)
        $raw = $snapshot->getRawOriginal('snapshot_data') ?? $snapshot->snapshot_data;
        $data = json_decode(
            is_string($raw) ? $raw : json_encode($raw),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (! isset($data['tables']) || ! is_array($data['tables'])) {
            throw new \RuntimeException('Invalid snapshot: missing "tables" key.');
        }

        $snapshotTables = $data['tables'];
        $deleteOrder    = array_reverse(self::TABLES);
        $insertOrder    = self::TABLES;

        $snapshot->status = 'restoring';
        $snapshot->save();

        try {
            DB::transaction(function () use ($snapshotTables, $deleteOrder, $insertOrder, $restoredBy): void {

                // ── Step 1: delete in reverse dependency order ────────────────
                foreach ($deleteOrder as $table) {
                    if (! Schema::hasTable($table) || ! array_key_exists($table, $snapshotTables)) {
                        continue;
                    }

                    if ($table === 'users') {
                        // Preserve the restoring user's row — their session must stay valid.
                        DB::table('users')->where('id', '!=', $restoredBy->id)->delete();
                    } else {
                        DB::table($table)->delete();
                    }
                }

                // ── Step 2: insert in dependency order ────────────────────────
                foreach ($insertOrder as $table) {
                    if (! Schema::hasTable($table) || ! array_key_exists($table, $snapshotTables)) {
                        continue;
                    }

                    $rows = $snapshotTables[$table];
                    if (empty($rows)) {
                        continue;
                    }

                    // Strip columns that no longer exist in the current schema
                    // so we stay forward-compatible with future migrations.
                    $existingCols = array_flip(Schema::getColumnListing($table));
                    $rows = array_map(
                        static fn (array $row): array => array_intersect_key($row, $existingCols),
                        $rows,
                    );

                    if ($table === 'users') {
                        foreach ($rows as $row) {
                            if ($row['id'] === $restoredBy->id) {
                                // Update the current user's profile from the snapshot
                                // but never touch their password or auth tokens.
                                $safe = array_diff_key(
                                    $row,
                                    array_flip(['password', 'password_hash', 'remember_token']),
                                );
                                DB::table('users')->where('id', $restoredBy->id)->update($safe);
                            } else {
                                DB::table('users')->insert($row);
                            }
                        }
                        continue;
                    }

                    // Chunk to stay under PostgreSQL's ~65 k parameter limit.
                    foreach (array_chunk($rows, 500) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }
            });

            $snapshot->status = 'ready';
            $snapshot->save();

            Log::info('backup.restore_success', [
                'snapshot_id' => $snapshot->id,
                'restored_by' => $restoredBy->id,
            ]);
        } catch (\Throwable $e) {
            // Mark the snapshot so the UI can show the failure without losing it.
            $snapshot->status = 'restore_failed';
            $snapshot->save();

            Log::error('backup.restore_failed', [
                'snapshot_id' => $snapshot->id,
                'restored_by' => $restoredBy->id,
                'error'       => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
