<?php

return [
    'account_sid' => env("TWILIO_ACCOUNT_SID"),
    'auth_token' => env("TWILIO_AUTH_TOKEN"),
    'whatsapp_number' => env("TWILIO_WHATSAPP_NUMBER"),
    'whatsapp_number_receipts' => [
        env("TWILIO_WHATSAPP_PART1"),
        env("TWILIO_WHATSAPP_PART2"),
        env("TWILIO_WHATSAPP_PART3"),
    ],
];
