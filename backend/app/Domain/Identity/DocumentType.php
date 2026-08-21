<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/** Tipo de identificacion oficial aceptada (columna identity_documents.document_type). */
enum DocumentType: string
{
    case Ine = 'INE';
    case Passport = 'passport';
    case Other = 'other';
}
