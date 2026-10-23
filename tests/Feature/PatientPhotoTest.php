<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\PhotoStage;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientAttachment;
use App\Models\Treatment;
use App\Models\User;
use App\Services\PhotoUploader;
use App\Services\RolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Fotos avulsas, enviadas pela ficha do paciente: o acervo anterior ao sistema.
 */
class PatientPhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Patient $patient;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('patient_photos');

        $this->author = User::factory()->professional()->create();
        $this->patient = Patient::factory()->create();
        $this->url = "/pacientes/{$this->patient->id}";
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'photos' => [UploadedFile::fake()->image('foto.jpg', 800, 600)],
            'taken_on' => '2025-06-15',
            'stage' => PhotoStage::Before->value,
            'caption' => 'Antes do preenchimento',
            ...$overrides,
        ];
    }

    private function uploadOne(): PatientAttachment
    {
        $this->actingAs($this->author)->post("{$this->url}/fotos", $this->payload())->assertSessionHasNoErrors();

        return PatientAttachment::whereNull('treatment_id')->firstOrFail();
    }

    public function test_professional_uploads_a_retroactive_photo_from_the_record(): void
    {
        $this->actingAs($this->author)
            ->post("{$this->url}/fotos", $this->payload())
            ->assertRedirect(route('patients.show', [$this->patient, 'aba' => 'fotos']).'#fotos-do-paciente')
            ->assertSessionHas('success', 'Foto adicionada.');

        $photo = PatientAttachment::firstOrFail();

        $this->assertSame($this->patient->id, $photo->patient_id);
        $this->assertNull($photo->treatment_id);
        $this->assertSame('2025-06-15', $photo->taken_on->format('Y-m-d'));
        $this->assertSame(PhotoStage::Before, $photo->stage);
        $this->assertSame($this->author->id, $photo->uploaded_by);
        Storage::disk('patient_photos')->assertExists($photo->path);

        $audit = AuditLog::where('action', AuditAction::PhotoUploaded)->firstOrFail();
        $this->assertSame($this->patient->id, $audit->metadata['patient_id']);
        $this->assertNull($audit->metadata['treatment_id']);

        $this->get("{$this->url}?aba=fotos")->assertOk()
            ->assertSee('Antes · Antes do preenchimento')->assertSee('15/06/2025')->assertSee('Foto avulsa')
            ->assertSee("{$this->url}/fotos/{$photo->id}?miniatura=1", false);
    }

    public function test_a_loose_photo_needs_a_date_that_is_not_in_the_future(): void
    {
        $this->actingAs($this->author);

        $this->post("{$this->url}/fotos", $this->payload(['taken_on' => '']))->assertSessionHasErrors('taken_on');
        $this->post("{$this->url}/fotos", $this->payload(['taken_on' => '15/06/2025']))->assertSessionHasErrors('taken_on');
        $this->post("{$this->url}/fotos", $this->payload(['taken_on' => now()->addDay()->format('Y-m-d')]))->assertSessionHasErrors('taken_on');
        $this->post("{$this->url}/fotos", $this->payload(['photos' => [UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf')]]))->assertSessionHasErrors('photos.0');

        $this->assertSame(0, PatientAttachment::count());
    }

    public function test_only_who_manages_records_uploads_and_only_who_views_them_sees(): void
    {
        $this->post("{$this->url}/fotos", $this->payload())->assertRedirect('/login');

        // Recepção com acesso ao prontuário só para leitura: vê, mas não envia.
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsView, Permission::RecordsView]);
        $reader = User::factory()->create();
        $photo = $this->uploadOne();

        $this->actingAs($reader);
        $this->get("{$this->url}?aba=fotos")->assertOk()->assertSee('Antes do preenchimento')->assertDontSee('Adicionar fotos avulsas');
        $this->get("{$this->url}/fotos/{$photo->id}")->assertOk();
        $this->post("{$this->url}/fotos", $this->payload())->assertForbidden();

        // Recepção padrão não chega ao prontuário.
        app(RolePermissions::class)->sync(UserRole::Receptionist, [Permission::PatientsView]);
        $this->get("{$this->url}/fotos/{$photo->id}")->assertForbidden();
        $this->get("{$this->url}/fotos/{$photo->id}?miniatura=1")->assertForbidden();
    }

    public function test_opening_a_photo_is_audited_but_the_thumbnail_is_not(): void
    {
        $photo = $this->uploadOne();

        $this->actingAs($this->author)->get("{$this->url}/fotos/{$photo->id}")
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Content-Disposition', 'inline; filename="foto-'.$photo->id.'.jpg"');
        $this->get("{$this->url}/fotos/{$photo->id}?miniatura=1")->assertOk();

        $this->assertSame(1, AuditLog::where('action', AuditAction::PhotoViewed)->count());
    }

    public function test_the_record_route_serves_treatment_photos_too_but_never_another_patients(): void
    {
        $treatment = Treatment::factory()->create(['patient_id' => $this->patient->id]);
        $this->actingAs($this->author)
            ->post("{$this->url}/atendimentos/{$treatment->id}/fotos", ['photos' => [UploadedFile::fake()->image('a.jpg', 300, 300)], 'stage' => 'after'])
            ->assertSessionHasNoErrors();
        $ofTreatment = PatientAttachment::firstOrFail();
        $loose = $this->uploadOne();

        $this->get("{$this->url}/fotos/{$ofTreatment->id}")->assertOk();
        $this->get("{$this->url}?aba=fotos")->assertOk()->assertSee("{$this->url}/fotos/{$ofTreatment->id}?miniatura=1", false);

        $other = Patient::factory()->create();
        $this->get("/pacientes/{$other->id}/fotos/{$loose->id}")->assertNotFound();
        $this->get("/pacientes/{$other->id}/fotos/{$ofTreatment->id}")->assertNotFound();

        // Atendimento arquivado leva as fotos junto; a avulsa fica.
        $treatment->delete();
        $this->get("{$this->url}/fotos/{$ofTreatment->id}")->assertNotFound();
        $this->get("{$this->url}/fotos/{$loose->id}")->assertOk();
    }

    public function test_only_admin_removes_a_loose_photo_and_the_file_is_kept(): void
    {
        $photo = $this->uploadOne();

        $this->actingAs($this->author);
        $this->get("{$this->url}?aba=fotos")->assertDontSee('Remover');
        $this->delete("{$this->url}/fotos/{$photo->id}")->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $this->get("{$this->url}?aba=fotos")->assertSee('Remover');
        $this->delete("{$this->url}/fotos/{$photo->id}")->assertRedirect(route('patients.show', [$this->patient, 'aba' => 'fotos']));

        $this->assertSoftDeleted($photo);
        Storage::disk('patient_photos')->assertExists($photo->path);
        $this->get("{$this->url}?aba=fotos")->assertDontSee('Antes do preenchimento');
        $this->get("{$this->url}/fotos/{$photo->id}")->assertNotFound();
        $this->assertSame(1, AuditLog::where('action', AuditAction::PhotoDeleted)->count());
    }

    public function test_the_uploader_refuses_a_treatment_of_another_patient(): void
    {
        $treatment = Treatment::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        app(PhotoUploader::class)->upload([UploadedFile::fake()->image('a.jpg', 300, 300)], ['stage' => 'before'], $this->patient, $treatment, $this->author);
    }

    public function test_a_treatment_photo_is_not_removed_from_the_record(): void
    {
        $treatment = Treatment::factory()->create(['patient_id' => $this->patient->id]);
        $this->actingAs($this->author)
            ->post("{$this->url}/atendimentos/{$treatment->id}/fotos", ['photos' => [UploadedFile::fake()->image('a.jpg', 300, 300)], 'stage' => 'after'])
            ->assertSessionHasNoErrors();
        $photo = PatientAttachment::firstOrFail();

        $this->actingAs(User::factory()->admin()->create())->get("{$this->url}?aba=fotos")->assertOk()->assertDontSee('Remover');
    }
}
