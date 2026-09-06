<?php

namespace App\Enums;

enum AmountStyle: string
{
    case SeparateDrCr = 'separate_dr_cr';
    case SingleWithMarker = 'single_with_marker';
    case SignedSingle = 'signed_single';
}
