<?php

namespace App\Http\Controllers\Teams;

use App\Actions\Teams\AcceptTeamInvitation as AcceptTeamInvitationAction;
use App\Actions\Teams\InviteMember;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Teams\CreateTeamInvitationRequest;
use App\Http\Requests\Teams\StoreInvitedAccountRequest;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Rules\ValidTeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class TeamInvitationController extends Controller
{
    /**
     * Store a newly created invitation.
     */
    public function store(CreateTeamInvitationRequest $request, Team $team, InviteMember $inviteMember): RedirectResponse
    {
        Gate::authorize('inviteMember', $team);

        $inviteMember->handle(
            $team,
            $request->user(),
            $request->validated('email'),
            TeamRole::from($request->validated('role')),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Cancel the specified invitation.
     */
    public function destroy(Team $team, TeamInvitation $invitation): RedirectResponse
    {
        abort_unless($invitation->team_id === $team->id, 404);

        Gate::authorize('cancelInvitation', $team);

        $invitation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation cancelled.')]);

        return to_route('teams.edit', ['team' => $team->slug]);
    }

    /**
     * Accept the invitation, or ask a new invitee to choose a password.
     */
    public function accept(Request $request, TeamInvitation $invitation, AcceptTeamInvitationAction $acceptTeamInvitation): RedirectResponse|Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $validator = Validator::make(
                ['invitation' => $invitation],
                ['invitation' => [new ValidTeamInvitation($user)]],
            );

            if ($validator->fails()) {
                return back()->withErrors($validator);
            }

            $acceptTeamInvitation->handle($user, $invitation);

            return redirect('/dashboard');
        }

        if ($redirect = $this->redirectIfInvitationClosed($invitation)) {
            return $redirect;
        }

        if (User::query()->where('email', $invitation->email)->exists()) {
            return redirect()->guest(route('login'));
        }

        return Inertia::render('auth/accept-invitation', [
            'email' => $invitation->email,
            'teamName' => $invitation->team->name,
            'acceptUrl' => route('invitations.accept.store', $invitation),
        ]);
    }

    /**
     * Create an account for the invited email and join the team.
     */
    public function createAccount(StoreInvitedAccountRequest $request, TeamInvitation $invitation, AcceptTeamInvitationAction $acceptTeamInvitation): RedirectResponse
    {
        if ($redirect = $this->redirectIfInvitationClosed($invitation)) {
            return $redirect;
        }

        if (User::query()->where('email', $invitation->email)->exists()) {
            return redirect()->guest(route('login'));
        }

        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $invitation->email,
            'password' => $request->validated('password'),
            'email_verified_at' => now(),
        ]);

        $acceptTeamInvitation->handle($user, $invitation);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/dashboard');
    }

    /**
     * Send a closed invitation back to login instead of the password form.
     */
    protected function redirectIfInvitationClosed(TeamInvitation $invitation): ?RedirectResponse
    {
        if ($invitation->isAccepted()) {
            return redirect()
                ->route('login')
                ->with('status', __('This invitation has already been accepted. Please log in.'));
        }

        if ($invitation->isExpired()) {
            return redirect()
                ->route('login')
                ->withErrors(['invitation' => __('This invitation has expired.')]);
        }

        return null;
    }
}
