<?php

namespace App\Data;

use App\Enums\AmountStyle;
use App\Enums\TargetField;

readonly class ColumnMapping
{
    /**
     * @param  array<int, TargetField>  $targets
     */
    public function __construct(
        public array $targets,
        public string $dateFormat = 'd/m/Y',
        public AmountStyle $amountStyle = AmountStyle::SeparateDrCr,
        public ?string $profileName = null,
    ) {}

    public function targetFor(int $columnIndex): TargetField
    {
        return $this->targets[$columnIndex] ?? TargetField::Ignore;
    }

    /**
     * @return array{targets: array<string, string>, date_format: string, amount_style: string, profile_name: ?string}
     */
    public function toArray(): array
    {
        $targets = [];

        foreach ($this->targets as $index => $field) {
            $targets[(string) $index] = $field->value;
        }

        return [
            'targets' => $targets,
            'date_format' => $this->dateFormat,
            'amount_style' => $this->amountStyle->value,
            'profile_name' => $this->profileName,
        ];
    }

    /**
     * @param  array{targets?: array<string, string>, date_format?: string, amount_style?: string, profile_name?: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        $targets = [];

        foreach ($data['targets'] ?? [] as $index => $value) {
            $targets[(int) $index] = TargetField::from($value);
        }

        return new self(
            targets: $targets,
            dateFormat: $data['date_format'] ?? 'd/m/Y',
            amountStyle: AmountStyle::from($data['amount_style'] ?? AmountStyle::SeparateDrCr->value),
            profileName: $data['profile_name'] ?? null,
        );
    }
}
