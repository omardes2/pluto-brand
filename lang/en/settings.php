<?php

// System settings (Production).
return [
    'title' => 'System Settings',
    'tab_general' => 'General',
    'tab_openai' => 'AI',
    'tab_email' => 'Email',
    'tab_whatsapp' => 'WhatsApp',
    'tab_delivery' => 'Delivery',
    'tab_sales' => 'Sales',
    'tab_inventory' => 'Inventory',
    'tab_seo' => 'SEO',
    'tab_system' => 'System',

    'store_name' => 'Store name',
    'company_info' => 'Company information',
    'logo' => 'Logo',
    'favicon' => 'Favicon',

    'ai_enable' => 'Enable AI',
    'ai_model' => 'Model',
    'ai_key' => 'OpenAI API key',
    'secret_set' => '•••••••• (set — leave blank to keep)',
    'secret_unset' => 'not set',
    'secret_note' => 'Stored encrypted. May also be set in .env (takes priority when present).',

    'from_address' => 'From address',
    'from_name' => 'From name',

    'wa_enable' => 'Enable WhatsApp',
    'wa_endpoint' => 'API endpoint',
    'wa_token' => 'Access token',

    'default_fee' => 'Default delivery fee',
    'free_threshold' => 'Free-shipping threshold',
    'low_stock_threshold' => 'Low-stock alert threshold (default)',
    'low_stock_threshold_hint' => 'Alerts you when an item’s on-hand quantity reaches this number or below. Applied to every item without its own threshold. Zero disables the global alert.',

    'online_treasury' => 'Online-orders collection cashbox',
    'online_treasury_none' => '— Default cash cashbox —',
    'online_treasury_hint' => 'Website order payments are collected into this cashbox. POS payments stay in the cashier cashbox.',

    'meta_title' => 'Default meta title',
    'meta_description' => 'Default meta description',

    'maintenance' => 'Maintenance mode',
    'timezone' => 'Timezone',
    'currency' => 'Currency',
    'language' => 'Language',
];
