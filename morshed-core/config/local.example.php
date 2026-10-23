<?php
// Copy to local.php in THIS PRIVATE folder, outside every document root. Never share it.
return [
    'environment' => 'staging',
    'app_key' => '', // At least 32 random bytes as 64 hex characters; offline helper provided.
    'origins' => ['site'=>'https://morshedapp.ir','admin'=>'https://admin.morshedapp.ir'],
    'allow_http_loopback' => false,
    'pretty_urls' => false, // Local test override. Operational site uses verified clean Apache routes; legacy app entry stays valid.
    'database' => ['dsn'=>'mysql:host=localhost;dbname=CPANEL_STAGING_DB;charset=utf8mb4','user'=>'','password'=>''],
    'setup' => ['enabled'=>false,'token_sha256'=>''],
    'sms' => ['enabled'=>false,'https_transport_confirmed'=>false,'method'=>'GET','environment'=>'staging',
        'allowed_recipients'=>[],'username'=>'','password'=>'','sender'=>''],
    'google'=>['enabled'=>false,'live_verified'=>false,'client_id'=>'','client_secret'=>'','redirect_uri'=>'https://morshedapp.ir/index.php?r=%2Fauth%2Fgoogle%2Fcallback'],
    'eitaa'=>['enabled'=>false,'live_verified'=>false,'app_token'=>''],
    // Private Jitsi configuration. Activate only after two-device and Eitaa checks.
    'jitsi'=>['enabled'=>false,'live_verified'=>false,'origin'=>'','app_id'=>'morshed','app_secret'=>'','subject'=>''],
    // Booking intake is enabled; media transport remains independently gated.
];
