<?php
declare(strict_types=1);
namespace Morshed\Notifications;

interface SmsGateway
{
    /** Sends an existing challenge's code. Code creation/storage belongs to Identity. */
    public function sendLoginCode(string $phone, string $code): SmsSubmission;
}

