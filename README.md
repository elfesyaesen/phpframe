# PHPFrame

**Compiled, DI-first PHP 8.5 uygulama framework'ü.**

Servis grafiği build zamanında doğrulanır ve düz PHP koduna derlenir; production'da
runtime reflection, graf yürüyüşü ve autowiring **yoktur**. Buna radix-tree router,
request-scoped servis yaşam döngüsü ve yerleşik istek/sorgu izleme eşlik eder.

<details>
<summary><b>"Compiled" ve "DI-first" ne demek — ölçülebilir tanım</b></summary>

Bu iki etiket pazarlama sıfatı değil; her biri doğrulanabilir bir davranışa
karşılık geliyor.

**Compiled.** `php frame container:compile` servis başına bir metot içeren düz
bir PHP sınıfı üretir. Üretilen kodda string ile servis arama, reflection ve
graf yürüyüşü yoktur — kurucu çağrıları literaldir:

```php
// system/cache/container/CompiledContainer.<hash>.php — ÜRETİLMİŞ, elle düzenlenmez
    protected function get_Api_Services_UserService(): \Api\Services\UserService
    {
        return $this->scopedInstance(\Api\Services\UserService::class, fn(): mixed => new \Api\Services\UserService(
            $this->get_Api_Models_UserModel(), // $users
            $this->get_Api_Models_AuthModel(), // $auth
            $this->get_Api_Models_PasswordResetModel(), // $resets
            $this->get_Api_Services_AuthService(), // $sessions
            $this->get_Api_Services_MailService(), // $mail
            $this->get_System_Security_PasswordHasher(), // $passwords
            $this->get_System_Database_Database(), // $database
            $this->get_Api_Services_Translator(), // $translator
            $this->get_System_Config_AppConfig(), // $app
            $this->get_System_Config_AuthConfig(), // $auth_config
        ));
    }
```

Production'da `Kernel` **yalnızca** bu dosyayı yükler. Derleme eksik veya bayatsa
boot **hard fatal** ile durur; sessiz reflection fallback **yoktur**:

```
$ php frame app:health          # APP_PRODUCTION=true, derleme eksik
[CONTAINER_NOT_COMPILED] Production derlenmiş container olmadan çalışamaz.
  …/CompiledContainer.<hash>.php  ✗ dosya yok
  Production'da runtime'da derleme YAPILMAZ ve reflection fallback YOKTUR.
```

Bu, "derlenmiş container'ı opsiyonel bir hızlandırıcı" olarak sunan yaklaşımdan
kasten farklı: fallback olduğunda bir servisin derlemeden düşmesi haftalarca
fark edilmez.

Dev modu farklıdır ve olması gerektiği gibidir: provider'lardan reflection ile
build edilir ve **her boot'ta doğrulanır**, böylece hata anında görünür.

**DI-first.** Bağımlılıklar yalnızca constructor'dan gelir. Framework'te service
locator, facade, global helper fonksiyonu veya `Container::getInstance()` yoktur;
`BaseController` bile container tutmaz — bağımlılıkları bildirilmiş bir façade
üzerinden erişir. Sonucu şu: **grafik statik olarak bilinebilir**, dolayısıyla
denetlenebilir. `container:validate` bunu build kapısına çevirir:

| Yakalanan | Nasıl |
|---|---|
| Bağımlılık döngüsü | Graf analizi |
| Eksik binding | Modül öneki dışı sınıflar bağlanmak zorunda |
| Singleton→Scoped ihlali | Lifetime uyumluluk kontrolü |
| Bağımlılığını bildirmemiş factory | `dependsOn` beyanı yoksa graf-opak uyarısı |
| Geçersiz middleware alias'ı | Her alias hedefi çözülebilir `MiddlewareInterface` mi |
| İki provider aynı id'yi tanımlamış | `provides()` manifestleri çakışıyor mu |

Bu listenin tamamı **build zamanında** çalışır — ilk istekte değil.

</details>

| | |
|---|---|
| **PHP** | 8.5+ (zorunlu — bootstrap'ta sürüm bariyeri var) |
| **Veritabanı** | MySQL / MariaDB (PDO) |
| **Cache** | Redis, array veya null |
| **Bağımlılık** | 5 Composer paketi (JWT, PHPMailer, PSR-11, symfony/translation, Twig) |
| **Boyut** | 32.752 satır `system/` (271 dosya) · 38.818 satır first-party toplam — üretilmiş cache hariç |
| **Lisans** | [MIT](LICENSE) — kullanın, değiştirin, dağıtın, satın; tek koşul telif bildirimini korumak |

---

## İçindekiler

- [Neden bu framework](#neden-bu-framework)
- [Kurulum](#kurulum)
- [Dizin yapısı](#dizin-yapısı)
- [Autoloading kuralı](#autoloading-kuralı)
- [Yapılandırma](#yapılandırma)
- [DI container](#di-container)
- [Routing](#routing)
- [Middleware](#middleware)
- [Katman sözleşmesi](#katman-sözleşmesi)
- [Controller](#controller)
- [Service](#service)
- [Model](#model)
- [Doğrulama](#doğrulama)
- [Hata yönetimi](#hata-yönetimi)
- [Veritabanı ve migration](#veritabanı-ve-migration)
- [Yetkilendirme (RBAC)](#yetkilendirme-rbac)
- [Çeviri](#çeviri)
- [Dosya depolama](#dosya-depolama)
- [Cache](#cache)
- [Olaylar](#olaylar)
- [Gözlemlenebilirlik (Monitor)](#gözlemlenebilirlik-monitor)
- [CLI komutları](#cli-komutları)
- [Test](#test)
- [Yeni modül eklemek](#yeni-modül-eklemek)
- [Production'a alma](#productiona-alma)
- [Yayınlamadan önce](#yayınlamadan-önce)

---

## Neden bu framework

Üç tasarım kararı diğer her şeyi belirliyor.

### 1. Container derlenir, runtime'da reflection yapmaz

Servis tanımları **bildirimseldir** ve provider'larda yaşar. `php frame container:compile`
tanımları doğrulayıp tek bir PHP dosyası üretir; production'da hiç reflection çalışmaz.

Pratik sonucu: **yapılandırma hataları build zamanında yakalanır, ilk istekte değil.**

```
$ php frame container:validate
 [SUCCESS] Doğrulama temiz — 100 servis.
```

Yakalanan hata sınıfları: bağımlılık döngüleri, eksik binding, Singleton→Scoped
scope ihlalleri, bağımlılığını bildirmemiş factory'ler, geçersiz middleware alias'ları.

### 2. Servis yaşam döngüsü isteğe göre — ve bu derleyici tarafından zorlanıyor

PHP-FPM'de bir worker ardışık birçok isteği aynı process'te karşılar. Yani
`singleton` = **worker ömrü boyunca tek örnek**. Mutable istek durumu taşıyan bir
servisi singleton yapmak, bir isteğin verisini sonraki isteğe sızdırır — ve bu
dev ortamında **asla görünmez**, çünkü orada her worker tek istek görür.

PHPFrame üç yaşam döngüsü tanır ve bir singleton'ın scoped bir servise bağımlı
olmasını **derleme hatası** sayar:

| Lifetime | Ömür | Örnek |
|---|---|---|
| `singleton` | Süreç | `Database`, `FileStorageInterface`, `PasswordHasher` |
| `scoped` | Tek istek | `Request`, `Response`, `Translator`, `Gate`, `Recorder` |
| `transient` | Her çözümlemede yeni | `Validator` |

### 3. Her yanıt `return` ile biter — `exit` yok

`Response::jsonResponse()` çıktıyı yazar ve **döner**. `exit` çağırmaz. Bunun üç
somut kazancı var: "after" middleware mantığı gerçekten çalışır, `finally` blokları
gerçekten çalışır (request scope temizliği buna bağlı), ve çift gönderim
`LogicException` ile yakalanır.

```php
$this->response()->jsonResponse(Status::OK, $data);
return;   // ← bu satır zorunlu
```

---

## Kurulum

```bash
git clone <repo-url> phpframe
cd phpframe

composer install
cp .env.example .env
```

`.env` içinde en az şunları doldurun:

```ini
DB_HOST=127.0.0.1
DB_NAME=phpframe
DB_USER=root
DB_PASS=

# 64+ karakterlik rastgele değer üretin:
#   php -r "echo bin2hex(random_bytes(32));"
SECRET_KEY=
```

Sonra:

```bash
php frame migrate          # şemayı kurar
php frame app:health       # DB/Redis/migration/cache hazırlık kontrolü
php frame serve            # http://127.0.0.1:8000
```

`app:health` çıktısı deploy probe'u olarak kullanılmak üzere tasarlandı:

```
  Durum  │  Kontrol                │  Detay
  ───────┼─────────────────────────┼──────────────────────────────
  ✓ OK   │  PHP sürümü             │  8.5.6
  ✓ OK   │  PDO sürücüsü           │  mysql
  ✓ OK   │  Veritabanı bağlantısı  │  root@127.0.0.1:3306/phpframe
  ✓ OK   │  Migration durumu       │  güncel
  ✓ OK   │  Redis cache            │  127.0.0.1:6379 db0
  ✓ OK   │  Derlenmiş container    │  güncel (<hash>…)
```

### Web sunucusu

**DocumentRoot `public/` olmalıdır.** Kök dizindeki `.htaccess` yalnızca bir
güvenlik ağıdır — Apache yanlış yapılandırılırsa dizin listelemeyi kapatır.

Apache için `public/.htaccess` hazır gelir. Nginx:

```nginx
root /path/to/phpframe/public;
index index.php;

location / {
    try_files $uri $uri/ /index.php?route=$uri&$args;
}

location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

> **`Authorization` başlığı:** bazı FastCGI kurulumları bu başlığı düşürür ve
> tüm kimlik doğrulaması sessizce çalışmaz hale gelir. `.htaccess` bunu
> `RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]` ile geri koyar. Nginx'te
> `fastcgi_params` genellikle yeterlidir; kimlik doğrulaması çalışmıyorsa
> **ilk buraya bakın**.

---

## Dizin yapısı

```
phpframe/
├── public/              ← DocumentRoot. index.php + swagger.json
├── config/
│   ├── config.php       Bootstrap primitive'leri (APP_ROOT, Env, Autoloader)
│   ├── providers.php    Servis tanımlarının TEK kaynağı
│   └── middleware.php   Middleware alias → sınıf eşlemesi
├── routes/routes.php    Tüm HTTP route tanımları
├── system/              Framework. Namespace: System\
│   ├── container/       DI: builder, derleyici, doğrulayıcı, scope yöneticisi
│   ├── routing/         RadixTree router + attribute tarayıcı
│   ├── http/            Request, Response
│   ├── engine/          BaseController, BaseModel, Autoloader, Twig
│   ├── database/        Database, Schema, Blueprint, Migration
│   ├── validation/      Validator + 25 kural
│   ├── console/         CLI çekirdeği + 21 komut
│   ├── config/          12 tipli config objesi
│   ├── monitor/         İstek/sorgu/exception izleme
│   ├── middleware/      Pipeline + RateLimit
│   ├── security/        PasswordHasher, RateLimiter, GateInterface
│   ├── storage/         Dosya depolama soyutlaması
│   ├── cache/           Redis / array / null
│   ├── event/           EventDispatcher
│   └── exceptions/      HTTP exception hiyerarşisi
├── api/                 ← UYGULAMA MODÜLÜ. Namespace: Api\
│   ├── controllers/     HTTP
│   ├── services/        İş kuralları
│   ├── models/          Tek tablo/agrega, SQL
│   ├── middleware/      Auth, Role, Permission
│   ├── exceptions/      Domain exception'ları
│   └── provider/        ApiProvider — modülün DI kayıtları
├── monitor/             ← Modül: gözlemlenebilirlik arayüzü (salt okunur)
├── migration/           Migration dosyaları
├── lang/                Çeviri katalogları
└── frame                CLI giriş noktası
```

---

## Autoloading kuralı

First-party kod (`System\`, `Api\`, `Monitor\` ve eklediğiniz modüller) için:

> **Namespace segmentleri KÜÇÜK HARFE çevrilir, sınıf adı OLDUĞU GİBİ kalır.**

```
Api\Services\UserService     →  api/services/UserService.php
System\Http\Request          →  system/http/Request.php
Monitor\Models\MonitorModel  →  monitor/models/MonitorModel.php
```

Bu **PSR-4 değildir** ve bilinçlidir: dizin adları küçük harf kalır. Üçüncü parti
kodun tamamı Composer'ın kendi PSR-4 loader'ından gelir — `vendor/` tek kanaldır,
`system/` altına elle kütüphane kopyalanmaz.

---

## Yapılandırma

`.env` okunur → 12 **tipli readonly config objesine** dönüştürülür → container'a
**instance** olarak girer. Global sabit yoktur (`APP_ROOT` istisnadır; o
Autoloader'ın herhangi bir obje var olmadan ihtiyaç duyduğu bir bootstrap
primitive'idir).

Config'i enjekte ederek okursunuz:

```php
use System\Config\AppConfig;
use System\Config\AuthConfig;

final class InvoiceService
{
    public function __construct(
        private readonly AppConfig $app,
        private readonly AuthConfig $auth,
    ) {}

    public function pdfUrl(string $uuid): string
    {
        return $this->app->url("invoices/{$uuid}.pdf");
    }
}
```

Bunun sabit okumaya göre üç faydası var: bağımlılık **imzada görünür** (dolayısıyla
derleyici doğrulayabilir), test edilebilir, ve config **derlenmiş container'a
gömülmez** — `DB_PASS`/`SECRET_KEY` üretilen PHP dosyasına asla yazılmaz.

### Config objeleri

| Sınıf | Alanlar |
|---|---|
| `AppConfig` | `root`, `url`, `production`, `timezone` · `path()`, `url()`, `isDebug()` |
| `AuthConfig` | `secretKey`, `accessTokenExpire`, `refreshTokenExpire`, `passwordResetExpire`, `passwordResetUrl`, `passwordResetMaxAttempts` · `resetLink()`, `hasStrongSecret()` |
| `DatabaseConfig` | `driver`, `host`, `port`, `name`, `user`, `password`, `charset`, `prefix`, `persistent`, `timeout`, `tls` · `dsn()`, `table()` |
| `CacheConfig` | `driver`, `prefix`, `defaultTtl`, `redis` · `usesRedis()` |
| `RedisConfig` | `host`, `port`, `password`, `database`, `persistent` |
| `HttpConfig` | `trustedProxies`, `corsOrigins` · `trustsProxy()`, `allowsOrigin()` |
| `MailConfig` | `protocol`, `host`, `port`, `username`, `password`, `title`, `verifyTls` · `isConfigured()` |
| `LogConfig` | `rotationPeriod`, `retention`, `maxFileSizeMb`, `directory` |
| `MonitorConfig` | 15 alan — örnekleme, yavaş eşikleri, gövde yakalama, saklama süresi |
| `RateLimitConfig` | `maxAttempts`, `decaySeconds` |
| `MysqlTlsConfig` | `ca`, `certificate`, `key`, `verifyServerCertificate` |
| `UpstreamConfig` | `productsUrl`, `productsTimeout` |

Tüm ortam değişkenleri açıklamalarıyla `.env.example` içinde (59 değişken).

### ⚠ Boolean ayrıştırması katıdır — sessizce `false` olur

`.env` içindeki boolean değerler yalnızca şu değerleri **doğru** sayar:

```
1   true   yes   on
```

Bunların dışındaki **her şey** — `False`, `0`, `no`, boş değer ve **yazım
hataları** — `false` olur ve **hiçbir uyarı verilmez**.

Tehlikeli yön şudur: production sunucusunda

```ini
APP_PRODUCTION=ture      # ← yazım hatası
```

yazarsanız uygulama **sessizce development modunda açılır** — stack trace'ler
istemciye gider, derlenmiş container kullanılmaz. Deploy'dan sonra **her zaman
doğrulayın**:

```bash
php frame app:about | head -8      # "Ortam  production" yazmalı
```

Aynı katılık `SMTP_VERIFY_TLS`, `DB_SSL`, `MONITOR_ENABLED` ve diğer tüm boolean
ayarlar için geçerlidir. Bu ayarlarda varsayılanlar bilinçli olarak **güvenli
tarafa** düşer (ör. `SMTP_VERIFY_TLS` varsayılanı `true`), ama açıkça yazılan
hatalı bir değer `false` olarak okunur.

---

## DI container

### Servis kaydetmek

Kayıtlar **provider** sınıflarında yaşar. Provider iki fazlıdır:

- `register()` → **SAF**. Yalnızca tanım. Derlenir.
- `boot()` → yan etkiler. Asla derlenmez.

`register()` içinde **yapmayın**: servis çözmek, `$_SERVER`/`php://input` okumak,
`header()`/`ob_start()`/`register_shutdown_function()`, dosya veya DB açmak.

```php
<?php

declare(strict_types=1);

namespace Shop\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use Shop\Services\CartService;
use Shop\Services\PricingService;
use Shop\Contract\TaxCalculatorInterface;
use Shop\Services\TrTaxCalculator;
use System\Config\AppConfig;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Lifetime\Lifetime;
use System\Container\Provider\AbstractServiceProvider;

final class ShopProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        // Süreç ömrü — durum taşımıyor
        $builder->singleton(PricingService::class);

        // İstek ömrü — Request'e bağımlı bir şeye dokunuyorsa ZORUNLU
        $builder->scoped(CartService::class);

        // Arayüz → uygulama
        $builder->alias(TaxCalculatorInterface::class, TrTaxCalculator::class);
        $builder->singleton(TrTaxCalculator::class);
    }

    public function provides(): array
    {
        return [
            PricingService::class,
            CartService::class,
            TaxCalculatorInterface::class,
            TrTaxCalculator::class,
        ];
    }
}
```

Sonra `config/providers.php` listesine ekleyin:

```php
return [
    // ... framework provider'ları
    Api\Provider\ApiProvider::class,
    Shop\Provider\ShopProvider::class,   // ← uygulama modülleri en sonda
];
```

> **`provides()` neden önemli:** iki provider aynı id'yi tanımlarsa **derleme hata
> verir**. Bildirmezseniz (boş dizi) çakışma kontrolüne girmez ve kazananı liste
> sırası belirler — yani deterministik olmaz.

> **`register()` sırası önemsizdir.** Tanımlar bildirimsel, çözümleme lazy. Bir
> provider kendisinden *sonra* gelen bir provider'ın servisine güvenle bağımlı
> olabilir.

### Builder API

| Metot | İş |
|---|---|
| `singleton($id, $concrete = null)` | Süreç ömürlü |
| `scoped($id, $concrete = null)` | İstek ömürlü |
| `transient($id, $concrete = null)` | Her çözümlemede yeni |
| `bind($id, $concrete, $lifetime)` | Açık lifetime ile |
| `factory($id, $callable, $lifetime, dependsOn: [...])` | Primitive parametre alan servisler |
| `instance($id, $object)` | Hazır obje (config'ler böyle girer) |
| `literal($id, $value)` | Skaler değer |
| `alias($id, $target)` | Arayüz → uygulama |
| `tag($ids, $tag)` | Etiketli koleksiyon |
| `decorate($id, $decorator)` | Dekoratör |
| `when($consumer)` | Bağlamsal binding |
| `cutEdge($from, $to, $reason)` | Döngü kırma (gerekçe zorunlu) |

### Primitive parametreler → factory

Autowire yalnızca sınıf tipli parametreleri çözebilir. `string $basePath` gibi bir
parametre autowire edilemez, factory gerekir — ve **bağımlılığı `dependsOn` ile
bildirilmelidir**, aksi halde grafik opak kalır ve scope doğrulaması onu görmez:

```php
final class StorageProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->factory(
            FileStorageInterface::class,
            [self::class, 'storage'],
            Lifetime::SINGLETON,
            dependsOn: [AppConfig::class],   // ← bildirilmezse doğrulama uyarır
        );
    }

    public static function storage(PsrContainerInterface $container): FileStorageInterface
    {
        return new LocalFileStorage(
            $container->get(AppConfig::class)->path('image')
        );
    }
}
```

### Örtük autowire yalnızca modül önekleri için

Container `System\` ve **keşfedilen modül** öneklerini örtük autowire'a açar.
Bir modül dizini şu koşulları sağlarsa keşfedilir: kök dizinde, adı `[a-z][a-z0-9_]*`
desenine uyuyor, rezerve isimlerden biri değil (`system`, `config`, `public`,
`vendor`, `migration`, `logs`, `lang`, `image`, `docs`, `tests`, …), ve içinde şu
alt dizinlerden **en az biri** var: `controllers`, `models`, `services`,
`middleware`, `security`, `views`.

Bu önekler dışındaki bir sınıfı bağlamayı unutursanız `container:validate` **hata
verir** — sessizce çalışmaya devam etmez.

### Container'ı incelemek

```bash
php frame container:list                      # tüm servisler: lifetime, kaynak, bağımlılık sayısı
php frame container:debug UserService         # bağımlılık ağacı + bulgular
php frame container:debug Database --reverse  # bu servisi KİM istiyor
php frame container:graph --format=mermaid    # grafiği dışa aktar
php frame container:validate                  # döngü, scope, factory, binding
php frame container:compile                   # doğrula → PHP üret → atomik yaz
```

---

## Routing

### Rota tanımlama

`routes/routes.php` içinde. Dosya iki yerel değişken bekler: `$router` ve
`$monitorConfig`.

```php
$router->get('/products', [ProductController::class, 'index'])->name('products.index');
$router->post('/products', [ProductController::class, 'store'])->name('products.store');
$router->put('/products/{uuid}', [ProductController::class, 'update']);
$router->delete('/products/{uuid}', [ProductController::class, 'destroy']);
$router->patch('/products/{uuid}', [ProductController::class, 'patch']);
$router->any('/webhook', [WebhookController::class, 'handle']);
```

Zincirlenebilir: `->name()`, `->middleware()`, `->params()`.

### Gruplar

```php
$router->group(['prefix' => '/v1/api', 'middleware' => ['api_auth']], function ($router) {
    $router->get('/users/me', [UserController::class, 'me'])->name('users.me');
    $router->put('/users/me', [UserController::class, 'updateProfile']);
});

$router->group(['prefix' => '/v1/api/admin', 'middleware' => ['api_auth', 'api_role:administrator']], function ($router) {
    $router->get('/roles', [RoleController::class, 'index']);
});
```

### Parametre adlandırması — dikkat

> Router path'i **küçük harfe normalize eder**. Bu yüzden parametre adları
> `snake_case` olmalıdır: `{user_uuid}` ✅, `{userUuid}` ❌ (sessizce eşleşmez).

### Attribute ile rota

Dosya tabanlı tanıma alternatif olarak controller metotlarına attribute
yazabilirsiniz:

```php
use System\Routing\Attributes\Route;
use System\Routing\Attributes\RouteGroup;
use System\Routing\Attributes\Middleware;

#[RouteGroup(prefix: '/v1/api/products')]
#[Middleware('api_auth')]
final class ProductController extends BaseController
{
    #[Route('/', methods: 'GET', name: 'products.index')]
    public function index(): void { /* ... */ }

    #[Route('/{uuid}', methods: ['GET', 'HEAD'], where: ['uuid' => '[0-9a-f-]{36}'], priority: 10)]
    #[Middleware('api_permission:products.read')]
    public function show(string $uuid): void { /* ... */ }
}
```

`Route` parametreleri: `path`, `methods`, `name`, `middleware`, `where`, `priority`.

### Route cache

Router bir **radix tree** kullanır ve production'da derlenmiş cache'ten okur.
Cache **yalnızca `APP_PRODUCTION=true` iken** aktiftir — dev'de `cache:warm`
bilinçli olarak uyarı verip çıkar, böylece rota değişikliği anında görünür.

```bash
php frame cache:warm     # route cache'i yeniden üretir (production)
php frame cache:clear    # Twig + route cache temizler
```

---

## Middleware

### Sözleşme

```php
namespace System\Middleware\Interface;

interface MiddlewareInterface
{
    /**
     * @param Closure(Request): void $next      Pipeline'ın geri kalanı
     * @param string|null            $parameter Alias'tan gelen ":param"
     */
    public function handle(
        Request $request,
        Response $response,
        Closure $next,
        ?string $parameter = null
    ): void;
}
```

> **İsteği reddetmenin TEK yolu `System\Exceptions\*` fırlatmaktır.**
>
> `$response` ile yanıt yazıp durmaya çalışmayın: `Response` artık `exit`
> etmediği için bu yanıtı gönderir ama **zinciri durdurmaz** — istek controller'a
> akmaya devam eder.

```php
<?php

declare(strict_types=1);

namespace Shop\Middleware;

use Closure;
use System\Exceptions\ForbiddenException;
use System\Http\Request;
use System\Http\Response;
use System\Middleware\Interface\MiddlewareInterface;

final class StoreOpenMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly StoreHours $hours) {}

    public function handle(Request $request, Response $response, Closure $next, ?string $parameter = null): void
    {
        if (!$this->hours->isOpen()) {
            throw new ForbiddenException('Mağaza şu anda kapalı.');
        }

        $next($request);   // ← devam etmek için ZORUNLU

        // $next() sonrası kod "after" mantığıdır ve GERÇEKTEN çalışır
        // (Response artık exit etmiyor).
    }
}
```

Alias'ı `config/middleware.php` içine yazın:

```php
return [
    'api_auth'       => \Api\Middleware\AuthMiddleware::class,
    'api_role'       => \Api\Middleware\RoleMiddleware::class,
    'api_permission' => \Api\Middleware\PermissionMiddleware::class,
    'rate_limit'     => \System\Middleware\RateLimitMiddleware::class,
    'store_open'     => \Shop\Middleware\StoreOpenMiddleware::class,
];
```

> Alias'ları burada tutmanın sebebi: `container:validate` her hedefin çözülebilir
> bir `MiddlewareInterface` olduğunu **derleme zamanında** doğrular. Yanlış yazılmış
> bir sınıf adı artık build hatasıdır — eskiden o korumalı route'a ilk istek
> geldiğinde ortaya çıkıyordu, yani **route sessizce korumasız kalabiliyordu**.

### Parametreli alias

Alias'tan sonraki `:` değeri `$parameter` olarak gelir:

```
->middleware('rate_limit:100,120')      100 istek / 120 saniye
->middleware('api_role:administrator')   $parameter = "administrator"
->middleware('api_permission:products.write')
```

Parametresiz `rate_limit` varsayılanlarını `RateLimitConfig`'ten alır.

---

## Katman sözleşmesi

```
Controller — YALNIZCA HTTP
  Request oku → TEK servis metodu çağır → jsonResponse(Status::X, $data)
  Sahibi : doğrulama kuralı dizileri, HTTP durum kodu seçimi
  Yasak  : password_*, Storage, Model, domain sonucu üzerinde koşul
  Her yanıt `return` ile biter.

Service — bir kullanım senaryosunun tamamı
  Model'leri ve altyapıyı orkestre eder.
  Sahibi : transaction, hash'leme, token, mail ve blob SIRALAMASI
  Başarısızlığı System\Exceptions\* FIRLATARAK bildirir.
  ASLA false / ['error' => ...] döndürmez. Request/Response/PDO'ya dokunmaz.

Model — tek tablo/agrega
  Yalnızca SQL + cache. Tipli veri veya null döner.
  Sürücü hatalarını (1062, FK RESTRICT) domain exception'ına çevirir.
```

**Doğrulama kuralları controller'da kalır** — istek-şekli sözleşmesidir.
**`Gate` middleware'de kalır** — scoped ve `Request`'e bağlı olduğu için
singleton bir servise enjekte edilmesi scope ihlali olur.

---

## Controller

```php
<?php

declare(strict_types=1);

namespace Shop\Controllers;

use Shop\Services\ProductService;
use System\Engine\BaseController;
use System\Helpers\Status;
use System\Validation\Validator;

final class ProductController extends BaseController
{
    public function __construct(
        private readonly ProductService $products,
        private readonly Validator $validator,
    ) {}

    public function index(): void
    {
        $this->response()->jsonResponse(Status::OK, $this->products->all());
        return;
    }

    public function store(): void
    {
        $validated = $this->validator->validate($this->request()->json(), [
            'name'  => 'required|string|max:120|unique:product,name',
            'price' => 'required|numeric|min:0',
            'sku'   => 'nullable|string|max:40',
        ]);

        $uuid = $this->products->create($validated);

        $this->response()->jsonResponse(Status::CREATED, ['uuid' => $uuid]);
        return;
    }

    public function destroy(string $uuid): void
    {
        $this->authorize('products.delete');   // yetki yoksa ForbiddenException

        $this->products->delete($uuid);

        $this->response()->jsonResponse(Status::NO_CONTENT, null);
        return;
    }
}
```

### `BaseController` yüzeyi

| Metot | Döner |
|---|---|
| `request()` | `Request` |
| `response()` | `Response` |
| `twig()` | `Twig\Environment` |
| `translator()` | `TranslatorInterface` |
| `gate()` | `GateInterface` |
| `can(string $permission)` | `bool` |
| `authorize(string $permission)` | `void` — yoksa `ForbiddenException` |
| `view(string $module, string $file, array $data = [])` | `string` (render edilmiş HTML) |

> `BaseController` container **tutmaz**. Bağımlılıkları bildirilmiş, scoped,
> readonly bir façade (`ControllerServices`) üzerinden erişir — böylece
> controller'ların gerçek bağımlılıkları derleyiciye görünür kalır.

### Request

```php
$r = $this->request();

$r->json();                    // JSON gövdesi → array
$r->input('email', 'yok');     // herhangi bir kaynaktan, default'lu
$r->all();  $r->only(['a']);  $r->except(['b']);  $r->has('a');
$r->query('page');  $r->post('name');  $r->put('x');  $r->patch('y');
$r->header('Authorization');
$r->files('avatar');
$r->method();  $r->path();  $r->ip();
$r->token();                   // Bearer token
$r->userUuid();  $r->authUser();   // AuthMiddleware bunları doldurur
```

### Response

```php
$this->response()->jsonResponse(Status::OK, $data);
$this->response()->htmlResponse(Status::OK, $html);
$this->response()->redirect('/login', 302);
return;   // ← her zaman
```

`Status` enum'u 61 HTTP durum kodu taşır (`Status::OK`, `Status::CREATED`,
`Status::NO_CONTENT`, `Status::CONFLICT`, `Status::UNPROCESSABLE_ENTITY`, …).
`int` de kabul edilir ama enum tercih edin.

### View render

```php
$html = $this->view('shop', 'products/index', ['products' => $products]);
$this->response()->htmlResponse(Status::OK, $html);
return;
```

İlk parametre **modül adıdır** ve `shop/views/products/index.twig` dosyasına
karşılık gelir. Her modül kendi Twig namespace'ini alır (`@shop/...`), böylece
farklı modüllerin aynı adlı şablonları çakışmaz. Modül adı tek segment olmak
zorundadır (`[a-z][a-z0-9_]*`) — slash/nokta içermediğinden path traversal
**yapısal olarak** imkânsızdır.

---

## Service

Servisler iş kurallarının tamamını taşır ve başarısızlığı **fırlatarak** bildirir.

```php
<?php

declare(strict_types=1);

namespace Shop\Services;

use Shop\Exceptions\OutOfStockException;
use Shop\Models\OrderModel;
use Shop\Models\StockModel;
use System\Database\Database;
use System\Exceptions\InternalServerException;
use System\Exceptions\NotFoundException;
use System\Translation\Contract\TranslatorInterface;

final class OrderService
{
    public function __construct(
        private readonly OrderModel $orders,
        private readonly StockModel $stock,
        private readonly Database $database,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * @throws NotFoundException   ürün yoksa
     * @throws OutOfStockException stok yetmezse
     */
    public function place(string $productUuid, int $quantity): string
    {
        $product = $this->stock->find($productUuid);

        if ($product === null) {
            throw new NotFoundException($this->translator->trans('order.product_missing'));
        }

        // Çok adımlı yazma → TEK transaction. Kapanış Database'in işi.
        return $this->database->transaction(function () use ($productUuid, $quantity): string {
            if (!$this->stock->reserve($productUuid, $quantity)) {
                throw new OutOfStockException();   // → rollback
            }

            $uuid = $this->orders->create($productUuid, $quantity);

            if ($uuid === null) {
                throw new InternalServerException($this->translator->trans('order.create_failed'));
            }

            return $uuid;
        });
    }
}
```

### `Database::transaction()`

```php
$uuid = $database->transaction(fn(): string => $this->orders->create($data));
$database->inTransaction();   // bool
```

Callback fırlatırsa **rollback** edilir ve exception yukarı çıkar. `inTransaction()`
bağlantı açmaz — lazy bağlantı korunur.

> **İç içe `transaction()` çağrısı bilinçli olarak hata fırlatır.** Savepoint
> desteği yok: test altyapısı olmayan bir kod tabanında sessiz kısmi rollback,
> gürültülü bir hatadan çok daha pahalıdır.

### Transaction neden `Database`'de, `BaseModel`'de değil

`BaseModel::pdo()` `protected` — servisler erişemez. `BaseModel`'e koymak
kontrolü Model'e geri verirdi ve iki farklı modeli (`OrderModel` + `StockModel`)
kapsayan tek bir senaryo ifade edilemezdi.

### Servis iskeleti üretmek

```bash
php frame make:service shop Order --model=OrderModel
```

Komut ayrıca **provider'a yapıştırılacak binding satırını yazdırır** — çünkü
bağlanmamış bir servis `container:validate`'te patlar.

---

## Model

Model yalnızca SQL ve cache bilir.

```php
<?php

declare(strict_types=1);

namespace Shop\Models;

use PDOException;
use Shop\Exceptions\DuplicateSkuException;
use System\Engine\BaseModel;
use System\Helpers\Uuid;

final class ProductModel extends BaseModel
{
    private const CACHE_TTL = 300;

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->remember(
            $this->cacheKey('product', 'all'),
            self::CACHE_TTL,
            fn() => $this->selectAll(
                'SELECT uuid, name, price FROM ' . $this->prefix() . 'product ORDER BY name'
            )
        );
    }

    /** @return array<string, mixed>|null */
    public function find(string $uuid): ?array
    {
        return $this->selectOne(
            'SELECT uuid, name, price, sku FROM ' . $this->prefix() . 'product WHERE uuid = :uuid',
            ['uuid' => $uuid]
        );
    }

    /** @throws DuplicateSkuException */
    public function create(string $name, float $price, ?string $sku): string
    {
        $uuid = Uuid::generate();

        $stmt = $this->pdo()->prepare(
            'INSERT INTO ' . $this->prefix() . 'product (uuid, name, price, sku)
             VALUES (:uuid, :name, :price, :sku)'
        );

        try {
            $stmt->execute(['uuid' => $uuid, 'name' => $name, 'price' => $price, 'sku' => $sku]);
        } catch (PDOException $e) {
            // Sürücü hatası → DOMAIN exception. Sentinel döndürmeyin.
            if ($this->isUniqueViolation($e)) {
                throw new DuplicateSkuException($sku ?? '', previous: $e);
            }
            throw $e;
        }

        $this->forget($this->cacheKey('product', 'all'));   // yazma → cache geçersiz

        return $uuid;
    }
}
```

### `BaseModel` yüzeyi

| Metot | İş |
|---|---|
| `prefix()` | Tablo öneki (`DB_PREFIX`) |
| `pdo()` | PDO — **lazy**, ilk çağrıda bağlanır |
| `selectAll($sql, $params)` | `array` |
| `selectOne($sql, $params)` | `array\|null` |
| `callFunction($fn, $params)` | DB fonksiyonu çağırır |
| `remember($key, $ttl, $callback)` | Cache-first okuma |
| `forget($key)` | Cache geçersiz kılma |
| `cacheKey(...$parts)` | `"a:b:c"` biçiminde anahtar |
| `isUniqueViolation(PDOException)` | `bool` (1062) |
| `isForeignKeyViolation(PDOException)` | `bool` (FK RESTRICT) |

> **`pdo()` lazy'dir ve bu önemli.** Eskiden constructor'da bağlanıyordu, yani
> *herhangi* bir modeli çözmek bir DB bağlantısı açıyordu: tamamen Redis'ten
> servis edilen istekler de TCP/TLS el sıkışması ödüyor, hiç sorgu yapmayan CLI
> komutları MySQL'e bağlanıyordu.

```bash
php frame make:model shop Product --table=product
```

### Prepared statement tuzağı

`ATTR_EMULATE_PREPARES => false` aktiftir. Yani **aynı isimli placeholder iki kez
kullanılamaz.** Çok satırlı INSERT'te her satıra ayrı isim verin:

```php
$values = [];
$params = [];
foreach ($ids as $i => $id) {
    $values[] = "(:role, :r{$i})";      // :r0, :r1, :r2 …
    $params["r{$i}"] = $id;
}
```

---

## Doğrulama

```php
$validated = $this->validator->validate($this->request()->json(), [
    'email'                 => 'required|email|unique_active:user,email',
    'password'              => 'required|password:6,simple|confirmed',
    'password_confirmation' => 'required',
    'age'                   => 'nullable|integer|between:18,120',
    'role'                  => 'required|in:admin,editor,viewer',
    'token'                 => 'required|string|regex:/^[a-f0-9]{64}$/',
    'uuid'                  => 'required|uuid',
    'avatar'                => 'required|file:image,2048',
]);
```

Başarısızlıkta `ValidationException` (**422**) fırlar; alan başına hata listesi
yanıt gövdesine girer. Dönen dizi yalnızca doğrulanmış alanları içerir.

### Yerleşik kurallar (25)

| | |
|---|---|
| **Varlık** | `required`, `nullable`, `confirmed` |
| **Tip** | `string`, `integer`, `numeric`, `boolean`, `array`, `date` |
| **Biçim** | `email`, `url`, `uuid`, `ip`, `alpha`, `alpha_num`, `regex` |
| **Aralık** | `min`, `max`, `between` |
| **Küme** | `in`, `not_in` |
| **DB** | `unique:tablo,kolon`, `unique_active:tablo,kolon` |
| **Özel** | `password`, `file` |

**Parametreli kuralların biçimi:**

```
password                       8+ karakter, büyük+küçük+rakam+özel
password:12                    min uzunluk 12, karakter sınıfları yine zorunlu
password:8,simple              YALNIZCA uzunluk kontrolü

file                           geçerli bir yükleme
file:image                     tip takma adı — image | document
file:2048                      max 2048 KB
file:image,2048                tip + boyut
                               ⚠ MIME LİSTESİ ALMAZ. `file:image/png` çalışmaz;
                                 MIME bazlı kontrol için UploadValidator kullanın.

unique:tablo,kolon             kayıt var mı
unique:tablo,kolon,5,id        `id = 5` satırını yok say (güncellemede)
unique_active:tablo,kolon      aynısı, ama soft-delete edilmiş satırları saymaz

min:3   max:120   between:18,120
in:a,b,c        not_in:x,y
regex:/^[a-f0-9]{64}$/
```

> **`size` kuralı YOKTUR.** Sabit uzunluk için `regex` kullanın —
> ör. `regex:/^[0-9]{6}$/`.

### Kendi kuralınız

```php
<?php

namespace Shop\Rules;

use System\Validation\Contracts\ParameterizedRuleInterface;

final class Currency implements ParameterizedRuleInterface
{
    private array $allowed = ['TRY', 'USD', 'EUR'];

    public function setParameters(array $params): self
    {
        if ($params !== []) {
            $this->allowed = $params;
        }
        return $this;
    }

    public function passes(mixed $value): bool
    {
        return is_string($value) && in_array(strtoupper($value), $this->allowed, true);
    }

    public function message(): string
    {
        return ':field geçerli bir para birimi olmalı';
    }
}
```

Kaydetmek:

```php
$validator->extend('currency', \Shop\Rules\Currency::class);
// kullanım: 'price_currency' => 'required|currency:TRY,USD'
```

Arayüzler: basit kural için `RuleInterface` (`passes()` + `message()`),
parametre alan kural için `ParameterizedRuleInterface`, tüm veriye erişmesi
gereken kural için `DataAwareRuleInterface`.

---

## Hata yönetimi

Servisler **fırlatır**, framework HTTP'ye çevirir.

| Exception | Kod |
|---|---|
| `BadRequestException` | 400 |
| `UnauthorizedException` | 401 |
| `ForbiddenException` | 403 |
| `NotFoundException` | 404 |
| `MethodNotAllowedException` | 405 |
| `ConflictException` | 409 |
| `ValidationException` | 422 |
| `TooManyRequestsException` | 429 |
| `InternalServerException` | 500 |

```php
throw new NotFoundException('Ürün bulunamadı');

// Bağlam ve başlık eklenebilir
throw (new ConflictException('SKU alınmış'))
    ->setContext('sku', $sku)
    ->setHeader('X-Conflict-Field', 'sku');

// Alan bazlı hatalar
throw new ValidationException(['sku' => ['Bu SKU kullanımda']], 'Doğrulama başarısız');
```

### Yanıt zarfı

```json
{
  "error": {
    "code": 404,
    "message": "Ürün bulunamadı"
  },
  "message": "Ürün bulunamadı"
}
```

`ValidationException` ek olarak `error.errors` altına alan başına hata listesi
yazar:

```json
{
  "error": {
    "code": 422,
    "message": "Doğrulama işlemi başarısız.",
    "errors": {
      "name":  ["Bu alan zorunludur."],
      "price": ["Bu alan en az 0 olmalıdır."]
    }
  },
  "message": "Doğrulama işlemi başarısız."
}
```

> **Üst seviye `message` bir GEÇİŞ anahtarıdır.** Kanonik konum
> `error.message`; `message` yalnızca eski istemciler için tekrarlanıyor ve
> ileride düşürülecek. **Yeni istemciler `error.message` okumalı.**
>
> Sebebi: framework hata yolları eskiden reddeden katmana göre iki farklı zarf
> döndürüyordu (middleware `error`, controller'lar düz `message`). Zarf
> birleştirildi, eski anahtar kırıcı değişikliği sıfıra indirmek için bırakıldı.

`APP_PRODUCTION=false` iken `error.debug` altına exception sınıfı, dosya, satır
ve stack trace eklenir. Production'da **eklenmez**.

> **Çeviriyi servisler yapar** ve mesajı çevrilmiş olarak fırlatır. Sebep:
> exception handler bootstrap'ta kaydedilen bir **singleton** ve locale'i yok;
> ona locale vermek scoped `Translator`'ı bir singleton'a enjekte etmek olurdu —
> `container:validate`'in tam olarak reddettiği kenar.

---

## Veritabanı ve migration

```bash
php frame make:migration create_product_table --create=product
php frame make:migration add_sku_to_product --table=product

php frame migrate            # bekleyenleri çalıştırır
php frame migrate:status     # durum tablosu
php frame migrate:rollback   # son batch'i geri alır
php frame migrate:reset      # tümünü geri alır
```

### Tablo oluşturma

```php
<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasTable('product')) {
            return;                       // idempotent
        }

        $this->schema->create('product', function (Blueprint $table) {
            $table->char('uuid', 36);
            $table->string('name', 120);
            $table->decimal('price', 10, 2)->default(0);
            $table->string('sku', 40)->nullable();
            $table->char('category_uuid', 36);
            $table->boolean('active')->default(1);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->softDeletes();                     // deleted_at

            $table->primary('uuid');
            $table->unique('sku', 'uq_' . $this->prefix() . 'product_sku');
            $table->index('name', 'ix_' . $this->prefix() . 'product_name');

            $table->foreign('category_uuid')
                  ->references('uuid')->on('category')
                  ->cascadeOnDelete()
                  ->name('fk_' . $this->prefix() . 'product_category');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('product');
    }
};
```

> **Tablo adlarına önek yazmayın** — `Schema`/`Blueprint` `DB_PREFIX`'i kendisi
> ekler. **İndeks ve FK adlarına yazın** — `$this->prefix()` kullanın.

### Tablo değiştirme

```php
$this->schema->table('product', function (Blueprint $table) {
    $table->string('barcode', 64)->nullable()->after('sku');
    $table->tinyInteger('stock')->unsigned()->default(0);
});

// geri alma
$this->schema->table('product', function (Blueprint $table) {
    $table->dropColumn(['barcode', 'stock']);
});
```

### Kolon tipleri

`id`, `increments`, `bigIncrements`, `integer`, `bigInteger`, `tinyInteger`,
`smallInteger`, `mediumInteger`, `unsignedInteger`, `unsignedBigInteger`,
`decimal`, `float`, `double`, `string`, `char`, `text`, `mediumText`, `longText`,
`boolean`, `date`, `datetime`, `timestamp`, `time`, `year`, `binary`, `json`,
`enum`, `timestamps()`, `softDeletes()`

**Değiştiriciler:** `nullable()`, `default()`, `unsigned()`, `autoIncrement()`,
`after()`, `comment()`, `charset()`, `collation()`, `length()`, `precision()`

**İndeksler:** `primary()`, `unique()`, `index()`, `fulltext()`, `dropIndex()`,
`dropUnique()`, `dropColumn()`, `renameColumn()`

**Foreign key:** `references()`, `on()`, `onDelete()`, `onUpdate()`,
`cascadeOnDelete()`, `cascadeOnUpdate()`, `nullOnDelete()`, `restrictOnDelete()`,
`name()`

### Schema sorguları

```php
$this->schema->hasTable('product');
$this->schema->hasColumn('product', 'sku');
$this->schema->getColumnListing('product');
$this->schema->getIndexes('product');
$this->schema->getForeignKeys('product');
$this->schema->rename('eski', 'yeni');
$this->schema->disableForeignKeyConstraints();
```

---

## Yetkilendirme (RBAC)

Roller ve yetkiler **çalışma zamanında** yönetilir; kod değişikliği gerektirmez.
Kullanıcı → rol → yetki zinciri, ayrıca kullanıcı bazlı **override** (izin/ret).

### Route seviyesinde

```php
$router->group(['middleware' => ['api_auth', 'api_role:administrator']], function ($router) {
    // ...
});

$router->post('/products', [ProductController::class, 'store'])
       ->middleware('api_permission:products.write');
```

### Controller içinde

```php
$this->authorize('products.delete');   // yoksa ForbiddenException (403)

if ($this->can('products.publish')) {
    // ...
}
```

`GateInterface`: `allows()`, `denies()`, `authorize()`.

> `Gate` **scoped**'tur ve `Request`'e bağlıdır. Singleton bir servise enjekte
> **etmeyin** — `container:validate` reddeder. Yetki kontrolü middleware veya
> controller katmanında kalmalı.

### Parola işlemleri

Tek politika: `System\Security\PasswordHasher`.

```php
$hash = $hasher->hash($plain);              // PASSWORD_DEFAULT
$ok   = $hasher->verify($plain, $hash);
$hasher->needsRehash($hash);                // algoritma/cost değiştiyse
$hasher->generate(16);                      // CSPRNG, random_int tabanlı
```

> `generate()` Fisher-Yates permütasyonunu `random_int` ile yapar. `str_shuffle`
> **kullanmaz**: o Mt19937 tabanlıdır ve tohum bilinirse deterministiktir.

---

## Çeviri

Kataloglar `lang/messages+intl-icu.<locale>.php`, ICU MessageFormat ile.

```php
// lang/messages+intl-icu.tr.php
return [
    'order.created'      => 'Sipariş oluşturuldu.',
    'order.item_count'   => '{count, plural, one {# ürün} other {# ürün}} eklendi.',
    'order.total'        => 'Toplam: {amount, number, currency}',
];
```

```php
use System\Translation\Contract\TranslatorInterface;

final class OrderNotifier
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    public function summary(int $count): string
    {
        return $this->translator->trans('order.created')
            . ' ' . $this->translator->trans('order.item_count', ['count' => $count]);
    }
}
```

Locale `Accept-Language` başlığından çözülür. `Translator` ve `LocaleResolver`
**scoped**'tur — singleton olsalardı bir worker'ın gördüğü ilk isteğin dili
sonraki tüm isteklere uygulanırdı.

`+intl-icu` son eki `symfony/translation`'a ICU biçimlendiricisini kullanmasını
söyler; bu olmadan `{count, plural, ...}` ifadeleri düz metin olarak çıkar.

---

## Dosya depolama

```php
use System\Storage\FileStorageInterface;
use System\Storage\UploadValidator;

final class AvatarService
{
    public function __construct(
        private readonly FileStorageInterface $storage,
        private readonly UploadValidator $uploads,
        private readonly UserModel $users,
    ) {}

    public function upload(string $uuid, array $file): void
    {
        // Doğrula → MIME döner
        $mime = $this->uploads->validate($file, ['image/png', 'image/jpeg'], 2 * 1024 * 1024);
        $ext  = $this->uploads->extensionFor($mime);
        $key  = "avatar/{$uuid}.{$ext}";

        // Blob ÖNCE (DB satırı anahtara ihtiyaç duyar), telafi AYNI kapsamda
        $this->storage->putFromUpload($key, $file);

        try {
            $this->users->setAvatar($uuid, $key);
        } catch (\Throwable $e) {
            $this->storage->delete($key);   // yetim blob bırakma
            throw $e;
        }
    }
}
```

`FileStorageInterface`: `put()`, `putFromUpload()`, `get()`, `stream()`,
`delete()`, `exists()`, `size()`, `mimeType()`.

Varsayılan sürücü `LocalFileStorage`, kök dizin `image/`. `StorageException`
mesajını **istemciye vermeyin** — dosya sistemi yolu sızdırır.

---

## Cache

```php
use System\Cache\CacheInterface;

$cache->get('k', 'default');
$cache->set('k', $v, 300);
$cache->has('k');
$cache->delete('k');
$cache->remember('k', 300, fn() => $expensive());
$cache->flush();
```

Sürücüler: `redis`, `array`, `null` (`CACHE_DRIVER`). Anahtarlara `CACHE_PREFIX`
otomatik eklenir. Model içinden `remember()`/`forget()`/`cacheKey()` kullanın.

---

## Olaylar

```php
use System\Event\EventDispatcher;

$events->listen('order.placed', function (array $payload) {
    // ...
}, priority: 10);

$events->once('app.booted', $callback);

$events->dispatch('order.placed', ['uuid' => $uuid]);   // false = iptal edildi

$events->forget('order.placed');
$events->hasListeners('order.placed');
$events->listenerCount('order.placed');
```

---

## Gözlemlenebilirlik (Monitor)

Yerleşik izleme; istek, sorgu ve exception kayıtlarını veritabanına yazar.

**Toplananlar:** istek/yanıt (opsiyonel gövdeler), SQL sorguları ve süreleri,
N+1 tespiti, cache isabet/ıska, exception'lar, **fatal error'lar** (shutdown
hook üzerinden).

```ini
MONITOR_ENABLED=true
MONITOR_AUTH_MODE=token          # token | rbac | both
MONITOR_TOKEN=<uzun-rastgele>
MONITOR_SAMPLE_RATE=100          # yüzde
MONITOR_SLOW_MS=500
MONITOR_SLOW_QUERY_MS=100
MONITOR_N_PLUS_ONE_THRESHOLD=5
MONITOR_CAPTURE_REQUEST_BODY=false
MONITOR_CAPTURE_RESPONSE_BODY=false
MONITOR_RETENTION_DAYS=7
MONITOR_SKIP_PREFIXES=/health,/metrics
```

Arayüz: `/monitor` ve `/monitor/{request_id}`.

> **Monitor kapalıysa route'lar hiç tanımlanmaz** ve dashboard **404** verir.
> 403 dönmek saldırgana "burada bir panel var" demek olurdu.

Saklama süresi uygulaması cron'a bağlanmalıdır — tablo aksi halde sınırsız büyür
ve istek/yanıt gövdeleri (yani kişisel veri) süresiz saklanır:

```cron
0  4 * * *  cd /path/to/app && php frame monitor:purge
15 4 * * *  cd /path/to/app && php frame auth:purge
```

---

## CLI komutları

```bash
php frame list              # tüm komutlar
php frame <komut> --help    # komut yardımı
```

| Komut | İş |
|---|---|
| `serve` | Geliştirme sunucusu (`--host`, `--port`) |
| `app:about` | Ortam, runtime, config, eklenti özeti |
| `app:health` | DB/Redis/migration/cache hazırlık kontrolü |
| `optimize` | Container + route cache derler (deploy build adımı) |
| `container:validate` | Döngü, scope, factory, eksik binding |
| `container:compile` | Doğrula → PHP üret → atomik yaz |
| `container:list` | Servisler: lifetime, kurulum, kaynak |
| `container:debug <servis>` | Bağımlılık ağacı (`--reverse`, `--depth`) |
| `container:graph` | `--format=tree\|dot\|mermaid` |
| `migrate` | Bekleyen migration'ları çalıştırır |
| `migrate:status` · `migrate:rollback` · `migrate:reset` | |
| `cache:warm` · `cache:clear` | Route/Twig cache |
| `make:controller <modül> <ad>` | `--api`, `--resource` |
| `make:model <modül> <ad>` | `--table` |
| `make:service <modül> <ad>` | `--model` |
| `make:migration <ad>` | `--create`, `--table` |
| `monitor:purge` | Eski monitor kayıtları (`--days`, `--chunk`, `--dry-run`) |
| `auth:purge` | Süresi geçmiş parola sıfırlama token'ları (`--dry-run`) |

### Kendi komutunuz

```php
<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Console\Input\InputOption;

#[AsCommand(
    name: 'shop:reindex',
    description: 'Ürün arama indeksini yeniden kurar',
    usage: 'php frame shop:reindex [--chunk=500]'
)]
final class ShopReindexCommand extends Command
{
    // Bağımlılıklar constructor'dan gelir — container komutları çözer.
    public function __construct(private readonly ProductModel $products)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Parti boyutu', 500);
    }

    protected function handle(): ExitCode
    {
        $this->title('Yeniden İndeksleme');

        $rows = $this->products->all();
        $this->progressStart(count($rows));

        foreach ($rows as $row) {
            // ...
            $this->progressAdvance();
        }

        $this->progressFinish();
        $this->success(count($rows) . ' ürün indekslendi.');

        return ExitCode::SUCCESS;
    }
}
```

**Çıktı yardımcıları:** `write`, `writeln`, `newLine`, `success`, `error`,
`warning`, `info`, `comment`, `note`, `caution`, `title`, `section`, `table`,
`listing`, `progressStart`/`progressAdvance`/`progressFinish`

**Girdi:** `argument()`, `option()`, `ask()`, `secret()`, `confirm()`, `choice()`

**Verbosity:** `isQuiet()`, `isVerbose()`, `isVeryVerbose()`, `isDebug()`

---

## Test

PHPUnit 13. Suite yapısı ROADMAP §0.9.2'yi izler ve **suite ayrımı kasıtlıdır**:
her biri farklı altyapı gerektirir, dolayısıyla ayrı koşturulabilmesi gerekir.

```bash
composer test                  # tümü
composer test:unit             # altyapı GEREKTİRMEZ
composer test:integration      # DB/Redis ister; yokken atlar
composer test:functional       # CANLI HTTP sunucusu ister
composer test:coverage         # metin kapsam raporu
```

| Suite | Gerektirdiği | İçerik |
|---|---|---|
| `Unit` | — | Saf mantık: config objeleri, yardımcılar, doğrulama kuralları |
| `Integration` | DB / Redis | Model + container sözleşmeleri |
| `Functional` | **canlı HTTP sunucusu** | Uçtan uca istek/yanıt |
| `Security` | — | Güvenlik invariant'ları (CSPRNG, kaçış, numaralandırma) |
| `Performance` | — | Ölçüm; eşik kırılganlığı yüzünden ayrı tutulur |

### Functional testleri koşturmak

Bu suite **gerçek bir sunucuya** istek atar. Sebebi bir sınırlama:
`Response` çıktıyı `header()` + `echo` ile doğrudan global çıktı akışına
yazıyor, dolayısıyla süreç içinde dispatch etmek `ob_start()` gerektirir ve
header'lar CLI'da doğrulanamaz — ki bu testlerin asıl konusu tam olarak
durum kodları ve header'lar.

```bash
# 1. sunucuyu başlat
php frame serve --port=8899

# 2. başka bir terminalde
PHPFRAME_TEST_URL=http://127.0.0.1:8899 \
PHPFRAME_TEST_EMAIL=admin@example.com \
PHPFRAME_TEST_PASSWORD=... \
composer test
```

> **Kimlik bilgileri koda GÖMÜLMEZ.** `seed_rbac` migration'ı bilinçli olarak
> admin kullanıcısı yaratmaz (halka açık bir framework'te gömülü kimlik
> bilgisi shiplenmez) ve testler aynı ilkeyi izler: `PHPFRAME_TEST_EMAIL` /
> `PHPFRAME_TEST_PASSWORD` tanımlı değilse kimlik gerektiren testler **atlanır**.
> Sunucu erişilemezse Functional suite tamamen atlanır. Böylece `composer test`
> hiçbir kurulum olmadan da anlamlı çalışır.

### Testler sıra bağımsızdır

Yerini aldıkları bash betiği katı bir sıra gerektiriyordu: `users_token`
kullanıcı başına **tek satır** tutar (her login öncekini öldürür), refresh
rotasyon yapar ve login rate limit'i 5/60. Burada her test kendi oturumunu
açar ve `setUp()` rate limiter'ı temizler — testler herhangi bir sırada, tek
tek koşabilir.

### Kendi testinizi yazmak

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Shop;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shop\Services\PricingService;

#[CoversClass(PricingService::class)]
final class PricingServiceTest extends TestCase
{
    public function testAppliesVat(): void
    {
        self::assertSame(120.0, (new PricingService())->withVat(100.0, 0.20));
    }
}
```

`Tests\` öneki PSR-4 ile `tests/` dizinine bağlıdır (`autoload-dev`). Bu,
first-party kodun küçük-harf konvansiyonuyla **çakışmaz**: Composer'ın
autoloader'ı `config/config.php` içinde PHPFrame Autoloader'ından **önce**
kaydedilir, dolayısıyla `Tests\` önekini o yakalar.

HTTP testi için `Tests\Functional\FunctionalTestCase`'i extend edin —
`get/post/put/delete` yardımcıları, `login()`, `assertErrorEnvelope()` ve
rate-limit temizliği hazır gelir.

### Bootstrap neden `config/config.php`

`vendor/autoload.php` **yetmez**. Autoload hibrittir: Composer yalnızca
`vendor/`'ü yükler, `System\`/`Api\`/`Monitor\` önekleri
`System\Engine\Autoloader`'a aittir. `config/config.php` sürüm bariyerini,
`.env` yüklemesini ve iki autoloader'ı **doğru sırayla** kurar.

---

## Yeni modül eklemek

Modül = kök dizinde, `[a-z][a-z0-9_]*` adlı, içinde `controllers`, `models`,
`services`, `middleware`, `security` veya `views` alt dizinlerinden en az biri
olan bir dizin.

```
shop/
├── controllers/ProductController.php     Shop\Controllers\ProductController
├── services/ProductService.php           Shop\Services\ProductService
├── models/ProductModel.php               Shop\Models\ProductModel
├── views/products/index.twig             $this->view('shop', 'products/index')
└── provider/ShopProvider.php             Shop\Provider\ShopProvider
```

Adımlar:

```bash
mkdir -p shop/{controllers,services,models,provider,views}
php frame make:controller shop Product --api --resource
php frame make:service shop Product --model=ProductModel
php frame make:model shop Product --table=product
```

1. `shop/provider/ShopProvider.php` yazın (yukarıdaki örnek)
2. `config/providers.php` listesine ekleyin
3. Rotaları `routes/routes.php` içine ya da attribute olarak yazın
4. Doğrulayın:

```bash
php frame container:validate
php frame container:compile
php frame app:health
```

Modül keşfedildiği an `Shop\` öneki örtük autowire'a açılır — ama servisleri
**yine de provider'da bağlamak** gerekir; `provides()` beyanı olmadan çakışma
kontrolü çalışmaz.

---

## Production'a alma

```bash
composer install --no-dev --optimize-autoloader
```

`.env`:

```ini
APP_PRODUCTION=true
```

Build adımı:

```bash
php frame optimize      # container + route cache
php frame migrate
php frame app:health    # deploy probe
```

### Deploy artifact'ı taşımak zorunda

`system/cache/*` **git'te değildir** (`.gitignore`). Deploy paketiniz derlenmiş
container'ı taşımalı, yoksa production container'sız kalır ve **boot'ta hata
verir**. `container:compile` bunu çıktısında hatırlatır.

### Kontrol listesi

- `SECRET_KEY` 32+ karakter — `AuthConfig::hasStrongSecret()` ölçer
- `CORS_ORIGINS` `*` **olmasın**
- `TRUSTED_PROXIES` gerçek proxy IP'leriniz olsun (`Request::ip()` buna güvenir)
- `SMTP_VERIFY_TLS=true` (varsayılan) — kapatmak sıfırlama e-postalarını MITM'e açar
- `MONITOR_TOKEN` uzun ve rastgele; `MONITOR_CAPTURE_*_BODY` kişisel veri taşır
- `DB_SSL=true` + `DB_SSL_VERIFY=true` (DB ayrı hostta ise)
- `opcache.enable=1`; `opcache.preload` için `system/preload.php` hazır
- `monitor:purge` ve `auth:purge` cron'a bağlı
- `logs/` webroot dışında (öyle) ve dönüyor (`LOG_*`)

`ConfigValidator` production'da riskli ayarları raporlar.

---

## Yayınlamadan önce

Üç maddeden ikisi **kapandı**:

- ✅ **Lisans** — proje MIT altında; `LICENSE` dosyası ve `composer.json`'daki
  `"license": "MIT"` alanı tutarlı.
- ✅ **`composer.lock`** — `.gitignore`'daki `*.lock` deseni kaldırıldı, dosya
  artık takip ediliyor. `composer install` sürümleri yeniden üretebiliyor ve
  `composer audit` garantisi geçerli.
- ✅ **`tests/`** — PHPUnit kurulu, suite yapısı ROADMAP §0.9.2'yi izliyor.
  Bkz. [Test](#test).

Kalan tek nokta kararınıza bağlı:

**`docs/` git'te yok.** `.gitignore` `docs/` satırı içeriyor, yani `DI-plan.md`,
`ROADMAP` ve **`api-layer.md` GitHub'da görünmez**. Bu README katman sözleşmesini
özetliyor ama tam metin yayınlanmıyor. `docs/` satırını kaldırmak ya da bu
belgeleri README'ye taşımak gerekir. (`.gitignore` içindeki yorum bu çelişkiyi
zaten not ediyor.)

---

## Sürüm ve gereksinimler

| | |
|---|---|
| PHP | 8.5+ — `config/config.php` içinde sert bariyer |
| Eklentiler | `pdo_mysql` (zorunlu), `redis` (Redis cache için), `opcache` (önerilir), `intl` (ICU çeviri için) |
| Composer | `firebase/php-jwt ^7.1`, `phpmailer/phpmailer ^6.10`, `psr/container ^2.0`, `symfony/translation ^8.1`, `twig/twig ^3.28` |

Ortam özetini `php frame app:about` verir.

---

## Lisans

[MIT](LICENSE) — Copyright (c) 2026 PHPFrame Contributors

**Herkes kullanabilir.** Ticari veya kişisel, kapalı veya açık kaynak, ücretli
veya ücretsiz; kullanma, kopyalama, değiştirme, birleştirme, yayınlama,
dağıtma, alt-lisanslama ve satma haklarının tamamı verilmiştir. Tek koşul:
telif bildirimini ve lisans metnini dağıttığınız kopyalarda **koruyun**.

Yazılım "OLDUĞU GİBİ" sunulur, garanti verilmez.

### Üçüncü parti bağımlılıklar

`vendor/` altındaki paketler **kendi lisansları** altındadır ve bu lisans
onları kapsamaz. Hepsi izin verici (permissive) lisanslıdır:

| Paket | Sürüm | Lisans |
|---|---|---|
| `firebase/php-jwt` | v7.1.0 | BSD-3-Clause |
| `phpmailer/phpmailer` | v6.12.0 | **LGPL-2.1-only** |
| `psr/container` | 2.0.2 | MIT |
| `symfony/translation` | v8.1.5 | MIT |
| `twig/twig` | v3.28.0 | BSD-3-Clause |
| ↳ `symfony/deprecation-contracts` | v3.7.1 | MIT |
| ↳ `symfony/translation-contracts` | v3.7.1 | MIT |
| ↳ `symfony/polyfill-ctype` | v1.37.0 | MIT |
| ↳ `symfony/polyfill-mbstring` | v1.38.2 | MIT |

`↳` ile işaretliler geçişli (transitive) bağımlılıklardır.

> `phpmailer/phpmailer` **LGPL-2.1**'dir. Kütüphane olarak kullanmak (bu
> projenin yaptığı gibi, Composer üzerinden ayrı bir paket olarak) kendi
> kodunuzu LGPL'e tabi kılmaz. Ancak PHPMailer'ı **değiştirip** dağıtırsanız
> değişiklikleriniz LGPL koşullarına girer. Tam listeyi ve doğrulanabilir
> hâlini şu komut verir:
>
> ```bash
> composer licenses
> ```
