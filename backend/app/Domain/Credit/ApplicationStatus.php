<?php

declare(strict_types=1);

namespace App\Domain\Credit;

/** Estado de la solicitud (credit_applications.application_status). */
enum ApplicationStatus: string
{
    case Draft = 'draft';
    case UnderReview = 'under_review';
    case PreApproved = 'pre_approved';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function isFinal(): bool
    {
        return $this === self::Approved || $this === self::Rejected || $this === self::Expired;
    }
}
