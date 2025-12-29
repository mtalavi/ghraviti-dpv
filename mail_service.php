<?php
/**
 * DP Volunteer Email System (Final Version)
 * No libraries required. Just pure PHP & cURL.
 */

function sendDPVEmail($type, $userEmail, $userName, $data = [])
{
    // 1. تنظیمات حیاتی
    // API key from config.php (loaded from environment variable)
    $apiKey = defined('BREVO_API_KEY') ? BREVO_API_KEY : getenv('BREVO_API_KEY');
    if (!$apiKey) {
        return ['success' => false, 'message' => "Error: BREVO_API_KEY not configured"];
    }

    // نگاشت آیدی‌ها طبق تصاویر ارسالی شما
    $templateIds = [
        'welcome' => 1, // Welcome to DP Volunteer
        'pass_change' => 2, // Security Alert
        'event_reg' => 3, // Registration Confirmed
        'check_in' => 4, // Checked In
        'check_out' => 5, // Thank You
        'dp_code_change' => 6 // DP Code Changed Notification
    ];

    // بررسی اینکه تایپ ایمیل معتبر باشد
    if (!array_key_exists($type, $templateIds)) {
        return ['success' => false, 'message' => "Error: Invalid Email Type ('$type')"];
    }

    $templateId = $templateIds[$type];

    // اضافه کردن نام کاربر به لیست متغیرها (چون در همه قالب‌ها استفاده می‌شود)
    $params = array_merge(['NAME' => $userName], $data);

    // ساختار دیتا برای ارسال به Brevo
    $payload = [
        "to" => [
            [
                "email" => $userEmail,
                "name" => $userName
            ]
        ],
        "templateId" => $templateId,
        "params" => $params
    ];

    // شروع عملیات cURL (ارسال درخواست به سرور Brevo)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.brevo.com/v3/smtp/email"); // اندپوینت صحیح برای ایمیل‌های تراکنشی
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "accept: application/json",
        "api-key: " . $apiKey,
        "content-type: application/json"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // لاگ کردن نتیجه برای دیباگ
    if ($curlError) {
        return ['success' => false, 'message' => "Connection Error: " . $curlError];
    }

    // کدهای 200 تا 299 یعنی موفقیت
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'message' => "Email sent successfully!"];
    } else {
        return ['success' => false, 'message' => "API Error ($httpCode): " . $response];
    }
}
