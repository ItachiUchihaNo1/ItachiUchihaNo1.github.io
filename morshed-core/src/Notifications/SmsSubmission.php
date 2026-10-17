<?php
declare(strict_types=1);
namespace Morshed\Notifications;

final class SmsSubmission
{
    public string $status;
    public ?string $providerCode;
    public ?string $receipt;

    public function __construct(string $status, ?string $providerCode = null, ?string $receipt = null)
    {
        if (!in_array($status, ['disabled', 'provider_rejected', 'provider_accepted', 'unverified_response', 'submission_uncertain'], true)) {
            throw new \InvalidArgumentException('Invalid submission state.');
        }
        $this->status = $status; $this->providerCode = $providerCode; $this->receipt = $receipt;
    }

    // Acceptance is not proof of handset delivery or verified phone ownership.
    public function accepted(): bool { return $this->status === 'provider_accepted'; }
}

