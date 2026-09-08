<?php

declare(strict_types=1);

namespace System\Logging\Formatters;

use System\Logging\Contracts\FormatterInterface;
use System\Logging\LogRecord;

class JsonFormatter implements FormatterInterface
{
    public function __construct(
        private bool $prettyPrint = true
    ) {}

    public function format(LogRecord $record): string
    {
        $data = [
            'time'    => $record->datetime->format('Y-m-d H:i:s.u'),
            'level'   => strtoupper($record->level->value),
            'message' => $record->message,
        ];

        if ($record->context !== []) {
            $data['context'] = $record->context;
        }

        // PARTIAL_OUTPUT_ON_ERROR + INVALID_UTF8_SUBSTITUTE: json_encode'un bozuk UTF-8
        // veya recursion'da false dönüp boş log satırı yazmasını (sessiz log kaybı) önler.
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

        if ($this->prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($data, $flags);

        // Yine de başarısızsa en azından zaman + seviye + mesajı kaybetme.
        if ($json === false) {
            $json = json_encode([
                'time'    => $record->datetime->format('Y-m-d H:i:s.u'),
                'level'   => strtoupper($record->level->value),
                'message' => $record->message,
                'context' => '[json_encode failed: ' . json_last_error_msg() . ']',
            ], JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        return $json . PHP_EOL;
    }
}
