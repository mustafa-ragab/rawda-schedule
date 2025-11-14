# جدول الروضة - Rawda Schedule

نظام إدارة الجداول الأسبوعية للمكاتب

## المميزات

- إدارة المكاتب
- إضافة الجداول الأسبوعية
- رفع ملفات PDF وصور للجلسات
- عرض الجداول حسب الشهر والأسبوع
- تصدير الجداول كـ PDF مع روابط قابلة للنقر

## المتطلبات

- PHP 7.4 أو أحدث
- MySQL 5.7 أو أحدث
- Apache Server
- Composer (للمكتبات)

## التثبيت

1. استنساخ المشروع:
```bash
git clone https://github.com/mustafa-ragab/rawda-schedule.git
cd rawda-schedule
```

2. تثبيت المكتبات:
```bash
composer install
```

3. إعداد قاعدة البيانات:
- إنشاء قاعدة بيانات باسم `rawda_schedule`
- استيراد ملف `database.sql` (إن وجد)

4. إعداد الاتصال:
- تعديل بيانات الاتصال في `config.php` إذا لزم الأمر

## الاستخدام

- افتح `index.php` لعرض الجداول
- افتح `admin.php` لإضافة بيانات جديدة
- افتح `add_office.php` لإدارة المكاتب

## الرخصة

MIT License

