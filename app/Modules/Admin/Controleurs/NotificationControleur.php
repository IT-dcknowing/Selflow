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
}
