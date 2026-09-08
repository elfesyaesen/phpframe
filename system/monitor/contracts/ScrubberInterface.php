<?php

declare(strict_types=1);

namespace System\Monitor\Contracts;

/**
 * Hassas veri maskeleme sözleşmesi.
 *
 * Monitor; istek gövdesi, yanıt gövdesi, header'lar ve sorgu parametrelerini
 * kalıcı olarak saklar. Bu üç kanalın TAMAMI aynı scrubber'dan geçmek
 * zorundadır: kaynak projede yalnızca istek gövdesi maskeleniyordu ve
 * `POST /auth/login` yanıtındaki access/refresh token'lar düz metin olarak
 * log tablosuna yazılıyordu. Tek bir maskeleme kaynağı bu sınıf hatasını
 * yapısal olarak imkânsız kılar.
 */
interface ScrubberInterface
{
    /**
     * Diziyi özyinelemeli tarar; hassas anahtarların değerlerini maskeler.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function scrub(array $data): array;

    /**
     * Ham gövde metnini maskeler ve $maxBytes'a kırpar.
     *
     * JSON veya form-urlencoded olarak çözülebiliyorsa yapılandırılmış biçimde
     * maskelenip yeniden serileştirilir; çözülemiyorsa (binary, HTML, düz metin)
     * içerik korunur ve yalnızca kırpma uygulanır — bu durumda maskeleme
     * yapılamayacağı için gövdenin hassas olabileceği çağıran tarafın bilgisidir.
     */
    public function scrubText(string $body, int $maxBytes): string;
}
