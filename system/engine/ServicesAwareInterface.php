<?php

declare(strict_types=1);

namespace System\Engine;

/**
 * Controller'a servis kümesini setter ile veren sözleşme.
 *
 * NEDEN SETTER, CONSTRUCTOR DEĞİL: controller'lar kendi domain
 * bağımlılıklarını (model, servis) constructor'dan alır ve bu imzalar temiz
 * kalmalı. `ControllerServices`'i her controller'ın constructor'ına eklemek
 * 20 dosyayı gürültüyle doldururdu.
 *
 * `ContainerAwareInterface`'in yerini alır ama önemli bir farkla: verilen şey
 * artık CONTAINER değil, bağımlılıkları BİLDİRİLMİŞ bir façade. Yani setter
 * injection sürüyor ama service locator yüzeyi kapandı — controller keyfi
 * servis çekemez, derleyici de neye bağımlı olduğunu görür.
 */
interface ServicesAwareInterface
{
    public function setServices(ControllerServices $services): void;
}
