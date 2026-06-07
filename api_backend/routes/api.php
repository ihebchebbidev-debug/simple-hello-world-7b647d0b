<?php

/**
 * API routes.
 *
 * Public endpoints sit at the top; everything that needs a logged-in user is
 * inside the `auth:sanctum` group. Each resource is registered under both an
 * unversioned path and a `/v1/...` alias so we can introduce `/v2` later
 * without breaking existing clients.
 */

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FeedbackReportController;
use App\Http\Controllers\Api\FertilizationOperationController;
use App\Http\Controllers\Api\FertilizerController;
use App\Http\Controllers\Api\HarvestOperationController;
use App\Http\Controllers\Api\IrrigationOperationController;
use App\Http\Controllers\Api\LaborConfigController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PesticideController;
use App\Http\Controllers\Api\PestController;
use App\Http\Controllers\Api\PhytosanitaryOperationController;
use App\Http\Controllers\Api\PlotController;
use App\Http\Controllers\Api\PostingController;
use App\Http\Controllers\Api\PriceHistoryController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SystemLogController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WaterConfigController;
use App\Support\ApiOverview;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(ApiOverview::payload()));

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'service' => config('app.name'),
    'time' => now()->toIso8601String(),
]));

/**
 * Register the same group of routes under multiple prefixes.
 *
 * @param  array<int, string>  $prefixes
 * @param  array<int, string>|string  $middleware
 */
$registerGroup = function (array $prefixes, array|string $middleware, \Closure $routes): void {
    foreach ($prefixes as $prefix) {
        Route::prefix($prefix)->middleware($middleware)->group($routes);
    }
};

$registerGroup(['auth', 'v1/auth'], 'throttle:10,1', function (): void {
    Route::get('setup-status', [AuthController::class, 'setupStatus']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::patch('me', [AuthController::class, 'updateMe']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-all', [AuthController::class, 'logoutAll']);
    });
});

$registerGroup(['users', 'v1/users'], ['auth:sanctum', 'role:admin'], function (): void {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::get('{id}', [UserController::class, 'show']);
    Route::put('{id}', [UserController::class, 'update']);
    Route::delete('{id}', [UserController::class, 'destroy']);
});

$registerGroup(['plots', 'v1/plots'], 'auth:sanctum', function (): void {
    Route::get('/', [PlotController::class, 'index'])->middleware('role:technician,manager,admin');
    Route::get('{plot}', [PlotController::class, 'show'])->middleware('role:technician,manager,admin');

    Route::middleware('role:admin')->group(function (): void {
        Route::post('/', [PlotController::class, 'store']);
        Route::put('{plot}', [PlotController::class, 'update']);
        Route::delete('{plot}', [PlotController::class, 'destroy']);
    });
});

$registerGroup(['fertilizers', 'v1/fertilizers'], 'auth:sanctum', function (): void {
    Route::get('/', [FertilizerController::class, 'index'])->middleware('role:technician,manager,admin');
    Route::get('{fertilizer}', [FertilizerController::class, 'show'])->middleware('role:technician,manager,admin');

    Route::middleware('role:admin')->group(function (): void {
        Route::post('/', [FertilizerController::class, 'store']);
        Route::put('{fertilizer}', [FertilizerController::class, 'update']);
        Route::delete('{fertilizer}', [FertilizerController::class, 'destroy']);
    });
});

$registerGroup(['pesticides', 'v1/pesticides'], 'auth:sanctum', function (): void {
    Route::get('/', [PesticideController::class, 'index'])->middleware('role:technician,manager,admin');
    Route::get('{pesticide}', [PesticideController::class, 'show'])->middleware('role:technician,manager,admin');

    Route::middleware('role:admin')->group(function (): void {
        Route::post('/', [PesticideController::class, 'store']);
        Route::put('{pesticide}', [PesticideController::class, 'update']);
        Route::delete('{pesticide}', [PesticideController::class, 'destroy']);
    });
});

$registerGroup(['water-config', 'v1/water-config'], 'auth:sanctum', function (): void {
    Route::get('/', [WaterConfigController::class, 'index'])->middleware('role:technician,manager,admin');
    Route::get('{waterConfig}', [WaterConfigController::class, 'show'])->middleware('role:technician,manager,admin');

    Route::middleware('role:admin')->group(function (): void {
        Route::post('/', [WaterConfigController::class, 'store']);
        Route::put('{waterConfig}', [WaterConfigController::class, 'update']);
        Route::delete('{waterConfig}', [WaterConfigController::class, 'destroy']);
    });
});

$registerGroup(['labor-config', 'v1/labor-config'], 'auth:sanctum', function (): void {
    Route::get('/', [LaborConfigController::class, 'index'])->middleware('role:technician,manager,admin');
    Route::get('{laborConfig}', [LaborConfigController::class, 'show'])->middleware('role:technician,manager,admin');

    Route::middleware('role:admin')->group(function (): void {
        Route::post('/', [LaborConfigController::class, 'store']);
        Route::put('{laborConfig}', [LaborConfigController::class, 'update']);
        Route::delete('{laborConfig}', [LaborConfigController::class, 'destroy']);
    });
});

$registerGroup(['prices', 'v1/prices'], 'auth:sanctum', function (): void {
    Route::get('/', [PriceHistoryController::class, 'index'])->middleware('role:technician,manager,admin');
    Route::get('{priceHistory}', [PriceHistoryController::class, 'show'])->middleware('role:technician,manager,admin');

    Route::middleware('role:admin')->group(function (): void {
        Route::post('/', [PriceHistoryController::class, 'store']);
        Route::put('{priceHistory}', [PriceHistoryController::class, 'update']);
        Route::delete('{priceHistory}', [PriceHistoryController::class, 'destroy']);
    });
});

// ─── Operations (technician creates, all roles read, admin updates/deletes) ───

$registerGroup(['irrigation-operations', 'v1/irrigation-operations'], 'auth:sanctum', function (): void {
    Route::get('/', [IrrigationOperationController::class, 'index'])->middleware('role:technician,manager,admin');
    Route::get('{irrigationOperation}', [IrrigationOperationController::class, 'show'])->middleware('role:technician,manager,admin');
    Route::post('/', [IrrigationOperationController::class, 'store'])->middleware('role:technician,admin');

    Route::middleware('role:admin')->group(function (): void {
        Route::put('{irrigationOperation}', [IrrigationOperationController::class, 'update']);
        Route::delete('{irrigationOperation}', [IrrigationOperationController::class, 'destroy']);
    });
});

$registerGroup(['fertilization-operations', 'v1/fertilization-operations'], 'auth:sanctum', function (): void {
    Route::get('/', [FertilizationOperationController::class, 'index'])->middleware('role:technician,manager,admin');
    Route::get('{fertilizationOperation}', [FertilizationOperationController::class, 'show'])->middleware('role:technician,manager,admin');
    Route::post('/', [FertilizationOperationController::class, 'store'])->middleware('role:technician,admin');

    Route::middleware('role:admin')->group(function (): void {
        Route::put('{fertilizationOperation}', [FertilizationOperationController::class, 'update']);
        Route::delete('{fertilizationOperation}', [FertilizationOperationController::class, 'destroy']);
    });
});

$registerGroup(['phytosanitary-operations', 'v1/phytosanitary-operations'], 'auth:sanctum', function (): void {
    Route::get('/', [PhytosanitaryOperationController::class, 'index'])->middleware('role:technician,manager,admin');
    Route::get('{phytosanitaryOperation}', [PhytosanitaryOperationController::class, 'show'])->middleware('role:technician,manager,admin');
    Route::post('/', [PhytosanitaryOperationController::class, 'store'])->middleware('role:technician,admin');

    Route::middleware('role:admin')->group(function (): void {
        Route::put('{phytosanitaryOperation}', [PhytosanitaryOperationController::class, 'update']);
        Route::delete('{phytosanitaryOperation}', [PhytosanitaryOperationController::class, 'destroy']);
    });
});

$registerGroup(['harvest-operations', 'v1/harvest-operations'], 'auth:sanctum', function (): void {
    Route::get('/', [HarvestOperationController::class, 'index'])->middleware('role:technician,manager,admin');
    Route::get('{harvestOperation}', [HarvestOperationController::class, 'show'])->middleware('role:technician,manager,admin');
    Route::post('/', [HarvestOperationController::class, 'store'])->middleware('role:technician,admin');

    Route::middleware('role:admin')->group(function (): void {
        Route::put('{harvestOperation}', [HarvestOperationController::class, 'update']);
        Route::delete('{harvestOperation}', [HarvestOperationController::class, 'destroy']);
    });
});

// ─── Campaigns ────────────────────────────────────────────────────────────────

$registerGroup(['campaigns', 'v1/campaigns'], 'auth:sanctum', function (): void {
    Route::get('/', [CampaignController::class, 'index'])->middleware('role:technician,manager,admin');
    Route::get('{campaign}', [CampaignController::class, 'show'])->middleware('role:technician,manager,admin');

    Route::middleware('role:admin')->group(function (): void {
        Route::post('/', [CampaignController::class, 'store']);
        Route::put('{campaign}', [CampaignController::class, 'update']);
        Route::delete('{campaign}', [CampaignController::class, 'destroy']);
    });
});

// ─── Pests ────────────────────────────────────────────────────────────────────

$registerGroup(['pests', 'v1/pests'], 'auth:sanctum', function (): void {
    Route::get('/', [PestController::class, 'index'])->middleware('role:technician,manager,admin');
    Route::get('{pest}', [PestController::class, 'show'])->middleware('role:technician,manager,admin');

    Route::middleware('role:admin')->group(function (): void {
        Route::post('/', [PestController::class, 'store']);
        Route::put('{pest}', [PestController::class, 'update']);
        Route::delete('{pest}', [PestController::class, 'destroy']);
    });
});

// ─── Notifications (current user only) ───────────────────────────────────────

$registerGroup(['notifications', 'v1/notifications'], 'auth:sanctum', function (): void {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::post('{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::delete('{notification}', [NotificationController::class, 'destroy']);
});

// ─── Dashboard (technician/manager/admin) ─────────────────────────────────────

$registerGroup(['dashboard', 'v1/dashboard'], ['auth:sanctum', 'role:technician,manager,admin'], function (): void {
    Route::get('stats',           [DashboardController::class, 'stats']);
    Route::get('recent-activity', [DashboardController::class, 'recentActivity']);
});

// ─── Offline sync queue ───────────────────────────────────────────────────────

$registerGroup(['postings', 'v1/postings'], 'auth:sanctum', function (): void {
    Route::post('/', [PostingController::class, 'store'])->middleware('role:technician,admin');
    Route::post('bulk', [PostingController::class, 'bulkStore'])->middleware('role:technician,admin');
    Route::get('/', [PostingController::class, 'index'])->middleware('role:admin');
    Route::get('{posting}', [PostingController::class, 'show'])->middleware('role:technician,admin');
    Route::post('{posting}/retry', [PostingController::class, 'retry'])->middleware('role:admin');
});

// ─── Reports (any authenticated user) ────────────────────────────────────────
// Access intentionally open to all authenticated roles (admin / manager /
// technician). Per-report row-level filtering is enforced inside the
// controllers when needed.
$registerGroup(['reports', 'v1/reports'], 'auth:sanctum', function (): void {
    Route::get('irrigation',       [ReportController::class, 'irrigation']);
    Route::get('fertilization',    [ReportController::class, 'fertilization']);
    Route::get('phytosanitary',    [ReportController::class, 'phytosanitary']);
    Route::get('harvest',          [ReportController::class, 'harvest']);
    Route::get('production-cost',  [ReportController::class, 'productionCost']);
});

// ─── System Logs (admin-only audit trail) ─────────────────────────────────────

$registerGroup(['logs', 'v1/logs'], ['auth:sanctum', 'role:admin'], function (): void {
    Route::get('/',              [SystemLogController::class, 'index']);
    Route::get('stats',          [SystemLogController::class, 'stats']);
    Route::get('{systemLog}',    [SystemLogController::class, 'show']);
    Route::post('/',             [SystemLogController::class, 'store']);
});

// ─── Feedback / bug reports ───────────────────────────────────────────────────
// PUBLIC submission: anyone (logged-in or not) can file a bug report.
// Rate-limited to discourage spam.
Route::post('feedback/public', [FeedbackReportController::class, 'storePublic'])
    ->middleware('throttle:20,1');
Route::post('v1/feedback/public', [FeedbackReportController::class, 'storePublic'])
    ->middleware('throttle:20,1');

// AUTHENTICATED submission + admin management (existing behaviour).
$registerGroup(['feedback-reports', 'v1/feedback-reports'], 'auth:sanctum', function (): void {
    Route::post('/', [FeedbackReportController::class, 'store']);

    Route::middleware('role:admin')->group(function (): void {
        Route::get('/',                 [FeedbackReportController::class, 'index']);
        Route::get('{feedbackReport}',  [FeedbackReportController::class, 'show']);
        Route::patch('{feedbackReport}',[FeedbackReportController::class, 'update']);
        Route::delete('{feedbackReport}',[FeedbackReportController::class, 'destroy']);
    });
});

// ─── Developer dashboard ──────────────────────────────────────────────────────
// Token-guarded (X-Developer-Token header or ?token= query), no signup needed.
$registerGroup(['developer', 'v1/developer'], 'developer.token', function (): void {
    Route::get('feedback',                  [FeedbackReportController::class, 'developerIndex']);
    Route::get('feedback/{feedbackReport}', [FeedbackReportController::class, 'show']);
    Route::patch('feedback/{feedbackReport}',[FeedbackReportController::class, 'update']);
    Route::delete('feedback/{feedbackReport}',[FeedbackReportController::class, 'destroy']);
});

