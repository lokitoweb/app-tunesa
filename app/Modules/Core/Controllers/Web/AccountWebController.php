<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Notifications\SecurityAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * Mi cuenta en el panel: datos de la persona y cambio de contraseña.
 * Es personal: no depende de la empresa elegida por el Super Admin.
 */
class AccountWebController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.edit', ['user' => $request->user()]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password'         => ['required', 'string', 'confirmed', 'different:current_password', PasswordRule::min(8)->letters()->mixedCase()->numbers()],
        ], [
            'current_password.current_password' => 'La contraseña actual es incorrecta.',
            'password.different'                => 'La nueva contraseña debe ser distinta de la actual.',
        ]);

        $user = $request->user();
        $wasTemporary = (bool) $user->must_change_password;

        $user->forceFill(['password' => $request->password, 'must_change_password' => false])->save();

        // Igual que en la app: se cierran las sesiones de la app en los celulares
        $user->tokens()->delete();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        if (! $wasTemporary) {
            try {
                $user->notify(new SecurityAlert('password_changed'));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return redirect()->route('account.edit')->with('success', 'Contraseña actualizada. Se cerró la sesión de la app en tus celulares.');
    }
}
