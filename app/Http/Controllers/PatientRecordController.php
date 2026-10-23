<?php

namespace App\Http\Controllers;

use App\Enums\AttachmentType;
use App\Enums\AuditAction;
use App\Models\Patient;
use App\Models\PatientAttachment;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PatientRecordController extends Controller
{
    /**
     * Prontuário para imprimir ou salvar em PDF: cadastro, ficha de saúde e
     * os atendimentos, com produtos, lotes e evoluções. Atende o pedido do
     * paciente pelos próprios dados e o arquivo em papel. Com ?atendimento=,
     * só aquele atendimento.
     */
    public function show(Request $request, Patient $patient, AuditLogger $audit): View
    {
        Gate::authorize('viewClinicalRecords', $patient);

        if ($patient->trashed()) {
            Gate::authorize('viewArchived', Patient::class);
        }

        $filters = $request->validate(['atendimento' => ['nullable', 'integer']]);
        $only = isset($filters['atendimento']) ? (int) $filters['atendimento'] : null;

        $treatments = $patient->treatments()
            ->with([
                'professional:id,name',
                'procedure:id,name',
                'products.batch.product:id,name,unit',
                'notes.author:id,name',
                'voidedBy:id,name',
            ])
            ->when($only, fn ($query, int $id) => $query->whereKey($id))
            ->oldest('performed_at')
            ->oldest('id')
            ->get();

        // Atendimento pedido que não é deste paciente: não existe para esta página.
        abort_if($only !== null && $treatments->isEmpty(), 404);

        $audit->log(AuditAction::PatientRecordPrinted, $patient, array_filter([
            'treatment_id' => $only,
            'treatments' => $treatments->count(),
        ], fn ($value) => $value !== null));

        // No prontuário completo, o inventário do que mais a clínica guarda do
        // paciente: fotos e documentos. As imagens em si são entregues à parte.
        $attachments = $only === null
            ? PatientAttachment::query()
                ->where('patient_id', $patient->getKey())
                // Anexo de atendimento arquivado fica de fora, como na ficha.
                ->where(fn ($query) => $query->whereNull('treatment_id')->orWhereHas('treatment'))
                ->with(['treatment:id,patient_id,procedure_id,performed_at', 'treatment.procedure:id,name'])
                ->orderBy('id')
                ->get()
            : collect();

        return view('patients.record', [
            'patient' => $patient->load('anamnesis'),
            'treatments' => $treatments,
            'single' => $only !== null,
            'photos' => $attachments->filter(fn (PatientAttachment $attachment) => $attachment->type === AttachmentType::Photo)->values(),
            'documents' => $attachments->filter(fn (PatientAttachment $attachment) => $attachment->type === AttachmentType::Document)->values(),
            'printedBy' => $request->user(),
        ]);
    }
}
