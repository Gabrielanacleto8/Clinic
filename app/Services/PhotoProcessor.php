<?php

namespace App\Services;

use App\Exceptions\InvalidPhotoException;
use GdImage;
use Illuminate\Http\UploadedFile;

/**
 * Converte a foto enviada em JPEG novo, gerado a partir dos pixels.
 *
 * Recodificar é a principal defesa do upload: descarta metadados (GPS,
 * modelo do aparelho, data) e qualquer conteúdo embutido no arquivo, e
 * garante que só imagens de verdade são gravadas.
 */
class PhotoProcessor
{
    private const ALLOWED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * @return array{image: string, thumbnail: string, width: int, height: int}
     *
     * @throws InvalidPhotoException
     */
    public function process(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        // Lê só o cabeçalho: tipo real e dimensões, sem confiar na extensão.
        $info = $path ? @getimagesize($path) : false;

        if ($info === false || ! in_array($info[2], self::ALLOWED_TYPES, true)) {
            throw new InvalidPhotoException('O arquivo não é uma imagem JPEG, PNG ou WebP válida.');
        }

        [$width, $height] = $info;

        // Barra "bombas de descompressão" antes de alocar memória para os pixels.
        if ($width < 1 || $height < 1 || $width * $height > config('clinic.photos.max_megapixels') * 1_000_000) {
            throw new InvalidPhotoException('A imagem tem dimensões grandes demais.');
        }

        $this->ensureMemoryFor($width, $height);

        $source = @imagecreatefromstring((string) file_get_contents($path));

        if (! $source instanceof GdImage) {
            throw new InvalidPhotoException('Não foi possível ler a imagem enviada.');
        }

        $source = $this->applyOrientation($source, $info[2] === IMAGETYPE_JPEG ? $path : null);

        $image = $this->fit($source, (int) config('clinic.photos.max_dimension'));
        $thumbnail = $this->fit($source, (int) config('clinic.photos.thumbnail_dimension'));

        return [
            'image' => $this->encode($image, 85),
            'thumbnail' => $this->encode($thumbnail, 80),
            'width' => imagesx($image),
            'height' => imagesy($image),
        ];
    }

    /**
     * Celulares gravam a foto "deitada" e indicam a rotação no EXIF. Como o
     * EXIF é descartado, a rotação precisa ser aplicada nos pixels.
     */
    private function applyOrientation(GdImage $image, ?string $jpegPath): GdImage
    {
        if ($jpegPath === null || ! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($jpegPath)['Orientation'] ?? 1);

        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            $image = $rotated instanceof GdImage ? $rotated : $image;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    /**
     * Reduz para caber em $max pixels no maior lado (nunca amplia) e achata
     * transparências sobre fundo branco, já que o JPEG não tem canal alfa.
     */
    private function fit(GdImage $source, int $max): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $max / max($width, $height));

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $target;
    }

    private function encode(GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    /**
     * O GD mantém os pixels descompactados na memória. Eleva o limite só
     * nesta requisição, o suficiente para o tamanho máximo aceito.
     */
    private function ensureMemoryFor(int $width, int $height): void
    {
        $needed = (int) ($width * $height * 5 * 2.2) + 64 * 1024 * 1024;
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));

        if ($limit > 0 && $limit < $needed) {
            @ini_set('memory_limit', (string) $needed);
        }
    }
}
