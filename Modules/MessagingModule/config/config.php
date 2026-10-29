<?php

/**
 * MessagingModule (2026-10-29): WhatsApp messages (click-to-chat now, the WhatsApp API later).
 * types: the message types in the popup order, each with its default texts (used until the owner saves Settings › WhatsApp
 * Messages, then copied into message_templates and edited there). space_texts: ready texts for one kind of space, offered as
 * "Start from" when adding a space version (code never knows which space is which). placeholders: {name} → MessagingService::variables().
 */
return [
    'name' => 'MessagingModule',

    'deposit_percent' => 25,

    'languages' => ['ar' => 'Arabic', 'en' => 'English'],

    'types' => [
        'booking_confirmation' => [
            'label' => 'Booking confirmation',
            'hint' => 'A new reservation.',
            'ar' => <<<'TXT'
أهلاً {member_name}،
تم تسجيل حجزك: {unit} – {space}، {period}.
لتأكيد الحجز، يرجى تحويل *{deposit_percent}% من إجمالي قيمة الحجز* ({deposit_amount} جنيه).

—> يرجى العلم أن *مبلغ التأكيد غير قابل للاسترداد في حالة إلغاء الحجز قبل موعد الحجز بأقل من 24 ساعة أو في حالة عدم الحضور*.
أما في حالة الإلغاء قبل موعد الحجز بـ *24 ساعة أو أكثر*، فيمكن استرداد مبلغ التأكيد.

يتم تأكيد الحجز نهائيًا بعد استلام مبلغ التأكيد.

شكرًا لتفهمكم وتعاونكم.
.....................

*بيانات التحويل:*
———
{payment_details}

يرجى إرسال صورة التحويل بعد إتمامه لتأكيد الحجز.
TXT,
            'en' => <<<'TXT'
Hello {member_name},
Your booking is registered: {unit} – {space}, {period}.
To confirm it, please transfer *{deposit_percent}% of the booking total* ({deposit_amount} EGP).

—> Please note: *the deposit is not refundable if the booking is cancelled less than 24 hours before its time, or in case of no-show*.
If it is cancelled *24 hours or more* before its time, the deposit can be refunded.

The booking is final once the deposit is received.

Thank you for your understanding and cooperation.
.....................

*Transfer details:*
———
{payment_details}

Please send a screenshot of the transfer once it is done to confirm the booking.
TXT,
        ],

        'renewal_reminder' => [
            'label' => 'Renewal reminder',
            'hint' => 'Before the renewal date.',
            'ar' => <<<'TXT'
اهلا بحضرتك يا فندم
حاببين نفكر حضرتك ان اشتراك الـ {space} ({unit}) معانا في {account_name} ينتهي بتاريخ ( {end_date} ).
قيمة التجديد {renewal_amount} جنيه، و منتظرين تشريف حضرتك للتجديد.

شكرا لثقتك فينا
{account_name}
TXT,
            'en' => <<<'TXT'
Hello,
This is a reminder that your {space} subscription ({unit}) with {account_name} ends on ( {end_date} ).
The renewal is {renewal_amount} EGP. We look forward to your renewal.

Thank you for your trust
{account_name}
TXT,
        ],

        'renewal_day' => [
            'label' => 'Renewal day',
            'hint' => 'On the renewal date.',
            'ar' => <<<'TXT'
اهلا بحضرتك يا فندم
حاببين نفكر حضرتك ان النهارده ( {renewal_date} ) معاد تجديد اشتراك الـ {space} ({unit}) معانا في {account_name}.
قيمة التجديد {renewal_amount} جنيه.

شكرا لثقتك فينا
{account_name}
TXT,
            'en' => <<<'TXT'
Hello,
This is a reminder that today ( {renewal_date} ) is the renewal date of your {space} subscription ({unit}) with {account_name}.
The renewal is {renewal_amount} EGP.

Thank you for your trust
{account_name}
TXT,
        ],

        'after_renewal' => [
            'label' => 'After-renewal reminder',
            'hint' => 'After the renewal date, while not renewed / not paid.',
            'ar' => <<<'TXT'
اهلا بحضرتك يا فندم
حاببين نبلغ حضرتك ان اشتراك الـ {space} ({unit}) معانا في {account_name} كان معاد تجديده يوم ( {renewal_date} ) و لسه ما اتجددش.
قيمة التجديد {renewal_amount} جنيه، يا ريت تشرفنا للتجديد في اقرب وقت.

شكرا لثقتك فينا
{account_name}
TXT,
            'en' => <<<'TXT'
Hello,
Your {space} subscription ({unit}) with {account_name} was due for renewal on ( {renewal_date} ) and has not been renewed yet.
The renewal is {renewal_amount} EGP. We hope to see you for the renewal soon.

Thank you for your trust
{account_name}
TXT,
        ],

        'payment_reminder' => [
            'label' => 'Payment reminder',
            'hint' => 'A current period not fully paid, before its renewal date (e.g. a new member\'s first month).',
            'ar' => <<<'TXT'
اهلا بحضرتك يا فندم
حاببين نفكر حضرتك ان فيه مبلغ {due} جنيه متبقي على اشتراك الـ {space} ({unit}) للفترة {period}.
اجمالي المستحق على حضرتك {owing_total} جنيه.

*بيانات التحويل:*
{payment_details}

شكرا لثقتك فينا
{account_name}
TXT,
            'en' => <<<'TXT'
Hello,
This is a reminder that {due} EGP is still due on your {space} subscription ({unit}) for the period {period}.
Your total due is {owing_total} EGP.

*Transfer details:*
{payment_details}

Thank you for your trust
{account_name}
TXT,
        ],

        'contract_end' => [
            'label' => 'Contract end',
            'hint' => 'When the period is marked Left.',
            'ar' => <<<'TXT'
اهلا بحضرتك يا فندم
حاببين نبلغ حضرتك ان اشتراك الـ {space} معانا في {account_name} انتهي من تاريخ ( {end_date} ) و نظرا لعدم التجديد تم انهاء الاشتراك.
نتمنى نشوف حضرتك تاني قريب.

شكرا لثقتك فينا طوال فتره الاشتراك
{account_name}
TXT,
            'en' => <<<'TXT'
Hello,
We would like to inform you that your {space} subscription with {account_name} ended on ( {end_date} ) and, as it was not renewed, it has been closed.
We hope to see you again soon.

Thank you for your trust throughout your subscription
{account_name}
TXT,
        ],
    ],

    // "Start from" texts for a space version (Settings › WhatsApp Messages › + Space Version)
    'space_texts' => [
        'virtual_office' => [
            'label' => 'Virtual Office',
            'texts' => [
                'contract_end' => [
                    'ar' => <<<'TXT'
اهلا بحضرتك يا فندم
حاببين نبلغ حضرتك ان للأسف عقد الـ {space} معانا في {account_name} انتهي من تاريخ ( {end_date} ).
و نظرا لعدم التجديد توقفنا عن استلام اي مراسلات خاصة بك سواء من البنوك او الجهات الحكومية مثل الضرائب و التأمينات او اي استعلامات عن الشركة.

شكرا لثقتك فينا طوال فتره التعاقد
{account_name}
TXT,
                    'en' => <<<'TXT'
Hello,
We regret to inform you that your {space} contract with {account_name} ended on ( {end_date} ).
As it was not renewed, we have stopped receiving any mail on your behalf, whether from banks or government bodies such as the tax authority and social insurance, and any inquiries about the company.

Thank you for your trust throughout the contract
{account_name}
TXT,
                ],
                'renewal_reminder' => [
                    'ar' => <<<'TXT'
اهلا بحضرتك يا فندم
حاببين نبلغ حضرتك ان عقد الـ {space} معانا في {account_name} ينتهي بتاريخ ( {end_date} ).
منتظرين تشريف حضرتك للتجديد و استمرار خدماتنا في استلام اي مراسلات خاصة بك سواء من البنوك او الجهات الحكومية مثل الضرائب و التأمينات او اي استعلامات عن الشركة.

شكرا لثقتك فينا
{account_name}
TXT,
                    'en' => <<<'TXT'
Hello,
We would like to inform you that your {space} contract with {account_name} ends on ( {end_date} ).
We look forward to your renewal, so we can keep receiving your mail from banks or government bodies such as the tax authority and social insurance, and any inquiries about the company.

Thank you for your trust
{account_name}
TXT,
                ],
            ],
        ],
    ],

    // {name} → what it is (the settings page list); the values: MessagingService::variables()
    'placeholders' => [
        'member_name' => 'Member name',
        'member_first_name' => 'Member first name',
        'unit' => 'Unit',
        'space' => 'Space',
        'plan' => 'Plan',
        'start_date' => 'Start date',
        'end_date' => 'End date (last day)',
        'start_time' => 'Start time (time based plans)',
        'end_time' => 'End time (time based plans)',
        'period' => 'Start → end',
        'renewal_date' => 'Renewal date (the day after the end)',
        'renewal_amount' => 'Renewal amount (the renewal\'s, else this period\'s)',
        'amount' => 'Total of this reservation',
        'due' => 'Still due on this reservation',
        'owing_total' => 'Everything the member still owes',
        'days_left' => 'Days until the renewal date',
        'days_overdue' => 'Days since the renewal date',
        'left_date' => 'Date marked left',
        'reservation_no' => 'Reservation number',
        'deposit_percent' => 'Deposit % (settings)',
        'deposit_amount' => 'Total × deposit %',
        'payment_details' => 'Payment details (settings)',
        'account_name' => 'Your business name',
        'account_phone' => 'Your phone',
        'employee_name' => 'Who sends it',
    ],
];
