<?php

namespace App\Models;

use Database\Factories\ConversionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversion extends Model
{
    /** @use HasFactory<ConversionFactory> */
    use HasFactory;

    protected $fillable = [
        'output_path',
        'file_count',
        'transaction_count',
        'status',
        'warnings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'warnings' => 'array',
            'file_count' => 'integer',
            'transaction_count' => 'integer',
        ];
    }
}
