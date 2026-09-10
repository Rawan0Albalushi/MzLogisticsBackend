<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'trip_id',
    'receiver_name',
    'otp_verified',
    'photo_paths',
    'received_quantity',
    'signature_path',
    'document_path',
    'notes',
    'lat',
    'lng',
    'captured_at',
])]
class ProofOfDelivery extends Model
{
    protected $table = 'proofs_of_delivery';

    protected function casts(): array
    {
        return [
            'otp_verified' => 'boolean',
            'photo_paths' => 'array',
            'received_quantity' => 'decimal:2',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'captured_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
