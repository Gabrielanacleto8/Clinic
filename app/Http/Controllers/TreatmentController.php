<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\TreatmentStatus;
use App\Http\Requests\TreatmentRequest;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ProductBatch;
use App\Models\Treatment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TypicalProductSuggester;
use App\Support\ClinicTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TreatmentController extends Controller
{
    private const FILLABLE = ['treated_areas', 'assessment', 'description', 'post_care', 'complications', 'return_date'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(Request $request, Patient $patient): View
    {
        Gate::authorize('viewClinicalRecords', $patient);
        Gate::authorize('create', Treatment::class);

        $treatment = new Treatment;
        $appointment = $this->appointmentToStart($request, $patient);

        // Vindo da agenda, o registro já abre com o que foi combinado no agendamento.
        if ($appointment) {
            Gate::authorize('startTreatment', $appointment);

            $treatment->procedure_id = $appointment->procedure_id;
            $treatment->professional_id = $appointment->professional_id;
        }

        return view('treatments.create', [
            'patient' => $patient->load('anamnesis'),
            'treatment' => $treatment,
            'appointment' => $appointment,
            ...$this->formOptions($treatment),
        ]);
    }

    public function store(TreatmentRequest $request, Patient $patient, TypicalProductSuggester $suggester): RedirectResponse
    {
        Gate::authorize('viewClinicalRecords', $patient);

        $suggested = false;

        $treatment = DB::transaction(function () use ($request, $patient, $suggester, &$suggested): Treatment {
            $treatment = new Treatment($request->safe()->only(self::FILLABLE));
            $treatment->procedure_id = (int) $request->validated('procedure_id');
            $treatment->performed_at = ClinicTime::fromInput($request->validated('performed_at'));
            $treatment->patient_id = $patient->getKey();
            $treatment->professional_id = $this->resolveProfessionalId($request, $request->user()->getKey());
            $treatment->created_by = $request->user()->getKey();
            $treatment->status = TreatmentStatus::Draft;
            $treatment->save();

            $products = $request->products();

            // Sem produtos informados, parte dos que o procedimento normalmente
            // usa. O profissional confere e ajusta antes de finalizar.
            if ($products === []) {
                $products = $suggester->suggestFor($treatment->procedure_id);
                $suggested = $products !== [];
            }

            $treatment->products()->createMany($products);

            $this->audit->log(AuditAction::TreatmentCreated, $treatment, ['patient_id' => $patient->getKey()]);

            if ($request->filled('appointment_id')) {
                $this->linkAppointment((int) $request->validated('appointment_id'), $treatment);
            }

            return $treatment;
        });

        return redirect()->route('patients.treatments.show', [$patient, $treatment])->with('success', $suggested
            ? 'Atendimento salvo. Os produtos padrão do procedimento foram sugeridos: confira antes de finalizar.'
            : 'Atendimento salvo. Adicione as fotos e finalize quando concluir.');
    }

    public function show(Request $request, Patient $patient, Treatment $treatment): View
    {
        Gate::authorize('view', $treatment);

        $this->audit->log(AuditAction::TreatmentViewed, $treatment, ['patient_id' => $patient->getKey()]);

        $treatment->load([
            'professional:id,name',
            'procedure:id,name',
            'products.batch.product:id,name,unit',
            'photos' => fn ($query) => $query->oldest('id'),
            'documents' => fn ($query) => $query->oldest('id'),
            'notes.author:id,name',
        ]);

        // O financeiro do atendimento só é consultado para quem pode vê-lo.
        if ($treatment->isFinalized() && $request->user()->hasPermission(Permission::FinanceView)) {
            $treatment->load('financialEntry.installments');
        }

        return view('treatments.show', [
            'patient' => $patient->load('anamnesis'),
            'treatment' => $treatment,
        ]);
    }

    public function edit(Patient $patient, Treatment $treatment): View
    {
        Gate::authorize('update', $treatment);

        $this->audit->log(AuditAction::TreatmentViewed, $treatment, ['patient_id' => $patient->getKey(), 'context' => 'edit']);

        return view('treatments.edit', [
            'patient' => $patient->load('anamnesis'),
            'treatment' => $treatment->load(['products', 'professional:id,name']),
            ...$this->formOptions($treatment),
        ]);
    }

    public function update(TreatmentRequest $request, Patient $patient, Treatment $treatment): RedirectResponse
    {
        DB::transaction(function () use ($request, $patient, $treatment): void {
            $treatment->fill($request->safe()->only(self::FILLABLE));
            $treatment->procedure_id = (int) $request->validated('procedure_id');
            $treatment->performed_at = ClinicTime::fromInput($request->validated('performed_at'));
            $treatment->professional_id = $this->resolveProfessionalId($request, $treatment->professional_id);

            // Só os nomes dos campos entram na auditoria, nunca os valores.
            $changed = array_keys($treatment->getDirty());

            $before = $treatment->products()->orderBy('id')->get()
                ->map(fn ($product) => ['product_batch_id' => $product->product_batch_id, 'quantity' => $product->quantity])
                ->all();

            if ($before !== $request->products()) {
                $treatment->products()->delete();
                $treatment->products()->createMany($request->products());
                $changed[] = 'products';
            }

            if ($changed !== []) {
                $treatment->save();

                $this->audit->log(AuditAction::TreatmentUpdated, $treatment, ['patient_id' => $patient->getKey(), 'fields' => $changed]);
            }
        });

        return redirect()->route('patients.treatments.show', [$patient, $treatment])->with('success', 'Atendimento atualizado.');
    }

    /**
     * Descarta um atendimento em andamento. O registro e os anexos ficam
     * guardados; o agendamento de origem volta a ficar livre para um novo atendimento.
     */
    public function destroy(Patient $patient, Treatment $treatment): RedirectResponse
    {
        Gate::authorize('delete', $treatment);

        DB::transaction(function () use ($patient, $treatment): void {
            Appointment::query()->where('treatment_id', $treatment->getKey())->update(['treatment_id' => null]);

            $treatment->delete();

            $this->audit->log(AuditAction::TreatmentDiscarded, $treatment, ['patient_id' => $patient->getKey()]);
        });

        return redirect()->route('patients.show', $patient)->with('success', 'Atendimento descartado.');
    }

    /**
     * Agendamento em aberto deste paciente indicado na URL ou devolvido pelo formulário.
     */
    private function appointmentToStart(Request $request, Patient $patient): ?Appointment
    {
        $id = $request->old('appointment_id', $request->query('agendamento'));

        if (! is_numeric($id)) {
            return null;
        }

        return Appointment::query()
            ->whereKey((int) $id)
            ->where('patient_id', $patient->getKey())
            ->whereIn('status', AppointmentStatus::open())
            ->whereNull('treatment_id')
            ->first();
    }

    /**
     * Liga o agendamento ao atendimento iniciado. A trava impede que dois
     * envios simultâneos iniciem dois atendimentos para o mesmo agendamento.
     * O agendamento só passa a "atendido" quando o atendimento é finalizado.
     */
    private function linkAppointment(int $appointmentId, Treatment $treatment): void
    {
        $appointment = Appointment::query()->whereKey($appointmentId)->lockForUpdate()->first();

        if (! $appointment?->isOpen() || $appointment->treatment_id !== null || $appointment->patient_id !== $treatment->patient_id) {
            throw ValidationException::withMessages([
                'appointment_id' => 'O agendamento informado não está mais em aberto. Volte ao Início e tente de novo.',
            ]);
        }

        $appointment->treatment_id = $treatment->getKey();
        $appointment->save();
    }

    /**
     * Só administradores registram atendimento em nome de outro profissional.
     * Para os demais o campo do formulário é ignorado, o que impede atribuir
     * um procedimento a outra pessoa.
     */
    private function resolveProfessionalId(TreatmentRequest $request, int $default): int
    {
        if ($request->user()->isAdmin() && $request->filled('professional_id')) {
            return (int) $request->validated('professional_id');
        }

        return $default;
    }

    /**
     * Listas dos campos de seleção do formulário.
     *
     * @return array{professionals: Collection<int, User>, procedures: Collection<int, Procedure>, batches: Collection<int, ProductBatch>}
     */
    private function formOptions(Treatment $treatment): array
    {
        $current = $treatment->exists ? $treatment->products->pluck('product_batch_id')->all() : [];

        return [
            'professionals' => User::query()->attending()->orderBy('name')->get(['id', 'name']),
            'procedures' => Procedure::query()->selectable($treatment->procedure_id)->orderBy('name')->get(['id', 'name']),
            // Lotes dentro da validade e com saldo, mais os que este atendimento já usa.
            'batches' => ProductBatch::query()
                ->with('product:id,name,unit')
                ->where(fn ($query) => $query
                    ->where(fn ($usable) => $usable->usable()->where('quantity', '>', 0))
                    ->when($current !== [], fn ($inner) => $inner->orWhereIn('id', $current)))
                ->orderBy('product_id')
                ->orderByRaw('expires_on is null')
                ->orderBy('expires_on')
                ->get(['id', 'product_id', 'batch_code', 'expires_on', 'quantity']),
        ];
    }
}
