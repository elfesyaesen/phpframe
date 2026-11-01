# PHPFrame Kullanım Kılavuzu

## İçindekiler

1. [Gereksinimler](#1-gereksinimler)
2. [Kurulum](#2-kurulum)
3. [Klasör yapısı](#3-klasör-yapısı)
4. [İlk özelliğinizi ekleyin (adım adım)](#4-ilk-özelliğinizi-ekleyin-adım-adım)
5. [Route'lar](#5-routelar)
6. [Controller](#6-controller)
7. [Doğrulama](#7-doğrulama)
8. [Hata döndürme](#8-hata-döndürme)
9. [Veritabanı](#9-veritabanı)
10. [Kimlik doğrulama ve yetki](#10-kimlik-doğrulama-ve-yetki)
11. [Çeviri](#11-çeviri)
12. [Medya (dosya yükleme)](#12-medya-dosya-yükleme)
13. [Cache, log ve ayarlar](#13-cache-log-ve-ayarlar)
14. [HTML sayfa (Twig)](#14-html-sayfa-twig)
15. [CLI komutları](#15-cli-komutları)
16. [Test](#16-test)
17. [Yayına alma](#17-yayına-alma)

---

## 1. Gereksinimler

- PHP **8.5** veya üzeri
- Composer
- MySQL / MariaDB
- PHP eklentileri: `pdo_mysql`, `intl`, `mbstring`, `fileinfo`, `gd` (veya `imagick`), `exif`
- İsteğe bağlı: Redis (`redis` eklentisi) — yoksa cache ve rate limit dosyaya yazılır

Eksik bir şey var mı diye bakmak için: `php frame app:health`

## 2. Kurulum

1. Bağımlılıkları kurun:
   ```bash
   composer install
   ```
2. Ayar dosyasını oluşturun:
   ```bash
   cp .env.example .env
   ```
3. `.env` içinde en az şunları doldurun:
   ```ini
   APP_PRODUCTION=false
   APP_URL=http://localhost:8000
   DB_HOST=127.0.0.1
   DB_NAME=phpframe
   DB_USER=root
   DB_PASS=
   SECRET_KEY=          # en az 32 karakter
   MEDIA_SIGNING_KEY=   # en az 32 karakter, SECRET_KEY'den farklı
   ```
   Rastgele anahtar üretmek için:
   ```bash
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```
4. Tabloları oluşturun:
   ```bash
   php frame migrate
   ```
5. Sunucuyu başlatın ve kontrol edin:
   ```bash
   php frame serve
   php frame app:health
   ```
   `http://localhost:8000/v1/api` → `200` dönmeli.

**`.env` yazım kuralları**

- Her satır `ANAHTAR=değer` biçiminde olmalı. Bozuk satır uygulamayı açtırmaz, hata satır numarasını gösterir.
- Aynı anahtar iki kez yazılamaz.
- Boşluk veya ` #` içeren değerleri tırnağa alın: `SMTP_TITLE="Firma Adı"`.
- Sunucu/konteyner aynı değişkeni zaten veriyorsa `.env`'deki değer kullanılmaz (`app:health` bunu gösterir).

## 3. Klasör yapısı

```
app/
  <modül>/              # her modül bir klasör (api, media, catalog ...)
    controllers/        # HTTP katmanı
    services/           # iş kuralları (otomatik kayıt olur)
    models/             # SQL sorguları
    middleware/         # modüle özel middleware
    views/              # Twig şablonları
  lang/                 # tr.php, en.php, az.php
  migration/            # veritabanı migration'ları
config/
  middleware.php        # middleware takma adları
  media.php             # dosya yükleme koleksiyonları
routes/
  routes.php            # tüm route'lar
public/                 # web kökü (sunucu buraya bakmalı)
storage/                # log, cache, yüklenen dosyalar
```

- Yeni modül için `app/` altına klasör açmanız yeterli; kayıt gerekmez.
- Servisler, controller'lar ve modeller constructor'a yazıldığı yerde otomatik gelir.

## 4. İlk özelliğinizi ekleyin (adım adım)

Örnek: `catalog` modülünde ürün listeleme ve ekleme.

**Adım 1 — Dosyaları üretin**

```bash
php frame make:migration create_product_table --create=product
php frame make:model catalog Product --table=product
php frame make:service catalog Product --model=ProductModel
php frame make:controller catalog Product --api --resource
```

**Adım 2 — Tabloyu tanımlayın** (`app/migration/..._create_product_table.php`)

```php
$this->schema->create('product', function (Blueprint $table) {
    $table->id();
    $table->char('uuid', 36);
    $table->string('name', 150);
    $table->decimal('price', 10, 2);
    $table->timestamps();
    $table->unique('uuid');
});
```

```bash
php frame migrate
```

**Adım 3 — Model: yalnızca SQL** (`app/catalog/models/ProductModel.php`)

```php
use System\Engine\BaseModel;
use System\Helpers\Uuid;

class ProductModel extends BaseModel
{
    public function all(): array
    {
        return $this->selectAll("SELECT uuid, name, price FROM " . $this->prefix() . "product ORDER BY name");
    }

    public function find(string $uuid): ?array
    {
        return $this->selectOne(
            "SELECT uuid, name, price FROM " . $this->prefix() . "product WHERE uuid = :uuid",
            ['uuid' => $uuid]
        );
    }

    public function create(string $name, float $price): string
    {
        $uuid = Uuid::generate();

        $this->pdo()->prepare(
            "INSERT INTO " . $this->prefix() . "product (uuid, name, price) VALUES (:uuid, :name, :price)"
        )->execute(['uuid' => $uuid, 'name' => $name, 'price' => $price]);

        return $uuid;
    }
}
```

**Adım 4 — Servis: iş kuralı ve hatalar** (`app/catalog/services/ProductService.php`)

```php
use App\Catalog\Models\ProductModel;
use System\Exceptions\NotFoundException;
use System\Translation\Contract\TranslatorInterface;

final class ProductService
{
    public function __construct(
        private readonly ProductModel $products,
        private readonly TranslatorInterface $translator,
    ) {}

    public function all(): array
    {
        return $this->products->all();
    }

    public function find(string $uuid): array
    {
        return $this->products->find($uuid)
            ?? throw new NotFoundException($this->translator->trans('product.not_found'));
    }

    public function create(array $data): string
    {
        return $this->products->create($data['name'], (float) $data['price']);
    }
}
```

**Adım 5 — Controller: yalnızca HTTP** (`app/catalog/controllers/ProductController.php`)

```php
use App\Catalog\Services\ProductService;
use System\Engine\BaseController;
use System\Helpers\Status;
use System\Validation\Validator;

class ProductController extends BaseController
{
    public function __construct(
        private readonly ProductService $products,
        private readonly Validator $validator,
    ) {}

    public function index(): void
    {
        $this->response()->jsonResponse(Status::OK, ['data' => $this->products->all()]);
        return;
    }

    public function show(string $uuid): void
    {
        $this->response()->jsonResponse(Status::OK, ['data' => $this->products->find($uuid)]);
        return;
    }

    public function store(): void
    {
        $data = $this->validator->validate($this->request()->json(), [
            'name'  => 'required|string|max:150',
            'price' => 'required|numeric|min:0',
        ]);

        $uuid = $this->products->create($data);

        $this->response()->jsonResponse(Status::CREATED, ['uuid' => $uuid]);
        return;
    }
}
```

**Adım 6 — Route ekleyin** (`routes/routes.php`)

```php
$router->group(['prefix' => '/v1/api', 'middleware' => ['api_auth', 'rate_limit']], function ($router) {
    $router->get('/products',        [\App\Catalog\Controllers\ProductController::class, 'index'])->name('products.index');
    $router->get('/products/{uuid}', [\App\Catalog\Controllers\ProductController::class, 'show'])->name('products.show');
    $router->post('/products',       [\App\Catalog\Controllers\ProductController::class, 'store'])->name('products.store');
});
```

**Adım 7 — Çeviri anahtarını ekleyin** (`app/lang/tr.php`, `en.php`, `az.php`)

```php
'product.not_found' => 'Ürün bulunamadı.',
```

```bash
php frame lang:check
```

**Adım 8 — Deneyin**

```bash
curl -H "Authorization: Bearer <access_token>" http://localhost:8000/v1/api/products
```

**Katman kuralları (kısaca)**

| Katman | Yapar | Yapmaz |
|---|---|---|
| Controller | Girdiyi doğrular, servisi çağırır, yanıt verir | SQL yazmaz, `if (!$x) 404` yazmaz |
| Servis | İş kuralı, transaction, hata fırlatma | `Request`/`Response` kullanmaz |
| Model | SQL | İş kuralı içermez |

## 5. Route'lar

```php
$router->get('/yol', [Controller::class, 'metot']);
$router->post(...);  $router->put(...);  $router->patch(...);  $router->delete(...);
```

- **Parametre:** `/products/{uuid}` → `show(string $uuid)`. Parametre adlarını küçük harf/snake_case yazın (`{user_uuid}`).
- **Kısıt:** `->params(['uuid' => '[0-9a-f-]{36}'])` veya satır içi `{id:\d+}`.
- **İsim:** `->name('products.show')`.
- **Route'a middleware:** `->middleware('rate_limit:5,60')`.
- **Grup:** prefix ve middleware iç içe gruplara da aktarılır.
  ```php
  $router->group(['prefix' => '/v1/api/admin', 'middleware' => ['api_auth', 'api_superadmin']], function ($router) {
      $router->group(['prefix' => '/reports'], function ($router) {
          $router->get('/sales', [...]);   // /v1/api/admin/reports/sales — korumalı
      });
  });
  ```

**Hazır middleware'ler**

| Takma ad | Ne yapar |
|---|---|
| `api_auth` | Geçerli access token ister (yoksa 401) |
| `api_superadmin` | Superadmin rolü ister (yoksa 403). `api_auth`'tan sonra yazın |
| `api_role:editor\|author` | Rollerden birini ister. `api_auth`'tan sonra yazın |
| `api_permission:product.create` | İzni ister. `api_auth`'tan sonra yazın |
| `rate_limit` | Varsayılan limit (`.env`: `RATE_LIMIT_MAX_ATTEMPTS`, `RATE_LIMIT_DECAY_SECONDS`) |
| `rate_limit:5,60` | 60 saniyede en fazla 5 istek. Yanlış yazım (`5;60`) hata verir |

Kendi middleware'iniz için: `MiddlewareInterface`'i uygulayın, `config/middleware.php`'ye takma ad ekleyin. Reddetmek için exception fırlatın, devam etmek için `$next($request)` çağırın.

## 6. Controller

Controller içinde hazır yardımcılar:

| Kullanım | Ne verir |
|---|---|
| `$this->request()->json()` | JSON gövde (dizi) |
| `$this->request()->input('ad', 'varsayılan')` | Form/JSON alanı |
| `$this->request()->query('sayfa', 1)` | Query string |
| `$this->request()->files('file')` | Yüklenen dosya |
| `$this->request()->header('X-Ad')` | Header |
| `$this->request()->userUuid()` | Giriş yapmış kullanıcının uuid'i (`api_auth` sonrası) |
| `$this->request()->authUser()` | Giriş yapmış kullanıcının kaydı |
| `$this->request()->ip()` | İstemci IP'si (proxy farkında) |
| `$this->can('product.create')` | İzin var mı (`bool`) |
| `$this->authorize('product.create')` | İzin yoksa 403 fırlatır |
| `$this->translator()->trans('anahtar')` | Çeviri |

**Yanıt verme**

```php
$this->response()->jsonResponse(Status::OK, $data);
$this->response()->htmlResponse(Status::OK, $html);
$this->response()->redirect('/hedef');
return;
```

- Metot imzası `: void` olsun.
- Yanıttan sonra her zaman `return;` yazın. Aynı istekte ikinci yanıt hata verir.
- Durum kodları için `Status::OK`, `Status::CREATED` ... kullanın.

## 7. Doğrulama

```php
$data = $this->validator->validate($this->request()->json(), [
    'email'    => 'required|email|unique_active:user,email',
    'age'      => 'nullable|integer|between:18,99',
    'tags'     => 'nullable|array',
    'tags.*'   => 'string|max:30',
]);
```

- Geçersizse otomatik **422** döner (alan bazlı hatalarla).
- Dönen `$data` yalnızca kurallarda yazan alanları içerir.

| Kural | Örnek |
|---|---|
| `required`, `nullable` | |
| `string`, `integer`, `numeric`, `boolean`, `array` | |
| `email`, `url`, `ip`, `uuid`, `date` | |
| `alpha`, `alpha_num` | |
| `min:n`, `max:n`, `between:a,b` | Metinde uzunluk, sayıda değer |
| `in:a,b,c`, `not_in:a,b` | |
| `regex:/^[A-Z]+$/` | Desende `,` veya `\|` kullanmayın |
| `confirmed` | `password` için `password_confirmation` alanı ister |
| `password` | 8+ karakter, büyük/küçük harf, rakam, sembol |
| `password:12` | 12+ karakter, aynı kurallar |
| `unique:tablo,kolon` | Tabloda tekil |
| `unique_active:tablo,kolon` | Silinmemiş kayıtlar arasında tekil |
| `file`, `file:image`, `file:document`, `file:image,2048` | Tür ve KB sınırı |

## 8. Hata döndürme

Servis veya controller'da exception fırlatın; doğru durum kodu ve JSON biçimi otomatik oluşur.

```php
throw new NotFoundException($this->translator->trans('product.not_found'));
```

| Exception | Kod |
|---|---|
| `BadRequestException` | 400 |
| `UnauthorizedException` | 401 |
| `ForbiddenException` | 403 |
| `NotFoundException` | 404 |
| `ConflictException` | 409 |
| `ValidationException` | 422 |
| `TooManyRequestsException` | 429 |
| `ServiceUnavailableException` | 503 |

Hepsi `System\Exceptions\` altında. Yanıt biçimi:

```json
{ "error": { "code": 404, "message": "Ürün bulunamadı." } }
```

- `APP_PRODUCTION=true` iken 5xx hatalarında iç detay gösterilmez; ayrıntı `storage/logs/` altına yazılır.

## 9. Veritabanı

**Model yardımcıları** (`BaseModel`)

| Metot | Ne yapar |
|---|---|
| `$this->selectAll($sql, $params)` | Tüm satırlar |
| `$this->selectOne($sql, $params)` | Tek satır veya `null` |
| `$this->pdo()` | PDO (insert/update için) |
| `$this->prefix()` | Tablo öneki (`DB_PREFIX`) — tablo adının önüne ekleyin |
| `$this->remember($key, $ttl, fn)` | Sonucu cache'le |

- Değerleri her zaman `:parametre` ile verin, SQL'e birleştirmeyin.
- `make:model`'in ürettiği `create()/update()` dizinin anahtarlarını kolon adı yapar: yalnızca doğrulanmış veriyi verin.

**Transaction** (serviste)

```php
use System\Database\Database;

public function __construct(private readonly Database $database) {}

$this->database->transaction(function () use ($data) {
    $this->orders->create(...);
    $this->stock->decrease(...);
});   // hata olursa ikisi de geri alınır
```

**Migration**

```bash
php frame make:migration add_stock_to_product --table=product
php frame migrate             # bekleyenleri çalıştır
php frame migrate:status      # durum
php frame migrate:rollback    # son grubu geri al
```

Sık kullanılan kolonlar:

```php
$table->id();                         // otomatik artan birincil anahtar
$table->char('uuid', 36);
$table->string('name', 150);
$table->text('body')->nullable();
$table->integer('stock')->default(0);
$table->decimal('price', 10, 2);
$table->boolean('active')->default(true);
$table->json('meta');
$table->enum('status', ['draft', 'published']);
$table->timestamps();                 // created_at, updated_at
$table->softDeletes();                // deleted_at
$table->unique('uuid');               // tekil indeks
$table->index('name');
$table->foreign('user_uuid')->references('uuid')->on('user')->onDelete('cascade');
```

Değiştirme migration'ında: `dropColumn()`, `renameColumn()`, `dropIndex()`.

## 10. Kimlik doğrulama ve yetki

**Akış**

1. Kayıt: `POST /v1/api/users/register`
   ```json
   { "username": "ali", "email": "ali@ornek.com", "password": "...", "password_confirmation": "..." }
   ```
2. Giriş: `POST /v1/api/auth/login` → `{ "email": "...", "password": "..." }`
3. Yanıt: `access_token`, `refresh_token`, `expired_at`, `user`
4. Her istekte: `Authorization: Bearer <access_token>`
5. Access token dolunca: `POST /v1/api/auth/refresh` — header'da **refresh token**: `Authorization: Bearer <refresh_token>`. Yeni token çifti döner, eskisi geçersiz olur.
6. Çıkış: `POST /v1/api/auth/logout`

**Hazır uçlar**

| Uç | Açıklama |
|---|---|
| `GET /v1/api/auth/me` | Oturumdaki kullanıcı |
| `GET/PUT/DELETE /v1/api/users/me` | Profil görüntüle / güncelle / hesabı sil |
| `POST /v1/api/users/change-password` | Parola değiştir |
| `POST /v1/api/users/reset-password` | Sıfırlama e-postası iste |
| `POST /v1/api/users/reset-password/confirm` | Link ile yeni parola |
| `POST /v1/api/users/reset-password/verify-code` | 6 haneli kod ile yeni parola |
| `POST/GET/DELETE /v1/api/users/me/avatar` | Profil resmi |

**Roller ve izinler** (yalnızca superadmin)

| Uç | Açıklama |
|---|---|
| `GET/POST /v1/api/admin/roles` | Rolleri listele / ekle |
| `PUT/DELETE /v1/api/admin/roles/{uuid}` | Rol adı değiştir / sil |
| `PUT /v1/api/admin/roles/{uuid}/permissions` | Rolün izinlerini ayarla `{ "permissions": ["<uuid>"] }` |
| `GET/POST/DELETE /v1/api/admin/permissions` | İzinleri yönet |
| `PUT /v1/api/admin/users/{user_uuid}/role` | Kullanıcıya rol ver `{ "role": "editor" }` |
| `PUT /v1/api/admin/users/{user_uuid}/permissions` | Kullanıcıya özel izin ver/kaldır |

- Her kullanıcının tek rolü vardır.
- Superadmin rolü (`administrator`) her izni geçer; silinemez, son superadmin'in rolü değiştirilemez.
- İlk superadmin'i veritabanında atayın (`user_role` tablosuna `administrator` rolü).
- Kodda izin kontrolü: route'ta `api_permission:product.create` veya controller'da `$this->authorize('product.create')`.

## 11. Çeviri

- Dosyalar: `app/lang/tr.php`, `en.php`, `az.php` — aynı anahtarlar üç dosyada da olmalı.
- Dil, istemcinin `Accept-Language` header'ından seçilir; yoksa `tr`.
- Kullanım:
  ```php
  'product.low_stock' => '{name} için stok {count} adet kaldı.',
  ```
  ```php
  $this->translator->trans('product.low_stock', ['name' => $p['name'], 'count' => 3]);
  ```
- Kontrol: `php frame lang:check`

## 12. Medya (dosya yükleme)

1. `config/media.php`'de koleksiyon tanımlayın:
   ```php
   'product' => [
       'visibility' => 'public',                                 // public | private
       'mimes'      => ['image/jpeg', 'image/png', 'image/webp'],
       'max_mb'     => 10,
       'variants'   => ['thumb' => 320, 'medium' => 800],
       'permission' => 'media.upload',                           // null → giriş yapan herkes
   ],
   ```
2. Yükleyin:
   ```bash
   curl -X POST http://localhost:8000/v1/api/media \
     -H "Authorization: Bearer <token>" \
     -F collection=product -F file=@urun.jpg
   ```
3. Dönen `uuid`'i kendi tablonuzda saklayın; `urls` içindeki adresleri `<img>`'de kullanın.
4. Özel (private) dosya adresleri süreli imzalıdır; süresi dolunca `GET /v1/api/media/{uuid}` ile yenisini alın.
5. Silme: `DELETE /v1/api/media/{uuid}` — dosyalar `php frame media:prune` ile kalıcı silinir.

- Resimler WebP'ye çevrilir, EXIF/GPS bilgisi silinir.
- `media.upload` gibi izinleri admin API'den oluşturup rollere atayın.
- Dosyalar `storage/media/` altında durur; web'den doğrudan açılamaz, her istek PHP'den geçer.

**Medya ayarları (`.env`)**

| Ayar | Varsayılan | Ne işe yarar |
|---|---|---|
| `MEDIA_SIGNING_KEY` | — | Özel dosya adreslerini imzalar. 32+ karakter, `SECRET_KEY`'den farklı |
| `MEDIA_SIGNING_KEY_PREVIOUS` | boş | Anahtar değiştirirken eskisini buraya yazın; `2×MEDIA_SIGNED_TTL` sonra boşaltın |
| `MEDIA_SIGNED_TTL` | `600` | İmzalı adres ömrü (sn) |
| `MEDIA_PUBLIC_BASE_URL` | `APP_URL/media` | Public görsel adres tabanı. CDN kullanıyorsanız CDN adresi |
| `MEDIA_SENDFILE` | `none` | Dosyayı kim gönderir: `none` (PHP) \| `apache` \| `nginx` — bkz. [Yayına alma](#17-yayına-alma) |
| `MEDIA_NGINX_INTERNAL_PREFIX` | `/_protected_media` | `MEDIA_SENDFILE=nginx` iken internal location öneki |
| `MEDIA_IMAGE_DRIVER` | `auto` | `auto` \| `gd` \| `imagick`. Sürücü yoksa resim yükleme 503 döner |
| `MEDIA_MAX_PIXELS` | `40000000` | Bundan büyük çözünürlüklü resim reddedilir |
| `MEDIA_MAX_DIMENSION` | `2560` | `full` varyantının en uzun kenarı (px) |
| `MEDIA_QUALITY` | `82` | WebP/AVIF kalitesi (40-100) |
| `MEDIA_AVIF` | `false` | WebP'ye ek olarak AVIF üret |
| `MEDIA_USER_QUOTA_MB` | `500` | Kullanıcı başına toplam alan (0 = sınırsız) |
| `MEDIA_CORP` | `cross-origin` | `Cross-Origin-Resource-Policy`. Ön yüz başka alan adındaysa `cross-origin` kalmalı |

## 13. Cache, log ve ayarlar

İhtiyacınız olanı constructor'a yazın:

```php
use System\Cache\CacheInterface;
use System\Logging\Contracts\LoggerInterface;
use System\Config\AppConfig;

public function __construct(
    private readonly CacheInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly AppConfig $app,
) {}
```

- **Cache:** `get`, `set($key, $value, $ttl)`, `has`, `delete`, `remember($key, $ttl, fn () => ...)`
- **Log:** `info`, `warning`, `error` ... → `storage/logs/`
- **Ayarlar:** `.env` değerlerini doğrudan okumayın; `AppConfig`, `DatabaseConfig`, `MediaConfig`, `AuthConfig` gibi hazır ayar sınıflarını enjekte edin.

## 14. HTML sayfa (Twig)

```bash
php frame make:controller site Home
```

```php
public function index(): void
{
    $html = $this->view('site', 'index', ['title' => 'Ana Sayfa']);   // app/site/views/index.twig
    $this->response()->htmlResponse(Status::OK, $html);
    return;
}
```

- Şablonlar otomatik kaçışlıdır; `|raw` kullanmayın.
- Şablonda `{{ APP_URL }}` hazırdır. Medya için: `{{ media_url(urun.gorsel, 'medium') }}`.

## 15. CLI komutları

| Komut | Ne yapar |
|---|---|
| `php frame serve [--port=8000]` | Geliştirme sunucusu |
| `php frame app:health` | Ortam ve ayar kontrolü (uyarıları gösterir) |
| `php frame app:about` | Ortam özeti |
| `php frame make:controller <modül> <Ad> [--api] [--resource]` | Controller |
| `php frame make:service <modül> <Ad> [--model=AdModel]` | Servis |
| `php frame make:model <modül> <Ad> [--table=tablo]` | Model |
| `php frame make:migration <ad> [--create=tablo \| --table=tablo]` | Migration |
| `php frame migrate` / `migrate:status` / `migrate:rollback` / `migrate:reset` | Migration yönetimi |
| `php frame lang:check` | Çeviri dosyalarını kontrol et |
| `php frame container:validate` | Bağımlılıkları doğrula |
| `php frame optimize` / `optimize:clear` | Route ve Twig cache'i üret / sil |
| `php frame media:prune [--orphans]` | Silinen medya dosyalarını temizle |
| `php frame auth:purge` | Süresi dolmuş sıfırlama kayıtlarını sil |
| `php frame monitor:purge` | Eski monitor kayıtlarını sil |

Her komut için: `php frame <komut> --help`

**Genel seçenekler** (tüm komutlarda)

| Seçenek | Ne yapar |
|---|---|
| `-h`, `--help` | Komutun yardımı |
| `-q`, `--quiet` | Normal çıktıyı kapatır (cron için) |
| `-n`, `--no-interaction` | Soru sormaz (CI/deploy için) |
| `-v`, `-vv`, `-vvv` | Ayrıntı seviyesi |
| `-V`, `--version` | Sürüm |

**Kısa adlar:** `health` (app:health), `about` (app:about), `opt` (optimize), `m:s` (make:service), `c:v` (container:validate)

**Sık kullanılan seçenekler**

| Komut | Seçenek |
|---|---|
| `app:health` | `--json` — makine-okunur çıktı. Çıkış kodu: `0` hazır, `1` veritabanı kullanılamıyor (uyarılar çıkış kodunu etkilemez) |
| `migrate` | `--force` production'da çalıştırmak için, `--step` tek tek çalıştırır |
| `migrate:rollback` | `--step=N` son N migration'ı geri alır, `--force` production için |
| `optimize` | Yalnızca `APP_PRODUCTION=true` iken üretir; `--force` her ortamda |
| `media:prune` | `--days=30` kaç gün önce silinenler, `--orphans` kaydı olmayan klasörler, `--dry-run` yalnızca raporlar |
| `monitor:purge` | `--days=N`, `--chunk=5000`, `--dry-run` |
| `auth:purge` | `--dry-run` |

## 16. Test

```bash
composer test               # hepsi
composer test:unit          # yalnızca unit testler
```

Functional testler çalışan bir sunucu ve test kullanıcısı ister:

1. `.env`'e ekleyin:
   ```ini
   PHPFRAME_TEST_EMAIL=admin@phpframe.local
   PHPFRAME_TEST_PASSWORD=...
   ```
2. Bu kullanıcıyı kaydedip `administrator` rolünü verin.
3. Sunucuyu başlatıp testleri çalıştırın:
   ```bash
   php frame serve --port=8899
   composer test:functional
   ```

- Test kullanıcısının satırlarını production `.env`'ine koymayın.

## 17. Yayına alma

**`.env`**

- `APP_PRODUCTION=true`
- `APP_URL` HTTPS olsun.
- `SECRET_KEY`, `MEDIA_SIGNING_KEY`, `MONITOR_TOKEN` güçlü ve birbirinden farklı olsun (32+ karakter).
- `CORS_ORIGINS`: ön yüz başka bir adresteyse açıkça yazın (`*` production'da kabul edilmez).
  ```ini
  CORS_ORIGINS=https://app.ornek.com,https://admin.ornek.com
  ```
- `TRUSTED_PROXIES`: önünde Nginx, Docker, Cloudflare veya load balancer varsa onların IP/CIDR aralıkları.
- `SMTP_*` gerçek değerlerle doldurulsun (parola sıfırlama e-postaları için).

**Sunucu**

- Web kökü **`public/`** klasörü olmalı; proje kökü web'den erişilemez olmalı.
- `storage/` yazılabilir olsun.
- Linux'ta: `chmod 640 .env`

**Apache**

- Gerekli modüller: `mod_rewrite`, `mod_headers`.
- Yönlendirme kuralları `public/.htaccess` içinde hazırdır; `.htaccess` okunabilsin diye `AllowOverride All` verin.
- Proje kökündeki ve `storage/` içindeki `.htaccess` dosyaları, web kökü yanlışlıkla oraya ayarlanırsa her şeyi reddeder.

```apache
<VirtualHost *:80>
    ServerName ornek.com
    DocumentRoot /yol/proje/public

    <Directory /yol/proje/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

`MEDIA_SENDFILE=apache` kullanacaksanız `mod_xsendfile` kurun ve medya klasörüne izin verin:

```apache
XSendFile On
XSendFilePath /yol/proje/storage/media
```

**Nginx + PHP-FPM**

- `.htaccess` dosyaları Nginx'te çalışmaz; aşağıdaki karşılığını yazın.
- Medya adresleri (`/media/p/...`, `/media/s/...`) `.webp`/`.pdf` ile biter ama diskte yoktur, PHP sunar. Resim uzantıları için ayrı bir `location` eklerseniz onda da `try_files $uri /index.php?$query_string;` kullanın.

```nginx
server {
    listen 80;
    server_name ornek.com;
    root /yol/proje/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass <php-fpm adresi>;
    }
}
```

`MEDIA_SENDFILE=nginx` kullanacaksanız aynı `server` bloğuna ekleyin (önek `MEDIA_NGINX_INTERNAL_PREFIX` ile aynı olmalı):

```nginx
location /_protected_media/ {
    internal;
    alias /yol/proje/storage/media/;
}
```

**Her dağıtımda**

```bash
composer install --no-dev --optimize-autoloader
php frame migrate --force
php frame optimize
php frame app:health
```

**Cron**

```cron
0 * * * *  cd /var/www/proje && php frame auth:purge -q
0 4 * * *  cd /var/www/proje && php frame monitor:purge -q
30 4 * * * cd /var/www/proje && php frame media:prune --orphans -q
```

---

Lisans: MIT — bkz. [LICENSE](LICENSE)
