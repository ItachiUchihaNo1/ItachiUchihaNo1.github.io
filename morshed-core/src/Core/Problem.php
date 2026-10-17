<?php
declare(strict_types=1);
namespace Morshed\Core;
final class Problem extends \RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $status = 400)
    { parent::__construct($message); }
}
