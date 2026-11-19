# النشر على خادم

كل النظام يعمل بأمر واحد عبر Docker Compose: قاعدة البيانات، Redis، المحرك، البث، صانع السوق، الـ API، مجدول مراقبة الهامش، والواجهة.

## المتطلبات

- خادم Linux عليه Docker و Docker Compose.
- للتجربة: 2 نواة و 4 جيجابايت ذاكرة تكفي.

## التشغيل لأول مرة

```bash
git clone https://github.com/engaldhubayb22-creator/tadawul-virtual.git
cd tadawul-virtual
cp deploy/.env.example deploy/.env
```

عدّل `deploy/.env`:

1. `POSTGRES_PASSWORD`: كلمة مرور طويلة من حروف وأرقام فقط.
2. `APP_KEY`: ولّده بهذا الأمر ثم الصق الناتج كاملًا:

   ```bash
   echo "base64:$(openssl rand -base64 32)"
   ```

3. `APP_URL`: عنوان الموقع.

ثم:

```bash
docker compose -f deploy/docker-compose.yml --env-file deploy/.env up -d --build
```

الموقع على المنفذ 80. عند أول تشغيل تُنشأ الجداول وتُحمَّل 10 شركات تجريبية وحسابا صانع السوق.

## إنشاء حساب المدير

سجّل حسابًا عاديًا من الموقع، ثم:

```bash
docker compose -f deploy/docker-compose.yml --env-file deploy/.env exec api php artisan app:make-admin 05xxxxxxxx
```

سجّل الخروج والدخول، فيظهر زر «لوحة الإدارة».

## أوامر يومية

```bash
C="docker compose -f deploy/docker-compose.yml --env-file deploy/.env"
$C ps                      # حالة الخدمات
$C logs -f engine          # سجل المحرك
$C restart bot             # إعادة تشغيل صانع السوق
$C exec postgres pg_dump -U tadawul tadawul | gzip > backup-$(date +%F).sql.gz   # نسخة احتياطية
```

السوق يعمل من 10:00 إلى 22:00 بتوقيت الرياض. للتجربة خارج الوقت عطّل «تطبيق أوقات السوق» من لوحة الإدارة.

## قبل فتح الموقع للناس

- **HTTPS:** الحاوية تستمع على HTTP فقط. ضع أمامها ما ينهي TLS، مثل Cloudflare أو nginx مع certbot أو Caddy، ووجّهه إلى المنفذ 80. البث اللحظي يعمل على `wss` تلقائيًا عندما يُفتح الموقع بـ `https`.
- **النسخ الاحتياطي:** جدول دوري لأمر `pg_dump` أعلاه، مع حفظ النسخ خارج الخادم.
- **تحديث الجداول:** الجداول الآن تُنشأ من ملف واحد عند أول تشغيل فقط. تغيير الجداول بعد وجود بيانات حقيقية يحتاج ملفات ترحيل تدريجية، وهذه لم تُجهَّز بعد.
- **الشركات:** الموجود 10 شركات بأسعار تجريبية.

## تحديث الكود

```bash
git pull
docker compose -f deploy/docker-compose.yml --env-file deploy/.env up -d --build
```

الأوامر الموجودة في الطابور لحظة إعادة تشغيل المحرك لا تضيع: تُقرأ عند عودته، ودفاتر الأوامر تُعاد من قاعدة البيانات.

## ما لم يُختبر

ملفات هذا المجلد كُتبت في بيئة لا تشغّل Docker. الذي جُرِّب فعلًا: صحة ملف Compose، وخطوات بناء الـ API في وضع الإنتاج (تثبيت بدون حزم التطوير، تخزين الإعدادات والمسارات، إنشاء الجداول، وطلبات حقيقية)، وبناء برامج Go والواجهة. الذي لم يُجرَّب: بناء الصور نفسها، وإعداد nginx. أول تشغيل على الخادم هو اختبارها.
