<?php

return [

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Login OTP delivery. "mail" generates the code here and emails it; "fmdq" uses the
    | FMDQ OTP master API (generator / validator) that the legacy platform called.
    */
    'otp' => [
        'driver' => env('OTP_DRIVER', 'mail'),
        'url' => env('OTP_URL'),
        'app_id' => env('OTP_APP_ID'),
        'username' => env('OTP_USERNAME'),
        'password' => env('OTP_PASSWORD'),
        'type' => env('OTP_TYPE', 'numeric'),
        'length' => (int) env('OTP_LENGTH', 6),
    ],

    /*
    | Retail onboarding BVN check through iGree (FMDQ-hosted). The consent page returns a
    | temporary code which is exchanged server-side for the BVN record; the browser never
    | supplies BVN data directly. "manual" lets local/test environments skip iGree.
    */
    'igree' => [
        'driver' => env('IGREE_DRIVER', 'manual'),
        'url' => env('IGREE_URL'),
        'api' => env('IGREE_API'),
        'client' => env('IGREE_CLIENT'),
        'secret' => env('IGREE_SECRET'),
    ],

    // FMDQ QPay hosted checkout & Payment API (see App\Services\QPay).
    'qpay' => [
        'driver' => env('QPAY_DRIVER', env('APP_ENV') === 'testing' ? 'odrum' : 'api'),
        'frontend_url' => env('QPAY_FRONTEND_URL', 'http://localhost:5175'),
        'initiate_url' => env('QPAY_INITIATE_URL', 'http://localhost/qpay/api/payment/initiate'),
        'invoice_url' => env('QPAY_INVOICE_URL', 'http://localhost/qpay/api/payment/invoice'),
        'receipt_url' => env('QPAY_RECEIPT_URL', 'http://localhost/qpay/api/payment/receipt'),
        'base_url' => env('QPAY_BASE_URL', 'http://localhost/qpay/api'),
        'app_id' => env('QPAY_APP_ID', '30'),
        'username' => env('QPAY_USERNAME', 'fmrr_app_user'),
        'password' => env('QPAY_PASSWORD', 'securepassword123'),
        'company' => env('QPAY_COMPANY', 'FMDQ Group'),
        'currency' => env('QPAY_CURRENCY', 'NGN'),

        // Legacy / fallback odrum encryption settings
        'url' => env('QPAY_URL', 'https://10.10.16.47/qpay/odrum/'),
        'scode' => env('QPAY_SCODE', '09014444'),
        'bid_scode' => env('QPAY_BID_SCODE', env('APP_ENV') === 'production' ? null : env('QPAY_SCODE', '09014444')),
        'encryption_key' => env('QPAY_ENCRYPTION_KEY', 'AKY_45_EncryptToDecrypt_DHJA'),
        'encryption_iv' => env('QPAY_ENCRYPTION_IV', '1789123114561012'),
    ],

    // CSD lodgement transmission (CR s5.4). "file" generates the report for download only.
    'csd' => [
        'driver' => env('CSD_DRIVER', 'file'),
        'url' => env('CSD_LODGEMENT_URL'),
        'token' => env('CSD_LODGEMENT_TOKEN'),
        'notify_email' => env('CSD_NOTIFY_EMAIL'),
    ],

    // CAC public search (icrp.cac.gov.ng) for QII / Non-QII RC number and name checks.
    // "cac" calls the registry; "off" skips the check (tests, or if the registry is retired).
    'cac' => [
        'driver' => env('CAC_DRIVER', 'cac'),
        'url' => env('CAC_SEARCH_URL', 'https://authapp.cac.gov.ng/name_similarity_app/api/public_search/search'),
        'timeout' => (int) env('CAC_TIMEOUT', 15),
        // Path to a CA bundle (PEM) when PHP's default one does not trust the chain, e.g. behind
        // a TLS-inspecting proxy. Certificate checks always stay on.
        // Defaults to storage/certs/ca-bundle.pem when present (exported from the Windows trusted
        // roots on a PHP install that ships without a CA bundle).
        'ca_bundle' => env('CAC_CA_BUNDLE', is_file(storage_path('certs/ca-bundle.pem')) ? storage_path('certs/ca-bundle.pem') : null),
    ],

];

