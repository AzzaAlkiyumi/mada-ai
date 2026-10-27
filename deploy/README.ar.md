# دليل نشر "رفيق" على سيرفر Contabo

السيرفر المعتمد: **Contabo Cloud VPS Core 4** — ‏4 vCPU، و8 GB RAM، و100 GB SSD، بسعر €5.50 شهريًا بعقد شهري وبدون رسوم إعداد (نحو 2.4 ريال، حسب السعر في 24 أكتوبر 2026، وقد تُضاف ضريبة عند الدفع).

> كل خطوة فيها دفع أو إنشاء حساب يقوم بها **أنت** بنفسك.

## ما الذي سيعمل على السيرفر

```
الإنترنت ──HTTPS──▶ Nginx ──▶ واجهة React (ملفات ثابتة)
                         └──▶ Laravel (PHP-FPM) ──▶ MySQL (127.0.0.1)
                                   └──▶ llama.cpp + Qwen3.5-4B (127.0.0.1:8080 + مفتاح)
الجدار الناري: المنافذ 22 و80 و443 فقط
```

## 1. قبل الطلب

1. **نطاق (Domain):** اشترِ نطاقًا أو استخدم نطاقًا فرعيًا تملكه. ستحتاجه لتفعيل HTTPS.
2. **مفتاح SSH** على جهازك (للدخول بدون كلمة مرور):
   ```powershell
   ssh-keygen -t ed25519
   ```
   سيُنشأ الملف `C:\Users\<اسمك>\.ssh\id_ed25519.pub` — هذا هو المفتاح العام الذي تلصقه في Contabo.

## 2. طلب السيرفر (تقوم به أنت)

- الخطة: **Cloud VPS Core 4**، المدة: **شهر واحد**.
- المنطقة: **Europe** (الأرخص؛ لا يوجد موقع في الشرق الأوسط).
- النظام: **Ubuntu 26.04** إن وُجد، وإلا **Ubuntu 24.04** (السكربت يتعامل مع الاثنين).
- أضف مفتاح SSH العام.
- لا تضف خدمات مدفوعة إضافية.

بعد التفعيل ستحصل على **عنوان IP**. في إعدادات النطاق أضف سجل **A** يشير إلى هذا العنوان.

## 3. تجهيز الحزمة على جهازك

من مجلد المشروع:

```powershell
powershell -ExecutionPolicy Bypass -File deploy\package-release.ps1
```

ينتج الملف `rafeeq-release.tar.gz` (أقل من 1 MB). لا يحتوي على `.env` ولا كلمات مرور ولا ملف النموذج — السكربت يرفض الحزمة إن وجدها.

## 4. الرفع والتثبيت

```powershell
scp rafeeq-release.tar.gz root@IP_السيرفر:/root/
ssh root@IP_السيرفر
```

على السيرفر:

```bash
cd /root && tar -xzf rafeeq-release.tar.gz deploy
sudo bash deploy/scripts/setup-server.sh your-domain.example
sudo bash deploy/scripts/install-release.sh /root/rafeeq-release.tar.gz
sudo certbot --nginx -d your-domain.example
cd /var/www/rafeeq/backend && sudo -u www-data php artisan user:create
```

- `setup-server.sh` يثبّت كل شيء ويحمّل النموذج (2.7 GB) ويتحقق من بصمته. يأخذ عادة 10–20 دقيقة.
- كلمات المرور والمفاتيح تُولَّد تلقائيًا وتُحفظ في `/etc/rafeeq/` (للمدير فقط)، ولا تُطبع على الشاشة.
- سجّل الدخول **بعد** تفعيل HTTPS؛ ملفات تعريف الجلسة مضبوطة لتعمل عبر HTTPS فقط.

## 5. تأمين الدخول (بعد التأكد أن الدخول بالمفتاح يعمل)

افتح نافذة جديدة وتأكد أن `ssh root@IP_السيرفر` يدخل **بدون** كلمة مرور، ثم:

```bash
sudo sed -i -E 's/^#?PasswordAuthentication .*/PasswordAuthentication no/' /etc/ssh/sshd_config
sudo systemctl restart ssh
```

## التشغيل اليومي

| المهمة | الأمر |
|---|---|
| حالة النموذج | `sudo systemctl status llama-server` |
| إيقاف النموذج | `sudo systemctl stop llama-server` |
| تشغيله | `sudo systemctl start llama-server` |
| إعادة تشغيله | `sudo systemctl restart llama-server` |
| سجلات النموذج | `sudo journalctl -u llama-server -n 100` |
| سجلات التطبيق | `sudo tail -n 100 /var/www/rafeeq/backend/storage/logs/laravel-*.log` |
| الذاكرة | `free -h` |
| المعالج | `top` |
| المساحة | `df -h` |
| تغيير كلمة المرور | `cd /var/www/rafeeq/backend && sudo -u www-data php artisan user:password` |

> **إيقاف خدمة النموذج لا يوقف رسوم Contabo.** السيرفر يُحاسَب شهريًا سواء كان يعمل أو لا؛ الطريقة الوحيدة لإيقاف الرسوم هي إلغاء الاشتراك.

## التحديث

على جهازك: أعد إنشاء الحزمة (الخطوة 3) وارفعها، ثم على السيرفر:

```bash
sudo bash /root/deploy/scripts/install-release.sh /root/rafeeq-release.tar.gz
```

يحتفظ بقاعدة البيانات و`.env` والملفات المخزنة، ويشغّل migrations الجديدة.

## النسخ الاحتياطي والاستعادة

- نسخة تلقائية يوميًا الساعة 3:30 في `/var/backups/rafeeq/`، يُحتفظ بآخر 14 يومًا.
- نسخة يدوية: `sudo rafeeq-backup-db`
- **انسخها إلى جهازك دوريًا** (النسخة على نفس السيرفر لا تحميك إن ضاع السيرفر):
  ```powershell
  scp "root@IP_السيرفر:/var/backups/rafeeq/*.sql.gz" .
  ```
- الاستعادة:
  ```bash
  gunzip -c /var/backups/rafeeq/assistant-YYYYmmdd-HHMM.sql.gz | sudo mysql assistant
  ```

## الحدود المتوقعة

- النموذج الصغير (4B): مناسب للأسئلة والشرح والأكواد البسيطة، وأضعف في التحليل المعقد.
- السرعة المتوقعة نحو 4–8 كلمات (tokens) في الثانية — **تقدير** يُقاس بعد التشغيل.
- مستخدم واحد في نفس اللحظة.

## الخصوصية

- النموذج لا يتصل بأي خدمة خارجية، والمحادثات تُحفظ في MySQL على السيرفر فقط.
- لكن **مزود الاستضافة يملك وصولًا ماديًا** للسيرفر والأقراص، والقرص غير مشفّر. لا تضع في المحادثات أسرارًا لا تحتمل هذا الخطر.
- ملفات النسخ الاحتياطي غير مشفّرة؛ احفظها في مكان آمن على جهازك.
