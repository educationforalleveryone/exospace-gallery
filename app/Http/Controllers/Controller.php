<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/*
 * FIX (Iter-002): TeamController (view/update/delete/invite/manageMembers/
 * switch) calls $this->authorize(...) on 11 separate lines, but this base
 * Controller never pulled in the AuthorizesRequests trait that provides that
 * method. Laravel 11+ no longer includes it here by default, and nothing in
 * this app was adding it back. Any call to $this->authorize() therefore threw
 * "Call to undefined method ...TeamController::authorize()", a fatal error —
 * which is exactly the 500 on POST /admin/teams/{team}/switch (and every
 * other team-management action). Adding the trait here fixes it for
 * TeamController and prevents the same crash in any future controller that
 * calls $this->authorize().
 */
abstract class Controller
{
    use AuthorizesRequests;
}
