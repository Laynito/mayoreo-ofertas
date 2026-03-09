<?php

namespace App\Http\Responses\Filament;

use App\Filament\Pages\PreciosBajos;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as Responsable;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class LoginResponse implements Responsable
{
    public function toResponse($request): RedirectResponse | Redirector
    {
        return redirect()->intended(PreciosBajos::getUrl());
    }
}
