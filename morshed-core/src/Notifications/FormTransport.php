<?php
declare(strict_types=1);
namespace Morshed\Notifications;

interface FormTransport
{
    /** @return array{http_status: int, body: string} */
    public function postForm(string $url, array $fields): array;
}

