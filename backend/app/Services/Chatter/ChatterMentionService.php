<?php

declare(strict_types=1);

namespace App\Services\Chatter;

use App\Models\DocumentFollower;
use App\Models\User;
use App\Platform\Notifications\NotificationService;
use App\Services\TenantRoleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Communication\Models\CommunicationMessage;

/**
 * Issue #635 (CHAT-UX2) — real @mentions.
 *
 * Every chatter message used to come back with `mentions: []` and posting
 * `@someone` did nothing. This service owns the whole mention rule so the
 * write path, the read path and the notification fan-out cannot drift:
 *
 *  - parse `@name` / `@email-handle` tokens from the message body and resolve
 *    them against tenant users with EXACT, case-insensitive matching. Tokens
 *    are tried longest-first so `@Sam Sales` beats a shorter `@Sam`, and a
 *    token only resolves when it maps to exactly one user — ambiguous
 *    candidates (two users with the same name, a name that equals another
 *    user's handle) are ignored rather than guessed. Unknown tokens
 *    (`@nobody`) and partial lookalikes (`@Samuelson` for "Sam Sales") are
 *    ignored;
 *  - persist the resolved mention payload on the message (the shared
 *    `communication_messages.metadata` bag, or `chatter_messages.mentions`
 *    for the legacy fallback store), so `show`/`since` return it;
 *  - notify each mentioned user through the platform NotificationService
 *    (in-app NotificationLog + mail, honouring the stored preference) and
 *    make them a follower of the document via DocumentFollowerService.
 *
 * ACCESS POLICY (deliberate, disclosed in the #635 report): a mentioned user
 * is only notified/followed when they hold the same `subject_permissions`
 * view permission the chatter read route enforces. A notification carries a
 * body excerpt — notifying a user who would get a 403 from the chatter route
 * itself would leak the record's content, and following them would feed them
 * later excerpts too. For such users the token still resolves and is returned
 * by the API; only delivery is skipped.
 */
class ChatterMentionService
{
    public const EVENT_MENTION = 'chatter.mention';

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly DocumentFollowerService $followers,
        private readonly TenantRoleService $roles,
    ) {}

    /**
     * Resolve the tenant users mentioned in a message body.
     *
     * @return array<int, User> keyed by user id, in order of appearance
     */
    public function resolveUsers(string $body): array
    {
        $body = trim($body);
        if ($body === '' || ! str_contains($body, '@') || ! mb_check_encoding($body, 'UTF-8')) {
            return [];
        }

        $haystack = mb_strtolower($body);

        // candidate token => users carrying it. A candidate is a full name or
        // an email handle; duplicates make it ambiguous and it is dropped.
        // The map key is prefixed so a purely numeric token ("123") cannot be
        // coerced to an int key and collide with another token.
        $byToken = [];
        foreach (User::query()->get(['id', 'name', 'email']) as $user) {
            foreach ($this->candidateTokens($user) as $token) {
                $key = mb_strlen($token).':'.$token;
                $byToken[$key]['token'] ??= $token;
                $byToken[$key]['users'][] = $user;
            }
        }

        $candidates = array_values($byToken);
        if ($candidates === []) {
            return [];
        }

        // Longest token first: "@Sam Sales" must win over a "@Sam" candidate.
        usort($candidates, fn (array $a, array $b) => mb_strlen($b['token']) <=> mb_strlen($a['token']));

        preg_match_all('/@/u', $body, $matches, PREG_OFFSET_CAPTURE);

        $resolved = [];
        $seen = [];
        foreach ($matches[0] as [$at, $offset]) {
            // Never start a mention inside a larger token or an email address
            // ("foo@bar", "@@name").
            if ($offset > 0 && preg_match('/[\p{L}\p{N}_.@\-]$/u', substr($body, 0, $offset))) {
                continue;
            }

            foreach ($candidates as $candidate) {
                $token = $candidate['token'];
                $length = strlen($token);
                if (substr($haystack, $offset + 1, $length) !== $token) {
                    continue;
                }
                if (! $this->boundaryAfter($body, $offset + 1 + $length)) {
                    continue;
                }

                // The token prefix matched but is ambiguous (e.g. two users
                // share the name, or one user's name equals another's
                // handle): ignore the whole mention rather than guess.
                if (count($candidate['users']) !== 1) {
                    break;
                }

                $user = $candidate['users'][0];
                if (! isset($seen[$user->id])) {
                    $seen[$user->id] = true;
                    $resolved[] = $user;
                }

                break;
            }
        }

        return $resolved;
    }

    /**
     * Resolve, persist, notify and auto-follow in one call.
     *
     * Side effects are best-effort and reported, matching the #639 follower
     * fan-out: the message is already stored and must never be lost because a
     * notification could not be delivered.
     *
     * @return array<int, array{id: int, name: string|null, email: string|null}>
     */
    public function apply(Model $message, string $subjectType, int $subjectId, int $actorId, string $body): array
    {
        $users = $this->resolveUsers($body);
        if ($users === []) {
            return [];
        }

        $payload = self::serializeMentions($users);
        $this->persist($message, $payload);

        foreach ($users as $user) {
            if ((int) $user->id === $actorId) {
                continue;
            }

            if (! $this->canViewSubject($user, $subjectType)) {
                continue;
            }

            try {
                $this->followers->followUser(
                    $subjectType,
                    $subjectId,
                    (int) $user->id,
                    [],
                    DocumentFollower::SOURCE_MENTION,
                );
                $this->notifications->dispatch(
                    self::EVENT_MENTION,
                    $user,
                    $this->notificationData($subjectType, $subjectId, $actorId, $body),
                );
            } catch (\Throwable $e) {
                Log::error('ChatterMentionService: mention side effect failed', [
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $payload;
    }

    /**
     * The persisted mention payload for a message.
     *
     * @return array<int, array{id: int, name: string|null, email: string|null}>
     */
    public function mentionsFromMessage(Model $message): array
    {
        if ($message instanceof CommunicationMessage) {
            $metadata = $message->metadata;

            return is_array($metadata) && is_array($metadata['mentions'] ?? null)
                ? array_values($metadata['mentions'])
                : [];
        }

        return is_array($message->mentions ?? null) ? array_values($message->mentions) : [];
    }

    /**
     * Does the mentioned user hold the record view permission the chatter
     * read route enforces? Super roles bypass via TenantRoleService.
     */
    public function canViewSubject(User $user, string $subjectType): bool
    {
        $permission = config('chatter.subject_permissions')[$subjectType] ?? null;
        if (! is_string($permission) || $permission === '') {
            return false;
        }

        $tenantId = (function_exists('tenant') && tenant()) ? (string) tenant('id') : '';
        if ($tenantId === '') {
            return false;
        }

        $centralUserId = (int) (DB::table('public.users')->where('email', $user->email)->value('id') ?? $user->id);

        return $this->roles->hasPermission($centralUserId, $tenantId, $permission);
    }

    /**
     * @param  array<int, User>  $users
     * @return array<int, array{id: int, name: string|null, email: string|null}>
     */
    public static function serializeMentions(array $users): array
    {
        return array_map(fn (User $user) => [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ], array_values($users));
    }

    /**
     * @param  array<int, array{id: int, name: string|null, email: string|null}>  $payload
     */
    private function persist(Model $message, array $payload): void
    {
        if ($message instanceof CommunicationMessage) {
            $message->update([
                'metadata' => array_merge($message->metadata ?? [], ['mentions' => $payload]),
            ]);

            return;
        }

        $message->forceFill(['mentions' => $payload])->save();
    }

    /**
     * Exact, lowercased tokens a user can be mentioned by: the full name and
     * the email handle (local part).
     *
     * @return string[]
     */
    private function candidateTokens(User $user): array
    {
        $tokens = [];

        $name = trim((string) $user->name);
        if ($name !== '') {
            $tokens[] = mb_strtolower($name);
        }

        $email = trim((string) $user->email);
        if ($email !== '' && str_contains($email, '@')) {
            $handle = mb_strtolower((string) strstr($email, '@', true));
            if ($handle !== '') {
                $tokens[] = $handle;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * The character after a matched token must not continue it. A sentence
     * period is allowed when it ends the sentence ("@Sam Sales.") but not when
     * it joins another token ("@Sam.Smith").
     */
    private function boundaryAfter(string $body, int $byteEnd): bool
    {
        if ($byteEnd >= strlen($body)) {
            return true;
        }

        $next = mb_substr(substr($body, $byteEnd), 0, 1);
        if ($next === '' || ! preg_match('/[\p{L}\p{N}_.@\-]/u', $next)) {
            return true;
        }

        if ($next === '.') {
            $after = mb_substr(substr($body, $byteEnd + 1), 0, 1);

            return $after === '' || ! preg_match('/[\p{L}\p{N}_@\-]/u', $after);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationData(string $subjectType, int $subjectId, int $actorId, string $body): array
    {
        $label = class_basename($subjectType);
        $actorName = (string) (User::query()->whereKey($actorId)->value('name') ?? 'A teammate');

        return [
            'subject' => "You were mentioned on {$label} #{$subjectId}",
            'body' => sprintf(
                '%s mentioned you on %s #%d: %s',
                $actorName,
                $label,
                $subjectId,
                $this->excerpt($body),
            ),
            'document_type' => $subjectType,
            'document_id' => $subjectId,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
        ];
    }

    private function excerpt(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);

        return mb_strlen($body) > 140 ? mb_substr($body, 0, 137).'…' : $body;
    }
}
