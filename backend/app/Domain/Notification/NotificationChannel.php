<?php

declare(strict_types=1);

namespace App\Domain\Notification;

/** Canal por el que se avisa al prospecto o cliente. */
enum NotificationChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
}
