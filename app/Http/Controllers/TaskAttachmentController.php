<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskAttachmentRequest;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Services\AttachmentVault;
use App\Services\TaskHistory;
use App\Support\BoardReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Anexos da tarefa: guardados criptografados pelo AttachmentVault e entregues
 * sempre como download, nunca abertos dentro do sistema.
 */
class TaskAttachmentController extends Controller
{
    /** Assinatura no início do arquivo, por extensão: a extensão e o tipo declarado não bastam. */
    private const SIGNATURES = [
        'pdf' => ['%PDF-'],
        'png' => ["\x89PNG"],
        'jpg' => ["\xFF\xD8\xFF"],
        'jpeg' => ["\xFF\xD8\xFF"],
        'docx' => ['PK'],
        'xlsx' => ['PK'],
    ];

    private const CONTENT_TYPES = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function __construct(
        private readonly AttachmentVault $vault,
        private readonly TaskHistory $history,
    ) {}

    public function store(TaskAttachmentRequest $request, Task $task): RedirectResponse
    {
        $file = $request->file('attachment');
        $extension = strtolower($file->getClientOriginalExtension());
        $contents = (string) file_get_contents($file->getRealPath());

        $signed = array_filter(self::SIGNATURES[$extension] ?? [], fn (string $signature) => str_starts_with($contents, $signature));

        if ($signed === []) {
            throw ValidationException::withMessages(['attachment' => 'O conteúdo do arquivo não corresponde ao tipo dele.']);
        }

        // Nome para a tela: o informado ou o do arquivo, sem pasta nem caracteres de controle.
        $name = Str::limit(trim((string) ($request->validated('name') ?: preg_replace('/[\x00-\x1F\x7F]/', '', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)))), 100, '');
        $path = $this->vault->storeIn('tasks', $contents);

        try {
            DB::transaction(function () use ($request, $task, $name, $extension, $contents, $path): void {
                $attachment = new TaskAttachment(['name' => $name !== '' ? $name : 'Anexo']);
                $attachment->extension = $extension;
                $attachment->size = strlen($contents);
                $attachment->path = $path;
                $attachment->uploaded_by = $request->user()->getKey();
                $task->attachments()->save($attachment);

                $this->history->log($task, $request->user(), 'attachment_added', ['name' => $attachment->name]);
            });
        } catch (Throwable $exception) {
            // Sem o registro, o arquivo gravado ficaria órfão no disco.
            $this->vault->delete($path);

            throw $exception;
        }

        return BoardReturn::toTask($task, 'anexos')->with('success', 'Anexo adicionado.');
    }

    public function show(Task $task, TaskAttachment $attachment): Response
    {
        Gate::authorize('view', $task);

        $contents = $this->vault->read($attachment->path);

        return response($contents, 200, [
            'Content-Type' => self::CONTENT_TYPES[$attachment->extension] ?? 'application/octet-stream',
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'attachment; filename="anexo-'.$attachment->getKey().'.'.$attachment->extension.'"',
        ]);
    }

    public function destroy(Task $task, TaskAttachment $attachment): RedirectResponse
    {
        Gate::authorize('update', $task);

        DB::transaction(function () use ($task, $attachment): void {
            $attachment->delete();
            $this->history->log($task, request()->user(), 'attachment_removed', ['name' => $attachment->name]);
        });

        $this->vault->delete($attachment->path);

        return BoardReturn::toTask($task, 'anexos')->with('success', 'Anexo removido.');
    }
}
