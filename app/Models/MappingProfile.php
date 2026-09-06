<?php

namespace App\Models;

use App\Data\ColumnMapping;
use Database\Factories\MappingProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MappingProfile extends Model
{
    /** @use HasFactory<MappingProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'fingerprint',
        'name',
        'mapping',
        'times_used',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'last_used_at' => 'datetime',
            'times_used' => 'integer',
        ];
    }

    public function columnMapping(): ColumnMapping
    {
        return ColumnMapping::fromArray($this->mapping ?? []);
    }

    public function markUsed(): void
    {
        $this->forceFill([
            'times_used' => $this->times_used + 1,
            'last_used_at' => now(),
        ])->save();
    }
}
