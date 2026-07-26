<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

abstract class Controller
{
    // AuthorizesRequests -> $this->authorize()/authorizeForUser() in API controllers.
    // ValidatesRequests  -> $this->validate() fallback for trivial inline rules.
    use AuthorizesRequests, ValidatesRequests;
}
