<?php

/**
 * Base controller.
 *
 * Every controller in the app extends this class. It exists so we have a
 * single point to plug in shared traits later (authorization, validation
 * helpers, response macros) without touching each controller.
 */

declare(strict_types=1);

namespace App\Http\Controllers;

abstract class Controller
{
}
