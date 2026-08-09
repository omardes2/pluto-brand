<?php

// إعدادات النظام (Production).
return [
    'title' => 'إعدادات النظام',
    'tab_general' => 'عام',
    'tab_openai' => 'الذكاء الاصطناعي',
    'tab_email' => 'البريد',
    'tab_whatsapp' => 'واتساب',
    'tab_delivery' => 'التوصيل',
    'tab_sales' => 'المبيعات',
    'tab_inventory' => 'المخزون',
    'tab_seo' => 'SEO',
    'tab_system' => 'النظام',

    'store_name' => 'اسم المتجر',
    'company_info' => 'معلومات الشركة',
    'logo' => 'الشعار',
    'favicon' => 'أيقونة الموقع (Favicon)',

    'ai_enable' => 'تفعيل الذكاء الاصطناعي',
    'ai_model' => 'النموذج',
    'ai_key' => 'مفتاح OpenAI',
    'secret_set' => '•••••••• (مضبوط — اتركه فارغًا للإبقاء)',
    'secret_unset' => 'غير مضبوط',
    'secret_note' => 'يُخزَّن مُشفَّرًا. يمكن أيضًا ضبطه في .env (له الأولوية إن وُجد).',

    'from_address' => 'بريد المُرسِل',
    'from_name' => 'اسم المُرسِل',

    'wa_enable' => 'تفعيل واتساب',
    'wa_endpoint' => 'رابط الـAPI',
    'wa_token' => 'رمز الوصول',

    'default_fee' => 'رسوم التوصيل الافتراضية',
    'free_threshold' => 'عتبة الشحن المجاني',
    'low_stock_threshold' => 'حدّ تنبيه نقص المخزون (افتراضي)',
    'low_stock_threshold_hint' => 'يُنبّهك عندما يصل المتوفّر من أي صنف لهذا الرقم أو أقل. يُطبَّق على كل صنف ليس له حدّ خاص. صفر = تعطيل التنبيه العام.',

    'online_treasury' => 'صندوق تحصيل طلبات الموقع (الأون لاين)',
    'online_treasury_none' => '— الصندوق النقدي الافتراضي —',
    'online_treasury_hint' => 'تُحصَّل دفعات طلبات الموقع الإلكتروني في هذا الصندوق. دفعات نقطة البيع تبقى في صندوق الكاشير.',

    'meta_title' => 'عنوان Meta الافتراضي',
    'meta_description' => 'وصف Meta الافتراضي',

    'maintenance' => 'وضع الصيانة',
    'timezone' => 'المنطقة الزمنية',
    'currency' => 'العملة',
    'language' => 'اللغة',
];
