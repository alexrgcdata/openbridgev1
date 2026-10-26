<?php

declare(strict_types=1);

namespace OpenBridge\Support;

/**
 * Stable PHP response boundary shared by the FileMaker Data API and OData.
 *
 * Only the outer envelope is normalized. FileMaker record field names and
 * values are preserved exactly as returned by the selected transport.
 */
final class OpenBridgeResponseNormalizer
{
    /** Normalize a restCWP Data API result into the OpenBridge v1 envelope. */
    public static function dataApi(mixed $raw, array $context = []): array
    {
        if (self::isNormalized($raw)) {
            return $raw;
        }

        $wrapper = is_array($raw) ? $raw : [];
        $body = isset($wrapper['result']) && is_array($wrapper['result'])
            ? $wrapper['result']
            : $wrapper;
        $response = isset($body['response']) && is_array($body['response'])
            ? $body['response']
            : [];
        $dataInfo = isset($response['dataInfo']) && is_array($response['dataInfo'])
            ? $response['dataInfo']
            : [];
        $records = isset($response['data']) && is_array($response['data'])
            ? array_values(array_filter($response['data'], 'is_array'))
            : [];
        $messages = self::messages($body['messages'] ?? ($wrapper['messages'] ?? []));
        $httpStatus = self::httpStatus($wrapper);
        $ok = self::isSuccessful($httpStatus, $messages, $raw);

        return self::envelope(
            $ok,
            'data-api',
            $context,
            $records,
            isset($dataInfo['foundCount']) && is_numeric($dataInfo['foundCount'])
                ? (int) $dataInfo['foundCount']
                : null,
            null,
            $messages,
            array_filter([
                'dataInfo' => $dataInfo,
                'httpStatus' => $httpStatus,
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
            $body
        );
    }

    /** Normalize an OData protocol body or restCWP OData transport result. */
    public static function oData(mixed $raw, array $context = []): array
    {
        if (self::isNormalized($raw)) {
            return $raw;
        }

        $wrapper = is_array($raw) ? $raw : [];
        $isTransportWrapper = array_key_exists('response', $wrapper)
            && (array_key_exists('success', $wrapper) || array_key_exists('http_code', $wrapper));
        $body = $isTransportWrapper
            ? (is_array($wrapper['response']) ? $wrapper['response'] : [])
            : $wrapper;
        $error = isset($body['error']) && is_array($body['error']) ? $body['error'] : null;
        $records = [];
        if (isset($body['value']) && is_array($body['value'])) {
            $records = array_values(array_filter($body['value'], 'is_array'));
        } elseif (self::looksLikeODataEntity($body)) {
            $records = [$body];
        }

        $messages = $error === null ? [] : [[
            'code' => (string) ($error['code'] ?? 'odata_error'),
            'message' => self::odataMessage($error['message'] ?? null),
        ]];
        if ($messages === [] && !empty($wrapper['error'])) {
            $messages[] = ['code' => 'transport_error', 'message' => (string) $wrapper['error']];
        }
        $httpStatus = self::httpStatus($wrapper);
        $ok = isset($wrapper['success'])
            ? (bool) $wrapper['success'] && $error === null
            : self::isSuccessful($httpStatus, $messages, $raw);
        $meta = [];
        foreach ($body as $key => $value) {
            if (is_string($key) && str_starts_with($key, '@odata.')) {
                $meta[$key] = $value;
            }
        }
        if ($httpStatus !== null) {
            $meta['httpStatus'] = $httpStatus;
        }
        if (isset($wrapper['latency_ms']) && is_numeric($wrapper['latency_ms'])) {
            $meta['latencyMs'] = (float) $wrapper['latency_ms'];
        }

        return self::envelope(
            $ok,
            'odata',
            $context,
            $records,
            isset($body['@odata.count']) && is_numeric($body['@odata.count'])
                ? (int) $body['@odata.count']
                : null,
            isset($body['@odata.nextLink']) && is_string($body['@odata.nextLink'])
                ? $body['@odata.nextLink']
                : null,
            $messages,
            $meta,
            $body
        );
    }

    private static function envelope(
        bool $ok,
        string $transport,
        array $context,
        array $records,
        ?int $totalCount,
        ?string $nextLink,
        array $messages,
        array $meta,
        mixed $raw
    ): array {
        return [
            'ok' => $ok,
            'transport' => $transport,
            'operation' => (string) ($context['operation'] ?? 'request'),
            'database' => (string) ($context['database'] ?? ''),
            'resource' => (string) ($context['resource'] ?? ''),
            'records' => $records,
            'returnedCount' => count($records),
            'totalCount' => $totalCount,
            'nextLink' => $nextLink,
            'messages' => $messages,
            'meta' => $meta,
            'raw' => $raw,
        ];
    }

    private static function isNormalized(mixed $value): bool
    {
        return is_array($value)
            && isset($value['transport'], $value['records'], $value['messages'])
            && array_key_exists('ok', $value)
            && array_key_exists('returnedCount', $value);
    }

    private static function messages(mixed $messages): array
    {
        if (!is_array($messages)) {
            return [];
        }
        $normalized = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $normalized[] = [
                'code' => (string) ($message['code'] ?? ''),
                'message' => (string) ($message['message'] ?? ''),
            ];
        }
        return $normalized;
    }

    private static function httpStatus(array $wrapper): ?int
    {
        if (isset($wrapper['http_code']) && is_numeric($wrapper['http_code'])) {
            return (int) $wrapper['http_code'];
        }
        if (isset($wrapper['status']['http_code']) && is_numeric($wrapper['status']['http_code'])) {
            return (int) $wrapper['status']['http_code'];
        }
        return null;
    }

    private static function isSuccessful(?int $httpStatus, array $messages, mixed $raw): bool
    {
        if (!is_array($raw)) {
            return false;
        }
        if ($httpStatus !== null && ($httpStatus < 200 || $httpStatus >= 300)) {
            return false;
        }
        foreach ($messages as $message) {
            if ((string) ($message['code'] ?? '') !== '0') {
                return false;
            }
        }
        return $messages !== [] || $httpStatus !== null;
    }

    private static function looksLikeODataEntity(array $body): bool
    {
        if ($body === [] || isset($body['error']) || isset($body['messages'])) {
            return false;
        }
        foreach (array_keys($body) as $key) {
            if (!is_string($key) || !str_starts_with($key, '@odata.')) {
                return true;
            }
        }
        return false;
    }

    private static function odataMessage(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_array($value) && isset($value['value']) && is_string($value['value'])) {
            return $value['value'];
        }
        return 'OData request failed.';
    }
}
