<?php

namespace App\GP247\Plugins\DataImport\Application;

use GP247\Core\Api\Sync\OutboundUrlGuard;
use GP247\Core\Api\Sync\OutboundUrlRejected;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Downloads a product image named by URL in an import file, safely: the core's outbound guard
 * (https, public addresses only) is checked before the request and before every redirect, and
 * the connection is pinned to the checked addresses; the body is cut at the size limit; the
 * type comes from the content (JPEG / PNG / WebP / GIF — SVG and anything else is refused).
 * The stored name is derived from the URL, so the same URL is downloaded once, and nothing of
 * the request (name, path) decides where the file goes.
 *
 * @aidlc-unit data-import
 * @aidlc-story US-data-import-image-fetch
 */
final class ImageFetcher
{
    /** Folder, on the upload disk, of imported product images. */
    public const FOLDER = 'product/import';

    private const MAX_REDIRECTS = 3;

    /** Image type => file extension. */
    private const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];

    /**
     * Download one image.
     *
     * @param string $url
     * @return array{path: string|null, error: string|null} Public path to store on the product, or why it failed
     *         (not_https | credentials | port | unresolvable | blocked_address | curl_missing | redirects | http_<status> | connection | too_large | not_image | storage).
     */
    public function fetch(string $url): array
    {
        $url = trim($url);
        $known = $this->known($url);
        if ($known !== null) {
            return ['path' => $known, 'error' => null];
        }
        try {
            $body = $this->download($url);
        } catch (ImageRejected $e) {
            return ['path' => null, 'error' => $e->getMessage()];
        }
        $info = @getimagesizefromstring($body);
        $extension = is_array($info) ? (self::TYPES[$info[2]] ?? null) : null;
        if ($extension === null) {
            return ['path' => null, 'error' => 'not_image'];
        }
        $relative = self::FOLDER . '/' . sha1($url) . '.' . $extension;
        if (!$this->disk()->put($relative, $body)) {
            return ['path' => null, 'error' => 'storage'];
        }

        return ['path' => $this->publicPath($relative), 'error' => null];
    }

    /**
     * Public path of an image of this URL downloaded earlier, or null.
     *
     * @param string $url
     * @return string|null
     */
    public function known(string $url): ?string
    {
        foreach (self::TYPES as $extension) {
            $relative = self::FOLDER . '/' . sha1(trim($url)) . '.' . $extension;
            if ($this->disk()->exists($relative)) {
                return $this->publicPath($relative);
            }
        }

        return null;
    }

    /**
     * File on disk of a public path returned by fetch() (tests, clean-up).
     *
     * @param string $path
     * @return string
     */
    public static function absolute(string $path): string
    {
        $prefix = rtrim((string) self::diskConfig('url'), '/') . '/';

        return rtrim((string) self::diskConfig('root'), '/\\') . DIRECTORY_SEPARATOR . ltrim(str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path, '/');
    }

    /**
     * GET with the guard checked on every hop, the body cut at the limit.
     *
     * @param string $url
     * @return string
     * @throws ImageRejected
     */
    private function download(string $url): string
    {
        $max = (int) config('Plugins/DataImport.image_max_bytes', 5242880);
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $target = OutboundUrlGuard::check($url);
            } catch (OutboundUrlRejected $e) {
                throw new ImageRejected($e->getMessage());
            }
            if (!defined('CURLOPT_RESOLVE')) {
                // WHY fail closed: without cURL the connection cannot be pinned to the checked addresses.
                throw new ImageRejected('curl_missing');
            }
            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'stream' => true,
                    // WHY pin: resolving again inside the HTTP client could reach a different (internal) address.
                    'curl' => [CURLOPT_RESOLVE => array_map(
                        static fn ($ip) => $target['host'] . ':' . $target['port'] . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip),
                        $target['ips']
                    )],
                ])->connectTimeout(3)->timeout((int) config('Plugins/DataImport.image_timeout', 10))
                    ->withHeaders(['User-Agent' => 'GP247-DataImport/1.0', 'Accept' => 'image/*'])->get($url);
            } catch (\Throwable) {
                throw new ImageRejected('connection');
            }
            if ($response->redirect()) {
                $url = $this->resolveLocation($url, (string) $response->header('Location'));
                continue;
            }
            if (!$response->successful()) {
                throw new ImageRejected('http_' . $response->status());
            }
            if ((int) $response->header('Content-Length') > $max) {
                throw new ImageRejected('too_large');
            }

            return $this->read($response->toPsrResponse()->getBody(), $max);
        }

        throw new ImageRejected('redirects');
    }

    /**
     * Read a body stream up to $max bytes.
     *
     * @param \Psr\Http\Message\StreamInterface $stream
     * @param int $max
     * @return string
     * @throws ImageRejected When the body is larger.
     */
    private function read($stream, int $max): string
    {
        $body = '';
        while (!$stream->eof()) {
            $body .= $stream->read(65536);
            if (strlen($body) > $max) {
                $stream->close();
                throw new ImageRejected('too_large');
            }
        }

        return $body;
    }

    /**
     * Absolute URL of a redirect target.
     *
     * @param string $from
     * @param string $location
     * @return string
     */
    private function resolveLocation(string $from, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $location)) {
            return $location;
        }
        $parts = parse_url($from);
        $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return str_starts_with($location, '/') ? $base . $location : $base . '/' . ltrim($location, '/');
    }

    /**
     * @param string $relative
     * @return string
     */
    private function publicPath(string $relative): string
    {
        return rtrim((string) self::diskConfig('url'), '/') . '/' . $relative;
    }

    /**
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    private function disk()
    {
        return Storage::disk(self::diskName());
    }

    /**
     * @return string The disk the file manager uploads to.
     */
    private static function diskName(): string
    {
        return (string) config('lfm.disk', 'gp247');
    }

    /**
     * @param string $key
     * @return mixed
     */
    private static function diskConfig(string $key)
    {
        return config('filesystems.disks.' . self::diskName() . '.' . $key);
    }
}
