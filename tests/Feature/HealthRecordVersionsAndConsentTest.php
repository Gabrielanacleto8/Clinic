<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\ClinicProfile;
use App\Models\Patient;
use App\Models\PatientAnamnesisVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Versões anteriores da ficha de saúde e termo de consentimento para imprimir.
 */
class HealthRecordVersionsAndConsentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function anamnesis(string $allergies): array
    {
        return ['allergies' => $allergies, 'medications' => '', 'health_conditions' => '', 'previous_procedures' => '', 'pregnant_or_lactating' => '0', 'notes' => ''];
    }

    public function test_each_change_keeps_the_previous_content_as_a_version(): void
    {
        $author = User::factory()->professional()->create(['name' => 'Dra. Helena']);
        $patient = Patient::factory()->create();
        $this->actingAs($author);

        // A primeira gravação não tem versão anterior.
        $this->put("/pacientes/{$patient->id}/ficha-de-saude", $this->anamnesis('Lidocaína'))->assertSessionHasNoErrors();
        $this->assertSame(0, PatientAnamnesisVersion::count());

        // Salvar sem mudar é revisão: também não cria versão.
        $this->put("/pacientes/{$patient->id}/ficha-de-saude", $this->anamnesis('Lidocaína'));
        $this->assertSame(0, PatientAnamnesisVersion::count());

        $this->put("/pacientes/{$patient->id}/ficha-de-saude", $this->anamnesis('Lidocaína e látex'));

        $version = PatientAnamnesisVersion::sole();
        $this->assertSame('Lidocaína', $version->field('allergies'));
        $this->assertSame($author->id, $version->recorded_by);
        $this->assertSame('Lidocaína e látex', $patient->anamnesis()->first()->allergies);
        // Gravada criptografada.
        $this->assertStringNotContainsString('Lidocaína', (string) DB::table('patient_anamnesis_versions')->value('content'));

        $this->get("/pacientes/{$patient->id}/ficha-de-saude")->assertOk()->assertSee('Versões anteriores')->assertSee('por Dra. Helena');

        $this->get("/pacientes/{$patient->id}/ficha-de-saude/versoes/{$version->id}")
            ->assertOk()
            ->assertSee('Lidocaína')
            ->assertDontSee('Lidocaína e látex');
        $log = AuditLog::where('action', AuditAction::AnamnesisVersionViewed->value)->sole();
        $this->assertSame($version->id, $log->metadata['version_id']);
    }

    public function test_versions_follow_the_record_access_and_the_patient(): void
    {
        $patient = Patient::factory()->create();
        $other = Patient::factory()->create();
        $professional = User::factory()->professional()->create();
        $this->actingAs($professional);
        $this->put("/pacientes/{$patient->id}/ficha-de-saude", $this->anamnesis('A'));
        $this->put("/pacientes/{$patient->id}/ficha-de-saude", $this->anamnesis('B'));
        $version = PatientAnamnesisVersion::sole();

        // Pelo paciente errado a versão não existe.
        $this->get("/pacientes/{$other->id}/ficha-de-saude/versoes/{$version->id}")->assertNotFound();

        $this->actingAs(User::factory()->create())->get("/pacientes/{$patient->id}/ficha-de-saude/versoes/{$version->id}")->assertForbidden();
    }

    public function test_consent_term_is_filled_with_patient_and_clinic_data(): void
    {
        ClinicProfile::query()->forceCreate(['id' => ClinicProfile::SINGLETON_ID, 'legal_name' => 'Clínica Bella Ltda.']);
        $patient = Patient::factory()->create(['name' => 'Mariana Costa', 'cpf' => '52998224725', 'birth_date' => '1990-05-04']);
        $toxin = $this->procedure('Toxina botulínica');
        $peeling = $this->procedure('Peeling químico', ['consent_template' => 'Eu, {paciente} ({cpf}), autorizo o {procedimento} em {clinica}.']);
        $this->procedure('Antigo', ['is_active' => false]);

        $this->actingAs(User::factory()->professional()->create());

        $this->get("/pacientes/{$patient->id}/termo-de-consentimento")
            ->assertOk()
            ->assertSee('Toxina botulínica')
            ->assertSee('Texto próprio')
            ->assertDontSee('Antigo');

        // Sem texto próprio, o modelo padrão.
        $this->get("/pacientes/{$patient->id}/termo-de-consentimento?procedimento={$toxin->id}")
            ->assertOk()
            ->assertSee('Eu, Mariana Costa, CPF 529.982.247-25, nascido(a) em 04/05/1990')
            ->assertSee('Clínica Bella Ltda.');

        $this->get("/pacientes/{$patient->id}/termo-de-consentimento?procedimento={$peeling->id}")
            ->assertOk()
            ->assertSee('Eu, Mariana Costa (529.982.247-25), autorizo o Peeling químico em Clínica Bella Ltda.');

        $this->assertSame(2, AuditLog::where('action', AuditAction::ConsentTermPrinted->value)->count());
        $this->get("/pacientes/{$patient->id}/termo-de-consentimento?procedimento=999999")->assertSessionHasErrors('procedimento');
    }

    public function test_consent_term_text_is_escaped_and_reception_cannot_print_it(): void
    {
        $patient = Patient::factory()->create();
        $procedure = $this->procedure('Microagulhamento', ['consent_template' => '<script>alert(1)</script> {paciente}']);

        $this->actingAs(User::factory()->professional()->create())
            ->get("/pacientes/{$patient->id}/termo-de-consentimento?procedimento={$procedure->id}")
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);

        $this->actingAs(User::factory()->create())->get("/pacientes/{$patient->id}/termo-de-consentimento?procedimento={$procedure->id}")->assertForbidden();
    }

    public function test_procedure_form_saves_the_consent_template(): void
    {
        $procedure = $this->procedure('Limpeza de pele', ['price' => 180, 'duration_minutes' => 60]);

        $this->actingAs(User::factory()->admin()->create())->put("/procedimentos/{$procedure->id}", [
            'name' => 'Limpeza de pele', 'category' => $procedure->category->value, 'price' => '180,00', 'duration_minutes' => 60, 'is_active' => '1',
            'consent_template' => 'Termo da limpeza para {paciente}.',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Termo da limpeza para {paciente}.', $procedure->fresh()->consent_template);
    }
}
