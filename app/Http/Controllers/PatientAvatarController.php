<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePatientAvatarRequest;
use App\Models\Patient;
use App\Services\PatientAvatar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Foto de identificação do paciente: vista por quem vê o cadastro e trocada
 * por quem o edita. É a única porta de saída do arquivo.
 */
class PatientAvatarController extends Controller
{
    public function __construct(private readonly PatientAvatar $avatar) {}

    /**
     * A foto aparece dentro da listagem e da ficha do paciente, que já seguem
     * a regra de auditoria delas; cada imagem não gera registro próprio.
     */
    public function show(Patient $patient): Response
    {
        Gate::authorize('view', $patient);

        $contents = $this->avatar->read($patient) ?? abort(404);

        return response($contents, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'inline; filename="paciente-'.$patient->getKey().'.jpg"',
        ]);
    }

    public function update(UpdatePatientAvatarRequest $request, Patient $patient): RedirectResponse
    {
        $this->avatar->replace($patient, $request->file('avatar'), $request->user());

        return redirect()->route('patients.show', $patient)->with('success', 'Foto de identificação atualizada.');
    }

    public function destroy(Request $request, Patient $patient): RedirectResponse
    {
        Gate::authorize('update', $patient);

        $this->avatar->remove($patient, $request->user());

        return redirect()->route('patients.show', $patient)->with('success', 'Foto de identificação removida.');
    }
}
