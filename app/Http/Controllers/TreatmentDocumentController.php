<?php

namespace App\Http\Controllers;

use App\Enums\AttachmentType;
use App\Enums\AuditAction;
use App\Http\Requests\StoreDocumentRequest;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentAttachment;
use App\Services\AttachmentVault;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TreatmentDocumentController extends Controller
{
    private const PDF_SIGNATURE = '%PDF-';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AttachmentVault $vault,
    ) {}

    public function store(StoreDocumentRequest $request, Patient $patient, Treatment $treatment): RedirectResponse
    {
        $contents = (string) file_get_contents($request->file('document')->getRealPath());

        // A extensão e o tipo declarado não bastam: confere a assinatura do arquivo.
        if (! str_starts_with($contents, self::PDF_SIGNATURE)) {
            throw ValidationException::withMessages(['document' => 'O arquivo não é um PDF válido.']);
        }

        DB::transaction(function () use ($request, $patient, $treatment, $contents): void {
            $document = new TreatmentAttachment(['caption' => $request->validated('title')]);
            $document->type = AttachmentType::Document;
            $document->treatment_id = $treatment->getKey();
            $document->path = $this->vault->store($patient->getKey(), $contents);
            $document->size = strlen($contents);
            $document->uploaded_by = $request->user()->getKey();
            $document->save();

            $this->audit->log(AuditAction::DocumentUploaded, $document, ['patient_id' => $patient->getKey(), 'treatment_id' => $treatment->getKey()]);
        });

        return redirect()->route('patients.treatments.show', [$patient, $treatment])->with('success', 'Documento adicionado.');
    }

    /**
     * O PDF é sempre entregue como download, nunca aberto dentro do sistema:
     * um PDF pode conter scripts, e assim ele não roda na origem da aplicação.
     */
    public function show(Patient $patient, Treatment $treatment, TreatmentAttachment $document): Response
    {
        Gate::authorize('view', $treatment);

        $this->audit->log(AuditAction::DocumentViewed, $document, ['patient_id' => $patient->getKey(), 'treatment_id' => $treatment->getKey()]);

        $contents = $this->vault->read($document->path);

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'attachment; filename="documento-'.$document->getKey().'.pdf"',
        ]);
    }

    public function destroy(Patient $patient, Treatment $treatment, TreatmentAttachment $document): RedirectResponse
    {
        Gate::authorize('detach', $treatment);

        DB::transaction(function () use ($patient, $treatment, $document): void {
            // Remoção lógica: o documento some das telas, mas o arquivo é mantido.
            $document->delete();

            $this->audit->log(AuditAction::DocumentDeleted, $document, ['patient_id' => $patient->getKey(), 'treatment_id' => $treatment->getKey()]);
        });

        return redirect()->route('patients.treatments.show', [$patient, $treatment])->with('success', 'Documento removido.');
    }
}
