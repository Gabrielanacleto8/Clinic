{{-- Envio, troca e remoção da foto de identificação do paciente. --}}
<x-dialog id="patient-avatar" title="Foto de identificação">
    <form method="POST" action="{{ route('patients.avatar.update', $patient) }}" enctype="multipart/form-data" class="space-y-4" novalidate>
        @csrf
        <input type="hidden" name="_dialog" value="patient-avatar">
        <p class="text-sm text-slate-600">
            Foto do rosto para reconhecer o paciente na recepção, tirada com o consentimento dele.
            Não use foto de procedimento: essas ficam no prontuário.
        </p>
        <div>
            <label for="patient-avatar-file" class="label">Foto <span class="text-red-600" aria-hidden="true">*</span></label>
            <input id="patient-avatar-file" name="avatar" type="file" required accept="image/jpeg,image/png,image/webp"
                   data-photo-input data-max-files="1" data-max-dimension="{{ config('clinic.photos.thumbnail_dimension') * 2 }}"
                   data-max-bytes="{{ App\Support\UploadLimits::photoBytes() }}" data-max-request-bytes="{{ App\Support\UploadLimits::requestBytes() }}"
                   @class(['input file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1 file:text-sm file:font-medium', 'input-error' => old('_dialog') === 'patient-avatar' && $errors->has('avatar')])>
            <p class="mt-1 text-xs text-slate-500" data-photo-status aria-live="polite">
                JPEG, PNG ou WebP. A foto é recortada em quadrado, no centro, e os dados do aparelho são removidos.
            </p>
            @if (old('_dialog') === 'patient-avatar')
                @error('avatar')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            @endif
        </div>

        <div class="flex flex-wrap justify-end gap-2 pt-2">
            <button type="button" class="btn btn-secondary" data-dialog-close>Cancelar</button>
            <button type="submit" class="btn btn-primary" data-photo-submit>Salvar foto</button>
        </div>
    </form>

    @if ($patient->avatar_path)
        <form method="POST" action="{{ route('patients.avatar.destroy', $patient) }}" class="mt-4 border-t border-slate-200 pt-4"
              data-confirm="Remover a foto de identificação de {{ $patient->name }}?">
            @csrf
            @method('DELETE')
            <button type="submit" class="cursor-pointer text-sm font-medium text-red-700 hover:underline">Remover foto atual</button>
        </form>
    @endif
</x-dialog>
