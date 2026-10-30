@php
    use App\Support\ClinicTime;

    // Campos da tarefa, na janela de nova tarefa e na edição pelo painel.
    // $scope é o valor de "_dialog" do formulário: valores antigos e erros só valem para ele.
    $task ??= null;
    $field = fn (string $name) => "{$scope}-{$name}";
    $selectedLabels = old('_dialog') === $scope ? array_map('intval', (array) old('labels', [])) : ($task?->labels->pluck('id')->all() ?? []);
@endphp
<div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
    <x-form.input class="sm:col-span-6" name="title" :id="$field('title')" :scope="$scope" label="Título" required maxlength="150" autocomplete="off" :value="$task?->title" />
    <x-form.textarea class="sm:col-span-6" name="description" :id="$field('description')" :scope="$scope" label="Descrição" rows="3" maxlength="5000" :value="$task?->description"
                     hint="Não escreva dados de pacientes aqui: o quadro é visto por toda a equipe." />
    <x-form.select class="sm:col-span-3" name="task_column_id" :id="$field('task_column_id')" :scope="$scope" label="Coluna" :options="$columns->pluck('name', 'id')->all()" required
                   :selected="$task?->task_column_id ?? $columns->first()?->id" />
    <x-form.select class="sm:col-span-3" name="priority" :id="$field('priority')" :scope="$scope" label="Prioridade" :options="$priorities" required :selected="$task?->priority->value ?? 'medium'" />
    <x-form.select class="sm:col-span-6" name="assignee_id" :id="$field('assignee_id')" :scope="$scope" label="Responsável" :options="$users->pluck('name', 'id')->all()" placeholder="Sem responsável"
                   :selected="$task?->assignee_id" />
    <x-form.input class="sm:col-span-3" name="starts_on" :id="$field('starts_on')" :scope="$scope" label="Início" type="date" :value="$task?->starts_on?->format('Y-m-d')" />
    <x-form.input class="sm:col-span-3" name="due_on" :id="$field('due_on')" :scope="$scope" label="Prazo" type="date" :value="$task?->due_on?->format('Y-m-d')" />
    @if ($labels->isNotEmpty())
        <fieldset class="sm:col-span-6">
            <legend class="label">Etiquetas</legend>
            <div class="mt-1 flex flex-wrap gap-2">
                @foreach ($labels as $label)
                    <label class="flex cursor-pointer items-center gap-1.5 text-sm">
                        <input type="checkbox" name="labels[]" value="{{ $label->id }}" class="size-4 rounded border-slate-300 accent-brand" @checked(in_array($label->id, $selectedLabels, true))>
                        <span class="badge {{ $label->badgeClasses() }}">{{ $label->name }}</span>
                    </label>
                @endforeach
            </div>
            @if (old('_dialog') === $scope)
                @error('labels')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            @endif
        </fieldset>
    @endif
</div>
