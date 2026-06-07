<?php

/**
 * Feedback / bug report endpoints.
 *
 * Any authenticated user can submit a report from the admin or mobile shell.
 * Admins can list, view, update (status / notes) and delete reports.
 */

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PaginatesResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\FeedbackReportResource;
use App\Models\FeedbackReport;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FeedbackReportController extends Controller
{
    use PaginatesResources;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'   => ['nullable', 'in:open,in_progress,resolved,closed'],
            'type'     => ['nullable', 'in:bug,feature,other'],
            'severity' => ['nullable', 'in:low,medium,high,critical'],
            'search'   => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = FeedbackReport::query()->with('user')->orderByDesc('created_at');

        foreach (['status', 'type', 'severity'] as $f) {
            if (! empty($data[$f])) {
                $query->where($f, $data[$f]);
            }
        }

        if (! empty($data['search'])) {
            $s = strtolower($data['search']);
            $query->where(function ($q) use ($s): void {
                $q->whereRaw('LOWER(title) LIKE ?', ["%{$s}%"])
                  ->orWhereRaw('LOWER(description) LIKE ?', ["%{$s}%"]);
            });
        }

        return $this->paginatedResponse(
            $query->paginate($data['per_page'] ?? 25),
            FeedbackReportResource::class,
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'        => ['required', 'in:bug,feature,other'],
            'severity'    => ['nullable', 'in:low,medium,high,critical'],
            'title'       => ['required', 'string', 'min:3', 'max:180'],
            'description' => ['required', 'string', 'min:5', 'max:5000'],
            'page_url'    => ['nullable', 'string', 'max:500'],
            'app_version' => ['nullable', 'string', 'max:40'],
            'metadata'    => ['nullable', 'array'],
        ]);

        try {
            $report = FeedbackReport::create([
                'user_id'     => $request->user()?->id,
                'type'        => $data['type'],
                'severity'    => $data['severity'] ?? 'medium',
                'status'      => 'open',
                'title'       => $data['title'],
                'description' => $data['description'],
                'page_url'    => $data['page_url']    ?? null,
                'user_agent'  => substr((string) $request->userAgent(), 0, 500),
                'app_version' => $data['app_version'] ?? null,
                'metadata'    => $data['metadata']    ?? null,
            ]);

            Log::info('feedback.created', ['id' => $report->id, 'type' => $report->type]);

            return ApiResponse::created(
                FeedbackReportResource::make($report->load('user'))->resolve(),
            );
        } catch (Throwable $e) {
            Log::error('feedback.create_failed', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return ApiResponse::error(
                'feedback_create_failed',
                'Could not save feedback report.',
                500,
            );
        }
    }

    /**
     * Public, anonymous submission — no auth required.
     *
     * Accepts the same payload as `store()` plus optional `reporter_name`
     * and `reporter_email` fields stuffed into the metadata blob so the
     * developer can follow up.
     */
    public function storePublic(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'           => ['required', 'in:bug,feature,other'],
            'severity'       => ['nullable', 'in:low,medium,high,critical'],
            'title'          => ['required', 'string', 'min:3', 'max:180'],
            'description'    => ['required', 'string', 'min:5', 'max:5000'],
            'page_url'       => ['nullable', 'string', 'max:500'],
            'app_version'    => ['nullable', 'string', 'max:40'],
            'reporter_name'  => ['nullable', 'string', 'max:120'],
            'reporter_email' => ['nullable', 'email', 'max:180'],
        ]);

        $metadata = array_filter([
            'reporter_name'  => $data['reporter_name']  ?? null,
            'reporter_email' => $data['reporter_email'] ?? null,
            'anonymous'      => true,
            'ip'             => $request->ip(),
        ], fn ($v) => $v !== null);

        try {
            $report = FeedbackReport::create([
                'user_id'     => $request->user()?->id, // null when anonymous
                'type'        => $data['type'],
                'severity'    => $data['severity'] ?? 'medium',
                'status'      => 'open',
                'title'       => $data['title'],
                'description' => $data['description'],
                'page_url'    => $data['page_url']    ?? null,
                'user_agent'  => substr((string) $request->userAgent(), 0, 500),
                'app_version' => $data['app_version'] ?? null,
                'metadata'    => $metadata,
            ]);

            Log::info('feedback.public_created', [
                'id'    => $report->id,
                'type'  => $report->type,
                'email' => $metadata['reporter_email'] ?? null,
            ]);

            return ApiResponse::created([
                'id'         => $report->id,
                'status'     => $report->status,
                'created_at' => $report->created_at,
            ]);
        } catch (Throwable $e) {
            Log::error('feedback.public_create_failed', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return ApiResponse::error(
                'feedback_create_failed',
                'Could not save feedback report.',
                500,
            );
        }
    }

    /**
     * Developer-token-guarded list (alias of index without admin role).
     */
    public function developerIndex(Request $request): JsonResponse
    {
        return $this->index($request);
    }


    public function show(FeedbackReport $feedbackReport): JsonResponse
    {
        return ApiResponse::ok(
            FeedbackReportResource::make($feedbackReport->load('user'))->resolve(),
        );
    }

    public function update(Request $request, FeedbackReport $feedbackReport): JsonResponse
    {
        $data = $request->validate([
            'status'      => ['nullable', 'in:open,in_progress,resolved,closed'],
            'severity'    => ['nullable', 'in:low,medium,high,critical'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (array_key_exists('status', $data) && in_array($data['status'], ['resolved', 'closed'], true)) {
            $data['resolved_at'] = now();
            $data['resolved_by'] = $request->user()?->id;
        }

        $feedbackReport->update($data);

        return ApiResponse::ok(
            FeedbackReportResource::make($feedbackReport->refresh()->load('user'))->resolve(),
        );
    }

    public function destroy(FeedbackReport $feedbackReport): JsonResponse
    {
        $feedbackReport->delete();

        return ApiResponse::ok(['deleted' => true]);
    }
}
