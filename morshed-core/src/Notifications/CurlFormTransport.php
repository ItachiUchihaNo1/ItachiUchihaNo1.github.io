<?php
declare(strict_types=1);
namespace Morshed\Notifications;

final class CurlFormTransport implements FormTransport
{
    public function postForm(string $url,array $fields):array{return $this->request($url,$fields,false);}
    public function getForm(string $url,array $fields):array{return $this->request($url,$fields,true);}
    private function request(string $url, array $fields,bool $query): array
    {
        if ($url !== TsmsSendOnlyGateway::ENDPOINT || !function_exists('curl_init')) {
            throw new \RuntimeException('SMS transport unavailable.');
        }
        $payload=http_build_query($fields,'','&',PHP_QUERY_RFC3986);$ch = curl_init($url.($query?'?'.$payload:''));
        if ($ch === false) { throw new \RuntimeException('SMS transport unavailable.'); }
        $body = '';
        try {
            curl_setopt_array($ch, [
                CURLOPT_POST => !$query,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8', 'Accept: text/plain'],
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HEADER => false, CURLOPT_VERBOSE => false,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 4096) { return 0; }
                    $body .= $chunk; return strlen($chunk);
                },
            ]);
            if(!$query){curl_setopt($ch,CURLOPT_POSTFIELDS,$payload);}
            if (curl_exec($ch) === false) {
                // Do not include curl_error(), credentials, URL query or provider response in errors.
                throw new \RuntimeException('SMS submission status is uncertain.');
            }
            return ['http_status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body];
        } finally { curl_close($ch); }
    }
}

