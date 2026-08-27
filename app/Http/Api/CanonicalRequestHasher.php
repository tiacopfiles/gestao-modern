<?php

namespace App\Http\Api;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use RuntimeException;

final class CanonicalRequestHasher
{
    public function hash(Request $request): string
    {
        $payload = $this->normalize($request->all());
        $canonical = [
            'method' => strtoupper($request->method()),
            'path' => '/'.$request->path(),
            'payload' => $payload,
        ];

        return hash('sha256', json_encode(
            $canonical,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            $path = $value->getRealPath();
            if (! is_string($path) || ! is_file($path)) {
                return ['uploaded_file' => 'unavailable', 'error' => $value->getError()];
            }

            $hash = hash_file('sha256', $path);
            if (! is_string($hash)) {
                throw new RuntimeException('Não foi possível calcular o hash seguro do upload.');
            }

            return [
                'uploaded_file' => 'sha256',
                'hash' => $hash,
                'size' => $value->getSize(),
            ];
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
