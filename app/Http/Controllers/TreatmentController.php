<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\TreatmentOrigin;
use App\Http\Requests\TreatmentRequest;
use App\Http\Requests\TreatmentWithPatientRequest;
use App\Http\Requests\VoidTreatmentRequest;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Treatment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TreatmentFormOptions;
use App\Services\TreatmentRecorder;
use App\Services\TreatmentVoider;
use App\Support\ClinicTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
            'profissional' => ['nullable', 'integer'],
            'situacao' => ['nullable', Rule::in(['validos', 'anulados', 'todos'])],
        ]);
        $status = $filters['situacao'] ?? 'validos';

        $treatments = Treatment::query()
            ->with(['patient:id,name', 'professional:id,name', 'procedure:id,name'])
            // Atendimentos de pacientes arquivados não aparecem.
            ->whereHas('patient', fn ($query) => $query->search($filters['q'] ?? null))
            ->when($filters['procedimento'] ?? null, fn ($query, int|string $procedureId) => $query->where('procedure_id', $procedureId))
            ->when($filters['profissional'] ?? null, fn ($query, int|string $professionalId) => $query->where('professional_id', $professionalId))
            ->when($status === 'validos', fn ($query) => $query->whereNull('voided_at'))
            ->when($status === 'anulados', fn ($query) => $query->whereNotNull('voided_at'))
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
            'filters' => ['q' => $filters['q'] ?? '', 'procedimento' => (string) ($filters['procedimento'] ?? ''), 'de' => $filters['de'] ?? '', 'ate' => $filters['ate'] ?? '', 'profissional' => (string) ($filters['profissional'] ?? ''), 'situacao' => $status],
            'procedures' => Procedure::query()->orderBy('name')->pluck('name', 'id'),
            'professionals' => User::query()->whereIn('id', Treatment::query()->select('professional_id'))->orderBy('name')->pluck('name', 'id'),
            'treatmentForm' => $request->user()->can('create', Treatment::class) ? $options->forDialog($request) : null,
        ]);
    }

    public function create(Request $request, Patient $patient, TreatmentFormOptions $options): View
    {
        Gate::authorize('viewClinicalRecords', $patient);
        Gate::authorize('create', Treatment::class);

        $treatment = new Treatment;
        $appointment = $this->appointmentToAttend($request, $patient);

        // Origem vem da URL (ou do formulário que voltou com erro): só um valor do enum vale.
        $origin = $request->old('origem', $request->query('origem'));

        // Vindo da agenda, o registro já abre com o que foi combinado no agendamento,
        // inclusive a data: o de dia anterior não pode ficar com a data de hoje.
        if ($appointment) {
            $treatment->procedure_id = $appointment->procedure_id;
            $treatment->professional_id = $appointment->professional_id;
            $treatment->performed_at = $appointment->scheduled_at->min(now());
        }

        return view('treatments.create', [
            'patient' => $patient->load('anamnesis'),
            'treatment' => $treatment,
            'appointment' => $appointment,
            'origin' => is_string($origin) ? TreatmentOrigin::tryFrom($origin) : null,
            ...$options->for($request->user(), $appointment?->procedure_id),
        ]);
    }

    public function store(TreatmentRequest $request, Patient $patient, TreatmentRecorder $recorder): RedirectResponse
    {
        $appointmentId = $request->filled('appointment_id') ? (int) $request->validated('appointment_id') : null;

        return $this->register($request, $patient, $recorder, $appointmentId);
    }

    /**
     * Diálogo "Registrar atendimento": o paciente vem do formulário, e não da URL.
     * Se ele tem agendamento em aberto no dia do atendimento, o registro o
     * encerra, como se tivesse partido da agenda: assim o horário não fica
     * em aberto nem recebe um segundo registro.
     */
    public function storeForChosenPatient(TreatmentWithPatientRequest $request, TreatmentRecorder $recorder): RedirectResponse
    {
        $patient = Patient::query()->findOrFail((int) $request->validated('patient_id'));

        Gate::authorize('viewClinicalRecords', $patient);

        [$dayStart, $dayEnd] = ClinicTime::dayBounds(ClinicTime::local(ClinicTime::fromInput($request->validated('performed_at')))->format('Y-m-d'));

        // Com mais de um agendamento no dia, o do mesmo profissional e procedimento
        // é o que este registro encerra.
        $professionalId = $this->resolveProfessionalId($request);
        $procedureId = (int) $request->validated('procedure_id');

        $appointmentId = Appointment::query()
            ->where('patient_id', $patient->getKey())
            ->openForTreatment()
            ->whereBetween('scheduled_at', [$dayStart, $dayEnd])
            ->orderByRaw('(professional_id = ?) desc, (procedure_id <=> ?) desc, scheduled_at', [$professionalId, $procedureId])
            ->value('id');

        return $this->register($request, $patient, $recorder, $appointmentId);
    }

    private function register(TreatmentRequest $request, Patient $patient, TreatmentRecorder $recorder, ?int $appointmentId): RedirectResponse
    {
        Gate::authorize('viewClinicalRecords', $patient);

        $treatment = new Treatment($request->safe()->only(self::FILLABLE));
        $treatment->procedure_id = (int) $request->validated('procedure_id');
        $treatment->performed_at = ClinicTime::fromInput($request->validated('performed_at'));
        $treatment->patient_id = $patient->getKey();
        $treatment->professional_id = $this->resolveProfessionalId($request);
        $treatment->created_by = $request->user()->getKey();

        $recorder->record($treatment, $request->products(), $request->payment(), $appointmentId, $request->user());

        $message = 'Atendimento registrado. Estoque e financeiro foram atualizados.';

        if ($appointmentId !== null && $request instanceof TreatmentWithPatientRequest) {
            $message .= ' O agendamento do paciente nesse dia passou para "Atendido".';
        }
        $origin = TreatmentOrigin::tryFrom((string) $request->validated('origem'));

        // Sem origem (link direto), abre o registro. Com origem, o usuário fica no
        // módulo em que estava, com um atalho para o registro na mensagem.
        if ($origin === null) {
            return redirect()->route('patients.treatments.show', [$patient, $treatment])->with('success', $message);
        }

        $appointment = $appointmentId !== null ? Appointment::find($appointmentId) : null;

        $links = [
            ['url' => route('patients.treatments.show', [$patient, $treatment]), 'label' => 'Ver o atendimento'],
            ['url' => route('patients.treatments.show', [$patient, $treatment]).'#fotos', 'label' => 'Adicionar fotos'],
        ];

        // Retorno previsto só vale se virar agendamento: o atalho já abre o dia.
        if ($treatment->return_date !== null && $request->user()->can('create', Appointment::class)) {
            $links[] = ['url' => route('appointments.create', ['paciente' => $patient->getKey(), 'data' => $treatment->return_date->format('Y-m-d')]), 'label' => 'Agendar o retorno de '.$treatment->return_date->format('d/m')];
        }

        return redirect()->to($origin->url($appointment))
            ->with('success', $message)
            ->with('success_links', $links);
    }

    public function show(Request $request, Patient $patient, Treatment $treatment): View
    {
        Gate::authorize('view', $treatment);

        $this->audit->log(AuditAction::TreatmentViewed, $treatment, ['patient_id' => $patient->getKey()]);

        $treatment->load([
            'professional:id,name',
            'creator:id,name',
            'voidedBy:id,name',
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
            // Quem pode anular vê, antes de tentar, se há pagamento registrado.
            'paidInstallments' => $treatment->relationLoaded('financialEntry') ? ($treatment->financialEntry?->installments->whereNotNull('paid_at')->count() ?? 0) : 0,
            // Anulado: o agendamento devolvido à agenda, se ainda estiver em aberto, recebe o registro correto.
            'reopenedAppointment' => $treatment->isVoided()
                ? Appointment::query()->where('patient_id', $patient->getKey())->openForTreatment()->whereBetween('scheduled_at', ClinicTime::dayBounds($treatment->performed_at ? ClinicTime::format($treatment->performed_at, 'Y-m-d') : ClinicTime::today()))->orderBy('scheduled_at')->first(['id'])
                : null,
        ]);
    }

    /**
     * Resumo para a janela da lista de atendimentos: o registro, os produtos,
     * a cobrança e as evoluções. Fotos e documentos ficam na página completa.
     * É leitura de prontuário e é auditada como a página completa.
     */
    public function summary(Request $request, Patient $patient, Treatment $treatment): View
    {
        Gate::authorize('view', $treatment);

        $this->audit->log(AuditAction::TreatmentViewed, $treatment, ['patient_id' => $patient->getKey(), 'view' => 'summary']);

        $treatment->load([
            'professional:id,name',
            'procedure:id,name',
            'products.batch.product:id,name,unit',
            'notes.author:id,name',
        ])->loadCount(['photos', 'documents']);

        if ($request->user()->hasPermission(Permission::FinanceView)) {
            $treatment->load('financialEntry.installments');
        }

        return view('treatments._summary', [
            'patient' => $patient->load('anamnesis'),
            'treatment' => $treatment,
        ]);
    }

    /**
     * Anula um registro feito com erro: devolve o estoque, exclui a cobrança e
     * reabre o agendamento. O registro continua no prontuário, marcado.
     */
    public function void(VoidTreatmentRequest $request, Patient $patient, Treatment $treatment, TreatmentVoider $voider): RedirectResponse
    {
        $voider->void($treatment, $request->user(), $request->validated('reason'));

        // O que aconteceu com o agendamento depende de o horário ainda estar livre.
        $appointment = Appointment::query()->where('treatment_id', null)->where('patient_id', $patient->getKey())
            ->whereKey($voider->reopenedAppointmentId())->first();

        $message = 'Atendimento anulado. O estoque foi devolvido e a cobrança foi excluída.';
        $message .= match (true) {
            $appointment === null => '',
            $appointment->isOpen() => ' O agendamento voltou para a agenda: registre o atendimento correto por ele.',
            default => ' O horário do agendamento já estava ocupado, então ele ficou cancelado: crie um novo agendamento se precisar.',
        };

        return redirect()->route('patients.treatments.show', [$patient, $treatment])->with('success', $message);
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
