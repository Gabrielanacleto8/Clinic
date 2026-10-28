<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Exceptions\InvalidPhotoException;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Logo da clínica e os ícones do aplicativo instalado, gerados a partir dele.
 *
 * Fica no disco das fotos porque é o volume que persiste e entra no backup em
 * produção, mas sem criptografia: é a marca da clínica, exibida até na tela
 * de login. A presença do logo é lida no disco, sem consulta ao banco, pois o
 * menu de toda página a consulta.
 */
class ClinicLogo
{
    /** Tamanhos de ícone servidos: 180 (iPhone) e os dois exigidos pelo Android. */
    public const ICON_SIZES = [180, 192, 512];

    private const DIRECTORY = 'clinica';

    private const LOGO_DIMENSION = 512;

    public function __construct(
        private readonly PhotoProcessor $processor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws InvalidPhotoException
     */
    public function replace(UploadedFile $file): void
    {
        $logo = $this->processor->logo($file, self::LOGO_DIMENSION);
        $disk = $this->disk();

        $disk->put($this->logoPath(), $logo);

        foreach (self::ICON_SIZES as $size) {
            $disk->put($this->iconPath($size), $this->squareIcon($logo, $size));
        }

        $this->audit->log(AuditAction::ClinicLogoUpdated, null);
    }

    public function remove(): void
    {
        $this->disk()->delete([$this->logoPath(), ...array_map($this->iconPath(...), self::ICON_SIZES)]);

        $this->audit->log(AuditAction::ClinicLogoRemoved, null);
    }

    public function exists(): bool
    {
        return $this->disk()->exists($this->logoPath());
    }

    /**
     * Marca de versão para as URLs do logo e dos ícones: muda quando o logo
     * muda, para o navegador não guardar o antigo. Nula sem logo.
     */
    public function version(): ?int
    {
        return $this->exists() ? $this->disk()->lastModified($this->logoPath()) : null;
    }

    public function logo(): ?string
    {
        return $this->exists() ? $this->disk()->get($this->logoPath()) : null;
    }

    /**
     * Ícone gerado do logo, ou o ícone padrão do sistema quando não há logo.
     */
    public function icon(int $size): string
    {
        $disk = $this->disk();

        return $disk->exists($this->iconPath($size))
            ? (string) $disk->get($this->iconPath($size))
            : (string) file_get_contents(public_path("icons/icone-{$size}.png"));
    }

    /**
     * O logo centralizado num quadrado branco, com margem: o Android recorta
     * os cantos do ícone, e um logo largo não pode ser deformado.
     */
    private function squareIcon(string $logo, int $size): string
    {
        $source = imagecreatefromstring($logo);

        if (! $source instanceof GdImage) {
            throw new InvalidPhotoException('Não foi possível ler a imagem enviada.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = ($size * 0.8) / max($width, $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $icon = imagecreatetruecolor($size, $size);
        imagefill($icon, 0, 0, (int) imagecolorallocate($icon, 255, 255, 255));
        imagecopyresampled($icon, $source, intdiv($size - $targetWidth, 2), intdiv($size - $targetHeight, 2), 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($icon, null, 9);

        return (string) ob_get_clean();
    }

    private function logoPath(): string
    {
        return self::DIRECTORY.'/logo.jpg';
    }

    private function iconPath(int $size): string
    {
        return self::DIRECTORY."/icone-{$size}.png";
    }

    private function disk(): Filesystem
    {
        return Storage::disk('patient_photos');
    }
}
