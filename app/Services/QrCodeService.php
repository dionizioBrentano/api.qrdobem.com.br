<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Log;

/**
 * Geração de QR Codes.
 *
 * A API é whitelabel: vários frontends consomem os mesmos dados. Por isso a URL
 * pública e a imagem do QR são montadas aqui, no servidor, e não em cada cliente.
 *
 * O formato é SVG (vetorial): a mesma imagem serve para tela e para impressão
 * em qualquer tamanho, sem perda de qualidade — importante porque a etiqueta
 * pode ser impressa em coleira, pulseira ou adesivo.
 */
class QrCodeService
{
    /**
     * Monta a URL pública que fica gravada dentro do QR Code.
     */
    public function urlFor(string $uniqueCode): string
    {
        $base = config('qrdobem.public_base_url');
        $prefix = config('qrdobem.public_path_prefix');

        return "{$base}/{$prefix}/{$uniqueCode}";
    }

    /**
     * Gera o SVG do QR Code de uma entidade.
     *
     * @return string|null SVG cru, ou null se a biblioteca ainda não estiver instalada.
     */
    public function svgFor(string $uniqueCode, ?int $size = null): ?string
    {
        return $this->svgForUrl($this->urlFor($uniqueCode), $size);
    }

    /**
     * Gera o SVG de qualquer conteúdo/URL.
     */
    public function svgForUrl(string $url, ?int $size = null): ?string
    {
        if (!$this->isAvailable()) {
            Log::warning('QrCodeService: bacon/bacon-qr-code não instalado. Rode "composer install" no servidor.');

            return null;
        }

        try {
            $renderer = new ImageRenderer(
                new RendererStyle(
                    $size ?? (int) config('qrdobem.size', 512),
                    (int) config('qrdobem.margin', 4)
                ),
                new SvgImageBackEnd()
            );

            return (new Writer($renderer))->writeString(
                $url,
                Encoder::DEFAULT_BYTE_MODE_ECODING,
                $this->errorCorrectionLevel()
            );
        } catch (\Throwable $e) {
            Log::error('QrCodeService: falha ao gerar QR Code. ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Gera o QR Code já pronto para uso em <img src="...">.
     */
    public function dataUriFor(string $uniqueCode, ?int $size = null): ?string
    {
        $svg = $this->svgFor($uniqueCode, $size);

        if ($svg === null) {
            return null;
        }

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * A biblioteca só existe depois do "composer install" rodar no servidor.
     * Sem essa checagem, um deploy pela metade derrubaria a criação de entidades.
     */
    public function isAvailable(): bool
    {
        return class_exists(Writer::class);
    }

    /**
     * Compatibilidade entre bacon-qr-code v2 (dasprid/enum) e v3 (enum nativo).
     */
    private function errorCorrectionLevel(): ErrorCorrectionLevel
    {
        $level = strtoupper((string) config('qrdobem.error_correction', 'Q'));

        if (!in_array($level, ['L', 'M', 'Q', 'H'], true)) {
            $level = 'Q';
        }

        // v3: enum nativo do PHP → ErrorCorrectionLevel::Q
        if (enum_exists(ErrorCorrectionLevel::class)) {
            return constant(ErrorCorrectionLevel::class . '::' . $level);
        }

        // v2: dasprid/enum → ErrorCorrectionLevel::Q()
        return ErrorCorrectionLevel::{$level}();
    }
}
