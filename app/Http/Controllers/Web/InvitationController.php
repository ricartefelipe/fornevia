<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Identity\AcceptInvitation;
use App\Actions\Identity\SelectContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Identity\MembershipKind;
use App\Http\Middleware\EstablishRequestContext;
use App\Models\Identity\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class InvitationController
{
    public function show(Request $request, string $organization, string $token): View
    {
        if ($request->user() === null) {
            // Retorna ao convite após login ou cadastro.
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return view('invitations.show', [
            'organization' => $organization,
            'token' => $token,
            'user' => $request->user(),
        ]);
    }

    public function accept(Request $request, string $organization, string $token, AcceptInvitation $accept, SelectContext $select): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $membership = $select->handle($user, $accept->handle($organization, $token, $user)->id);
        } catch (BusinessRuleViolation $e) {
            return redirect()->route('invitations.show', ['organization' => $organization, 'token' => $token])
                ->with('error', $e->getMessage());
        }

        $request->session()->migrate(true);
        $request->session()->put(EstablishRequestContext::SESSION_KEY, EstablishRequestContext::sessionPayload($membership));

        return redirect()->to($membership->kind === MembershipKind::Buyer ? route('portal.home') : route('dashboard'))
            ->with('status', 'Convite aceito. Bem-vindo!');
    }
}
