<?php

declare(strict_types=1);

/**
 * Пример-хелпер Hosted Embedded Signup для партнёрского проекта.
 *
 * Самодостаточный, без внешних зависимостей — можно скопировать как есть.
 * Формирует подписанный payload для hosted-страницы `connect.dereu.*`
 * (`d`/`p`/`sig`) и верифицирует OUT-редирект (`result`/`sig`) тем же
 * `connect_signing_secret`.
 *
 * Схема подписи (симметричный HMAC-SHA256 над base64url-строкой, НЕ JWT) —
 * повторяет серверную `App\Domain\Connect\Domain\ConnectSignature`:
 *   d   = base64url(json_encode(payload))
 *   sig = base64url(HMAC-SHA256(строка d, connect_signing_secret))  // подписываем строку d, а не её JSON
 *   p   = key_prefix партнёрского credential (по нему сервер находит секрет)
 *
 * Секрет (`consec_...`) и `allowed_return_origins` выдаёт оператор Dereu
 * командой `dereu:issue-platform-key --connect-return-origin=...`.
 */
final class DereuConnect
{
    /**
     * @param  string  $connectSigningSecret  секрет `consec_...` (хранить в секрет-хранилище, не в git)
     * @param  string  $keyPrefix             `key_prefix` партнёрского credential (значение для `p`, напр. `plat_ab12cd`)
     */
    public function __construct(
        private readonly string $connectSigningSecret,
        private readonly string $keyPrefix,
    ) {}

    /**
     * Собрать подписанный набор `d`/`p`/`sig` для встраивания в виджет или ручной `window.open`.
     *
     * @param  string  $externalId  ваш internal org id (ключ идемпотентности, тот же что в M2M)
     * @param  string  $returnUrl   куда Dereu вернёт клиента; origin ДОЛЖЕН быть в `allowed_return_origins`
     * @param  string  $nonce       one-time случайная строка (сохраните на своей стороне для матчинга OUT)
     * @param  int     $ttlSeconds  время жизни payload, 5–10 минут (300–600)
     * @param  string|null  $companyName  опционально — имя компании для провижининга
     * @param  string|null  $accountMode  опц. режим онбординга (`business_only`|`coexistence`, также
     *                                    принимает Meta-native `cloud_api`/`smb_coexistence`); без него
     *                                    сервер дефолтит `business_only` — coexistence-попап (QR WhatsApp
     *                                    Business App) не покажется. `coexistence` требует включённой
     *                                    партнёру capability `coexistence`, иначе `403 coexistence_not_entitled`.
     * @return array{d: string, p: string, sig: string, nonce: string, exp: int}
     */
    public function buildSignedPayload(
        string $externalId,
        string $returnUrl,
        string $nonce,
        int $ttlSeconds = 600,
        ?string $companyName = null,
        ?string $accountMode = null,
    ): array {
        $exp = time() + $ttlSeconds;

        $payload = [
            'external_id' => $externalId,
            'return_url' => $returnUrl,
            'nonce' => $nonce,
            'exp' => $exp,
        ];
        if ($companyName !== null && $companyName !== '') {
            $payload['company_name'] = $companyName;
        }
        if ($accountMode !== null && $accountMode !== '') {
            $payload['account_mode'] = $accountMode;
        }

        $d = self::base64UrlEncode((string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return [
            'd' => $d,
            'p' => $this->keyPrefix,
            'sig' => self::sign($d, $this->connectSigningSecret),
            'nonce' => $nonce,
            'exp' => $exp,
        ];
    }

    /**
     * URL hosted-страницы с query-параметрами (эквивалент того, что делает widget.js при `window.open`).
     *
     * @param  string  $connectUrl  напр. `https://connect.dereu.chat/connect`
     */
    public function buildConnectUrl(string $connectUrl, string $externalId, string $returnUrl, string $nonce, int $ttlSeconds = 600, ?string $companyName = null, ?string $accountMode = null): string
    {
        $signed = $this->buildSignedPayload($externalId, $returnUrl, $nonce, $ttlSeconds, $companyName, $accountMode);

        return $connectUrl.'?'.http_build_query([
            'd' => $signed['d'],
            'p' => $signed['p'],
            'sig' => $signed['sig'],
        ]);
    }

    /**
     * Единственный статус, означающий успех — WABA активна и можно отправлять.
     * Литерала `success` в контракте НЕТ, не проверяйте по нему.
     */
    public const STATUS_CONNECTED = 'connected';

    /**
     * `pending` — WABA заведена, но ещё не подтверждена Meta (редкий edge-кейс,
     * не happy-path). НЕ declined — дозреет до `connected`, не финализировать
     * как успех, но и не показывать как отказ.
     */
    public const STATUS_PENDING = 'pending';

    /** Терминальные/отказные статусы: номер приостановлен или токен затёрт. */
    public const STATUSES_FAILED = ['suspended', 'deleted'];

    /**
     * Проверить и распарсить OUT-редирект `return_url?result=<b64>&sig=<hmac>`.
     *
     * Верифицирует `sig` тем же секретом (constant-time), декодирует `result`.
     * Вызывающий ОБЯЗАН дополнительно сматчить `nonce` со своим сохранённым (one-time)
     * и разобрать `status` по {@see self::STATUS_CONNECTED}/{@see self::STATUS_PENDING}/
     * {@see self::STATUSES_FAILED} — успех финализируется ТОЛЬКО при `status === 'connected'`.
     *
     * @return array{dereu_company_id: string, phone_number_id: string, waba_id: string, status: string, nonce: string}|null
     *         null — подпись неверна или payload битый (обрабатывать как отказ)
     */
    public function verifyResult(string $result, string $providedSig): ?array
    {
        if (! hash_equals(self::sign($result, $this->connectSigningSecret), $providedSig)) {
            return null;
        }

        $json = self::base64UrlDecode($result);
        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }

        foreach (['dereu_company_id', 'phone_number_id', 'waba_id', 'status', 'nonce'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field])) {
                return null;
            }
        }

        /** @var array{dereu_company_id: string, phone_number_id: string, waba_id: string, status: string, nonce: string} $data */
        return $data;
    }

    public static function sign(string $message, string $secret): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', $message, $secret, true));
    }

    public static function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): ?string
    {
        $b64 = strtr($value, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($b64, true);

        return $decoded === false ? null : $decoded;
    }
}

/*
 * Пример использования
 * --------------------
 *
 * $connect = new DereuConnect(
 *     connectSigningSecret: getenv('DEREU_CONNECT_SECRET'),   // consec_...
 *     keyPrefix: getenv('DEREU_CONNECT_PREFIX'),              // напр. plat_ab12cd
 * );
 *
 * // 1. Перед показом кнопки — сгенерировать nonce и сохранить его (Redis/БД) на TTL:
 * $nonce = bin2hex(random_bytes(16));
 * // saveNonce($nonce, ttl: 600);
 *
 * // 2a. Отдать во виджет:
 * $signed = $connect->buildSignedPayload(
 *     externalId:  'org_123',
 *     returnUrl:   'https://app.partner.kz/whatsapp/connected',
 *     nonce:       $nonce,
 *     ttlSeconds:  600,
 *     accountMode: 'coexistence', // без этого поля сервер дефолтит business_only —
 *                                 // coexistence-попап (QR WhatsApp Business App) не покажется
 * );
 * // -> <script src=".../widget.js" data-connect-url="..." data-payload="{$signed['d']}"
 * //            data-prefix="{$signed['p']}" data-sig="{$signed['sig']}"></script>
 *
 * // 2b. Или собрать URL для ручного window.open:
 * $url = $connect->buildConnectUrl('https://connect.dereu.chat/connect', 'org_123',
 *     'https://app.partner.kz/whatsapp/connected', $nonce, accountMode: 'coexistence');
 *
 * // 3. На return_url — проверить OUT:
 * $data = $connect->verifyResult($_GET['result'] ?? '', $_GET['sig'] ?? '');
 * if ($data === null) { http_response_code(400); exit; }          // подпись/формат
 * if (! consumeNonce($data['nonce'])) { http_response_code(409); exit; }  // one-time, ваш стор
 *
 * // status: WabaStatus подключённой WABA, НЕ литерал 'success'. Финализировать
 * // успех можно ТОЛЬКО при 'connected'; 'pending' — ждать (не declined),
 * // 'suspended'/'deleted' — отказ.
 * if ($data['status'] !== DereuConnect::STATUS_CONNECTED) {
 *     if ($data['status'] === DereuConnect::STATUS_PENDING) {
 *         // показать «активируется», перепроверить статус компании позже
 *     } else {
 *         // suspended/deleted — отказ, показать ошибку клиенту
 *     }
 *     exit;
 * }
 * // сохранить $data['dereu_company_id'] / $data['phone_number_id'] / $data['waba_id'];
 *
 * // 4. Забрать api_key S2S (наружу в OUT он не отдаётся):
 * //    POST https://api.dereu.noderail.io/api/v1/platform/companies/org_123/api-key/reissue
 * //    Authorization: Bearer plat_<prefix>.<secret>   -> { "api_key": "dereu_..." }
 */
