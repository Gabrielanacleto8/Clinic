<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\PhotoStage;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentAttachment;
use App\Models\User;
use App\Services\AttachmentVault;
use App\Support\UploadLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TreatmentPhotoTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG_MAGIC = "\xFF\xD8\xFF";

    private User $author;

    private Treatment $treatment;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('patient_photos');

        $this->author = User::factory()->professional()->create();
        $this->treatment = Treatment::factory()->create(['professional_id' => $this->author->id, 'created_by' => $this->author->id]);
        $this->url = "/pacientes/{$this->treatment->patient_id}/atendimentos/{$this->treatment->id}";
    }

    /**
     * @param  list<UploadedFile>  $photos
     * @return array<string, mixed>
     */
    private function payload(array $photos, array $overrides = []): array
    {
        return ['photos' => $photos, 'stage' => PhotoStage::Before->value, 'caption' => 'Perfil direito', ...$overrides];
    }

    private function uploadOne(): TreatmentAttachment
    {
        $this->actingAs($this->author)
            ->post("{$this->url}/fotos", $this->payload([UploadedFile::fake()->image('foto.jpg', 800, 600)]))
            ->assertSessionHasNoErrors();

        return TreatmentAttachment::firstOrFail();
    }

    public function test_professional_can_upload_photos(): void
    {
        $this->actingAs($this->author)
            ->post("{$this->url}/fotos", $this->payload([
                UploadedFile::fake()->image('antes-1.jpg', 800, 600),
                UploadedFile::fake()->image('antes-2.png', 400, 900),
            ]))
            ->assertRedirect($this->url)
            ->assertSessionHasNoErrors();

        $photos = TreatmentAttachment::orderBy('id')->get();

        $this->assertCount(2, $photos);
        $this->assertSame(PhotoStage::Before, $photos[0]->stage);
        $this->assertSame('Perfil direito', $photos[0]->caption);
        $this->assertSame([800, 600], [$photos[0]->width, $photos[0]->height]);
        $this->assertSame([400, 900], [$photos[1]->width, $photos[1]->height]);
        $this->assertSame($this->author->id, $photos[0]->uploaded_by);
        $this->assertSame(2, AuditLog::where('action', AuditAction::PhotoUploaded->value)->count());

        foreach ($photos as $photo) {
            Storage::disk('patient_photos')->assertExists([$photo->path, $photo->thumbnail_path]);
        }

        $this->get($this->url)->assertOk()->assertSee('Perfil direito')->assertSee('miniatura=1');
    }

    public function test_photos_are_encrypted_at_rest_and_always_stored_as_jpeg(): void
    {
        $this->actingAs($this->author)
            ->post("{$this->url}/fotos", $this->payload([UploadedFile::fake()->image('foto.png', 300, 300)]));

        $photo = TreatmentAttachment::firstOrFail();
        $raw = Storage::disk('patient_photos')->get($photo->path);

        // No disco não há nada reconhecível como imagem.
        $this->assertStringStartsNotWith(self::JPEG_MAGIC, $raw);
        $this->assertStringNotContainsString('JFIF', $raw);
        $this->assertFalse(@getimagesizefromstring($raw));

        // Decifrado, é sempre um JPEG, mesmo que o envio tenha sido PNG.
        $this->assertStringStartsWith(self::JPEG_MAGIC, app(AttachmentVault::class)->read($photo->path));
        $this->assertStringStartsWith(self::JPEG_MAGIC, app(AttachmentVault::class)->read($photo->thumbnail_path));
    }

    public function test_stored_paths_never_use_the_client_file_name(): void
    {
        $this->actingAs($this->author)
            ->post("{$this->url}/fotos", $this->payload([UploadedFile::fake()->image('../../../public/shell.php.jpg', 100, 100)]))
            ->assertSessionHasNoErrors();

        $photo = TreatmentAttachment::firstOrFail();
        $pattern = '/^'.$this->treatment->patient_id.'\/[0-9A-Z]{26}\.enc$/';

        $this->assertMatchesRegularExpression($pattern, $photo->path);
        $this->assertMatchesRegularExpression($pattern, $photo->thumbnail_path);
        $this->assertNotSame($photo->path, $photo->thumbnail_path);
    }

    public function test_reencoding_discards_anything_embedded_in_the_file(): void
    {
        // JPEG válido com código anexado ao final (arquivo "poliglota").
        $valid = UploadedFile::fake()->image('ok.jpg', 200, 200);
        $polyglot = $this->realUpload('inocente.jpg', file_get_contents($valid->getRealPath()).'<?php system($_GET["c"]); ?>');

        $this->actingAs($this->author)
            ->post("{$this->url}/fotos", $this->payload([$polyglot]))
            ->assertSessionHasNoErrors();

        $stored = app(AttachmentVault::class)->read(TreatmentAttachment::firstOrFail()->path);

        $this->assertStringStartsWith(self::JPEG_MAGIC, $stored);
        $this->assertStringNotContainsString('<?php', $stored);
        $this->assertStringNotContainsString('system(', $stored);
    }

    public function test_large_photos_are_downscaled(): void
    {
        config(['clinic.photos.max_dimension' => 500, 'clinic.photos.thumbnail_dimension' => 100]);

        $this->actingAs($this->author)
            ->post("{$this->url}/fotos", $this->payload([UploadedFile::fake()->image('grande.jpg', 2000, 1000)]));

        $photo = TreatmentAttachment::firstOrFail();

        $this->assertSame([500, 250], [$photo->width, $photo->height]);
        $this->assertSame([100, 50], array_slice(getimagesizefromstring(app(AttachmentVault::class)->read($photo->thumbnail_path)), 0, 2));
    }

    public function test_non_images_and_unsupported_formats_are_rejected(): void
    {
        $this->actingAs($this->author);

        // Arquivos reais em disco: o tipo é detectado pelo conteúdo, não pelo nome.
        $rejected = [
            'código com extensão de imagem' => $this->realUpload('falsa.jpg', '<?php echo "oi";'),
            'código com tipo declarado falso' => $this->realUpload('shell.php', '<?php echo "oi";', 'image/jpeg'),
            'svg com script' => $this->realUpload('vetor.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'html' => $this->realUpload('pagina.png', '<html><script>alert(1)</script></html>'),
            'gif' => UploadedFile::fake()->image('animacao.gif', 50, 50),
            'pdf' => UploadedFile::fake()->create('laudo.pdf', 10, 'application/pdf'),
        ];

        foreach ($rejected as $label => $file) {
            $this->post("{$this->url}/fotos", $this->payload([$file]))->assertSessionHasErrors('photos.0');
            $this->assertSame(0, TreatmentAttachment::count(), "Aceitou: {$label}");
        }

        $this->assertSame([], Storage::disk('patient_photos')->allFiles());
    }

    public function test_content_that_passes_the_type_check_is_still_stopped_when_decoding(): void
    {
        // O arquivo falso do Laravel informa o tipo pelo nome, então passa pela
        // primeira barreira. A decodificação da imagem é quem o barra.
        $this->actingAs($this->author)
            ->post("{$this->url}/fotos", $this->payload([UploadedFile::fake()->createWithContent('falsa.jpg', '<?php echo "oi";')]))
            ->assertSessionHasErrors(['photos' => 'O arquivo não é uma imagem JPEG, PNG ou WebP válida.']);

        // Cabeçalho de JPEG válido seguido de lixo: o tipo confere, os pixels não.
        $valid = UploadedFile::fake()->image('ok.jpg', 300, 300);
        $truncated = substr(file_get_contents($valid->getRealPath()), 0, 200);

        $this->post("{$this->url}/fotos", $this->payload([$this->realUpload('corrompida.jpg', $truncated.str_repeat('x', 500))]))
            ->assertSessionHasErrors('photos');

        $this->assertSame(0, TreatmentAttachment::count());
        $this->assertSame([], Storage::disk('patient_photos')->allFiles());
    }

    public function test_upload_limits_are_enforced(): void
    {
        $this->actingAs($this->author);

        $this->post("{$this->url}/fotos", $this->payload([]))->assertSessionHasErrors('photos');

        $six = array_map(fn ($i) => UploadedFile::fake()->image("f{$i}.jpg", 20, 20), range(1, 6));
        $this->post("{$this->url}/fotos", $this->payload($six))->assertSessionHasErrors('photos');

        $this->post("{$this->url}/fotos", $this->payload([UploadedFile::fake()->image('pesada.jpg', 20, 20)->size(9000)]))
            ->assertSessionHasErrors('photos.0');

        $this->post("{$this->url}/fotos", $this->payload([UploadedFile::fake()->image('ok.jpg', 20, 20)], ['stage' => 'durante']))
            ->assertSessionHasErrors('stage');

        $this->assertSame(0, TreatmentAttachment::count());
    }

    public function test_file_refused_by_the_php_upload_limit_gets_a_clear_message(): void
    {
        // Simula o PHP recusando o arquivo por exceder upload_max_filesize.
        $refused = new UploadedFile(tempnam(sys_get_temp_dir(), 'upload'), 'grande.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->actingAs($this->author)
            ->post("{$this->url}/fotos", $this->payload([$refused]))
            ->assertSessionHasErrors(['photos.0' => 'Uma das fotos passa do limite de '.UploadLimits::megabytes().' MB por arquivo aceito por este servidor.']);
    }

    public function test_request_larger_than_the_server_limit_returns_to_the_page_with_a_message(): void
    {
        $this->actingAs($this->author)
            ->from($this->url)
            ->call('POST', "{$this->url}/fotos", [], [], [], ['CONTENT_LENGTH' => PHP_INT_MAX])
            ->assertRedirect($this->url)
            ->assertSessionHas('error', 'O envio é grande demais para o servidor. Tente enviar menos fotos por vez.');
    }

    public function test_oversized_dimensions_are_rejected_before_decoding(): void
    {
        // Limite de 0,01 megapixel: uma imagem de 200x200 (0,04 MP) já estoura.
        config(['clinic.photos.max_megapixels' => 0.01]);

        $this->actingAs($this->author)
            ->post("{$this->url}/fotos", $this->payload([UploadedFile::fake()->image('bomba.jpg', 200, 200)]))
            ->assertSessionHasErrors(['photos' => 'A imagem tem dimensões grandes demais.']);

        $this->assertSame(0, TreatmentAttachment::count());
    }

    public function test_a_failing_photo_leaves_nothing_behind(): void
    {
        config(['clinic.photos.max_megapixels' => 0.01]);

        // A primeira é válida; a segunda estoura o limite e derruba o envio todo.
        $this->actingAs($this->author)
            ->post("{$this->url}/fotos", $this->payload([
                UploadedFile::fake()->image('ok.jpg', 50, 50),
                UploadedFile::fake()->image('bomba.jpg', 200, 200),
            ]))
            ->assertSessionHasErrors('photos');

        $this->assertSame(0, TreatmentAttachment::count());
        $this->assertSame([], Storage::disk('patient_photos')->allFiles());
        $this->assertDatabaseMissing('audit_logs', ['action' => AuditAction::PhotoUploaded->value]);
    }

    public function test_photo_is_served_only_to_clinical_staff_and_viewing_is_audited(): void
    {
        $photo = $this->uploadOne();
        $photoUrl = "{$this->url}/fotos/{$photo->id}";

        auth()->logout();
        $this->get($photoUrl)->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get($photoUrl)->assertForbidden();
        $this->actingAs(User::factory()->create())->get("{$photoUrl}?miniatura=1")->assertForbidden();

        $viewer = User::factory()->professional()->create();
        $response = $this->actingAs($viewer)->get($photoUrl);

        $response->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith(self::JPEG_MAGIC, $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::PhotoViewed->value,
            'user_id' => $viewer->id,
            'auditable_id' => $photo->id,
        ]);
    }

    public function test_thumbnail_is_smaller_and_not_audited_separately(): void
    {
        $photo = $this->uploadOne();

        $full = $this->get("{$this->url}/fotos/{$photo->id}")->getContent();
        $before = AuditLog::where('action', AuditAction::PhotoViewed->value)->count();

        $thumbnail = $this->get("{$this->url}/fotos/{$photo->id}?miniatura=1")->assertOk()->getContent();

        $this->assertLessThan(strlen($full), strlen($thumbnail));
        $this->assertSame([480, 360], array_slice(getimagesizefromstring($thumbnail), 0, 2));
        $this->assertSame($before, AuditLog::where('action', AuditAction::PhotoViewed->value)->count());
    }

    public function test_a_photo_is_only_reachable_through_its_own_treatment_and_patient(): void
    {
        $photo = $this->uploadOne();
        $otherTreatment = Treatment::factory()->create();
        $otherOfSamePatient = Treatment::factory()->create(['patient_id' => $this->treatment->patient_id]);

        $this->actingAs(User::factory()->admin()->create());

        $this->get("/pacientes/{$otherTreatment->patient_id}/atendimentos/{$otherTreatment->id}/fotos/{$photo->id}")->assertNotFound();
        $this->get("/pacientes/{$otherTreatment->patient_id}/atendimentos/{$this->treatment->id}/fotos/{$photo->id}")->assertNotFound();
        $this->get("/pacientes/{$this->treatment->patient_id}/atendimentos/{$otherOfSamePatient->id}/fotos/{$photo->id}")->assertNotFound();
        $this->delete("/pacientes/{$otherTreatment->patient_id}/atendimentos/{$otherTreatment->id}/fotos/{$photo->id}")->assertNotFound();

        $this->assertNotSoftDeleted($photo);
    }

    public function test_only_who_can_edit_the_treatment_can_add_or_remove_photos(): void
    {
        $photo = $this->uploadOne();
        $file = fn () => $this->payload([UploadedFile::fake()->image('nova.jpg', 50, 50)]);

        // Recepção: nada. Outro profissional: vê, mas não altera.
        foreach ([User::factory()->create(), User::factory()->professional()->create()] as $user) {
            $this->actingAs($user);
            $this->post("{$this->url}/fotos", $file())->assertForbidden();
            $this->delete("{$this->url}/fotos/{$photo->id}")->assertForbidden();
        }

        $this->assertSame(1, TreatmentAttachment::count());
        $this->get($this->url)->assertOk()->assertDontSee('Adicionar fotos')->assertDontSee('Remover');

        $this->actingAs(User::factory()->admin()->create())->post("{$this->url}/fotos", $file())->assertSessionHasNoErrors();
        $this->assertSame(2, TreatmentAttachment::count());
    }

    public function test_removed_photo_disappears_but_the_file_is_kept(): void
    {
        $photo = $this->uploadOne();

        $this->delete("{$this->url}/fotos/{$photo->id}")->assertRedirect($this->url);

        $this->assertSoftDeleted($photo);
        Storage::disk('patient_photos')->assertExists($photo->path);
        $this->get("{$this->url}/fotos/{$photo->id}")->assertNotFound();
        $this->get($this->url)->assertOk()->assertDontSee("fotos/{$photo->id}");
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::PhotoDeleted->value, 'auditable_id' => $photo->id]);
    }

    public function test_photos_of_an_archived_treatment_or_patient_are_unreachable(): void
    {
        $photo = $this->uploadOne();
        $admin = User::factory()->admin()->create();

        $this->treatment->delete();
        $this->actingAs($admin)->get("{$this->url}/fotos/{$photo->id}")->assertNotFound();

        $this->treatment->restore();
        Patient::findOrFail($this->treatment->patient_id)->delete();
        $this->actingAs($admin)->get("{$this->url}/fotos/{$photo->id}")->assertNotFound();
    }

    public function test_photo_disk_is_private_and_has_no_direct_route(): void
    {
        $config = config('filesystems.disks.patient_photos');

        $this->assertFalse($config['serve']);
        $this->assertStringStartsWith(storage_path('app/private'), $config['root']);
        $this->assertStringNotContainsString(public_path(), $config['root']);
        $this->assertArrayNotHasKey('url', $config);
    }

    public function test_internal_paths_are_hidden_from_serialization(): void
    {
        $serialized = $this->uploadOne()->toArray();

        $this->assertArrayNotHasKey('path', $serialized);
        $this->assertArrayNotHasKey('thumbnail_path', $serialized);
    }
}
