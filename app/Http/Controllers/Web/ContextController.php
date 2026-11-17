<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Identity\SelectContext;
use App\Data\Identity\ContextOption;
use App\Domain\Identity\Exceptions\ContextUnavailable;
use App\Enums\Identity\MembershipKind;
use App\Http\Middleware\EstablishRequestContext;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Services\Identity\ContextDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Seleção e troca de contexto (organização e tipo de acesso).
 */
final class ContextController
{
    public function index(Request $request, ContextDirectory $directory, SelectContext $select): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $options = $directory->forUser($user);
        $available = array_values(array_filter($options, static fn (ContextOption $o): bool => $o->available));

        // Retomada automática quando há um único contexto disponível.
        if (count($available) === 1 && ! $request->session()->has(EstablishRequestContext::SESSION_KEY) && ! $request->boolean('escolher')) {
            return $this->activate($request, $select->handle($user, $available[0]->membershipId));
        }

        return view('contexts.index', ['options' => $options]);
    }

    public function store(Request $request, SelectContext $select): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $membership = $select->handle($user, (string) $request->input('membership_id', ''));
        } catch (ContextUnavailable) {
            return redirect()->route('contexts.index', ['escolher' => 1])->with('error', 'Contexto indisponível.');
        }

        return $this->activate($request, $membership);
    }

    public function leave(Request $request): RedirectResponse
    {
        $request->session()->forget(EstablishRequestContext::SESSION_KEY);

        return redirect()->route('contexts.index', ['escolher' => 1]);
    }

    private function activate(Request $request, Membership $membership): RedirectResponse
    {
        // Novo identificador de sessão ao mudar de contexto.
        $request->session()->migrate(true);
        $request->session()->put(EstablishRequestContext::SESSION_KEY, EstablishRequestContext::sessionPayload($membership));

        return redirect()->to($membership->kind === MembershipKind::Buyer ? route('portal.home') : route('dashboard'));
    }
}
