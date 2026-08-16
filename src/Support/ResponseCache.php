<?php

declare(strict_types=1);

namespace App\Support;

final class ResponseCache
{
    private const PAGE_CACHE_DIR = __DIR__ . '/../../storage/cache/pages';
    private const ASSET_CACHE_DIR = __DIR__ . '/../../storage/cache/assets';

    /**
     * @return list<string>
     */
    public static function defaultExcludedPatterns(): array
    {
        return [
            '/admin*',
            '/api/*',
            '/checkout*',
            '/contul-meu*',
            '/cos*',
            '/auth/*',
            '/login*',
            '/register*',
            '/webhook/*',
            '/health',
        ];
    }

    /**
     * @return array{served:bool, buffering:bool, cache_file:string, meta_file:string, ttl_seconds:int, path:string, query:string}
     */
    public static function beginRequest(string $method, string $requestUri, array $settings): array
    {
        $disabled = [
            'served' => false,
            'buffering' => false,
            'cache_file' => '',
            'meta_file' => '',
            'ttl_seconds' => 0,
            'path' => '',
            'query' => '',
        ];

        if (strtoupper(trim($method)) !== 'GET') {
            return $disabled;
        }

        $path = self::normalizePathFromUri($requestUri);
        if ($path === '' || self::isPathExcluded($path, $settings)) {
            return $disabled;
        }
        if (self::hasUnsafeSessionContext()) {
            return $disabled;
        }
        if (self::requestForcesNoCache()) {
            return $disabled;
        }

        $rule = self::resolveRuleForPath($path, $settings);
        if (!is_array($rule)) {
            return $disabled;
        }

        $queryRaw = parse_url($requestUri, PHP_URL_QUERY);
        $query = self::canonicalizeQueryString(is_string($queryRaw) ? $queryRaw : '');
        // Consimțământul intră în cheie: paginile diferă prin scripturile de
        // urmărire, iar o variantă „cu Analytics" nu are voie să ajungă la un
        // vizitator care a refuzat.
        $cacheKey = sha1($path . '|' . $query . '|' . CookieConsent::cheieCache());
        $cacheDir = self::cacheDirectory();
        $cacheFile = $cacheDir . '/' . $cacheKey . '.html';
        $metaFile = $cacheDir . '/' . $cacheKey . '.json';
        $ttlSeconds = max(60, (int) ($rule['ttl_seconds'] ?? 1800));
        // Cu disponibilitatea citită din ERP, o pagină ținută în cache o
        // jumătate de oră ar minți despre stoc — un produs epuizat ar apărea
        // în continuare la vânzare. Scurtăm la fereastra folosită oricum de
        // ErpStock, ca cele două să nu se contrazică.
        if ((string) ($settings['erp_stock_enabled'] ?? '0') === '1'
            && (string) ($settings['erp_enabled'] ?? '0') === '1') {
            $ttlSeconds = min($ttlSeconds, 60);
        }

        $meta = self::readMeta($metaFile);
        if (is_array($meta) && is_file($cacheFile)) {
            $expiresAt = (int) ($meta['expires_at'] ?? 0);
            if ($expiresAt > time()) {
                $etag = trim((string) ($meta['etag'] ?? ''));
                $lastModifiedTs = (int) ($meta['last_modified_ts'] ?? 0);
                if (self::matchesNotModified($etag, $lastModifiedTs)) {
                    if ($etag !== '') {
                        header('ETag: ' . $etag);
                    }
                    if ($lastModifiedTs > 0) {
                        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModifiedTs) . ' GMT');
                    }
                    header('Cache-Control: ' . (string) ($meta['cache_control'] ?? ('public, max-age=' . min($ttlSeconds, 86400))));
                    header('X-Page-Cache: HIT-304');
                    http_response_code(304);
                    return [
                        'served' => true,
                        'buffering' => false,
                        'cache_file' => '',
                        'meta_file' => '',
                        'ttl_seconds' => 0,
                        'path' => $path,
                        'query' => $query,
                    ];
                }

                $body = @file_get_contents($cacheFile);
                if (is_string($body) && $body !== '') {
                    $status = (int) ($meta['status_code'] ?? 200);
                    if ($status < 100 || $status > 599) {
                        $status = 200;
                    }
                    http_response_code($status);
                    header('Content-Type: text/html; charset=utf-8');
                    if ($etag !== '') {
                        header('ETag: ' . $etag);
                    }
                    if ($lastModifiedTs > 0) {
                        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModifiedTs) . ' GMT');
                    }
                    header('Cache-Control: ' . (string) ($meta['cache_control'] ?? ('public, max-age=' . min($ttlSeconds, 86400))));
                    header('X-Page-Cache: HIT');
                    echo $body;
                    return [
                        'served' => true,
                        'buffering' => false,
                        'cache_file' => '',
                        'meta_file' => '',
                        'ttl_seconds' => 0,
                        'path' => $path,
                        'query' => $query,
                    ];
                }
            } else {
                self::deleteFile($cacheFile);
                self::deleteFile($metaFile);
            }
        }

        ob_start();

        return [
            'served' => false,
            'buffering' => true,
            'cache_file' => $cacheFile,
            'meta_file' => $metaFile,
            'ttl_seconds' => $ttlSeconds,
            'path' => $path,
            'query' => $query,
        ];
    }

    /**
     * @param array{served:bool, buffering:bool, cache_file:string, meta_file:string, ttl_seconds:int, path:string, query:string} $context
     */
    public static function finishRequest(array $context): void
    {
        if (($context['buffering'] ?? false) !== true) {
            return;
        }

        $body = ob_get_clean();
        if (!is_string($body)) {
            return;
        }

        $statusCode = http_response_code();
        $headers = headers_list();
        $shouldCache = self::isResponseCacheable($statusCode, $headers, $body);
        if ($shouldCache) {
            $ttlSeconds = max(60, (int) ($context['ttl_seconds'] ?? 1800));
            $maxAge = max(0, min($ttlSeconds, 86400));
            $etag = '"' . sha1($body) . '"';
            $lastModifiedTs = time();
            $lastModifiedHttp = gmdate('D, d M Y H:i:s', $lastModifiedTs) . ' GMT';
            $cacheControl = 'public, max-age=' . $maxAge;
            header('Content-Type: text/html; charset=utf-8');
            header('ETag: ' . $etag, true);
            header('Last-Modified: ' . $lastModifiedHttp, true);
            header('Cache-Control: ' . $cacheControl, true);
            header('X-Page-Cache: MISS', true);

            self::ensureCacheDirectory();
            $cacheFile = (string) ($context['cache_file'] ?? '');
            $metaFile = (string) ($context['meta_file'] ?? '');
            if ($cacheFile !== '' && $metaFile !== '') {
                self::atomicWrite($cacheFile, $body);
                $meta = [
                    'path' => (string) ($context['path'] ?? ''),
                    'query' => (string) ($context['query'] ?? ''),
                    'status_code' => 200,
                    'created_at' => $lastModifiedTs,
                    'expires_at' => $lastModifiedTs + $ttlSeconds,
                    'last_modified_ts' => $lastModifiedTs,
                    'etag' => $etag,
                    'cache_control' => $cacheControl,
                ];
                self::atomicWrite($metaFile, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
            }
        } else {
            header('X-Page-Cache: BYPASS', true);
        }

        echo $body;
    }

    public static function purgePageCache(?string $pathPattern = null): int
    {
        $dir = self::cacheDirectory();
        if (!is_dir($dir)) {
            return 0;
        }

        $normalizedPattern = $pathPattern !== null ? self::normalizePathPattern($pathPattern) : '';
        $purged = 0;

        $metaFiles = glob($dir . '/*.json') ?: [];
        foreach ($metaFiles as $metaFile) {
            if (!is_string($metaFile) || !is_file($metaFile)) {
                continue;
            }
            $cacheFile = substr($metaFile, 0, -5) . '.html';
            if ($normalizedPattern !== '') {
                $meta = self::readMeta($metaFile);
                $metaPath = self::normalizePathPattern((string) ($meta['path'] ?? ''));
                if ($metaPath === '' || !self::matchesPattern($metaPath, $normalizedPattern)) {
                    continue;
                }
            }
            if (self::deleteFile($metaFile)) {
                $purged++;
            }
            self::deleteFile($cacheFile);
        }

        if ($normalizedPattern === '') {
            foreach (glob($dir . '/*.html') ?: [] as $htmlFile) {
                if (!is_string($htmlFile) || !is_file($htmlFile)) {
                    continue;
                }
                self::deleteFile($htmlFile);
            }
        }

        return $purged;
    }

    /**
     * @return array{entries:int, fresh:int, expired:int}
     */
    public static function pageCacheStats(): array
    {
        $dir = self::cacheDirectory();
        if (!is_dir($dir)) {
            return ['entries' => 0, 'fresh' => 0, 'expired' => 0];
        }
        $entries = 0;
        $fresh = 0;
        $expired = 0;
        $now = time();
        foreach (glob($dir . '/*.json') ?: [] as $metaFile) {
            if (!is_string($metaFile) || !is_file($metaFile)) {
                continue;
            }
            $meta = self::readMeta($metaFile);
            if (!is_array($meta)) {
                continue;
            }
            $entries++;
            $expiresAt = (int) ($meta['expires_at'] ?? 0);
            if ($expiresAt > $now) {
                $fresh++;
            } else {
                $expired++;
            }
        }
        return ['entries' => $entries, 'fresh' => $fresh, 'expired' => $expired];
    }

    /**
     * @return list<array{path_pattern:string, ttl_seconds:int}>
     */
    public static function parseRules(string $rawJson): array
    {
        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            return [];
        }
        return self::normalizeRules($decoded);
    }

    /**
     * @param array<int, mixed> $rawRules
     * @return list<array{path_pattern:string, ttl_seconds:int}>
     */
    public static function normalizeRules(array $rawRules): array
    {
        $normalized = [];
        $seen = [];
        foreach ($rawRules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $pathPattern = self::normalizePathPattern((string) ($rule['path_pattern'] ?? $rule['path'] ?? ''));
            if ($pathPattern === '') {
                continue;
            }
            $ttlSeconds = self::normalizeTtlSeconds($rule['ttl_seconds'] ?? $rule['ttl'] ?? 1800);
            $signature = $pathPattern . '|' . $ttlSeconds;
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $normalized[] = [
                'path_pattern' => $pathPattern,
                'ttl_seconds' => $ttlSeconds,
            ];
        }
        return $normalized;
    }

    public static function normalizePathPattern(string $raw): string
    {
        $candidate = trim($raw);
        if ($candidate === '') {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $candidate) === 1) {
            $parsed = parse_url($candidate, PHP_URL_PATH);
            $candidate = is_string($parsed) ? $parsed : '';
        }
        $candidate = trim($candidate);
        if ($candidate === '') {
            return '';
        }
        if (!str_starts_with($candidate, '/')) {
            $candidate = '/' . ltrim($candidate, '/');
        }
        $candidate = preg_replace('/#.*$/', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\?.*$/', '', $candidate) ?? $candidate;
        if ($candidate !== '/' && !str_contains($candidate, '*')) {
            $candidate = rtrim($candidate, '/');
        }
        if ($candidate === '') {
            $candidate = '/';
        }
        if (strlen($candidate) > 255) {
            $candidate = substr($candidate, 0, 255);
        }
        return $candidate;
    }

    public static function normalizeTtlSeconds(mixed $raw, int $default = 1800): int
    {
        $value = (int) $raw;
        if ($value <= 0) {
            $value = $default;
        }
        return max(60, min(604800, $value));
    }

    public static function normalizeCustomExclusions(string $raw): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $normalized = [];
        $seen = [];
        foreach ($lines as $line) {
            $pattern = self::normalizePathPattern((string) $line);
            if ($pattern === '' || in_array($pattern, self::defaultExcludedPatterns(), true)) {
                continue;
            }
            if (isset($seen[$pattern])) {
                continue;
            }
            $seen[$pattern] = true;
            $normalized[] = $pattern;
        }
        return implode("\n", $normalized);
    }

    public static function cacheDirectory(): string
    {
        return self::PAGE_CACHE_DIR;
    }

    public static function assetCacheDirectory(): string
    {
        return self::ASSET_CACHE_DIR;
    }

    public static function purgeAssetCache(): int
    {
        return self::purgeDirectoryFiles(self::assetCacheDirectory());
    }

    public static function applyAssetCacheHeaders(array $settings, string $requestUri): void
    {
        $path = self::normalizePathFromUri($requestUri);
        if (!str_starts_with($path, '/assets/') && !str_starts_with($path, '/uploads/')) {
            return;
        }
        if ((string) ($settings['cache_assets_enabled'] ?? '0') !== '1') {
            return;
        }

        $isUploads = str_starts_with($path, '/uploads/');
        $ttlKey = $isUploads ? 'cache_uploads_ttl_seconds' : 'cache_assets_ttl_seconds';
        $defaultTtl = $isUploads ? 604800 : 86400;
        $ttlSeconds = self::normalizeTtlSeconds($settings[$ttlKey] ?? $settings['cache_assets_ttl_seconds'] ?? $defaultTtl, $defaultTtl);
        $versioningMode = self::normalizeAssetVersioningMode(
            (string) ($settings['cache_assets_versioning_mode'] ?? ($settings['cache_assets_versioning'] ?? ''))
        );
        $cacheControl = 'public, max-age=' . $ttlSeconds;
        if ($versioningMode !== 'none') {
            $cacheControl .= ', immutable';
        }

        header('Cache-Control: ' . $cacheControl, true);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $ttlSeconds) . ' GMT', true);
        if ((string) ($settings['cache_assets_etag_enabled'] ?? '1') === '0') {
            header_remove('ETag');
        }
    }

    public static function assetVersionToken(array $settings): string
    {
        $mode = self::normalizeAssetVersioningMode((string) ($settings['cache_assets_versioning_mode'] ?? ''));
        if ($mode === 'none' && (string) ($settings['cache_assets_versioning'] ?? '0') === '1') {
            $mode = 'query';
        }
        if ($mode === 'none') {
            return '';
        }

        $token = trim((string) ($settings['cache_assets_version_token'] ?? ''));
        if ($token !== '') {
            return preg_replace('/[^a-zA-Z0-9_-]/', '', $token) ?? '';
        }

        return '';
    }

    public static function generateAssetVersionToken(): string
    {
        try {
            return bin2hex(random_bytes(6));
        } catch (\Throwable) {
            return substr(sha1((string) microtime(true)), 0, 12);
        }
    }

    public static function normalizeAssetVersioningMode(string $raw): string
    {
        $mode = strtolower(trim($raw));
        if (in_array($mode, ['query', 'hash'], true)) {
            return $mode;
        }
        if (in_array($mode, ['1', 'on', 'yes', 'true'], true)) {
            return 'query';
        }
        return 'none';
    }

    private static function resolveRuleForPath(string $path, array $settings): ?array
    {
        if ((string) ($settings['cache_pages_enabled'] ?? '0') !== '1') {
            return null;
        }
        $rules = self::parseRules((string) ($settings['cache_pages_rules_json'] ?? '[]'));
        foreach ($rules as $rule) {
            $pattern = (string) ($rule['path_pattern'] ?? '');
            if ($pattern === '') {
                continue;
            }
            if (self::matchesPattern($path, $pattern)) {
                return $rule;
            }
        }
        return null;
    }

    private static function isPathExcluded(string $path, array $settings): bool
    {
        $patterns = self::defaultExcludedPatterns();
        $custom = preg_split('/\r\n|\r|\n/', (string) ($settings['cache_exclusions_custom'] ?? '')) ?: [];
        foreach ($custom as $line) {
            $normalized = self::normalizePathPattern((string) $line);
            if ($normalized !== '') {
                $patterns[] = $normalized;
            }
        }
        foreach ($patterns as $pattern) {
            if (self::matchesPattern($path, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private static function matchesPattern(string $path, string $pattern): bool
    {
        $safePath = self::normalizePathPattern($path);
        $safePattern = self::normalizePathPattern($pattern);
        if ($safePath === '' || $safePattern === '') {
            return false;
        }
        if (!str_contains($safePattern, '*')) {
            return $safePath === $safePattern;
        }
        $regex = '#^' . str_replace('\*', '.*', preg_quote($safePattern, '#')) . '$#';
        return preg_match($regex, $safePath) === 1;
    }

    private static function normalizePathFromUri(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || trim($path) === '') {
            return '/';
        }
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }
        return self::normalizePathPattern($path);
    }

    private static function canonicalizeQueryString(string $query): string
    {
        if (trim($query) === '') {
            return '';
        }
        $params = [];
        parse_str($query, $params);
        if (!is_array($params) || $params === []) {
            return '';
        }
        self::ksortRecursive($params);
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function ksortRecursive(array &$value): void
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::ksortRecursive($item);
            }
        }
        unset($item);
    }

    private static function hasUnsafeSessionContext(): bool
    {
        if ((int) ($_SESSION['admin_id'] ?? 0) > 0) {
            return true;
        }
        if ((int) ($_SESSION['customer_user_id'] ?? 0) > 0) {
            return true;
        }
        if (!empty($_SESSION['flash']) || !empty($_SESSION['checkout_form'])) {
            return true;
        }
        $cartItems = (array) ($_SESSION['cart']['items'] ?? []);
        $itemsCount = 0;
        foreach ($cartItems as $qty) {
            $itemsCount += max(0, (int) $qty);
            if ($itemsCount > 0) {
                return true;
            }
        }
        return false;
    }

    private static function requestForcesNoCache(): bool
    {
        $cacheControl = strtolower(trim((string) ($_SERVER['HTTP_CACHE_CONTROL'] ?? '')));
        $pragma = strtolower(trim((string) ($_SERVER['HTTP_PRAGMA'] ?? '')));
        if (str_contains($cacheControl, 'no-cache') || str_contains($cacheControl, 'no-store')) {
            return true;
        }
        if (str_contains($pragma, 'no-cache')) {
            return true;
        }
        return false;
    }

    private static function isResponseCacheable(int $statusCode, array $headers, string $body): bool
    {
        if ($statusCode !== 200) {
            return false;
        }
        if (trim($body) === '') {
            return false;
        }
        $hasHtmlContentType = false;
        foreach ($headers as $header) {
            $lower = strtolower((string) $header);
            if (str_starts_with($lower, 'content-type:')) {
                $hasHtmlContentType = str_contains($lower, 'text/html');
            }
            if (str_starts_with($lower, 'location:') || str_starts_with($lower, 'set-cookie:')) {
                return false;
            }
            if (str_starts_with($lower, 'cache-control:') && (str_contains($lower, 'no-store') || str_contains($lower, 'private'))) {
                return false;
            }
        }
        return $hasHtmlContentType;
    }

    private static function matchesNotModified(string $etag, int $lastModifiedTs): bool
    {
        $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($etag !== '' && $ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            return true;
        }

        $ifModifiedSince = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if ($lastModifiedTs > 0 && $ifModifiedSince !== '') {
            $ifModifiedTs = strtotime($ifModifiedSince);
            if ($ifModifiedTs !== false && $ifModifiedTs >= $lastModifiedTs) {
                return true;
            }
        }
        return false;
    }

    private static function ensureCacheDirectory(): void
    {
        $dir = self::cacheDirectory();
        if (is_dir($dir)) {
            return;
        }
        @mkdir($dir, 0775, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function readMeta(string $metaFile): array
    {
        if (!is_file($metaFile)) {
            return [];
        }
        $raw = @file_get_contents($metaFile);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function atomicWrite(string $targetFile, string $contents): void
    {
        $dir = dirname($targetFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $targetFile . '.tmp';
        @file_put_contents($tmp, $contents, LOCK_EX);
        @rename($tmp, $targetFile);
    }

    private static function deleteFile(string $file): bool
    {
        if (!is_file($file)) {
            return false;
        }
        return @unlink($file);
    }

    private static function purgeDirectoryFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $deleted = 0;
        foreach (glob($dir . '/*') ?: [] as $entry) {
            if (!is_string($entry) || !is_file($entry)) {
                continue;
            }
            if (self::deleteFile($entry)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}

