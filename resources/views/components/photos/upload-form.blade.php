@props(['action', 'title', 'description' => null])
@php
    use App\Enums\PhotoStage;

    $maxPhotos = config('clinic.photos.max_per_upload');
@endphp
{{-- Envio de fotos, do atendimento ou da ficha. Campos extras entram pelo slot, antes do momento. --}}
<form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="mt-6 border-t border-slate-200 pt-6" novalidate data-photo-form>
    @csrf
    <h3 class="text-sm font-semibold">{{ $title }}</h3>
    <p class="mt-1 text-sm text-slate-600">
        @if ($description) {{ $description }} @endif
        Até {{ $maxPhotos }} fotos por envio, em JPEG, PNG ou WebP. Localização e dados do aparelho são removidos antes de gravar.
    </p>

    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-6">
        <div class="md:col-span-6">
            <label for="photos" class="label">Fotos <span class="text-red-600" aria-hidden="true">*</span></label>
            <input id="photos" name="photos[]" type="file" multiple required accept="image/jpeg,image/png,image/webp"
                   data-photo-input data-max-files="{{ $maxPhotos }}" data-max-dimension="{{ config('clinic.photos.max_dimension') }}"
                   data-max-bytes="{{ App\Support\UploadLimits::photoBytes() }}" data-max-request-bytes="{{ App\Support\UploadLimits::requestBytes() }}"
                   @class(['input file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1 file:text-sm file:font-medium', 'input-error' => $errors->has('photos') || $errors->has('photos.*')])>
            <p class="mt-1 text-xs text-slate-500" data-photo-status aria-live="polite"></p>
            @foreach (collect($errors->get('photos'))->merge(collect($errors->get('photos.*'))->flatten())->unique() as $message)
                <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
            @endforeach
        </div>
        {{ $slot }}
        <x-form.select class="md:col-span-2" name="stage" label="Momento" :options="PhotoStage::options()" :selected="PhotoStage::Before->value" required />
        <x-form.input :class="$slot->isEmpty() ? 'md:col-span-4' : 'md:col-span-2'" name="caption" label="Legenda" maxlength="150" autocomplete="off" placeholder="Ex.: Perfil direito" />
    </div>

    <div class="mt-4 flex justify-end">
        <button type="submit" class="btn btn-primary" data-photo-submit>Enviar fotos</button>
    </div>
</form>
