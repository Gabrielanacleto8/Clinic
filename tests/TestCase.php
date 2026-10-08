<?php

namespace Tests;

use App\Models\Procedure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A suíte não depende dos assets compilados pelo Vite.
        $this->withoutVite();
    }

    /**
     * Procedimento do catálogo com o nome dado, criado na primeira vez em que é pedido.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function procedure(string $name, array $attributes = []): Procedure
    {
        return Procedure::query()->where('name', $name)->first()
            ?? Procedure::factory()->create(['name' => $name, ...$attributes]);
    }

    /**
     * Upload com arquivo real em disco, para o tipo ser detectado pelo conteúdo
     * como em produção (o arquivo falso do Laravel informa o tipo pelo nome).
     */
    protected function realUpload(string $name, string $contents, ?string $declaredMime = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, $declaredMime, null, true);
    }
}
