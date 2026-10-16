<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Http\Requests\TreatmentRequest;
use App\Http\Requests\TreatmentWithPatientRequest;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Treatment;
use App\Services\AuditLogger;
use App\Services\TreatmentFormOptions;
use App\Services\TreatmentRecorder;
use App\Support\ClinicTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TreatmentController extends Controller
{
    private const FILLABLE = ['treated_areas', 'assessment', 'description', 'post_care', 'complications', 'return_date'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Atendimentos registrados, do mais recente ao mais antigo.
     */
    public function index(Request $request, TreatmentFormOptions $options): View
    {
        Gate::authorize('viewAny', Treatment::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'procedimento' => ['nullable', 'integer'],
            'de' => ['nullable', 'date_format:Y-m-d'],
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $treatments = Treatment::query()
            ->with(['patient:id,name', 'professional:id,name', 'procedure:id,name'])
            // Atendimentos de pacientes arquivados não aparecem.
            ->whereHas('patient', fn ($query) => $query->search($filters['q'] ?? null))
            ->when($filters['procedimento'] ?? null, fn ($query, int|string $procedureId) => $query->where('procedure_id', $procedureId))
            ->performedBetween($filters['de'] ?? null, $filters['ate'] ?? null)
            ->latest('performed_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // A lista mostra quem fez qual procedimento: é leitura de prontuário.
        // Na auditoria entra só quais filtros foram usados, nunca o que foi buscado.
        $this->audit->log(AuditAction::TreatmentsListed, null, ['filters' => array_keys(array_filter($filters, filled(...)))]);

        return view('treatments.index', [
            'treatments' => $treatments,
            'filters' => ['q' => $filters['q'] ?? '', 'procedimento' => (string) ($filters['procedimento'] ?? ''), 'de' => $filters['de'] ?? '', 'ate' => $filters['ate'] ?? ''],
            'procedures' => Procedure::query()->orderBy('name')->pluck('name', 'id'),
            'treatmentForm' => $request->user()->can('create', Treatment::class) ? $options->forDialog($request) : null,
        ]);
    }

    public function create(Request $request, Patient $patient, TreatmentFormOptions $options): View
    {
        Gate::authorize('viewClinicalRecords', $patient);
        Gate::authorize('create', Treatment::class);

        $treatment = new Treatment;
        $appointment = $this->appointmentToAttend($request, $patient);

        // Vindo da agenda, o registro já abre com o que foi combinado no agendamento.
        if ($appointment) {
            $treatment->procedure_id = $appointment->procedure_id;
            $treatment->professional_id = $appointment->professional_id;
        }

        return view('treatments.create', [
            'patient' => $patient->load('anamnesis'),
            'treatment' => $treatment,
            'appointment' => $appointment,
            ...$options->for($request->user()),
        ]);
    }

    public function store(TreatmentRequest $request, Patient $patient, TreatmentRecorder $recorder): RedirectResponse
    {
        Gate::authorize('viewClinicalRecords', $patient);

        $treatment = new Treatment($request->safe()->only(self::FILLABLE));
        $treatment->procedure_id = (int) $request->validated('procedure_id');
        $treatment->performed_at = ClinicTime::fromInput($request->validated('performed_at'));
        $treatment->patient_id = $patient->getKey();
        $treatment->professional_id = $this->resolveProfessionalId($request);
        $treatment->created_by = $request->user()->getKey();

        $recorder->record(
            $treatment,
            $request->products(),
            $request->payment(),
            $request->filled('appointment_id') ? (int) $request->validated('appointment_id') : null,
            $request->user(),
        );

        return redirect()->route('patients.treatments.show', [$patient, $treatment])
            ->with('success', 'Atendimento registrado. Estoque e financeiro foram atualizados.');
    }

    /**
     * Diálogo "Registrar atendimento": o paciente vem do formulário, e não da URL.
     */
    public function storeForChosenPatient(TreatmentWithPatientRequest $request, TreatmentRecorder $recorder): RedirectResponse
    {
        $patient = Patient::query()->findOrFail((int) $request->validated('patient_id'));

        return $this->store($request, $patient, $recorder);
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
        if ($request->user()->hasPermission(Permission::FinanceView)) {
            $treatment->load('financialEntry.installments');
        }

        return view('treatments.show', [
            'patient' => $patient->load('anamnesis'),
            'treatment' => $treatment,
        ]);
    }

    /**
     * Agendamento deste paciente, ainda em aberto, indicado na URL ou devolvido pelo formulário.
     */
    private function appointmentToAttend(Request $request, Patient $patient): ?Appointment
    {
        $id = $request->old('appointment_id', $request->query('agendamento'));

        if (! is_numeric($id)) {
            return null;
        }

        return Appointment::query()
            ->whereKey((int) $id)
            ->where('patient_id', $patient->getKey())
            ->openForTreatment()
            ->first();
    }

    /**
     * Só administradores registram atendimento em nome de outro profissional.
     * Para os demais o campo do formulário é ignorado, o que impede atribuir
     * um procedimento a outra pessoa.
     */
    private function resolveProfessionalId(TreatmentRequest $request): int
    {
        if ($request->user()->isAdmin() && $request->filled('professional_id')) {
            return (int) $request->validated('professional_id');
        }

        return $request->user()->getKey();
    }
}
