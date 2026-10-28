<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\ClinicProfile;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dados da clínica nos documentos, logo, aplicativo instalável e registro
 * profissional de quem atende.
 */
class ClinicIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CNPJ = '11.222.333/0001-81';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('patient_photos');
    }

    /**
     * @return array<string, string>
     */
    private function profile(array $overrides = []): array
    {
        return [
            'legal_name' => 'Clínica Bella Estética Ltda.',
            'cnpj' => self::VALID_CNPJ,
            'address' => 'Rua das Flores, 100 · Centro · São Paulo/SP · CEP 01000-000',
            'phone' => '(11) 3333-4444',
            'email' => 'contato@bella.test',
            'technical_lead' => 'Dra. Ana Lima · CRBM-1 12345',
            ...$overrides,
        ];
    }

    private function logoUpload(): UploadedFile
    {
        $image = imagecreatetruecolor(300, 120);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 15, 118, 110));
        ob_start();
        imagepng($image);

        return $this->realUpload('logo.png', (string) ob_get_clean());
    }

    public function test_only_the_admin_manages_the_clinic(): void
    {
        foreach ([User::factory()->create(), User::factory()->professional()->create()] as $user) {
            $this->actingAs($user);

            $this->get('/configuracoes')->assertForbidden();
            $this->put('/configuracoes', $this->profile())->assertForbidden();
            $this->post('/configuracoes/logo', ['logo' => $this->logoUpload()])->assertForbidden();
            $this->delete('/configuracoes/logo')->assertForbidden();
        }

        $this->assertNull(ClinicProfile::query()->first());
        $this->get('/pacientes')->assertDontSee('href="'.route('clinic.edit').'"', false);
    }

    public function test_admin_saves_the_clinic_data_with_audit_of_field_names(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->get('/configuracoes')->assertOk()->assertSee('Razão social');
        $this->put('/configuracoes', $this->profile())->assertRedirect('/configuracoes')->assertSessionHasNoErrors();

        $profile = ClinicProfile::current();
        $this->assertSame(ClinicProfile::SINGLETON_ID, $profile->id);
        $this->assertSame('11222333000181', $profile->cnpj);
        $this->assertSame('1133334444', $profile->phone);

        $log = AuditLog::where('action', AuditAction::ClinicProfileUpdated->value)->sole();
        $this->assertContains('cnpj', $log->metadata['fields']);
        $this->assertStringNotContainsString('Bella', json_encode($log->metadata));

        // Salvar de novo sem mudança não grava nem audita.
        $this->put('/configuracoes', $this->profile())->assertRedirect('/configuracoes');
        $this->assertSame(1, AuditLog::where('action', AuditAction::ClinicProfileUpdated->value)->count());
    }

    public function test_invalid_cnpj_and_phone_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->put('/configuracoes', $this->profile(['cnpj' => '11.111.111/1111-11', 'phone' => '123']))
            ->assertSessionHasErrors(['cnpj', 'phone']);

        $this->assertNull(ClinicProfile::query()->first());
    }

    public function test_logo_is_reencoded_with_icons_and_served_publicly(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/configuracoes/logo', ['logo' => $this->logoUpload()])->assertRedirect('/configuracoes')->assertSessionHasNoErrors();

        $disk = Storage::disk('patient_photos');
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($disk->get('clinica/logo.jpg'))[2]);

        foreach ([180, 192, 512] as $size) {
            $info = getimagesizefromstring($disk->get("clinica/icone-{$size}.png"));
            $this->assertSame([$size, $size, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
        }

        $this->assertSame(1, AuditLog::where('action', AuditAction::ClinicLogoUpdated->value)->count());

        // A marca aparece no menu e no login, e é pública (sem dado de paciente).
        $this->get('/pacientes')->assertSee(route('clinic.logo').'?v=', false);
        auth()->logout();
        $this->get('/clinica/logo')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/login')->assertSee(route('clinic.logo').'?v=', false);

        $this->actingAs(User::factory()->admin()->create());
        $this->delete('/configuracoes/logo')->assertRedirect('/configuracoes');
        $this->assertFalse($disk->exists('clinica/logo.jpg'));
        $this->assertFalse($disk->exists('clinica/icone-512.png'));
        $this->get('/clinica/logo')->assertNotFound();
    }

    public function test_a_file_that_is_not_an_image_is_refused_as_logo(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/configuracoes/logo', ['logo' => $this->realUpload('logo.png', '<?php echo "x";')])->assertSessionHasErrors('logo');

        $this->assertFalse(Storage::disk('patient_photos')->exists('clinica/logo.jpg'));
    }

    public function test_the_app_can_be_installed_without_signing_in(): void
    {
        $manifest = $this->get('/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json');

        $this->assertSame('standalone', $manifest->json('display'));
        $this->assertSame(['192x192', '512x512'], array_column($manifest->json('icons'), 'sizes'));

        // Sem logo, o ícone padrão do sistema. Resposta pública, sem cookie de sessão.
        $this->get('/clinica/icone-192.png')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeaderMissing('Set-Cookie');
        $this->get('/clinica/icone-64.png')->assertNotFound();

        $this->get('/login')->assertSee('rel="manifest"', false)->assertSee('apple-touch-icon', false);

        // Mesmo para quem está conectado, a resposta pública não leva cookie.
        $this->actingAs(User::factory()->create())->get('/manifest.webmanifest')->assertOk()->assertHeaderMissing('Set-Cookie');
    }

    public function test_printed_record_shows_the_clinic_and_the_professional_registration(): void
    {
        ClinicProfile::query()->forceCreate(['id' => ClinicProfile::SINGLETON_ID, 'legal_name' => 'Clínica Bella Estética Ltda.', 'cnpj' => '11222333000181', 'technical_lead' => 'Dra. Ana Lima · CRBM-1 12345']);
        $professional = User::factory()->professional()->create(['name' => 'Dra. Helena', 'professional_registration' => 'CRBM-2 9876']);
        $patient = Patient::factory()->create();
        Treatment::factory()->create(['patient_id' => $patient->id, 'professional_id' => $professional->id, 'created_by' => $professional->id]);

        $this->actingAs($professional)->get("/pacientes/{$patient->id}/prontuario")
            ->assertOk()
            ->assertSee('Clínica Bella Estética Ltda.')
            ->assertSee('CNPJ 11.222.333/0001-81')
            ->assertSee('Responsável técnico: Dra. Ana Lima · CRBM-1 12345')
            ->assertSee('Dra. Helena · CRBM-2 9876');
    }

    public function test_admin_records_the_professional_registration_of_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->professional()->create();

        $this->actingAs($admin)->put("/usuarios/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'role' => 'professional', 'is_active' => '1',
            'professional_registration' => 'CRBM-1 55555',
        ])->assertSessionHasNoErrors();

        $this->assertSame('CRBM-1 55555', $user->fresh()->professional_registration);
        $this->assertContains('professional_registration', AuditLog::where('action', AuditAction::UserUpdated->value)->sole()->metadata['fields']);

        $this->put("/usuarios/{$user->id}", [
            'name' => $user->name, 'email' => $user->email, 'role' => 'professional', 'is_active' => '1',
            'professional_registration' => str_repeat('9', 41),
        ])->assertSessionHasErrors('professional_registration');
    }
}
