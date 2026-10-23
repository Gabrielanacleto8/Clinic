<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\AttachmentType;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Http\Requests\PatientRequest;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\FinancialEntry;
use App\Models\Patient;
use App\Models\PatientAttachment;
use App\Models\Treatment;
use App\Models\User;
use App\Services\RolePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O dia a dia na ficha do paciente: avisos de saúde onde o profissional
 * olha, agenda e débito à vista, busca por telefone, CPF repetido com saída,
 * fotos comparadas e o responsável legal de quem é menor.
 */
class PatientCareFlowsTest extends TestCase
{
    use RefreshDatabase;

    private Patient $patient;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));

        $this->patient = Patient::factory()->create(['name' => 'Marina Albuquerque', 'birth_date' => '1990-05-20', 'phone' => '11987654321']);
        $this->url = "/pacientes/{$this->patient->id}";
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'João da Silva',
            'cpf' => '390.533.447-05',
            'birth_date' => '1985-03-20',
            ...$overrides,
        ];
    }

    private function photo(?Treatment $treatment, array $attributes = []): PatientAttachment
    {
        $attachment = new PatientAttachment;
        $attachment->forceFill([
            'patient_id' => $treatment?->patient_id ?? $this->patient->id, 'treatment_id' => $treatment?->id, 'taken_on' => $treatment ? null : '2026-01-15',
            'type' => AttachmentType::Photo, 'stage' => 'before', 'caption' => 'Frontal',
            'path' => 'x/'.uniqid().'.enc', 'thumbnail_path' => 'x/'.uniqid().'.enc', 'width' => 100, 'height' => 100, 'size' => 10,
            ...$attributes,
        ])->save();

        return $attachment;
    }

    // --- Avisos de saúde -------------------------------------------------

    public function test_health_alerts_include_medications_and_conditions_and_sit_at_the_top_of_the_record(): void
    {
        $this->patient->anamnesis()->create(['medications' => 'Isotretinoína', 'health_conditions' => 'Tendência a queloide']);

        $page = $this->actingAs(User::factory()->professional()->create())->get($this->url)->assertOk()
            ->assertSee('Medicamentos em uso:')->assertSee('Isotretinoína')->assertSee('Condições de saúde:')->assertSee('Tendência a queloide');

        // O aviso vem antes dos cartões do cadastro.
        $this->assertLessThan(strpos($page->getContent(), 'Identificação</h2>'), strpos($page->getContent(), 'data-health-alerts-static'));

        $this->getJson("{$this->url}/alertas-de-saude")->assertOk()->assertJson([
            'missing' => false,
            'alerts' => [['label' => 'Medicamentos em uso', 'text' => 'Isotretinoína'], ['label' => 'Condições de saúde', 'text' => 'Tendência a queloide']],
        ]);
    }

    public function test_a_missing_or_old_health_form_is_itself_an_alert(): void
    {
        $this->actingAs(User::factory()->professional()->create());

        $this->get($this->url)->assertOk()->assertSee('Ficha de saúde não preenchida');
        $this->getJson("{$this->url}/alertas-de-saude")->assertOk()->assertJson(['missing' => true]);

        // A recepção não vê o prontuário: nenhum aviso de saúde para ela.
        $this->actingAs(User::factory()->create())->get($this->url)->assertOk()->assertDontSee('Ficha de saúde não preenchida');

        $this->patient->anamnesis()->create(['allergies' => 'Lidocaína']);
        $this->travel(13)->months();

        $this->actingAs(User::factory()->professional()->create());
        $this->get($this->url)->assertOk()->assertSee('sem revisão desde 08/10/2026')->assertSee('Lidocaína');
        $this->getJson("{$this->url}/alertas-de-saude")->assertOk()->assertJson(['stale_since' => '08/10/2026']);
    }

    public function test_saving_the_health_form_unchanged_records_a_review(): void
    {
        $author = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $this->patient->anamnesis()->create(['allergies' => 'Lidocaína']);
        $this->travel(13)->months();

        $this->actingAs($author)
            ->put("{$this->url}/ficha-de-saude", ['allergies' => 'Lidocaína', 'pregnant_or_lactating' => '0'])
            ->assertRedirect($this->url)
            ->assertSessionHas('success', 'Ficha de saúde revisada: nada mudou.');

        $anamnesis = $this->patient->anamnesis()->sole();
        $this->assertSame($author->id, $anamnesis->updated_by);
        $this->assertFalse($anamnesis->isStale());
        $this->assertSame(1, AuditLog::where('action', AuditAction::AnamnesisReviewed->value)->count());
        $this->assertSame(0, AuditLog::where('action', AuditAction::AnamnesisUpdated->value)->count());

        $this->get($this->url)->assertSee('Revisada em')->assertSee('por Dra. Helena');
    }

    // --- Agenda na ficha ---------------------------------------------------

    public function test_the_record_shows_upcoming_appointments_no_shows_and_the_pending_return(): void
    {
        $professional = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $make = fn (string $when, AppointmentStatus $status) => Appointment::factory()->at($when)->status($status)->create(['patient_id' => $this->patient->id, 'professional_id' => $professional->id]);

        $make('2026-09-01 10:00', AppointmentStatus::NoShow);
        $make('2026-09-15 10:00', AppointmentStatus::NoShow);
        $make('2026-10-01 10:00', AppointmentStatus::Completed);
        $make('2026-10-09 10:00', AppointmentStatus::Scheduled);
        $make('2026-10-10 10:00', AppointmentStatus::Confirmed);
        $make('2026-10-11 10:00', AppointmentStatus::Scheduled);
        $make('2026-10-12 10:00', AppointmentStatus::Scheduled);

        // O cartão mostra só os 3 próximos; a aba, todos. O profissional abre na aba de atendimentos.
        $this->actingAs(User::factory()->professional()->create())->get($this->url)->assertOk()
            ->assertSee('2 faltas')
            ->assertSee('09/10/2026 10:00')->assertSee('10/10/2026 10:00')->assertSee('11/10/2026 10:00')
            ->assertDontSee('12/10/2026 10:00')
            ->assertSee('aba=agendamentos', false);

        $this->actingAs(User::factory()->create())->get("{$this->url}?aba=agendamentos")->assertOk()->assertViewHas('tab', 'agendamentos')
            ->assertSee('Faltou')->assertSee('Atendido')->assertSee('12/10/2026 10:00');
    }

    public function test_without_future_appointments_the_last_return_date_offers_scheduling(): void
    {
        Treatment::factory()->create(['patient_id' => $this->patient->id, 'performed_at' => '2026-10-01 15:00:00', 'return_date' => '2026-10-20']);
        // Anulado não conta.
        Treatment::factory()->create(['patient_id' => $this->patient->id, 'performed_at' => '2026-10-05 15:00:00', 'return_date' => '2026-11-30', 'voided_at' => now(), 'voided_by' => User::factory()->admin()->create()->id, 'void_reason' => 'Erro']);

        $this->actingAs(User::factory()->professional()->create())->get($this->url)->assertOk()
            ->assertSee('Retorno previsto em 20/10/2026')
            ->assertSee(e(route('appointments.create', ['paciente' => $this->patient->id, 'data' => '2026-10-20'])), false);

        // A recepção vê a agenda, mas não o retorno, que vem do prontuário.
        $this->actingAs(User::factory()->create())->get($this->url)->assertOk()->assertDontSee('Retorno previsto');
    }

    public function test_the_agenda_card_runs_a_fixed_number_of_queries(): void
    {
        $professional = User::factory()->professional()->create();

        foreach (range(1, 6) as $day) {
            Appointment::factory()->at("2026-10-1{$day} 10:00")->create(['patient_id' => $this->patient->id, 'professional_id' => $professional->id]);
        }

        $this->actingAs(User::factory()->create());
        $this->get($this->url)->assertOk();
        DB::enableQueryLog();
        $this->get($this->url)->assertOk();
        $one = count(DB::getQueryLog());

        foreach (range(1, 5) as $day) {
            Appointment::factory()->at("2026-10-2{$day} 10:00")->create(['patient_id' => $this->patient->id, 'professional_id' => $professional->id]);
        }

        DB::flushQueryLog();
        $this->get($this->url)->assertOk();

        $this->assertSame($one, count(DB::getQueryLog()));
    }

    // --- Busca por telefone --------------------------------------------------

    public function test_patients_are_found_by_phone_and_eleven_digits_try_cpf_and_phone(): void
    {
        Patient::factory()->create(['name' => 'Bruno Rocha', 'cpf' => '11144477735', 'phone' => '21999990000']);

        $this->actingAs(User::factory()->create());

        $this->get('/pacientes?q=98765-4321')->assertOk()->assertSee('Marina Albuquerque')->assertDontSee('Bruno Rocha');
        $this->get('/pacientes?q=(11) 98765-4321')->assertOk()->assertSee('Marina Albuquerque');
        // Onze dígitos: o CPF do Bruno, e o celular da Marina.
        $this->get('/pacientes?q=111.444.777-35')->assertOk()->assertSee('Bruno Rocha')->assertDontSee('Marina Albuquerque');
        $this->get('/pacientes?q=11987654321')->assertOk()->assertSee('Marina Albuquerque')->assertDontSee('Bruno Rocha');
        // Poucos dígitos continuam sendo nome.
        $this->get('/pacientes?q=1198')->assertOk()->assertDontSee('Marina Albuquerque');

        $this->getJson('/pacientes/buscar?q=21999990000')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Bruno Rocha')->assertJsonPath('0.phone', '(21) 99999-0000');
    }

    public function test_the_picker_search_tells_when_the_list_was_cut(): void
    {
        foreach (range(1, 9) as $index) {
            Patient::factory()->create(['name' => "Paciente {$index}"]);
        }

        $this->actingAs(User::factory()->create());

        $this->getJson('/pacientes/buscar?q=Paciente')->assertOk()->assertJsonCount(8)->assertHeader('X-Has-More', '1');
        $this->getJson('/pacientes/buscar?q=Paciente 1')->assertOk()->assertHeader('X-Has-More', '0');
    }

    // --- CPF já cadastrado -----------------------------------------------------

    public function test_an_existing_cpf_leads_to_the_existing_record(): void
    {
        $existing = Patient::factory()->create(['cpf' => '39053344705', 'name' => 'João Existente']);

        $response = $this->actingAs(User::factory()->create())->from('/pacientes/novo')->post('/pacientes', $this->payload())
            ->assertRedirect('/pacientes/novo')
            ->assertSessionHasErrors(['cpf' => 'Já existe um paciente cadastrado com este CPF.'])
            ->assertSessionHas('cpf_owner', ['id' => $existing->id, 'archived' => false]);

        $this->get('/pacientes/novo')->assertOk()->assertSee('Abrir o cadastro existente')->assertSee(route('patients.show', $existing));

        $log = AuditLog::where('action', AuditAction::PatientLookup->value)->sole();
        $this->assertSame([$existing->id, ['archived' => false]], [$log->auditable_id, $log->metadata]);
        $this->assertStringNotContainsString('39053344705', (string) json_encode($log));
    }

    public function test_an_archived_cpf_offers_restoring_to_who_can_and_guidance_to_who_cannot(): void
    {
        $archived = Patient::factory()->create(['cpf' => '39053344705']);
        $archived->delete();

        // Quem não vê arquivados não fica sabendo que o cadastro está arquivado, mas recebe a saída.
        $this->actingAs(User::factory()->create())->from('/pacientes/novo')->post('/pacientes', $this->payload())
            ->assertSessionHasErrors(['cpf' => PatientRequest::CPF_HIDDEN_MESSAGE]);
        $this->from('/pacientes/novo')->followingRedirects()->post('/pacientes', $this->payload())
            ->assertOk()->assertSee('peça ao administrador')->assertDontSee('cadastro arquivado')->assertDontSee('Restaurar o cadastro');

        $this->actingAs(User::factory()->admin()->create())->from('/pacientes/novo')->post('/pacientes', $this->payload())
            ->assertSessionHasErrors('cpf');
        $this->get('/pacientes/novo')->assertOk()->assertSee('Restaurar o cadastro')->assertSee(route('patients.restore', $archived));

        $this->assertSame(3, AuditLog::where('action', AuditAction::PatientLookup->value)->where('metadata->archived', true)->count());
    }

    // --- Fotos -----------------------------------------------------------------

    public function test_two_photos_of_the_patient_are_compared_side_by_side_and_audited(): void
    {
        $treatment = Treatment::factory()->create(['patient_id' => $this->patient->id, 'performed_at' => '2026-10-01 15:00:00']);
        $before = $this->photo(null, ['caption' => 'Antes avulsa']);
        $after = $this->photo($treatment, ['stage' => 'after', 'caption' => 'Depois']);
        $foreign = $this->photo(Treatment::factory()->create(['performed_at' => '2026-10-01 15:00:00']));
        $url = "{$this->url}/fotos/comparar";

        $this->get("{$url}?fotos[]={$before->id}&fotos[]={$after->id}")->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get("{$url}?fotos[]={$before->id}&fotos[]={$after->id}")->assertForbidden();

        $this->actingAs(User::factory()->professional()->create());
        $this->get("{$url}?fotos[]={$before->id}&fotos[]={$after->id}")->assertOk()
            ->assertSeeInOrder(['Antes avulsa', 'Depois'])->assertSee('Foto avulsa')->assertSee($treatment->procedure->name);
        $this->assertSame(2, AuditLog::where('action', AuditAction::PhotoViewed->value)->where('metadata->view', 'compare')->count());

        // Foto de outro paciente não entra; uma só não compara.
        $this->get("{$url}?fotos[]={$before->id}&fotos[]={$foreign->id}")->assertNotFound();
        $this->get("{$url}?fotos[]={$before->id}")->assertSessionHasErrors('fotos');
        $this->get("{$url}?fotos[]={$before->id}&fotos[]={$before->id}")->assertSessionHasErrors('fotos.0');
    }

    public function test_the_gallery_marks_voided_treatments_and_requires_a_stage_for_loose_photos(): void
    {
        $voided = Treatment::factory()->create(['patient_id' => $this->patient->id, 'performed_at' => '2026-10-01 15:00:00', 'voided_at' => now(), 'voided_by' => User::factory()->admin()->create()->id, 'void_reason' => 'Paciente errado']);
        $this->photo($voided, ['caption' => 'Da anulada']);

        $this->actingAs(User::factory()->professional()->create());
        $this->get("{$this->url}?aba=fotos")->assertOk()->assertSee('Da anulada')->assertSee('Anulado')
            ->assertSee('data-compare-form', false)->assertSee('<option value="">Selecione</option>', false);

        $this->post("{$this->url}/fotos", ['photos' => [], 'taken_on' => '2026-01-15'])->assertSessionHasErrors(['stage', 'photos']);
    }

    // --- Débito, responsável legal e contato -----------------------------------

    public function test_the_header_shows_the_pending_balance_only_to_who_sees_finance(): void
    {
        FinancialEntry::factory()->withInstallment('100', '2026-09-01')->withInstallment('50', '2026-11-01', number: 2)->create(['patient_id' => $this->patient->id]);

        $this->actingAs(User::factory()->admin()->create());
        $this->get($this->url)->assertOk()->assertSee('Pendente R$ 150,00')->assertSee('vencido R$ 100,00');
        $this->get("{$this->url}/editar")->assertOk()->assertSee('Há R$ 150,00 pendentes no financeiro.')->assertSee('Arquivar paciente');

        // A recepção recebe de paciente: vê o débito. Sem essa permissão, nada.
        $this->actingAs(User::factory()->create())->get($this->url)->assertOk()->assertSee('Pendente R$ 150,00');
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsView]);
        $this->actingAs(User::factory()->create())->get($this->url)->assertOk()->assertDontSee('Pendente R$');
    }

    public function test_a_minor_needs_a_legal_guardian_whose_cpf_is_encrypted(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/pacientes', $this->payload(['birth_date' => '2015-03-20']))
            ->assertSessionHasErrors(['guardian_name', 'guardian_relationship', 'guardian_phone']);

        $this->post('/pacientes', $this->payload([
            'birth_date' => '2015-03-20',
            'guardian_name' => 'Maria da Silva', 'guardian_relationship' => 'Mãe', 'guardian_cpf' => '111.444.777-35', 'guardian_phone' => '(11) 91234-5678',
        ]))->assertSessionHasNoErrors();

        $minor = Patient::where('name', 'João da Silva')->sole();
        $this->assertSame(['Maria da Silva', 'Mãe', '11144477735', '11912345678'], [$minor->guardian_name, $minor->guardian_relationship, $minor->guardian_cpf, $minor->guardian_phone]);
        $this->assertNotSame('11144477735', DB::table('patients')->where('id', $minor->id)->value('guardian_cpf'));
        $this->assertStringNotContainsString('11144477735', (string) json_encode($minor));

        $this->get("/pacientes/{$minor->id}")->assertOk()->assertSee('Menor de idade')->assertSee('Maria da Silva (Mãe)')->assertSee('111.444.777-35');
        $this->getJson('/pacientes/buscar?q=João')->assertOk()->assertJsonPath('0.minor', true);
        $this->get("/pacientes/{$minor->id}/editar")->assertOk()->assertSee('O responsável legal autoriza');

        // Adulto não precisa de responsável.
        $this->post('/pacientes', $this->payload(['cpf' => '529.982.247-25', 'name' => 'Adulto']))->assertSessionHasNoErrors();
    }

    public function test_the_record_and_the_printout_show_the_guardian_or_warn_when_a_minor_has_none(): void
    {
        $minor = Patient::factory()->minor()->create(['guardian_name' => 'Responsável Fictícia', 'guardian_relationship' => 'Mãe']);
        $withoutGuardian = Patient::factory()->minor()->create(['guardian_name' => null, 'guardian_relationship' => null, 'guardian_phone' => null]);

        $this->actingAs(User::factory()->admin()->create());

        $this->get("/pacientes/{$minor->id}")->assertOk()->assertSee('Menor de idade')->assertSee('Responsável Fictícia (Mãe)')
            ->assertDontSee('sem responsável legal cadastrado');
        $this->get("/pacientes/{$minor->id}/prontuario")->assertOk()->assertSee('Responsável legal: Responsável Fictícia (Mãe)');

        $this->get("/pacientes/{$withoutGuardian->id}")->assertOk()->assertSee('Paciente menor de idade sem responsável legal cadastrado.');
        $this->get("/pacientes/{$withoutGuardian->id}/prontuario")->assertOk()->assertSee('Menor de idade sem responsável legal cadastrado.');

        // Adulto: nem selo nem seção de responsável.
        $this->get($this->url)->assertOk()->assertDontSee('Menor de idade')->assertDontSee('Responsável legal');
        $this->get("{$this->url}/prontuario")->assertOk()->assertDontSee('Responsável legal')->assertDontSee('sem responsável legal');
    }

    public function test_contact_links_and_archived_list(): void
    {
        $this->patient->update(['email' => 'marina@example.com']);

        $this->actingAs(User::factory()->admin()->create())->get($this->url)->assertOk()
            ->assertSee('href="tel:+5511987654321"', false)->assertSee('https://wa.me/5511987654321')->assertSee('mailto:marina@example.com')
            ->assertSee('Imprimir prontuário')->assertSee('Ver acessos');

        $this->delete($this->url)->assertRedirect('/pacientes');
        $this->get('/pacientes?arquivados=1')->assertOk()->assertSee('Arquivado em 08/10/2026')->assertSee('(11) 98765-4321')->assertSee('Ativos');
    }

    public function test_the_printed_record_lists_photos_documents_and_the_image_consent(): void
    {
        $treatment = Treatment::factory()->create(['patient_id' => $this->patient->id, 'performed_at' => '2026-10-01 15:00:00']);
        $this->photo(null, ['caption' => 'Acervo antigo']);
        $this->photo($treatment, ['type' => AttachmentType::Document, 'stage' => null, 'caption' => 'Termo assinado', 'thumbnail_path' => null, 'width' => null, 'height' => null]);

        $this->actingAs(User::factory()->professional()->create())->get("{$this->url}/prontuario")->assertOk()
            ->assertSee('Fotos guardadas (1)')->assertSee('Acervo antigo')
            ->assertSee('Documentos guardados (1)')->assertSee('Termo assinado')
            ->assertSee('Uso de imagem em divulgação:')->assertSee('não autorizado');
    }

    public function test_cep_lookup_fills_the_address_from_the_server_and_is_cached(): void
    {
        Http::fake(['viacep.com.br/ws/01310100/json/' => Http::response(['logradouro' => 'Avenida Paulista', 'bairro' => 'Bela Vista', 'localidade' => 'São Paulo', 'uf' => 'sp'])]);

        $this->getJson('/cep/01310100')->assertUnauthorized();

        $this->actingAs(User::factory()->create());
        $this->getJson('/cep/01310100')->assertOk()->assertExactJson(['street' => 'Avenida Paulista', 'district' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP']);
        $this->getJson('/cep/01310100')->assertOk();
        Http::assertSentCount(1);

        Http::fake(['viacep.com.br/ws/99999999/json/' => Http::response(['erro' => true])]);
        $this->getJson('/cep/99999999')->assertNotFound();
        $this->getJson('/cep/abc')->assertNotFound();

        $this->get('/pacientes/novo')->assertOk()->assertSee('data-cep-lookup', false);
    }

    public function test_a_cep_service_failure_is_not_cached_and_lookup_requires_patient_management(): void
    {
        Http::fake(['viacep.com.br/ws/04538133/json/' => Http::sequence()->push('', 502)->push(['logradouro' => 'Avenida Faria Lima', 'bairro' => 'Itaim Bibi', 'localidade' => 'São Paulo', 'uf' => 'SP'])]);

        $this->actingAs(User::factory()->create());
        // A queda do serviço não vira "não encontrado" em cache: a próxima consulta tenta de novo.
        $this->getJson('/cep/04538133')->assertStatus(503);
        $this->getJson('/cep/04538133')->assertOk()->assertJson(['street' => 'Avenida Faria Lima']);

        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsView]);
        $this->actingAs(User::factory()->create())->getJson('/cep/04538133')->assertForbidden();
    }

    public function test_reopening_the_same_tab_shortly_after_does_not_repeat_the_view_audit(): void
    {
        $this->actingAs(User::factory()->professional()->create());

        $this->get($this->url)->assertOk();
        $this->get($this->url)->assertOk();
        $this->get("{$this->url}?aba=fotos")->assertOk();

        $logs = AuditLog::where('action', AuditAction::PatientViewed->value)->orderBy('id')->get();
        $this->assertSame([['tab' => 'atendimentos'], ['tab' => 'fotos']], $logs->pluck('metadata')->all());

        $this->travel(6)->minutes();
        $this->get($this->url)->assertOk();
        $this->assertSame(3, AuditLog::where('action', AuditAction::PatientViewed->value)->count());
    }

    public function test_reception_without_the_archive_permission_cannot_restore_from_the_cpf_notice(): void
    {
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsView, Permission::PatientsManage]);
        $archived = Patient::factory()->create(['cpf' => '39053344705']);
        $archived->delete();

        $this->actingAs(User::factory()->create())->patch("/pacientes/{$archived->id}/restaurar")->assertForbidden();
    }
}
