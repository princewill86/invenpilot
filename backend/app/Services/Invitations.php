<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Invitations
{
    public function accept(User $user, string $token): void
    {
        DB::transaction(function () use ($user, $token): void {
            $hash = hash('sha256', $token);
            $candidate = DB::table('business_invitations')->where('token_hash', $hash)->first();
            if ($candidate === null) {
                $this->invalid();
            }

            $business = DB::table('businesses')->where('id', $candidate->business_id)->lockForUpdate()->first();
            if ($business->suspended_at !== null) {
                $this->invalid();
            }
            $invitation = DB::table('business_invitations')->where('id', $candidate->id)->lockForUpdate()->first();
            if ($invitation->email !== $user->email || $invitation->accepted_at !== null || now()->gte($invitation->expires_at)) {
                $this->invalid();
            }

            // A stale invitation cannot overwrite an established membership role.
            if (! $user->businesses()->where('businesses.id', $invitation->business_id)->lockForUpdate()->first()) {
                $user->businesses()->attach($invitation->business_id, ['role' => $invitation->role]);
            }
            DB::table('business_invitations')->where('id', $invitation->id)->update(['accepted_at' => now()]);
            ActivityLog::record($invitation->business_id, $user->id, 'member.joined', ['role' => $invitation->role]);
        }, 3);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['token' => 'This invitation is invalid or expired. Use the email address that received it.']);
    }
}
