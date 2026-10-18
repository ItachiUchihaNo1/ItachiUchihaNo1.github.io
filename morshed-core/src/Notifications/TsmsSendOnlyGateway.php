<?php
declare(strict_types=1);
namespace Morshed\Notifications;

/** Send-only adaptation of the supplied Python client; not a public HTTP endpoint. */
final class TsmsSendOnlyGateway implements SmsGateway
{
    public const ENDPOINT = 'https://tsms.ir/url/tsmshttp.php';
    private array $config;
    private FormTransport $transport;
    /** @var callable|null Trusted parser installed AFTER confirming official success grammar. */
    private $successVerifier;

    public function __construct(array $privateConfig, FormTransport $transport, ?callable $successVerifier = null)
    {
        $this->config = $privateConfig; $this->transport = $transport; $this->successVerifier = $successVerifier;
    }

    public function sendLoginCode(string $phone, string $code): SmsSubmission
    {
        $phone = self::normalizePhone($phone);
        if (!preg_match('/^[0-9]{4}$/D', $code)) { throw new \InvalidArgumentException('Invalid login code format.'); }
        // Both explicit opt-ins are private deployment settings, not request parameters.
        if (($this->config['enabled'] ?? false) !== true || ($this->config['https_transport_confirmed'] ?? $this->config['https_post_confirmed'] ?? false) !== true) {
            return new SmsSubmission('disabled');
        }
        $allow = $this->config['allowed_recipients'] ?? [];
        if (($this->config['environment'] ?? 'staging') !== 'production') {
            if (!is_array($allow) || !in_array($phone, $allow, true)) { return new SmsSubmission('disabled'); }
        }
        foreach (['username', 'password', 'sender'] as $name) {
            if (!isset($this->config[$name]) || !is_string($this->config[$name]) || $this->config[$name] === '') {
                throw new \RuntimeException('SMS private configuration is incomplete.');
            }
        }
        if (!preg_match('/^[0-9]{3,20}$/D', $this->config['sender'])) {
            throw new \RuntimeException('Invalid SMS sender configuration.');
        }
        $fields = [
            'from' => $this->config['sender'], 'to' => $phone,
            'username' => $this->config['username'], 'password' => $this->config['password'],
            'message' => "مرشد؛ کد ورود: " . $code . "\nاین کد را به دیگران ندهید.",
        ];
        try { $method=$this->config['method']??'POST';if(!in_array($method,['GET','POST'],true)){throw new \RuntimeException('Unsupported SMS transport.');}if($method==='GET'){if(!method_exists($this->transport,'getForm')){throw new \RuntimeException('SMS GET transport unavailable.');}$response=$this->transport->getForm(self::ENDPOINT,$fields);}else{$response=$this->transport->postForm(self::ENDPOINT, $fields);} }
        catch (\Throwable $e) { return new SmsSubmission('submission_uncertain'); }
        // A timeout/unknown response may already have sent the SMS. Never auto-retry here.
        if (!isset($response['http_status'], $response['body']) || !is_int($response['http_status']) ||
            !is_string($response['body']) || strlen($response['body']) > 4096 ||
            $response['http_status'] < 200 || $response['http_status'] > 299) {
            return new SmsSubmission('submission_uncertain');
        }
        $body = trim($response['body']);
        // Error codes copied from the supplied Python reference; validate with provider before live use.
        if (in_array($body, ['1', '2', '3', '4', '5', '6', '7', '8', '9', '14'], true)) {
            return new SmsSubmission($body === '14' ? 'submission_uncertain' : 'provider_rejected', $body);
        }
        // No "any non-error means success" assumption. No raw provider body is exposed/logged.
        if ($this->successVerifier !== null) {
            try { $receipt = ($this->successVerifier)($body); }
            catch (\Throwable $e) { return new SmsSubmission('unverified_response'); }
            if (is_string($receipt) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $receipt)) {
                return new SmsSubmission('provider_accepted', null, $receipt);
            }
        }
        return new SmsSubmission('unverified_response');
    }

    public static function normalizePhone(string $phone): string
    {
        $phone = strtr(trim($phone), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        if (strpos($phone, '+98') === 0) { $phone = '0' . substr($phone, 3); }
        elseif (strpos($phone, '0098') === 0) { $phone = '0' . substr($phone, 4); }
        if (!preg_match('/^09[0-9]{9}$/D', $phone)) { throw new \InvalidArgumentException('Invalid Iranian mobile number.'); }
        return $phone;
    }
}

