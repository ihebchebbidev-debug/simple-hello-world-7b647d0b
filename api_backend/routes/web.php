<?php

/**
 * Web routes.
 *
 * This service is API-only. The single web route renders the interactive
 * developer console at `/`; everything else lives under `/api`.
 */

declare(strict_types=1);

use App\Support\ApiOverview;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('api-docs', ['payload' => ApiOverview::payload()]));
