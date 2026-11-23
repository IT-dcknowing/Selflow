<?php

namespace App\Modules\Admin\Controleurs;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class NotificationControleur
{
    public function marquerLue(string $notification): RedirectResponse
    {
        Auth::user()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return back();
    }

    /** Tout marquer comme lu, d'un clic (23/11/2026). */
    public function toutLire(): RedirectResponse
    {
        Auth::user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
