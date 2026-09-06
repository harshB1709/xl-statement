<?php

namespace App\Enums;

enum TargetField: string
{
    case Date = 'date';
    case ValueDate = 'value_date';
    case Description = 'description';
    case Reference = 'reference';
    case Debit = 'debit';
    case Credit = 'credit';
    case Amount = 'amount';
    case DrCrMarker = 'dr_cr_marker';
    case Balance = 'balance';
    case AppendToDescription = 'append_to_description';
    case KeepAsExtra = 'keep_as_extra';
    case Ignore = 'ignore';

    public function label(): string
    {
        return match ($this) {
            self::Date => 'Date',
            self::ValueDate => 'Value Date',
            self::Description => 'Description',
            self::Reference => 'Reference',
            self::Debit => 'Debit',
            self::Credit => 'Credit',
            self::Amount => 'Amount',
            self::DrCrMarker => 'Dr/Cr marker',
            self::Balance => 'Balance',
            self::AppendToDescription => 'Append to Description',
            self::KeepAsExtra => 'Keep as extra column',
            self::Ignore => 'Ignore',
        };
    }
}
