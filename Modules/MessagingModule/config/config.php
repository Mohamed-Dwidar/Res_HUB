<?php

/**
 * MessagingModule (2026-10-29; by name + "Suggest when" 2026-10-31): WhatsApp messages (click-to-chat now, the WhatsApp API later).
 * suggest_when: the optional "Suggest when" of a message (NULL = Never): the ★ in the popup (by the Schedule group / the list
 * filter / the reservation's state, MessagingService::suggest()) and the popup opened after a save (new_booking) / Mark left
 * (marked_left); a unit has at most one message of each value. starts: ready texts offered as "Start from" in the message form
 * (they fill the name, "Suggest when" and both texts; nothing is created from them). placeholders: {name} → MessagingService::variables().
 */
return [
    'name' => 'MessagingModule',

    'languages' => ['ar' => 'Arabic', 'en' => 'English'],

    'suggest_when' => [
        'new_booking' => ['label' => 'New booking', 'hint' => 'Starts today, a new reservation (the popup opens after saving it).'],
        'before_renewal' => ['label' => 'Before renewal', 'hint' => 'Next N days, before the renewal date.'],
        'renewal_day' => ['label' => 'Renewal day', 'hint' => 'Renews today.'],
        'after_renewal' => ['label' => 'After renewal (not paid)', 'hint' => 'Not paid, after the renewal date.'],
        'payment_due' => ['label' => 'Payment due', 'hint' => 'Not paid, before the renewal date (e.g. a new member\'s first month).'],
        'marked_left' => ['label' => 'Marked left', 'hint' => 'Left (the popup opens after Mark left).'],
    ],

    'starts' => [
        'booking_confirmation' => [
            'label' => 'Booking confirmation',
            'suggest_when' => 'new_booking',
            'ar' => <<<'TXT'
أهلاً {member_name}،
تم تسجيل حجزك :
تاريخ :  {start_date}
الوقت : من {start_time} الي {end_time}
تكلفة الحجز :  {amount} جنيه
العنوان : ٦٩٦ طريق الحرية - لوران - بجوار ش شعراوي - اعلي B-Tech - *الدور الثامن*
موقعنا علي جوجل ماب : https://g.page/pivot-coworking-space
--------
لتأكيد الحجز، يسعدنا استلام مقدم الحجز بقيمة *25%* من إجمالي قيمة الحجز خلال *ساعتين* من وقت إرسال الرسالة، *وذلك لضمان حجز الموعد وتثبيته*.

*في حالة عدم إتمام التحويل خلال هذه المدة، سيتم إلغاء الحجز تلقائيًا وإتاحة الموعد للحجوزات الأخرى.*

*يرجى العلم: أن مبلغ التأكيد غير قابل للاسترداد في حالة إلغاء الحجز قبل موعده بأقل من 24 ساعة، أو في حالة عدم الحضور.*

أما في حالة الإلغاء قبل موعد الحجز بـ 24 ساعة أو أكثر، فيمكن استرداد مبلغ التأكيد بالكامل.

يُعتبر الحجز مؤكدًا بشكل نهائي بعد استلام مبلغ التأكيد.

شكرًا لتفهمكم وتعاونكم
.....................

*بيانات التحويل:*
———
- انستاباي: *ah2020@instapay*
- أو من خلال الرابط:  *https://ipn.eg/S/ah2020/instapay/0WP43n*
- أو تحويل بنكي على رقم الموبايل: *01288989336*  
  *(حساب بنكي وليس محفظة)*

يرجى إرسال صورة التحويل بعد إتمامه لتأكيد الحجز.
TXT,
            'en' => <<<'TXT'
Hello {member_name},
Your booking is registered: {unit} – {space}, {period}.
To confirm it, please transfer *25% of the booking total* (the total is {amount} EGP).

—> Please note: *the deposit is not refundable if the booking is cancelled less than 24 hours before its time, or in case of no-show*.
If it is cancelled *24 hours or more* before its time, the deposit can be refunded.

The booking is final once the deposit is received.

Thank you for your understanding and cooperation.
.....................

*Transfer details:*
———
Write the transfer details here

Please send a screenshot of the transfer once it is done to confirm the booking.
TXT,
        ],

        'renewal_reminder' => [
            'label' => 'Renewal reminder',
            'suggest_when' => 'before_renewal',
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
            'suggest_when' => 'renewal_day',
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
            'suggest_when' => 'after_renewal',
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
            'suggest_when' => 'payment_due',
            'ar' => <<<'TXT'
اهلا بحضرتك يا فندم
حاببين نفكر حضرتك ان فيه مبلغ {due} جنيه متبقي على اشتراك الـ {space} ({unit}) للفترة {period}.
اجمالي المستحق على حضرتك {owing_total} جنيه.

*بيانات التحويل:*
اكتب بيانات التحويل هنا

شكرا لثقتك فينا
{account_name}
TXT,
            'en' => <<<'TXT'
Hello,
This is a reminder that {due} EGP is still due on your {space} subscription ({unit}) for the period {period}.
Your total due is {owing_total} EGP.

*Transfer details:*
Write the transfer details here

Thank you for your trust
{account_name}
TXT,
        ],

        'contract_end' => [
            'label' => 'Contract end',
            'suggest_when' => 'marked_left',
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

        // ready texts for one kind of space (code never knows which space is which)
        'virtual_office_end' => [
            'label' => 'Virtual Office contract end',
            'suggest_when' => 'marked_left',
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

        'virtual_office_renewal' => [
            'label' => 'Virtual Office renewal reminder',
            'suggest_when' => 'before_renewal',
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
        'renewal_amount' => 'Renewal amount (the renewal\'s; else the next price with the increase; else this period\'s)',
        'renewal_price' => 'Next renewal price with the increase (discount kept, before taxes; empty = no increase)',
        'renewal_increase' => 'Renewal increase, e.g. 10% or 500 (empty = no increase)',
        'amount' => 'Total of this reservation',
        'due' => 'Still due on this reservation',
        'owing_total' => 'Everything the member still owes',
        'days_left' => 'Days until the renewal date',
        'days_overdue' => 'Days since the renewal date',
        'left_date' => 'Date marked left',
        'reservation_no' => 'Reservation number',
        'account_name' => 'Your business name',
        'account_phone' => 'Your phone',
        'employee_name' => 'Who sends it',
    ],
];
