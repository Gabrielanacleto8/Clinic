<?php

namespace Tests\Feature;

use App\Enums\AttachmentType;
use App\Enums\AuditAction;
use App\Models\PatientAttachment;
use App\Models\Treatment;
use App\Models\User;
use App\Services\AttachmentVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TreatmentDocumentTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

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

    private function pdf(string $name = 'termo.pdf', ?string $contents = null): UploadedFile
    {
        return $this->realUpload($name, $contents ?? self::PDF);
    }

    private function uploadOne(): PatientAttachment
    {
        $this->actingAs($this->author)
            ->post("{$this->url}/documentos", ['document' => $this->pdf(), 'title' => 'Termo de consentimento'])
            ->assertSessionHasNoErrors();

        return PatientAttachment::firstOrFail();
    }

    public function test_professional_can_attach_a_pdf_document(): void
    {
        $this->actingAs($this->author)
            ->post("{$this->url}/documentos", ['document' => $this->pdf('../../etc/termo assinado.pdf'), 'title' => 'Termo de consentimento'])
            ->assertRedirect($this->url)->assertSessionHasNoErrors();

        $document = PatientAttachment::sole();

        $this->assertSame(AttachmentType::Document, $document->type);
        $this->assertSame('Termo de consentimento', $document->caption);
        $this->assertNull($document->stage);
        $this->assertNull($document->thumbnail_path);
        $this->assertSame(strlen(self::PDF), $document->size);
        $this->assertSame($this->author->id, $document->uploaded_by);
        // O nome do arquivo enviado nunca é aproveitado no caminho.
        $this->assertMatchesRegularExpression('/^'.$this->treatment->patient_id.'\/[0-9A-Z]{26}\.enc$/', $document->path);

        // Criptografado no disco.
        $raw = Storage::disk('patient_photos')->get($document->path);
        $this->assertStringNotContainsString('%PDF', $raw);
        $this->assertSame(self::PDF, app(AttachmentVault::class)->read($document->path));

        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::DocumentUploaded->value, 'auditable_id' => $document->id]);
        $this->get($this->url)->assertOk()->assertSee('Termo de consentimento')->assertSee("documentos/{$document->id}");

        // Documento não aparece como foto, e vice-versa.
        $this->assertSame(0, $this->treatment->photos()->count());
        $this->assertSame(1, $this->treatment->documents()->count());
    }

    public function test_only_real_pdfs_are_accepted(): void
    {
        $this->actingAs($this->author);

        $image = UploadedFile::fake()->image('foto.jpg', 50, 50);

        $rejected = [
            'imagem com extensão pdf' => $this->realUpload('foto.pdf', file_get_contents($image->getRealPath())),
            'script com extensão pdf' => $this->realUpload('shell.pdf', '<?php echo "oi";'),
            'html com tipo declarado falso' => $this->realUpload('pagina.html', '<html><script>alert(1)</script></html>', 'application/pdf'),
            'executável' => $this->realUpload('programa.exe', "MZ\x90\x00binario"),
        ];

        foreach ($rejected as $label => $file) {
            $this->post("{$this->url}/documentos", ['document' => $file, 'title' => 'Documento'])->assertSessionHasErrors('document');
            $this->assertSame(0, PatientAttachment::count(), "Aceitou: {$label}");
        }

        // O arquivo falso do Laravel informa o tipo pelo nome e passa pela
        // primeira barreira. A assinatura do conteúdo é quem o barra.
        $this->post("{$this->url}/documentos", ['document' => UploadedFile::fake()->createWithContent('falso.pdf', 'isto não é um pdf'), 'title' => 'Documento'])
            ->assertSessionHasErrors(['document' => 'O arquivo não é um PDF válido.']);

        $this->post("{$this->url}/documentos", ['document' => $this->pdf(), 'title' => ''])->assertSessionHasErrors('title');
        $this->post("{$this->url}/documentos", ['title' => 'Sem arquivo'])->assertSessionHasErrors('document');

        $this->assertSame(0, PatientAttachment::count());
        $this->assertSame([], Storage::disk('patient_photos')->allFiles());
    }

    public function test_document_is_delivered_as_a_download_and_the_access_is_audited(): void
    {
        $document = $this->uploadOne();
        $documentUrl = "{$this->url}/documentos/{$document->id}";

        auth()->logout();
        $this->get($documentUrl)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get($documentUrl)->assertForbidden();

        $viewer = User::factory()->professional()->create();
        $response = $this->actingAs($viewer)->get($documentUrl);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            // Sempre como anexo: o PDF nunca é aberto dentro da origem do sistema.
            ->assertHeader('Content-Disposition', 'attachment; filename="documento-'.$document->id.'.pdf"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(self::PDF, $response->getContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::DocumentViewed->value, 'user_id' => $viewer->id, 'auditable_id' => $document->id]);
    }

    public function test_documents_and_photos_do_not_answer_on_each_others_routes(): void
    {
        $document = $this->uploadOne();
        $this->post("{$this->url}/fotos", ['photos' => [UploadedFile::fake()->image('foto.jpg', 50, 50)], 'stage' => 'before']);
        $photo = $this->treatment->photos()->sole();
        $otherTreatment = Treatment::factory()->create();

        $this->actingAs(User::factory()->admin()->create());

        $this->get("{$this->url}/fotos/{$document->id}")->assertNotFound();
        $this->get("{$this->url}/documentos/{$photo->id}")->assertNotFound();
        $this->get("/pacientes/{$otherTreatment->patient_id}/atendimentos/{$otherTreatment->id}/documentos/{$document->id}")->assertNotFound();
        $this->delete("/pacientes/{$otherTreatment->patient_id}/atendimentos/{$otherTreatment->id}/documentos/{$document->id}")->assertNotFound();

        $this->assertNotSoftDeleted($document);
    }

    public function test_any_professional_adds_documents_but_only_admin_removes_them(): void
    {
        $document = $this->uploadOne();

        $this->actingAs(User::factory()->create());
        $this->post("{$this->url}/documentos", ['document' => $this->pdf(), 'title' => 'Outro'])->assertForbidden();
        $this->delete("{$this->url}/documentos/{$document->id}")->assertForbidden();

        // O registro é histórico permanente: nem quem enviou remove.
        foreach ([$this->author, User::factory()->professional()->create()] as $professional) {
            $this->actingAs($professional)->delete("{$this->url}/documentos/{$document->id}")->assertForbidden();
        }

        $this->assertSame(1, PatientAttachment::count());
        $this->assertNotSoftDeleted($document);

        // Um colega acrescenta, por exemplo, o termo assinado no retorno.
        $this->post("{$this->url}/documentos", ['document' => $this->pdf(), 'title' => 'Termo do retorno'])->assertSessionHasNoErrors();
        $this->assertSame(2, PatientAttachment::count());

        $this->actingAs(User::factory()->admin()->create())->delete("{$this->url}/documentos/{$document->id}")->assertRedirect($this->url);

        // Remoção lógica: some das telas, o arquivo é mantido.
        $this->assertSoftDeleted($document);
        Storage::disk('patient_photos')->assertExists($document->path);
        $this->get("{$this->url}/documentos/{$document->id}")->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => AuditAction::DocumentDeleted->value, 'auditable_id' => $document->id]);
    }

    public function test_file_refused_by_the_php_upload_limit_gets_a_clear_message(): void
    {
        $refused = new UploadedFile(tempnam(sys_get_temp_dir(), 'upload'), 'grande.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);

        $response = $this->actingAs($this->author)->post("{$this->url}/documentos", ['document' => $refused, 'title' => 'Exame']);

        $response->assertSessionHasErrors('document');
        $this->assertStringContainsString('passa do limite de', session('errors')->first('document'));
    }

    public function test_document_title_is_escaped(): void
    {
        $this->actingAs($this->author)->post("{$this->url}/documentos", ['document' => $this->pdf(), 'title' => '<script>alert(1)</script>']);

        $this->get($this->url)->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }
}
