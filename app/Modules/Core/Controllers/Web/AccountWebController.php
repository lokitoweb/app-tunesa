<?php

namespace App\Modules\Core\Controllers\Web;

use App\Modules\Core\Controllers\Controller;
use App\Modules\Core\Notifications\SecurityAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
        $user = $request->user();
        $activeTokensCount = $user->tokens()->count();
        $primaryBranch = $user->branches()->wherePivot('is_primary', true)->first();

        return view('account.edit', compact('user', 'activeTokensCount', 'primaryBranch'));
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'phone'  => ['nullable', 'string', 'max:25'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,webp,jpg', 'max:3072'],
        ], [
            'avatar.image' => 'El archivo debe ser una imagen válida (JPG, PNG o WEBP).',
            'avatar.max'   => 'La foto de perfil no debe superar los 3 MB.',
        ]);

        if ($request->hasFile('avatar')) {
            // La foto anterior se borra para no dejar archivos huérfanos
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $user->avatar_path = $request->file('avatar')->store('avatars', 'public');
        }

        if (array_key_exists('phone', $validated)) {
            $user->phone = $validated['phone'];
        }

        $user->save();

        return redirect()->route('account.edit')->with('success', 'Datos de perfil actualizados correctamente.');
    }

    public function revokeTokens(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->tokens()->delete();

        return redirect()->route('account.edit')->with('success', 'Se cerraron todas las sesiones activas en tus dispositivos móviles.');
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
