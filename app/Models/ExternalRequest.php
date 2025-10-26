<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalRequest extends Model
{
    protected $table = 'external_requests';

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = true;

    protected $fillable = [
        'id',
        'run_id',
        'driver',
        'method',
        'url',
        'query_json',
        'req_headers',
        'req_body',
        'resp_status',
        'resp_headers',
        'resp_body',
        'duration_ms',
        'attempt',
        'outcome',
        'idempotency_key',
    ];

    protected $casts = [
        'query_json'   => 'array',
        'req_headers'  => 'array',
        'req_body'     => 'array',
        'resp_headers' => 'array',
        'resp_body'    => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'run_id');
    }

    /**
     * Override Laravel’s JSON encoding to prevent malformed UTF-8 errors.
     *
     * @param  mixed  $value
     * @param  int    $flags
     */
    protected function asJson($value, $flags = 0): string
    {
        // Merge custom flags with Laravel defaults
        $flags |= JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

        // Truncate large raw fields to avoid DB overflow (optional)
        if (is_array($value) && isset($value['raw']) && is_string($value['raw'])) {
            $value['raw'] = mb_substr($value['raw'], 0, 65000);
        }

        $encoded = json_encode($value, $flags);

        // Fallback — if json_encode still fails, sanitize recursively
        if ($encoded === false) {
            $value = $this->convertToUtf8Array($value);
            $encoded = json_encode($value, $flags);
        }

        return $encoded ?: '{}';
    }

    /**
     * Recursively convert string values to UTF-8 safe text or base64 fallback.
     */
    private function convertToUtf8Array(mixed $data): mixed
    {
        if (is_array($data)) {
            foreach ($data as $key => $val) {
                $data[$key] = $this->convertToUtf8Array($val);
            }
            return $data;
        }

        if (is_string($data)) {
            if (!mb_check_encoding($data, 'UTF-8')) {
                return base64_encode($data);
            }
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }

        return $data;
    }
}
