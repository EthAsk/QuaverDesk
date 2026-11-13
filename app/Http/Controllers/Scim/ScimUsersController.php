<?php

namespace App\Http\Controllers\Scim;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LicenceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SCIM 2.0 (RFC 7643/7644) for a company's directory (Microsoft Entra, Okta,
 * and others): adds people, keeps their name and email in step, and switches
 * them off when they leave. It never deletes anyone and never touches the
 * owner, admins can't be made from here, and a full plan refuses new people.
 */
class ScimUsersController extends Controller
{
    private const USER = 'urn:ietf:params:scim:schemas:core:2.0:User';
    private const LIST = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';
    private const ERROR = 'urn:ietf:params:scim:api:messages:2.0:Error';

    public function config(): JsonResponse
    {
        return $this->json([
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig'],
            'patch' => ['supported' => true], 'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => 100], 'changePassword' => ['supported' => false],
            'sort' => ['supported' => false], 'etag' => ['supported' => false],
            'authenticationSchemes' => [['type' => 'oauthbearertoken', 'name' => 'Bearer token', 'description' => 'The sync token from Admin → Single sign-on']],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = User::query()->orderBy('id');
        if (($filter = (string) $request->query('filter')) !== '') {
            if (! preg_match('/^(userName|externalId)\s+eq\s+"([^"]*)"$/i', trim($filter), $m)) {
                return $this->error(400, 'Only filters like userName eq "x" or externalId eq "x" are supported.', 'invalidFilter');
            }
            strtolower($m[1]) === 'username' ? $query->where('email', strtolower($m[2])) : $query->where('scim_external_id', $m[2]);
        }

        $total = (clone $query)->count();
        $start = max(1, (int) $request->query('startIndex', 1));
        $count = max(0, min(100, (int) $request->query('count', 100)));
        $users = $query->skip($start - 1)->take($count)->get();

        return $this->json([
            'schemas' => [self::LIST], 'totalResults' => $total, 'startIndex' => $start, 'itemsPerPage' => $users->count(),
            'Resources' => $users->map(fn (User $u) => $this->resource($u))->all(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $user = User::query()->whereKey($id)->first();

        return $user ? $this->json($this->resource($user)) : $this->error(404, 'No such user.');
    }

    public function store(Request $request, LicenceManager $licences): JsonResponse
    {
        $body = $request->json()->all();
        $email = $this->email($body);
        if ($email === null) {
            return $this->error(400, 'userName must be an email address.', 'invalidValue');
        }
        // Unique across every company: sign-in finds a person by email alone.
        if (User::query()->withoutGlobalScopes()->where('email', $email)->exists()) {
            return $this->error(409, 'A user with that email already exists.', 'uniqueness');
        }

        $max = $licences->current()->maxUsers();
        $user = DB::transaction(function () use ($body, $email, $max) {
            if (User::query()->seated()->lockForUpdate()->count() >= $max) {
                return null;
            }
            // Nobody knows this password: they sign in with the provider, or set one from an invite an admin sends.
            $user = new User(['name' => $this->name($body) ?? $email, 'email' => $email, 'password' => Str::password(32)]);
            $user->role = User::ROLE_USER;
            $user->scim_external_id = isset($body['externalId']) ? substr((string) $body['externalId'], 0, 255) : null;
            $user->suspended_at = array_key_exists('active', $body) && $this->falsy($body['active']) ? now() : null;
            $user->save();

            return $user;
        });

        return $user === null
            ? $this->error(409, "The plan allows {$max} users and they are all in use. Upgrade or remove someone first.")
            : $this->json($this->resource($user), 201);
    }

    public function replace(Request $request, string $id): JsonResponse
    {
        $user = User::query()->whereKey($id)->first();
        if ($user === null) {
            return $this->error(404, 'No such user.');
        }
        if ($user->isOwner()) {
            return $this->error(403, 'The owner can only be changed here, not from the directory.');
        }
        $body = $request->json()->all();
        $this->apply($user, [
            'userName' => $this->email($body), 'name' => $this->name($body), 'externalId' => $body['externalId'] ?? null,
            'active' => array_key_exists('active', $body) ? ! $this->falsy($body['active']) : true,
        ]);

        return $this->json($this->resource($user->refresh()));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::query()->whereKey($id)->first();
        if ($user === null) {
            return $this->error(404, 'No such user.');
        }
        if ($user->isOwner()) {
            return $this->error(403, 'The owner can only be changed here, not from the directory.');
        }

        $changes = [];
        foreach ($request->json('Operations', []) as $op) {
            $verb = strtolower((string) ($op['op'] ?? ''));
            if (! in_array($verb, ['add', 'replace', 'remove'], true)) {
                return $this->error(400, 'Unknown operation.', 'invalidSyntax');
            }
            $path = (string) ($op['path'] ?? '');
            $value = $op['value'] ?? null;
            // Without a path, the value is an object of attributes.
            $pairs = $path === '' && is_array($value) ? $value : [$path => $value];
            foreach ($pairs as $key => $v) {
                $key = strtolower((string) $key);
                if ($verb === 'remove') {
                    continue; // Nothing here can be blanked: the name and email always stay.
                }
                match (true) {
                    $key === 'active' => $changes['active'] = ! $this->falsy($v),
                    $key === 'username' => $changes['userName'] = $this->email(['userName' => $v]),
                    $key === 'displayname' => $changes['name'] = is_string($v) && trim($v) !== '' ? trim($v) : null,
                    $key === 'externalid' => $changes['externalId'] = $v,
                    str_starts_with($key, 'emails') => $changes['userName'] = $this->email(['emails' => is_array($v) && array_is_list($v) ? $v : [['value' => $v]]]) ?? ($changes['userName'] ?? null),
                    $key === 'name' => $changes['name'] ??= $this->name(['name' => (array) $v]),
                    $key === 'name.givenname' || $key === 'name.familyname' => $changes['name'] ??= $this->partName($user, $key, $v),
                    default => null, // Anything else the directory sends is ignored.
                };
            }
        }
        $this->apply($user, $changes);

        return $this->json($this->resource($user->refresh()));
    }

    /** "Deleting" a person in the directory switches them off here; admins can remove them from Users. */
    public function destroy(string $id): \Illuminate\Http\Response|JsonResponse
    {
        $user = User::query()->whereKey($id)->first();
        if ($user === null) {
            return $this->error(404, 'No such user.');
        }
        if ($user->isOwner()) {
            return $this->error(403, 'The owner can only be changed here, not from the directory.');
        }
        $this->apply($user, ['active' => false]);

        return response('', 204);
    }

    /** @param array<string, mixed> $changes */
    private function apply(User $user, array $changes): void
    {
        DB::transaction(function () use ($user, $changes) {
            if (($changes['userName'] ?? null) !== null && $changes['userName'] !== $user->email
                && ! User::query()->withoutGlobalScopes()->where('email', $changes['userName'])->exists()) {
                $user->email = $changes['userName'];
            }
            if (($changes['name'] ?? null) !== null) {
                $user->name = $changes['name'];
            }
            if (array_key_exists('externalId', $changes) && $changes['externalId'] !== null) {
                $user->scim_external_id = substr((string) $changes['externalId'], 0, 255);
            }
            if (array_key_exists('active', $changes)) {
                if ($changes['active'] === false && ! $user->isSuspended()) {
                    $user->suspended_at = now();
                    // Out of the people who sign in: sessions end, and they leave their queues.
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                    $user->forceFill(['remember_token' => null]);
                    DB::table('call_queue_user')->where('user_id', $user->id)->update(['logged_in' => false]);
                } elseif ($changes['active'] === true) {
                    $user->suspended_at = null;
                }
            }
            $user->save();
        });
    }

    /** @return array<string, mixed> */
    private function resource(User $user): array
    {
        [$given, $family] = array_pad(explode(' ', $user->name, 2), 2, '');

        return [
            'schemas' => [self::USER],
            'id' => (string) $user->id,
            'externalId' => $user->scim_external_id,
            'userName' => $user->email,
            'name' => ['formatted' => $user->name, 'givenName' => $given, 'familyName' => $family],
            'displayName' => $user->name,
            'emails' => [['value' => $user->email, 'type' => 'work', 'primary' => true]],
            'active' => ! $user->isSuspended(),
            'meta' => ['resourceType' => 'User', 'created' => $user->created_at?->toIso8601String(), 'lastModified' => $user->updated_at?->toIso8601String()],
        ];
    }

    /** @param array<string, mixed> $body */
    private function email(array $body): ?string
    {
        $candidates = [$body['userName'] ?? null];
        foreach ((array) ($body['emails'] ?? []) as $e) {
            $candidates[] = is_array($e) ? ($e['value'] ?? null) : null;
        }
        foreach ($candidates as $c) {
            if (is_string($c) && filter_var(trim($c), FILTER_VALIDATE_EMAIL)) {
                return strtolower(trim($c));
            }
        }

        return null;
    }

    /** @param array<string, mixed> $body */
    private function name(array $body): ?string
    {
        $name = trim((string) ($body['displayName'] ?? ''));
        if ($name === '') {
            $n = (array) ($body['name'] ?? []);
            $name = trim(($n['formatted'] ?? '') ?: trim(($n['givenName'] ?? '').' '.($n['familyName'] ?? '')));
        }

        return $name === '' ? null : mb_substr($name, 0, 255);
    }

    /** One half of the name changed: keep the other half. */
    private function partName(User $user, string $key, mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        [$given, $family] = array_pad(explode(' ', $user->name, 2), 2, '');

        return trim($key === 'name.givenname' ? trim($value).' '.$family : $given.' '.trim($value));
    }

    /** Entra sends "False" as text. */
    private function falsy(mixed $v): bool
    {
        return $v === false || (is_string($v) && strtolower($v) === 'false') || $v === 0 || $v === '0';
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Content-Type' => 'application/scim+json']);
    }

    private function error(int $status, string $detail, ?string $type = null): JsonResponse
    {
        return $this->json(['schemas' => [self::ERROR], 'status' => (string) $status, 'detail' => $detail] + ($type ? ['scimType' => $type] : []), $status);
    }
}
