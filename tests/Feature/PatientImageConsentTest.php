<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientImageConsentTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'João da Silva',
            'cpf' => '529.982.247-25',
            'birth_date' => '1985-03-20',
            ...$overrides,
        ];
    }

    public function test_consent_is_recorded_with_its_date_kept_and_withdrawn(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 13:00', 'America/Sao_Paulo'));
        $this->actingAs(User::factory()->create());

        $this->post('/pacientes', $this->payload(['image_use_consent' => '1']))->assertSessionHasNoErrors();
        $patient = Patient::sole();
        $this->assertSame('2026-10-08 16:00', $patient->image_use_consented_at->format('Y-m-d H:i'));

        $this->get("/pacientes/{$patient->id}")->assertSee('Autorizado')->assertSee('08/10/2026');

        // Salvar de novo com a caixa marcada não muda a data da autorização.
        $this->travel(2)->days();
        $this->put("/pacientes/{$patient->id}", $this->payload(['image_use_consent' => '1', 'name' => 'João Silva']))->assertSessionHasNoErrors();
        $this->assertSame('2026-10-08', $patient->refresh()->image_use_consented_at->format('Y-m-d'));

        // Retirada: a data some e a auditoria registra só o nome do campo.
        $this->put("/pacientes/{$patient->id}", $this->payload(['image_use_consent' => '0', 'name' => 'João Silva']))->assertSessionHasNoErrors();
        $this->assertNull($patient->refresh()->image_use_consented_at);
        $this->assertEquals(['fields' => ['image_use_consented_at']], AuditLog::where('action', AuditAction::PatientUpdated->value)->latest('id')->first()->metadata);

        $this->get("/pacientes/{$patient->id}")->assertSee('Não autorizado');
    }

    public function test_absent_field_keeps_the_consent_and_new_patients_start_without_it(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/pacientes', $this->payload())->assertSessionHasNoErrors();
        $patient = Patient::sole();
        $this->assertNull($patient->image_use_consented_at);

        $patient->forceFill(['image_use_consented_at' => now()])->save();
        $this->put("/pacientes/{$patient->id}", $this->payload(['name' => 'Outro Nome']))->assertSessionHasNoErrors();
        $this->assertNotNull($patient->refresh()->image_use_consented_at);

        $this->post('/pacientes', $this->payload(['cpf' => '111.444.777-35', 'image_use_consent' => 'talvez']))->assertSessionHasErrors('image_use_consent');
    }
}
