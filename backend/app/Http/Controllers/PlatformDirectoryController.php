<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformDirectoryController extends Controller
{
    private const USER_FIELDS = ['id', 'name', 'email', 'platform_admin', 'suspended_at', 'created_at'];

    private const BUSINESS_FIELDS = ['id', 'name', 'currency', 'suspended_at', 'created_at'];

    public function users(Request $request): JsonResponse
    {
        $query = $this->directory($request, 'users');
        $query->select(self::USER_FIELDS)->selectSub(
            DB::table('business_memberships')->selectRaw('count(*)')->whereColumn('user_id', 'users.id'), 'membership_count',
        );

        return response()->json($query->latest('id')->paginate(25));
    }

    public function businesses(Request $request): JsonResponse
    {
        $query = $this->directory($request, 'businesses');
        $query->select(self::BUSINESS_FIELDS)->selectSub(
            DB::table('business_memberships')->selectRaw('count(*)')->whereColumn('business_id', 'businesses.id'), 'member_count',
        );

        return response()->json($query->latest('id')->paginate(25));
    }

    public function user(User $user): JsonResponse
    {
        return response()->json([
            ...$user->only(self::USER_FIELDS),
            'memberships' => $user->businesses()->orderBy('businesses.name')->paginate(25),
        ]);
    }

    public function business(Business $business): JsonResponse
    {
        return response()->json([
            ...$business->only(self::BUSINESS_FIELDS),
            'members' => DB::table('business_memberships as membership')
                ->join('users', 'users.id', '=', 'membership.user_id')
                ->where('membership.business_id', $business->id)->orderBy('users.name')->orderBy('users.id')
                ->paginate(25, ['users.id', 'users.name', 'users.email', 'users.suspended_at', 'membership.role']),
        ]);
    }

    public function userStatus(Request $request, User $user): JsonResponse
    {
        $input = $this->statusInput($request);
        DB::transaction(function () use ($request, $user, $input): void {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_if($target->platform_admin, 403, 'Platform administrators are managed by the installation operator.');
            if (($target->suspended_at !== null) === $input['suspended']) {
                return;
            }
            $target->forceFill([
                'suspended_at' => $input['suspended'] ? now() : null,
                'remember_token' => null,
            ])->save();
            if ($input['suspended'] && config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table'))->where('user_id', $target->id)->delete();
            }
            $this->audit($request, $input['suspended'] ? 'user.suspended' : 'user.reactivated', 'user', $target->id, $input['reason']);
        }, 3);

        return $this->user($user->refresh());
    }

    public function businessStatus(Request $request, Business $business): JsonResponse
    {
        $input = $this->statusInput($request);
        DB::transaction(function () use ($request, $business, $input): void {
            $target = Business::query()->lockForUpdate()->findOrFail($business->id);
            if (($target->suspended_at !== null) === $input['suspended']) {
                return;
            }
            $target->forceFill(['suspended_at' => $input['suspended'] ? now() : null])->save();
            $this->audit($request, $input['suspended'] ? 'business.suspended' : 'business.reactivated', 'business', $target->id, $input['reason']);
        }, 3);

        return $this->business($business->refresh());
    }

    public function membership(Request $request, Business $business, User $user): JsonResponse
    {
        $input = $request->validate([
            'role' => [$request->isMethod('DELETE') ? 'prohibited' : 'required', 'in:staff,administrator,owner'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        DB::transaction(function () use ($request, $business, $user, $input): void {
            Business::query()->lockForUpdate()->findOrFail($business->id);
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            $memberships = DB::table('business_memberships')->where('business_id', $business->id);
            $existing = (clone $memberships)->where('user_id', $target->id)->first();
            $role = $input['role'] ?? null;
            if ($existing?->role === $role || ($role === null && ! $existing)) {
                return;
            }
            abort_if($existing?->role === 'owner', 409, 'Transfer ownership to another active member first.');
            abort_if($role !== null && ($target->platform_admin || $target->suspended_at !== null), 422, 'Select an active business account.');
            if ($role === null) {
                (clone $memberships)->where('user_id', $target->id)->delete();
            } elseif ($role === 'owner') {
                abort_unless($existing, 422, 'Add the new owner as a member first.');
                // The business lock makes the transfer atomic with other membership changes.
                (clone $memberships)->where('role', 'owner')->update(['role' => 'administrator', 'updated_at' => now()]);
                (clone $memberships)->where('user_id', $target->id)->update(['role' => 'owner', 'updated_at' => now()]);
            } elseif ($existing) {
                (clone $memberships)->where('user_id', $target->id)->update(['role' => $role, 'updated_at' => now()]);
            } else {
                $target->businesses()->attach($business, ['role' => $role]);
            }

            $this->audit($request, 'business.membership_changed', 'business', $business->id, $input['reason'], [
                'user_id' => $target->id, 'previous_role' => $existing?->role, 'role' => $role,
            ]);
        }, 3);

        return $this->business($business);
    }

    public function activity(): JsonResponse
    {
        return response()->json(DB::table('platform_activity as activity')->join('users', 'users.id', '=', 'activity.actor_id')
            ->latest('activity.id')->paginate(25, ['activity.*', 'users.name as actor_name']));
    }

    private function directory(Request $request, string $table): Builder
    {
        $input = $request->validate(['search' => ['nullable', 'string', 'max:160'], 'status' => ['nullable', 'in:active,suspended']]);
        $query = DB::table($table);
        if (isset($input['search'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $input['search']).'%';
            $query->where(function (Builder $search) use ($term, $table): void {
                $search->whereRaw("name like ? escape '!'", [$term]);
                if ($table === 'users') {
                    $search->orWhereRaw("email like ? escape '!'", [$term]);
                }
            });
        }
        if (($input['status'] ?? null) === 'active') {
            $query->whereNull('suspended_at');
        } elseif (($input['status'] ?? null) === 'suspended') {
            $query->whereNotNull('suspended_at');
        }

        return $query;
    }

    private function statusInput(Request $request): array
    {
        $input = $request->validate(['suspended' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:1000']]);
        $input['suspended'] = $request->boolean('suspended');

        return $input;
    }

    private function audit(Request $request, string $action, string $target, int $id, string $reason, array $details = []): void
    {
        DB::table('platform_activity')->insert([
            'actor_id' => $request->user()->id, 'action' => $action, 'target_type' => $target,
            'target_id' => $id, 'reason' => $reason, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
