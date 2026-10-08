<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnamnesisTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'allergies' => 'Lidocaína',
            'medications' => 'Anticoncepcional oral',
            'health_conditions' => 'Herpes labial recorrente',
            'previous_procedures' => 'Toxina botulínica em 2025, sem reações',
            'pregnant_or_lactating' => '1',
            'notes' => 'Prefere sessões pela manhã.',
            ...$overrides,
        ];
    }

    public function test_reception_cannot_see_or_change_the_health_record(): void
    {
        $patient = Patient::factory()->create();
        $patient->anamnesis()->create(['allergies' => 'Lidocaína']);

        $this->actingAs(User::factory()->create());

        $this->get("/pacientes/{$patient->id}/ficha-de-saude")->assertForbidden();
        $this->put("/pacientes/{$patient->id}/ficha-de-saude", $this->payload(['allergies' => 'Nenhuma']))->assertForbidden();

        // Na página do paciente, a recepção vê só o cadastro.
        $this->get("/pacientes/{$patient->id}")
            ->assertOk()
            ->assertDontSee('Ficha de saúde')
            ->assertDontSee('Lidocaína')
            ->assertDontSee('Atendimentos');

        $this->assertSame('Lidocaína', $patient->anamnesis()->first()->allergies);
    }

    public function test_guests_are_redirected(): void
    {
        $patient = Patient::factory()->create();

        $this->get("/pacientes/{$patient->id}/ficha-de-saude")->assertRedirect('/login');
        $this->put("/pacientes/{$patient->id}/ficha-de-saude", $this->payload())->assertRedirect('/login');
    }

    public function test_professional_can_fill_the_health_record(): void
    {
        $user = User::factory()->professional()->create();
        $patient = Patient::factory()->create();

        $this->actingAs($user);

        $this->get("/pacientes/{$patient->id}")->assertOk()->assertSee('Ficha ainda não preenchida');
        $this->get("/pacientes/{$patient->id}/ficha-de-saude")->assertOk();

        $this->put("/pacientes/{$patient->id}/ficha-de-saude", $this->payload())
            ->assertRedirect(route('patients.show', $patient))
            ->assertSessionHasNoErrors();

        $anamnesis = $patient->anamnesis()->firstOrFail();

        $this->assertSame('Lidocaína', $anamnesis->allergies);
        $this->assertTrue($anamnesis->pregnant_or_lactating);
        $this->assertSame($user->id, $anamnesis->updated_by);

        // Alergias e gestação aparecem em destaque no prontuário.
        $this->get("/pacientes/{$patient->id}")
            ->assertSee('Atenção')
            ->assertSee('Lidocaína')
            ->assertSee('Gestante ou lactante.');
    }

    public function test_health_data_is_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $patient = Patient::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->put("/pacientes/{$patient->id}/ficha-de-saude", $this->payload());

        $raw = (array) DB::table('patient_anamneses')->first();

        foreach (['allergies' => 'Lidocaína', 'medications' => 'Anticoncepcional', 'health_conditions' => 'Herpes', 'notes' => 'manhã'] as $column => $plain) {
            $this->assertStringNotContainsString($plain, $raw[$column]);
        }

        $this->assertSame(['id', 'patient_id', 'updated_by', 'created_at', 'updated_at'], array_keys($patient->anamnesis()->first()->toArray()));
    }

    public function test_update_audit_records_field_names_but_never_values(): void
    {
        $patient = Patient::factory()->create();

        $this->actingAs(User::factory()->professional()->create());

        $this->put("/pacientes/{$patient->id}/ficha-de-saude", $this->payload());
        $this->put("/pacientes/{$patient->id}/ficha-de-saude", $this->payload(['allergies' => 'Lidocaína e látex']));
        // Salvar sem mudar nada não gera novo registro.
        $this->put("/pacientes/{$patient->id}/ficha-de-saude", $this->payload(['allergies' => 'Lidocaína e látex']));

        $logs = AuditLog::where('action', AuditAction::AnamnesisUpdated->value)->orderBy('id')->get();

        $this->assertCount(2, $logs);
        $this->assertSame(['allergies'], $logs[1]->metadata['fields']);
        $this->assertStringNotContainsString('látex', (string) json_encode($logs->pluck('metadata')));
    }

    public function test_health_record_is_validated(): void
    {
        $patient = Patient::factory()->create();

        $this->actingAs(User::factory()->professional()->create())
            ->put("/pacientes/{$patient->id}/ficha-de-saude", $this->payload(['allergies' => str_repeat('a', 2001)]))
            ->assertSessionHasErrors('allergies');

        $this->assertNull($patient->anamnesis()->first());
    }

    public function test_health_record_of_an_archived_patient_is_unreachable(): void
    {
        $patient = Patient::factory()->create();
        $patient->delete();

        $this->actingAs(User::factory()->admin()->create())
            ->get("/pacientes/{$patient->id}/ficha-de-saude")
            ->assertNotFound();
    }
}
