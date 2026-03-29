<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the current legal entity and book context from request parameters or user preferences.
 * Sets context on the request so downstream services can be entity-aware.
 */
class ResolveEntityContext
{
    public function handle(Request $request, Closure $next)
    {
        $entityId = $request->header('X-Entity-Id')
            ?? $request->input('entity_id')
            ?? $this->getUserDefaultEntity($request);

        $bookId = $request->header('X-Book-Id')
            ?? $request->input('book_id')
            ?? $this->getDefaultBook($entityId);

        if ($entityId) {
            $request->attributes->set('legal_entity_id', (int) $entityId);
        }
        if ($bookId) {
            $request->attributes->set('entity_book_id', (int) $bookId);
        }

        return $next($request);
    }

    private function getUserDefaultEntity(Request $request): ?int
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        // Try user preference first
        $pref = DB::table('user_preferences')
            ->where('user_id', $user->id)
            ->where('key', 'default_legal_entity_id')
            ->value('value');

        if ($pref) {
            return (int) $pref;
        }

        // Fall back to the first legal entity in the tenant
        return DB::table('legal_entities')
            ->where('status', 'active')
            ->orderBy('id')
            ->value('id');
    }

    private function getDefaultBook(?int $entityId): ?int
    {
        if (!$entityId) {
            return null;
        }

        return DB::table('entity_books')
            ->where('legal_entity_id', $entityId)
            ->where('is_primary', true)
            ->where('status', 'active')
            ->value('id');
    }
}
