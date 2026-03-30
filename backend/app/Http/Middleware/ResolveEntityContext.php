<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        if (!$user || !Schema::hasTable('legal_entities')) {
            return null;
        }

        if (Schema::hasTable('user_entity_access')) {
            $entityId = DB::table('user_entity_access as uea')
                ->join('legal_entities as le', 'le.id', '=', 'uea.legal_entity_id')
                ->where('uea.user_id', $user->id)
                ->where('le.status', 'active')
                ->orderByDesc('uea.is_default')
                ->orderBy('uea.legal_entity_id')
                ->value('uea.legal_entity_id');

            if ($entityId) {
                return (int) $entityId;
            }
        }

        return null;
    }

    private function getDefaultBook(?int $entityId): ?int
    {
        if (!$entityId || !Schema::hasTable('entity_books')) {
            return null;
        }

        return DB::table('entity_books')
            ->where('legal_entity_id', $entityId)
            ->where('is_primary', true)
            ->where('status', 'active')
            ->value('id');
    }
}
