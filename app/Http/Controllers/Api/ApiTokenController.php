<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * M-28: API token management + authenticated user info.
 *
 * Authenticated endpoints (Sanctum token auth). Users create API tokens
 * here or from the profile page UI. Tokens are scoped: 'read' (public
 * endpoints) or 'write' (can manage galleries via API — future).
 *
 * Currently only 'read' scope is used (the API is read-only). The scope
 * system is in place for future write endpoints.
 */
class ApiTokenController extends Controller
{
    /**
     * Get the authenticated user's info.
     * GET /api/v1/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id'              => $user->id,
                'name'            => $user->name,
                'email'           => $user->email,
                'plan'            => $user->plan,
                'max_galleries'   => $user->max_galleries,
                'max_images'      => $user->max_images,
                'created_at'      => $user->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * List the user's API tokens.
     * GET /api/v1/tokens
     */
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()->get(['id', 'name', 'abilities', 'last_used_at', 'created_at']);

        return response()->json([
            'data' => $tokens->map(fn($t) => [
                'id'           => $t->id,
                'name'         => $t->name,
                'abilities'    => $t->abilities,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'created_at'   => $t->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Create a new API token.
     * POST /api/v1/tokens { "name": "My App", "abilities": ["read"] }
     *
     * Returns the plain-text token ONCE — store it securely, it won't be shown again.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:100'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'in:read,write'],
        ]);

        $abilities = $validated['abilities'] ?? ['read'];

        $token = $request->user()->createToken(
            $validated['name'],
            $abilities
        );

        return response()->json([
            'data' => [
                'token'    => $token->plainTextToken,
                'name'     => $validated['name'],
                'abilities'=> $abilities,
            ],
            'message' => 'Token created. Store it securely — it won\'t be shown again.',
        ], 201);
    }

    /**
     * Revoke an API token.
     * DELETE /api/v1/tokens/{tokenId}
     */
    public function destroy(Request $request, int $tokenId): JsonResponse
    {
        $request->user()->tokens()->where('id', $tokenId)->delete();

        return response()->json(['message' => 'Token revoked.']);
    }
}
