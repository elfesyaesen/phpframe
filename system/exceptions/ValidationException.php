<?php

declare(strict_types=1);

namespace System\Exceptions;

use System\Helpers\Status;
use Throwable;

class ValidationException extends HttpException
{
    public function __construct(
        private readonly array $errors,
        string $message = 'Doğrulama işlemi başarısız',
        array $headers = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, Status::UNPROCESSABLE_ENTITY->value, $headers, ['errors' => $errors], $previous);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Taban sınıfla aynı sözleşme: `error` nesnesi + geçiş `message` anahtarı.
     *
     * HTML kaçışı KALDIRILDI — gerekçesi `HttpException::sanitizeContext()`
     * içinde: gövde `application/json` olarak yayınlanıyor, `htmlspecialchars`
     * orada yalnızca veriyi bozuyordu. Doğrulama hataları alan adı ve kullanıcı
     * girdisi taşıdığı için bozulma bu sınıfta en görünür haldeydi.
     */
    public function toArray(bool $debug = false): array
    {
        $data = [
            'error' => [
                'code' => $this->statusCode,
                'message' => $this->getMessage(),
                'errors' => $this->errors,
            ],
            // Geçiş anahtarı — bkz. HttpException::toArray().
            'message' => $this->getMessage(),
        ];

        if ($debug) {
            $data['error']['debug'] = [
                'exception' => static::class,
                // Tam path yerine sadece dosya adı (bilgi sızıntısını önler).
                'file' => basename($this->getFile()),
                'line' => $this->getLine(),
            ];
        }

        return $data;
    }
}
