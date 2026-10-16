<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TreatmentListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
    }

    private function treatmentFor(string $patientName, string $localDateTime, string $procedure = 'Toxina botulínica', array $attributes = []): Treatment
    {
        return Treatment::factory()->create([
            'patient_id' => Patient::factory()->create(['name' => $patientName])->id,
            'procedure_id' => $this->procedure($procedure)->id,
            'performed_at' => CarbonImmutable::parse($localDateTime, 'America/Sao_Paulo')->utc(),
            ...$attributes,
        ]);
    }

    public function test_access_follows_the_clinical_records_permission(): void
    {
        $this->treatmentFor('Paciente Reservado', '2026-10-08 10:00');

        $this->get('/atendimentos')->assertRedirect('/login');

        // A lista mostra quem fez qual procedimento: a recepção não a vê.
        $this->actingAs(User::factory()->create());
        $this->get('/atendimentos')->assertForbidden()->assertDontSee('Paciente Reservado');
        $this->get('/')->assertOk()->assertDontSee(route('treatments.index'), false);

        $this->actingAs(User::factory()->professional()->create())->get('/atendimentos')->assertOk()->assertSee('Paciente Reservado');
        $this->actingAs(User::factory()->admin()->create())->get('/atendimentos')->assertOk()->assertSee('Paciente Reservado');
    }

    public function test_lists_registered_treatments_from_the_most_recent(): void
    {
        $professional = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $old = $this->treatmentFor('Ana Antiga', '2026-09-30 09:15', 'Preenchimento labial');
        $recent = $this->treatmentFor('Bia Recente', '2026-10-08 11:40', attributes: ['professional_id' => $professional->id, 'treated_areas' => 'Glabela e frontal']);

        $response = $this->actingAs($professional)->get('/atendimentos');

        $response->assertOk()
            ->assertSee('2 registros')
            ->assertSeeInOrder(['Bia Recente', 'Ana Antiga'])
            ->assertSee('08/10/2026 11:40')
            ->assertSee('30/09/2026 09:15')
            ->assertSee('Toxina botulínica')
            ->assertSee('Glabela e frontal')
            ->assertSee('Preenchimento labial')
            ->assertSee('Dra. Helena')
            ->assertSee(route('patients.treatments.show', [$recent->patient_id, $recent]), false)
            ->assertSee(route('patients.show', $old->patient_id), false)
            // Nada de fila: não há quem espera nem atendimento em andamento.
            ->assertDontSee('Em espera')
            ->assertDontSee('Em atendimento')
            ->assertDontSee('Em andamento');
    }

    public function test_empty_list_says_so(): void
    {
        $this->actingAs(User::factory()->professional()->create());

        $this->get('/atendimentos')->assertOk()->assertSee('0 registros')->assertSee('Nenhum atendimento registrado ainda.');
        $this->get('/atendimentos?q=ninguem')->assertOk()->assertSee('Nenhum atendimento encontrado com estes filtros.');
    }

    public function test_filters_by_patient_procedure_and_period(): void
    {
        $this->treatmentFor('Carla Mendes', '2026-10-01 23:30', 'Toxina botulínica');
        $this->treatmentFor('Carla Mendes Filha', '2026-10-05 10:00', 'Preenchimento labial');
        $this->treatmentFor('Dora Lima', '2026-10-08 09:00', 'Preenchimento labial');
        $filler = $this->procedure('Preenchimento labial')->id;

        $this->actingAs(User::factory()->professional()->create());

        $this->get('/atendimentos?q=carla')->assertSee('2 registros')->assertSee('Carla Mendes Filha')->assertDontSee('Dora Lima');
        $this->get("/atendimentos?procedimento={$filler}")->assertSee('2 registros')->assertSee('Dora Lima')->assertDontSee('01/10/2026');
        // O dia é o do calendário da clínica: 23h30 do dia 1º ainda é dia 1º.
        $this->get('/atendimentos?de=2026-10-01&ate=2026-10-01')->assertSee('1 registro')->assertSee('01/10/2026 23:30');
        $this->get('/atendimentos?de=2026-10-02')->assertSee('2 registros')->assertDontSee('01/10/2026 23:30');
        $this->get("/atendimentos?q=carla&procedimento={$filler}&ate=2026-10-07")->assertSee('1 registro')->assertSee('Carla Mendes Filha');

        // Curingas do LIKE são tratados como texto.
        $this->get('/atendimentos?q=%25')->assertSee('0 registros');

        $this->get('/atendimentos?de=ontem')->assertSessionHasErrors('de');
        $this->get('/atendimentos?procedimento=abc')->assertSessionHasErrors('procedimento');
    }

    public function test_listing_is_audited_without_what_was_searched(): void
    {
        $user = User::factory()->professional()->create();

        $this->actingAs($user)->get('/atendimentos?q=Marina+Sigilosa&de=2026-10-01')->assertOk();

        $log = AuditLog::where('action', AuditAction::TreatmentsListed->value)->sole();

        $this->assertSame($user->id, $log->user_id);
        $this->assertEqualsCanonicalizing(['q', 'de'], $log->metadata['filters']);
        $this->assertStringNotContainsString('Sigilosa', (string) json_encode($log->metadata));
    }

    public function test_archived_patients_and_discarded_records_are_left_out(): void
    {
        $this->treatmentFor('Paciente Arquivado', '2026-10-08 10:00')->patient->delete();
        // Rascunho descartado antes de o registro passar a ser em um passo só.
        $this->treatmentFor('Registro Descartado', '2026-10-08 11:00')->delete();
        $this->treatmentFor('Paciente Ativo', '2026-10-08 12:00');

        $this->actingAs(User::factory()->admin()->create())->get('/atendimentos')
            ->assertOk()->assertSee('1 registro')->assertSee('Paciente Ativo')->assertDontSee('Paciente Arquivado')->assertDontSee('Registro Descartado');
    }

    public function test_list_is_paginated_keeping_the_filters(): void
    {
        $patient = Patient::factory()->create(['name' => 'Paciente Frequente']);
        Treatment::factory()->count(21)->create(['patient_id' => $patient->id, 'procedure_id' => $this->procedure('Toxina botulínica')->id]);

        $this->actingAs(User::factory()->professional()->create())->get('/atendimentos?q=frequente')
            ->assertOk()
            ->assertSee('21 registros')
            ->assertSee('q=frequente&amp;page=2', false);
    }

    public function test_sidebar_lists_the_module_right_below_the_agenda(): void
    {
        $html = $this->actingAs(User::factory()->professional()->create())->get('/atendimentos')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<aside id="sidebar".*?<\/aside>/s', $html, $sidebar));

        $link = '<a href="'.preg_quote(route('treatments.index'), '/').'"';
        $agenda = '<a href="'.preg_quote(route('agenda.index'), '/').'"';

        $this->assertMatchesRegularExpression("/{$link}[^>]*aria-current=\"page\"/", $sidebar[0]);
        $this->assertDoesNotMatchRegularExpression("/{$agenda}[^>]*aria-current=\"page\"/", $sidebar[0]);
        // Entre a Agenda e o item de Atendimentos não há nenhum outro módulo.
        $this->assertMatchesRegularExpression("/{$agenda}(?:(?!<a ).)*{$link}/s", $sidebar[0]);
    }

    public function test_list_runs_a_fixed_number_of_queries(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/atendimentos')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->actingAs(User::factory()->professional()->create());
        $this->treatmentFor('Paciente 1', '2026-10-08 10:01', 'Procedimento 1');

        // A primeira requisição do processo faz uma consulta a mais, que não se repete.
        $count();
        $withOne = $count();

        foreach (range(2, 9) as $index) {
            $this->treatmentFor("Paciente {$index}", "2026-10-08 10:0{$index}", "Procedimento {$index}");
        }

        // Mais atendimentos, pacientes, profissionais e procedimentos não mudam o número de consultas.
        $this->assertSame($withOne, $count());
    }

    public function test_output_is_escaped(): void
    {
        $this->treatmentFor('<script>alert(1)</script>', '2026-10-08 12:40', '<b>negrito</b>', ['treated_areas' => '<img src=x onerror=alert(2)>']);

        $this->actingAs(User::factory()->admin()->create())->get('/atendimentos')
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<b>negrito</b>', false)
            ->assertDontSee('<img src=x', false);
    }
}
