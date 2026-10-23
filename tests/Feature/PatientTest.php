<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Http\Requests\PatientRequest;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use App\Support\BlindIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PatientTest extends TestCase
{
    use RefreshDatabase;

    private const CPF = '52998224725';

    private const OTHER_CPF = '11144477735';

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'João da Silva',
            'cpf' => '529.982.247-25',
            'birth_date' => '1985-03-20',
            'sex' => 'M',
            'phone' => '(11) 98765-4321',
            'email' => 'joao@example.com',
            'zip_code' => '01310-100',
            'street' => 'Avenida Paulista',
            'number' => '1000',
            'complement' => 'Sala 5',
            'district' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'sp',
            'notes' => 'Alérgico a dipirona.',
            ...$overrides,
        ];
    }

    public function test_guests_cannot_reach_patient_routes(): void
    {
        $patient = Patient::factory()->create();

        $this->get('/pacientes')->assertRedirect('/login');
        $this->get("/pacientes/{$patient->id}")->assertRedirect('/login');
        $this->post('/pacientes', $this->payload())->assertRedirect('/login');
        $this->delete("/pacientes/{$patient->id}")->assertRedirect('/login');

        $this->assertSame(1, Patient::count());
    }

    #[DataProvider('allRoles')]
    public function test_every_role_can_list_create_view_and_update_patients(UserRole $role): void
    {
        $this->actingAs(User::factory()->role($role)->create());

        $this->get('/pacientes')->assertOk();
        $this->get('/pacientes/novo')->assertOk();

        $this->post('/pacientes', $this->payload())->assertSessionHasNoErrors();
        $patient = Patient::firstOrFail();

        $this->get("/pacientes/{$patient->id}")->assertOk()->assertSee('João da Silva')->assertSee('529.982.247-25');
        $this->get("/pacientes/{$patient->id}/editar")->assertOk();

        $this->put("/pacientes/{$patient->id}", $this->payload(['name' => 'João Souza']))
            ->assertRedirect(route('patients.show', $patient))
            ->assertSessionHasNoErrors();

        $this->assertSame('João Souza', $patient->refresh()->name);
    }

    public function test_patient_is_stored_normalized_with_its_creator(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/pacientes', $this->payload())->assertSessionHasNoErrors();

        $patient = Patient::firstOrFail();

        $this->assertSame(self::CPF, $patient->cpf);
        $this->assertSame('11987654321', $patient->phone);
        $this->assertSame('01310100', $patient->zip_code);
        $this->assertSame('SP', $patient->state);
        $this->assertSame('1985-03-20', $patient->birth_date->format('Y-m-d'));
        $this->assertSame($user->id, $patient->created_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::PatientCreated->value,
            'user_id' => $user->id,
            'auditable_id' => $patient->id,
        ]);
    }

    public function test_cpf_and_notes_are_encrypted_at_rest(): void
    {
        $this->actingAs(User::factory()->create())->post('/pacientes', $this->payload());

        $raw = DB::table('patients')->first();

        $this->assertStringNotContainsString(self::CPF, $raw->cpf);
        $this->assertStringNotContainsString('dipirona', $raw->notes);
        $this->assertSame(BlindIndex::for(self::CPF), $raw->cpf_hash);
        $this->assertStringNotContainsString(self::CPF, $raw->cpf_hash);
    }

    public function test_sensitive_fields_are_hidden_from_serialization(): void
    {
        $serialized = Patient::factory()->create(['notes' => 'segredo'])->toArray();

        $this->assertArrayNotHasKey('cpf', $serialized);
        $this->assertArrayNotHasKey('cpf_hash', $serialized);
        $this->assertArrayNotHasKey('notes', $serialized);
    }

    public function test_patient_data_is_validated(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/pacientes', $this->payload([
                'name' => 'Jo',
                'cpf' => '529.982.247-24',
                'birth_date' => now()->addDay()->format('Y-m-d'),
                'sex' => 'X',
                'phone' => '12345',
                'email' => 'nao-e-email',
                'zip_code' => '123',
                'state' => 'XX',
            ]))
            ->assertSessionHasErrors(['name', 'cpf', 'birth_date', 'sex', 'phone', 'email', 'zip_code', 'state']);

        $this->assertSame(0, Patient::count());
    }

    public function test_only_required_fields_are_needed(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/pacientes', ['name' => 'Ana Lima', 'cpf' => self::CPF, 'birth_date' => '2000-01-01'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('patients', ['name' => 'Ana Lima', 'phone' => null, 'notes' => null]);
    }

    public function test_cpf_must_be_unique_even_against_archived_patients(): void
    {
        Patient::factory()->create(['cpf' => self::CPF])->delete();

        $this->actingAs(User::factory()->create())
            ->post('/pacientes', $this->payload())
            ->assertSessionHasErrors(['cpf' => PatientRequest::CPF_HIDDEN_MESSAGE]);

        $this->actingAs(User::factory()->admin()->create())
            ->post('/pacientes', $this->payload())
            ->assertSessionHasErrors(['cpf' => PatientRequest::CPF_ARCHIVED_MESSAGE]);

        $this->assertSame(1, Patient::withTrashed()->count());
    }

    public function test_patient_can_keep_its_own_cpf_but_not_take_another(): void
    {
        $patient = Patient::factory()->create(['cpf' => self::CPF]);
        Patient::factory()->create(['cpf' => self::OTHER_CPF]);

        $this->actingAs(User::factory()->create());

        $this->put("/pacientes/{$patient->id}", $this->payload())->assertSessionHasNoErrors();
        $this->put("/pacientes/{$patient->id}", $this->payload(['cpf' => self::OTHER_CPF]))->assertSessionHasErrors('cpf');

        $this->assertSame(self::CPF, $patient->refresh()->cpf);
    }

    public function test_blind_index_follows_cpf_changes(): void
    {
        $patient = Patient::factory()->create(['cpf' => self::CPF]);

        $this->actingAs(User::factory()->create())
            ->put("/pacientes/{$patient->id}", $this->payload(['cpf' => self::OTHER_CPF]))
            ->assertSessionHasNoErrors();

        $this->assertSame(BlindIndex::for(self::OTHER_CPF), DB::table('patients')->value('cpf_hash'));
    }

    public function test_mass_assignment_cannot_set_protected_columns(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->post('/pacientes', $this->payload([
            'created_by' => $other->id,
            'cpf_hash' => str_repeat('a', 64),
            'deleted_at' => now()->toDateTimeString(),
        ]))->assertSessionHasNoErrors();

        $patient = Patient::firstOrFail();

        $this->assertSame($user->id, $patient->created_by);
        $this->assertSame(BlindIndex::for(self::CPF), $patient->cpf_hash);
        $this->assertNull($patient->deleted_at);
    }

    public function test_listing_masks_the_cpf(): void
    {
        Patient::factory()->create(['name' => 'Carla Mendes', 'cpf' => self::CPF]);

        $this->actingAs(User::factory()->create())->get('/pacientes')
            ->assertOk()
            ->assertSee('Carla Mendes')
            ->assertSee('***.982.247-**')
            ->assertDontSee('529.982.247-25')
            ->assertDontSee(self::CPF);
    }

    public function test_search_by_name_and_by_full_cpf(): void
    {
        Patient::factory()->create(['name' => 'Carla Mendes', 'cpf' => self::CPF]);
        Patient::factory()->create(['name' => 'Bruno Rocha', 'cpf' => self::OTHER_CPF]);

        $this->actingAs(User::factory()->create());

        $this->get('/pacientes?q=carla')->assertSee('Carla Mendes')->assertDontSee('Bruno Rocha');
        $this->get('/pacientes?q=111.444.777-35')->assertSee('Bruno Rocha')->assertDontSee('Carla Mendes');
        $this->get('/pacientes?q=11144477735')->assertSee('Bruno Rocha')->assertDontSee('Carla Mendes');
        $this->get('/pacientes?q=inexistente')->assertSee('Nenhum paciente encontrado');
    }

    public function test_search_treats_like_wildcards_as_literal_text(): void
    {
        Patient::factory()->create(['name' => 'Carla Mendes']);

        $this->actingAs(User::factory()->create());

        $this->get('/pacientes?q=%25')->assertDontSee('Carla Mendes');
        $this->get('/pacientes?q=C_rla')->assertDontSee('Carla Mendes');
        $this->get('/pacientes?q='.str_repeat('a', 101))->assertSessionHasErrors('q');
    }

    public function test_output_is_escaped(): void
    {
        $patient = Patient::factory()->create(['name' => '<script>alert(1)</script>', 'notes' => '<img src=x onerror=alert(1)>']);

        $this->actingAs(User::factory()->create());

        $this->get('/pacientes')->assertDontSee('<script>alert(1)</script>', false);
        $this->get("/pacientes/{$patient->id}")
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertSee('<script>alert(1)</script>');
    }

    public function test_viewing_a_patient_is_audited(): void
    {
        $user = User::factory()->create();
        $patient = Patient::factory()->create();

        $this->actingAs($user)->get("/pacientes/{$patient->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::PatientViewed->value,
            'user_id' => $user->id,
            'auditable_type' => $patient->getMorphClass(),
            'auditable_id' => $patient->id,
        ]);
    }

    public function test_update_audit_records_field_names_but_never_values(): void
    {
        // Adulto: menor de idade exigiria o responsável legal, que não é o assunto aqui.
        $patient = Patient::factory()->create(['cpf' => self::CPF, 'name' => 'Nome Antigo', 'birth_date' => '1985-03-20']);

        $this->actingAs(User::factory()->create())
            ->put("/pacientes/{$patient->id}", [
                ...$patient->only(['birth_date', 'phone', 'zip_code', 'street', 'number', 'district', 'city', 'state']),
                'birth_date' => $patient->birth_date->format('Y-m-d'),
                'sex' => $patient->sex->value,
                'email' => $patient->email,
                'cpf' => self::CPF,
                'name' => 'Nome Novo',
                'notes' => 'Observação sigilosa',
            ])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', AuditAction::PatientUpdated->value)->sole();

        $this->assertEqualsCanonicalizing(['name', 'notes'], $log->metadata['fields']);
        $this->assertStringNotContainsString('Nome Novo', (string) json_encode($log->metadata));
        $this->assertStringNotContainsString('sigilosa', (string) json_encode($log->metadata));
    }

    public function test_saving_without_changes_does_not_create_an_update_audit(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/pacientes', $this->payload());
        $patient = Patient::firstOrFail();

        $this->put("/pacientes/{$patient->id}", $this->payload())->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::PatientUpdated->value]);
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_cannot_archive_restore_or_see_archived_patients(UserRole $role): void
    {
        $patient = Patient::factory()->create();
        $archived = Patient::factory()->create();
        $archived->delete();

        $this->actingAs(User::factory()->role($role)->create());

        $this->delete("/pacientes/{$patient->id}")->assertForbidden();
        $this->patch("/pacientes/{$archived->id}/restaurar")->assertForbidden();
        $this->get('/pacientes?arquivados=1')->assertForbidden();
        $this->get("/pacientes/{$patient->id}")->assertDontSee('Arquivar');

        $this->assertNotSoftDeleted($patient);
        $this->assertSoftDeleted($archived);
    }

    public function test_admin_can_archive_and_restore_a_patient(): void
    {
        $admin = User::factory()->admin()->create();
        $patient = Patient::factory()->create(['name' => 'Paciente Arquivado']);

        $this->actingAs($admin);

        $this->delete("/pacientes/{$patient->id}")->assertRedirect(route('patients.index'));

        // Arquivar não apaga: o registro continua no banco.
        $this->assertSoftDeleted($patient);
        $this->get('/pacientes')->assertDontSee('Paciente Arquivado');
        $this->get("/pacientes/{$patient->id}")->assertNotFound();
        $this->get('/pacientes?arquivados=1')->assertSee('Paciente Arquivado');

        $this->patch("/pacientes/{$patient->id}/restaurar")->assertRedirect(route('patients.show', $patient));

        $this->assertNotSoftDeleted($patient);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::PatientArchived->value, 'auditable_id' => $patient->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::PatientRestored->value, 'auditable_id' => $patient->id]);
    }

    public function test_authenticated_pages_are_not_cacheable(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/pacientes');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_audit_logs_are_immutable(): void
    {
        $this->actingAs(User::factory()->create())->get('/pacientes/'.Patient::factory()->create()->id);

        $log = AuditLog::firstOrFail();

        $this->expectException(\LogicException::class);

        $log->delete();
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function allRoles(): array
    {
        return [
            'administrador' => [UserRole::Admin],
            'profissional' => [UserRole::Professional],
            'recepção' => [UserRole::Receptionist],
        ];
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function nonAdminRoles(): array
    {
        return [
            'profissional' => [UserRole::Professional],
            'recepção' => [UserRole::Receptionist],
        ];
    }
}
