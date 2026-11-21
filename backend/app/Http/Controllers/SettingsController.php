<?php

namespace App\Http\Controllers;

use App\Services\ActivityLog;
use App\Services\Invitations;
use App\Services\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->attributes->get('business')->only(['name', 'currency', 'allow_negative_stock']));
    }

    public function update(Request $request): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'allow_negative_stock' => ['required', 'boolean'],
        ]);
        $business = $request->attributes->get('business');
        DB::transaction(function () use ($request, $business, $input): void {
            $business->update($input);
            ActivityLog::record($business->id, $request->user()->id, 'business.updated', $input);
        });

        return $this->show($request);
    }

    public function members(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business')->id;

        return response()->json(DB::table('business_memberships as membership')
            ->join('users', 'users.id', '=', 'membership.user_id')
            ->where('membership.business_id', $business)
            ->orderBy('users.name')
            ->paginate(25, ['users.id', 'users.name', 'users.email', 'membership.role']));
    }

    public function invite(Request $request, Platform $platform): JsonResponse
    {
        $input = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(['administrator', 'staff'])],
        ]);
        $business = $request->attributes->get('business')->id;
        $email = Str::lower($input['email']);
        $token = Str::random(64);
        $expiresAt = now()->addDays((int) $platform->settings()['invitation_days']);

        DB::transaction(function () use ($request, $business, $email, $token, $input, $expiresAt): void {
            DB::table('businesses')->where('id', $business)->lockForUpdate()->first();
            $existingMember = DB::table('business_memberships')
                ->join('users', 'users.id', '=', 'business_memberships.user_id')
                ->where('business_id', $business)->where('users.email', $email)->exists();
            abort_if($existingMember, 409, 'This person is already a member.');

            DB::table('business_invitations')->where('business_id', $business)->where('email', $email)
                ->whereNull('accepted_at')->update(['expires_at' => now()]);
            DB::table('business_invitations')->insert([
                'business_id' => $business,
                'user_id' => $request->user()->id,
                'email' => $email,
                'role' => $input['role'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ]);
            ActivityLog::record($business, $request->user()->id, 'member.invited', ['email' => $email, 'role' => $input['role']]);
        });

        return response()->json(['token' => $token, 'expires_at' => $expiresAt], 201);
    }

    public function accept(Request $request, Invitations $invitations): Response
    {
        $input = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $invitations->accept($request->user(), $input['token']);

        return response()->noContent();
    }

    public function manageMember(Request $request, int $business, int $member): Response
    {
        $input = $request->isMethod('PATCH')
            ? $request->validate(['role' => ['required', Rule::in(['administrator', 'staff'])]])
            : [];
        DB::transaction(function () use ($request, $business, $member, $input): void {
            DB::table('businesses')->where('id', $business)->lockForUpdate()->first();
            $membership = DB::table('business_memberships')->where('business_id', $business)->where('user_id', $member);
            $row = (clone $membership)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->role === 'owner', 403, 'The owner membership cannot be modified.');

            if ($input) {
                $membership->update(['role' => $input['role'], 'updated_at' => now()]);
                ActivityLog::record($business, $request->user()->id, 'member.role_changed', ['member' => $member, 'role' => $input['role']]);
            } else {
                $membership->delete();
                ActivityLog::record($business, $request->user()->id, 'member.removed', ['member' => $member, 'role' => $row->role]);
            }
        });

        return response()->noContent();
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $request->user()->update($input);

        return response()->json($request->user()->only(['id', 'name', 'email']));
    }

    public function password(Request $request, Platform $platform): Response
    {
        $input = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'min:'.$platform->settings()['password_minimum'], 'confirmed', 'different:current_password', 'regex:/\A[^\x00]{1,72}\z/'],
        ]);
        $user = $request->user();
        $user->forceFill(['password' => $input['password'], 'remember_token' => Str::random(60)])->save();
        $request->session()->regenerate(true);
        $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword()));

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table'))
                ->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        }

        return response()->noContent();
    }

    public function activity(Request $request): JsonResponse
    {
        return response()->json(DB::table('activity_logs')
            ->where('business_id', $request->attributes->get('business')->id)
            ->latest('id')->paginate(25));
    }
}
