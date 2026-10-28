<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Exceptions\InvalidPhotoException;
use App\Http\Requests\ClinicProfileRequest;
use App\Http\Requests\UpdateClinicLogoRequest;
use App\Models\ClinicProfile;
use App\Services\AuditLogger;
use App\Services\ClinicLogo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Identificação da clínica: dados dos documentos e logo. Só o administrador.
 */
class ClinicSettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(ClinicLogo $logo): View
    {
        Gate::authorize('manage-clinic');

        return view('clinic.edit', [
            'profile' => ClinicProfile::current(),
            'logoVersion' => $logo->version(),
        ]);
    }

    public function update(ClinicProfileRequest $request): RedirectResponse
    {
        $profile = ClinicProfile::current();
        $profile->fill($request->validated());
        $changed = array_keys($profile->getDirty());

        if ($changed !== []) {
            $profile->id = ClinicProfile::SINGLETON_ID;
            $profile->updated_by = $request->user()->getKey();
            $profile->save();

            $this->audit->log(AuditAction::ClinicProfileUpdated, $profile, ['fields' => $changed]);
        }

        return redirect()->route('clinic.edit')->with('success', 'Dados da clínica salvos.');
    }

    public function updateLogo(UpdateClinicLogoRequest $request, ClinicLogo $logo): RedirectResponse
    {
        try {
            $logo->replace($request->file('logo'));
        } catch (InvalidPhotoException $exception) {
            return back()->withErrors(['logo' => $exception->getMessage()]);
        }

        return redirect()->route('clinic.edit')->with('success', 'Logo atualizado. O ícone do aplicativo instalado muda na próxima instalação.');
    }

    public function destroyLogo(ClinicLogo $logo): RedirectResponse
    {
        Gate::authorize('manage-clinic');

        $logo->remove();

        return redirect()->route('clinic.edit')->with('success', 'Logo removido.');
    }
}
