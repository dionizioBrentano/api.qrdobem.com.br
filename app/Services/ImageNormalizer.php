<?php

namespace App\Services;

use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\File;

class ImageNormalizer
{
    /**
     * POST multipart campo file
     * aceita image/jpeg, image/jpg, image/png, image/webp
     * API devolve JPEG lado máx. 1600 px quality 80
     * vídeo (mp4/quicktime) NÃO passa no normalizador
     *
     * @param UploadedFile $file
     * @return File
     * @throws Exception
     */
    public function normalize(UploadedFile $file): File
    {
        $mime = $file->getMimeType();
        $imagePath = $file->getRealPath();

        $image = match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($imagePath),
            'image/png' => @imagecreatefrompng($imagePath),
            'image/webp' => @imagecreatefromwebp($imagePath),
            default => throw new Exception("Formato não suportado: {$mime}"),
        };

        if (!$image) {
            throw new Exception('Falha ao abrir a imagem com o GD nativo.');
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $maxSide = config('media.max_side', 1600);
        $quality = config('media.jpeg_quality', 80);

        if ($width > $maxSide || $height > $maxSide) {
            if ($width > $height) {
                $newWidth = $maxSide;
                $newHeight = (int) ($height * ($maxSide / $width));
            } else {
                $newHeight = $maxSide;
                $newWidth = (int) ($width * ($maxSide / $height));
            }
        } else {
            $newWidth = $width;
            $newHeight = $height;
        }

        $newImage = imagecreatetruecolor($newWidth, $newHeight);

        if ($mime === 'image/png' || $mime === 'image/webp') {
            $white = imagecolorallocate($newImage, 255, 255, 255);
            imagefill($newImage, 0, 0, $white);
        }

        imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $tempPath = tempnam(sys_get_temp_dir(), 'img_norm_');

        if (!imagejpeg($newImage, $tempPath, $quality)) {
            imagedestroy($image);
            imagedestroy($newImage);
            throw new Exception('Falha ao salvar a imagem em JPEG.');
        }

        imagedestroy($image);
        imagedestroy($newImage);

        return new File($tempPath);
    }
}
