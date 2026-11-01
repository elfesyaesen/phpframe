<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\ParameterizedRuleInterface;
use finfo;

final class File implements ParameterizedRuleInterface
{
    private ?string $type;
    private int $maxSizeKb;
    private string $failedRule = '';

    /**
     * @var array<string, array<string>>
     */
    private static array $mimeTypes = [
        'image' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
        ],
        'document' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'text/csv',
        ],
        'video' => [
            'video/mp4',
            'video/webm',
            'video/ogg',
            'video/quicktime',
        ],
        'audio' => [
            'audio/mpeg',
            'audio/mp3',
            'audio/wav',
            'audio/ogg',
        ],
        'archive' => [
            'application/zip',
            'application/x-rar-compressed',
            'application/x-7z-compressed',
            'application/gzip',
        ],
    ];

    /**
     * @var array<string>
     */
    private static array $dangerousExtensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht', 'phar',
        'exe', 'com', 'bat', 'cmd', 'sh', 'bash',
        'js', 'vbs', 'wsf', 'wsh', 'ps1',
        'htaccess', 'htpasswd',
    ];

    public function __construct(?string $type = null, int $maxSizeKb = 5120)
    {
        $this->type = $type;
        $this->maxSizeKb = $maxSizeKb;
    }

    public function setParameters(array $params): self
    {
        foreach ($params as $param) {
            $param = trim((string) $param);

            if ($param === '') {
                continue;
            }

            if (is_numeric($param)) {
                $this->maxSizeKb = (int) $param;
            } elseif (isset(self::$mimeTypes[$param])) {
                $this->type = $param;
            }
        }

        return $this;
    }

    public function passes(mixed $value): bool
    {
        if (!is_array($value) || !isset($value['tmp_name'], $value['error'])) {
            $this->failedRule = 'format';
            return false;
        }

        if ($value['error'] !== UPLOAD_ERR_OK) {
            $this->failedRule = 'upload';
            return false;
        }

        if (!is_uploaded_file($value['tmp_name'])) {
            $this->failedRule = 'uploaded';
            return false;
        }

        $sizeKb = ($value['size'] ?? 0) / 1024;
        if ($sizeKb > $this->maxSizeKb) {
            $this->failedRule = 'size';
            return false;
        }

        $extension = strtolower(pathinfo($value['name'] ?? '', PATHINFO_EXTENSION));
        if (in_array($extension, self::$dangerousExtensions, true)) {
            $this->failedRule = 'extension';
            return false;
        }

        if ($this->type !== null) {
            $allowedMimes = self::$mimeTypes[$this->type] ?? [];

            if (!empty($allowedMimes)) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($value['tmp_name']);

                if ($mimeType === false || !in_array($mimeType, $allowedMimes, true)) {
                    $this->failedRule = 'mime';
                    return false;
                }
            }
        }

        return true;
    }

    public function message(): string
    {
        return match ($this->failedRule) {
            'format' => ':field geçerli bir dosya formatında olmalıdır',
            'upload' => ':field yüklenirken bir hata oluştu',
            'uploaded' => ':field geçerli bir yüklenmiş dosya olmalıdır',
            'size' => ':field en fazla ' . $this->maxSizeKb . ' KB olabilir',
            'extension' => ':field bu uzantıya izin verilmiyor',
            'mime' => ':field geçerli bir ' . ($this->type ?? 'dosya') . ' tipinde olmalıdır',
            default => ':field geçerli bir dosya olmalıdır',
        };
    }

    public static function image(int $maxSizeKb = 5120): self
    {
        return new self('image', $maxSizeKb);
    }

    public static function document(int $maxSizeKb = 10240): self
    {
        return new self('document', $maxSizeKb);
    }

    public static function video(int $maxSizeKb = 102400): self
    {
        return new self('video', $maxSizeKb);
    }
    public function messageKey(): string
    {
        return match ($this->failedRule) {
            'upload', 'uploaded', 'format' => 'validation.file.upload',
            'size'                         => 'validation.file.size',
            'extension', 'mime'            => 'validation.file.type',
            default                        => 'validation.file.upload',
        };
    }

    public function messageParams(): array
    {
        return ['max' => $this->maxSizeKb, 'type' => $this->type ?? ''];
    }
}
