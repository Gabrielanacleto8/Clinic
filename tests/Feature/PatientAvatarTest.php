<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientAttachment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PatientAvatar;
use App\Services\RolePermissions;
use GdImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Foto de identificação do paciente: segue o acesso ao cadastro, fica
 * criptografada e não se mistura com as fotos clínicas.
 */
class PatientAvatarTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    private Patient $patient;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('patient_photos');

        $this->receptionist = User::factory()->role(UserRole::Receptionist)->create();
        $this->patient = Patient::factory()->create(['name' => 'Marina de Souza Albuquerque']);
        $this->url = "/pacientes/{$this->patient->id}/avatar";
    }

    private function upload(int $width = 800, int $height = 600, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->receptionist)->post($this->url, [
            'avatar' => UploadedFile::fake()->image('rosto.jpg', $width, $height),
            '_dialog' => 'patient-avatar',
        ]);
    }

    public function test_reception_adds_an_identification_photo_stored_encrypted_and_square(): void
    {
        $this->upload()
            ->assertRedirect("/pacientes/{$this->patient->id}")
            ->assertSessionHas('success', 'Foto de identificação atualizada.');

        $path = $this->patient->refresh()->avatar_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith($this->patient->id.'/', $path);

        // No disco o arquivo está criptografado, sem nada do nome enviado.
        $raw = Storage::disk('patient_photos')->get($path);
        $this->assertStringNotContainsString('rosto', $path);
        $this->assertFalse(@getimagesizefromstring($raw));

        // Servida pela rota, é um JPEG quadrado recortado no tamanho de avatar.
        $response = $this->actingAs($this->receptionist)->get($this->url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        [$width, $height] = getimagesizefromstring($response->getContent());
        $this->assertSame([320, 320], [$width, $height]);

        // Não é foto clínica: nada entra na galeria do prontuário.
        $this->assertSame(0, PatientAttachment::count());

        $audit = AuditLog::where('action', AuditAction::PatientUpdated->value)->sole();
        $this->assertSame($this->patient->id, $audit->auditable_id);
        $this->assertSame(['fields' => ['avatar']], $audit->metadata);
    }

    public function test_a_small_photo_is_never_enlarged(): void
    {
        $this->upload(200, 120)->assertSessionHasNoErrors();

        [$width, $height] = getimagesizefromstring($this->actingAs($this->receptionist)->get($this->url)->getContent());
        $this->assertSame([120, 120], [$width, $height]);
    }

    public function test_replacing_and_removing_delete_the_previous_file(): void
    {
        $this->upload()->assertSessionHasNoErrors();
        $first = $this->patient->refresh()->avatar_path;

        $this->upload()->assertSessionHasNoErrors();
        $second = $this->patient->refresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('patient_photos')->assertMissing($first);
        Storage::disk('patient_photos')->assertExists($second);

        $this->actingAs($this->receptionist)->delete($this->url)
            ->assertRedirect("/pacientes/{$this->patient->id}")
            ->assertSessionHas('success', 'Foto de identificação removida.');

        $this->assertNull($this->patient->refresh()->avatar_path);
        Storage::disk('patient_photos')->assertMissing($second);
        $this->actingAs($this->receptionist)->get($this->url)->assertNotFound();
        $this->assertSame(3, AuditLog::where('action', AuditAction::PatientUpdated->value)->count());
    }

    public function test_invalid_files_are_refused_and_nothing_is_stored(): void
    {
        $this->actingAs($this->receptionist)->post($this->url, [])->assertSessionHasErrors(['avatar' => 'Selecione uma foto.']);
        $this->actingAs($this->receptionist)->post($this->url, ['avatar' => UploadedFile::fake()->create('ficha.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('avatar');
        // Extensão de imagem, conteúdo que não é imagem: barrado pela decodificação.
        $this->actingAs($this->receptionist)->post($this->url, ['avatar' => UploadedFile::fake()->createWithContent('rosto.jpg', str_repeat('x', 2048))])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($this->patient->refresh()->avatar_path);
        $this->assertSame([], Storage::disk('patient_photos')->allFiles());
        $this->assertSame(0, AuditLog::where('action', AuditAction::PatientUpdated->value)->count());
    }

    public function test_access_follows_the_patient_registry_permissions(): void
    {
        $this->upload()->assertSessionHasNoErrors();
        $path = $this->patient->refresh()->avatar_path;

        // Só ver o cadastro: vê a foto, mas não troca nem remove.
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsView]);
        $this->app->forgetScopedInstances();

        $this->actingAs($this->receptionist)->get($this->url)->assertOk();
        $this->upload()->assertForbidden();
        $this->actingAs($this->receptionist)->delete($this->url)->assertForbidden();

        // Sem acesso a pacientes, nem a foto.
        app(RolePermissions::class)->sync(UserRole::Receptionist, []);
        $this->app->forgetScopedInstances();
        $this->actingAs($this->receptionist)->get($this->url)->assertForbidden();

        auth()->logout();
        $this->get($this->url)->assertRedirect('/login');
        $this->post($this->url, ['avatar' => UploadedFile::fake()->image('rosto.jpg')])->assertRedirect('/login');

        $this->assertSame($path, $this->patient->refresh()->avatar_path);
    }

    public function test_archived_patients_have_no_photo_route_and_show_initials(): void
    {
        $this->upload()->assertSessionHasNoErrors();
        $this->patient->refresh()->delete();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get($this->url)->assertNotFound();
        $this->actingAs($admin)->get('/pacientes?arquivados=1')
            ->assertOk()
            ->assertSee('>MA</span>', false)
            ->assertDontSee($this->url);
    }

    public function test_the_record_and_the_list_show_the_photo_or_the_initials(): void
    {
        $withoutPhoto = Patient::factory()->create(['name' => 'Joana Prado']);
        $this->upload()->assertSessionHasNoErrors();

        $this->actingAs($this->receptionist)->get('/pacientes')
            ->assertOk()
            ->assertSee('src="'.route('patients.avatar.show', $this->patient).'"', false)
            ->assertSee('>JP</span>', false)
            ->assertDontSee(route('patients.avatar.show', $withoutPhoto));

        $this->actingAs($this->receptionist)->get("/pacientes/{$this->patient->id}")
            ->assertOk()
            ->assertSee('src="'.route('patients.avatar.show', $this->patient).'"', false)
            ->assertSee('Trocar foto')
            ->assertSee('Remover foto atual');

        $this->actingAs($this->receptionist)->get("/pacientes/{$withoutPhoto->id}")
            ->assertOk()
            ->assertSee('Adicionar foto')
            ->assertDontSee('Remover foto atual');

        // O caminho interno nunca sai em serializações.
        $this->assertArrayNotHasKey('avatar_path', $this->patient->refresh()->toArray());
    }

    public function test_the_list_does_not_query_per_photo(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->receptionist)->get('/pacientes')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $count();
        $this->upload()->assertSessionHasNoErrors();
        $one = $count();

        foreach (Patient::factory()->count(5)->create() as $patient) {
            $this->actingAs($this->receptionist)->post("/pacientes/{$patient->id}/avatar", ['avatar' => UploadedFile::fake()->image('rosto.jpg')]);
        }

        $this->assertSame($one, $count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'uma palavra' => ['Marina', 'M'],
            'nome e sobrenome' => ['Joana Prado', 'JP'],
            'usa o primeiro e o último' => ['Marina de Souza Albuquerque', 'MA'],
            'espaços extras' => ['   joana    prado  ', 'JP'],
            'tabulação e quebra de linha' => ["Joana\t\nPrado", 'JP'],
            'espaço não separável' => ["Joana\u{00A0}Prado", 'JP'],
            'acentos em minúscula' => ['élida conceição ávila', 'ÉÁ'],
            'acento só no início' => ['Ítalo', 'Í'],
        ];
    }

    #[DataProvider('names')]
    public function test_initials_take_the_first_and_last_words_keeping_accents(string $name, string $initials): void
    {
        $this->assertSame($initials, (new Patient)->forceFill(['name' => $name])->initials());
    }

    public function test_initials_of_an_accented_name_appear_escaped_in_the_list(): void
    {
        Patient::factory()->create(['name' => 'Élida Ávila']);

        $this->actingAs($this->receptionist)->get('/pacientes')->assertOk()->assertSee('>ÉÁ</span>', false);
    }

    /**
     * @return array<string, array{callable(): array<string, mixed>, string}>
     */
    public static function invalidUploads(): array
    {
        return [
            'sem arquivo' => [fn (): array => [], 'Selecione uma foto.'],
            'formato recusado' => [fn (): array => ['avatar' => UploadedFile::fake()->create('ficha.pdf', 10, 'application/pdf')], 'Envie uma foto em JPEG, PNG ou WebP.'],
            'conteúdo que não é imagem' => [fn (): array => ['avatar' => UploadedFile::fake()->createWithContent('rosto.jpg', str_repeat('x', 2048))], 'O arquivo não é uma imagem JPEG, PNG ou WebP válida.'],
            'acima do limite' => [fn (): array => ['avatar' => UploadedFile::fake()->image('rosto.jpg')->size(9000)], 'A foto pode ter no máximo 8 MB.'],
        ];
    }

    #[DataProvider('invalidUploads')]
    public function test_an_invalid_upload_reopens_the_avatar_dialog_with_the_error(callable $payload, string $message): void
    {
        $admin = User::factory()->admin()->create();
        $record = "/pacientes/{$this->patient->id}";

        $html = $this->actingAs($admin)->followingRedirects()->from($record)
            ->post($this->url, [...$payload(), '_dialog' => 'patient-avatar'])
            ->assertOk()
            ->assertSee($message)
            ->getContent();

        // Reabre só o diálogo do avatar, com o campo marcado com erro.
        $this->assertMatchesRegularExpression('/<dialog id="patient-avatar"[^>]*data-dialog-autoshow/', $html);
        $this->assertSame(1, substr_count($html, 'data-dialog-autoshow'));
        $this->assertMatchesRegularExpression('/id="patient-avatar-file"[^>]*input-error/s', $html);

        $this->assertNull($this->patient->refresh()->avatar_path);
        $this->assertSame([], Storage::disk('patient_photos')->allFiles());

        // Sem envio inválido, a ficha abre com o diálogo fechado e sem erro.
        $clean = $this->actingAs($admin)->get($record)->assertOk()->assertDontSee($message)->getContent();
        $this->assertStringNotContainsString('data-dialog-autoshow', $clean);
    }

    public function test_an_error_on_another_form_does_not_open_the_avatar_dialog(): void
    {
        $html = $this->actingAs($this->receptionist)
            ->withSession(['_old_input' => ['_dialog' => 'outro-dialogo']])
            ->get("/pacientes/{$this->patient->id}")
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/<dialog id="patient-avatar"[^>]*data-dialog-autoshow/', $html);
    }

    public function test_who_only_views_the_registry_sees_the_photo_without_button_or_dialog(): void
    {
        $this->upload()->assertSessionHasNoErrors();

        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsView]);
        $this->app->forgetScopedInstances();

        $this->actingAs($this->receptionist)->get("/pacientes/{$this->patient->id}")
            ->assertOk()
            ->assertSee('src="'.route('patients.avatar.show', $this->patient).'"', false)
            ->assertDontSee('data-dialog-open="patient-avatar"', false)
            ->assertDontSee('id="patient-avatar"', false)
            ->assertDontSee('action="'.route('patients.avatar.update', $this->patient).'"', false)
            ->assertDontSee('Trocar foto')
            ->assertDontSee('Adicionar foto')
            ->assertDontSee('Remover foto atual');
    }

    public function test_the_record_offers_the_avatar_and_the_clinical_photo_inputs_side_by_side(): void
    {
        // O redutor do app.js precisa atender os dois [data-photo-input] da ficha.
        $html = $this->actingAs(User::factory()->admin()->create())
            ->get("/pacientes/{$this->patient->id}?aba=fotos")
            ->assertOk()
            ->assertSee('data-dialog-open="patient-avatar"', false)
            ->getContent();

        $this->assertSame(2, substr_count($html, 'data-photo-input'));
        $this->assertMatchesRegularExpression('/<input id="patient-avatar-file" name="avatar" type="file"[^>]*data-photo-input[^>]*data-max-files="1"/s', $html);
    }

    public function test_admin_and_professional_manage_the_photo(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->professional()->create()] as $user) {
            $this->upload(user: $user)->assertSessionHasNoErrors()->assertRedirect("/pacientes/{$this->patient->id}");
            $this->assertNotNull($this->patient->refresh()->avatar_path);
            $this->actingAs($user)->get($this->url)->assertOk();

            $this->actingAs($user)->delete($this->url)->assertRedirect("/pacientes/{$this->patient->id}");
            $this->assertNull($this->patient->refresh()->avatar_path);
        }

        $this->assertSame([], Storage::disk('patient_photos')->allFiles());
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::PatientUpdated->value, 'auditable_id' => $this->patient->id]);
    }

    public function test_removing_when_there_is_no_photo_changes_and_audits_nothing(): void
    {
        $this->actingAs($this->receptionist)->delete($this->url)
            ->assertRedirect("/pacientes/{$this->patient->id}")
            ->assertSessionHas('success');

        $this->assertNull($this->patient->refresh()->avatar_path);
        $this->assertSame(0, AuditLog::where('action', AuditAction::PatientUpdated->value)->count());
    }

    public function test_the_route_only_reaches_the_photo_of_its_own_patient(): void
    {
        $other = Patient::factory()->create();
        $this->upload()->assertSessionHasNoErrors();
        $path = $this->patient->refresh()->avatar_path;

        // O outro paciente não tem foto: 404, nunca a foto de quem tem.
        $this->actingAs($this->receptionist)->get("/pacientes/{$other->id}/avatar")->assertNotFound();
        $this->actingAs($this->receptionist)->delete("/pacientes/{$other->id}/avatar")->assertRedirect("/pacientes/{$other->id}");

        $this->assertSame($path, $this->patient->refresh()->avatar_path);
        Storage::disk('patient_photos')->assertExists($path);
        $this->actingAs($this->receptionist)->get('/pacientes/999999/avatar')->assertNotFound();
    }

    public function test_the_served_file_name_never_carries_the_patient_name(): void
    {
        $this->upload()->assertSessionHasNoErrors();

        $disposition = (string) $this->actingAs($this->receptionist)->get($this->url)->assertOk()->headers->get('Content-Disposition');

        $this->assertSame('inline; filename="paciente-'.$this->patient->id.'.jpg"', $disposition);
        $this->assertStringNotContainsString('Marina', $disposition);
    }

    public function test_audit_of_the_photo_carries_no_path_nor_file_name(): void
    {
        $this->upload()->assertSessionHasNoErrors();
        $path = $this->patient->refresh()->avatar_path;

        $audit = AuditLog::where('action', AuditAction::PatientUpdated->value)->sole();
        $serialized = json_encode($audit->getAttributes());

        $this->assertSame('patient', $audit->auditable_type);
        $this->assertStringNotContainsString($path, $serialized);
        $this->assertStringNotContainsString('rosto', $serialized);
        $this->assertStringNotContainsString('Marina', $serialized);
    }

    public function test_the_crop_is_taken_from_the_center_of_wide_and_tall_photos(): void
    {
        // Três faixas: só a do meio deve sobrar no quadrado.
        foreach ([[300, 100], [100, 300]] as [$width, $height]) {
            $image = imagecreatetruecolor($width, $height);
            $wide = $width > $height;
            $colors = [imagecolorallocate($image, 255, 0, 0), imagecolorallocate($image, 0, 255, 0), imagecolorallocate($image, 0, 0, 255)];
            foreach ($colors as $index => $color) {
                $wide
                    ? imagefilledrectangle($image, $index * 100, 0, $index * 100 + 99, 99, $color)
                    : imagefilledrectangle($image, 0, $index * 100, 99, $index * 100 + 99, $color);
            }

            $this->actingAs($this->receptionist)
                ->post($this->url, ['avatar' => $this->realUpload('rosto.png', $this->png($image))])
                ->assertSessionHasNoErrors();

            $avatar = imagecreatefromstring($this->actingAs($this->receptionist)->get($this->url)->getContent());
            $this->assertSame([100, 100], [imagesx($avatar), imagesy($avatar)]);

            foreach ([[3, 3], [50, 50], [96, 96], [3, 96], [96, 3]] as [$x, $y]) {
                $this->assertPixelIsGreen($avatar, $x, $y);
            }
        }
    }

    public function test_transparency_is_flattened_on_white(): void
    {
        $image = imagecreatetruecolor(60, 60);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        $this->actingAs($this->receptionist)
            ->post($this->url, ['avatar' => $this->realUpload('rosto.png', $this->png($image))])
            ->assertSessionHasNoErrors();

        $avatar = imagecreatefromstring($this->actingAs($this->receptionist)->get($this->url)->getContent());
        ['red' => $red, 'green' => $green, 'blue' => $blue] = imagecolorsforindex($avatar, imagecolorat($avatar, 30, 30));
        $this->assertGreaterThan(240, min($red, $green, $blue));
    }

    public function test_dimensions_over_the_limit_are_refused_before_decoding(): void
    {
        config(['clinic.photos.max_megapixels' => 0.01]);

        $this->upload(200, 200)->assertSessionHasErrors(['avatar' => 'A imagem tem dimensões grandes demais.']);

        $this->assertNull($this->patient->refresh()->avatar_path);
        $this->assertSame([], Storage::disk('patient_photos')->allFiles());
    }

    public function test_a_failed_save_keeps_the_current_photo_and_leaves_no_orphan_file(): void
    {
        // Direto pelo serviço: o controller fica guardado na rota entre requisições
        // do mesmo teste, e o dublê de auditoria só vale para a primeira.
        app(PatientAvatar::class)->replace($this->patient, UploadedFile::fake()->image('rosto.jpg', 400, 400), $this->receptionist);
        $current = $this->patient->refresh()->avatar_path;

        $this->mock(AuditLogger::class)->shouldReceive('log')->andThrow(new RuntimeException('falha simulada'));

        $this->upload()->assertServerError();

        $this->assertSame($current, $this->patient->refresh()->avatar_path);
        $this->assertSame([$current], Storage::disk('patient_photos')->allFiles());
    }

    public function test_the_record_runs_the_same_queries_with_or_without_a_photo(): void
    {
        $admin = User::factory()->admin()->create();

        $count = function (User $user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->get("/pacientes/{$this->patient->id}")->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $count($this->receptionist);
        $count($admin);
        $withoutReception = $count($this->receptionist);
        $withoutAdmin = $count($admin);

        $this->upload()->assertSessionHasNoErrors();

        $this->assertSame($withoutReception, $count($this->receptionist));
        $this->assertSame($withoutAdmin, $count($admin));
    }

    public function test_archiving_keeps_the_photo_and_restoring_brings_it_back(): void
    {
        $this->upload()->assertSessionHasNoErrors();
        $path = $this->patient->refresh()->avatar_path;
        $contents = $this->actingAs($this->receptionist)->get($this->url)->getContent();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->delete("/pacientes/{$this->patient->id}")->assertRedirect(route('patients.index'));

        // Arquivado: a foto continua guardada, mas nenhuma rota a alcança ou altera.
        $this->actingAs($admin)->get($this->url)->assertNotFound();
        $this->upload(user: $admin)->assertNotFound();
        $this->actingAs($admin)->delete($this->url)->assertNotFound();

        $this->assertSame($path, Patient::withTrashed()->find($this->patient->id)->avatar_path);
        Storage::disk('patient_photos')->assertExists($path);

        $this->actingAs($admin)->patch("/pacientes/{$this->patient->id}/restaurar")->assertRedirect(route('patients.show', $this->patient));

        $this->assertSame($contents, $this->actingAs($admin)->get($this->url)->assertOk()->getContent());
        $this->actingAs($admin)->get("/pacientes/{$this->patient->id}")
            ->assertOk()
            ->assertSee('src="'.route('patients.avatar.show', $this->patient).'"', false)
            ->assertSee('Trocar foto');
        $this->assertSame(1, AuditLog::where('action', AuditAction::PatientUpdated->value)->count());
    }

    private function png(GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function assertPixelIsGreen(GdImage $image, int $x, int $y): void
    {
        ['red' => $red, 'green' => $green, 'blue' => $blue] = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        $this->assertGreaterThan(200, $green, "Pixel ({$x}, {$y}) deveria ser da faixa central.");
        $this->assertLessThan(60, max($red, $blue), "Pixel ({$x}, {$y}) deveria ser da faixa central.");
    }
}
