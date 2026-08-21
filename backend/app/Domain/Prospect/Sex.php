<?php

declare(strict_types=1);

namespace App\Domain\Prospect;

/** Sexo declarado (prospects.sex); coincide con la posicion 11 de la CURP. */
enum Sex: string
{
    case Male = 'H';
    case Female = 'M';
    case NotSpecified = 'X';
}
