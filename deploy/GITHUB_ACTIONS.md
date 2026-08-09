# النشر التلقائي عبر GitHub Actions

يشغّل الملف `.github/workflows/deploy.yml` النشرَ تلقائيًا على الخادم عند كل **دمج/دفع إلى `main`**
(يشمل دمج Pull Requests)، أو يدويًا من تبويب **Actions → Deploy to production → Run workflow**.

يتّصل الإجراء بالخادم عبر SSH ويشغّل سكربت التحديث الجاهز `deploy/update.sh` الذي يتكفّل بـ:
`git pull` → `composer install --no-dev` → `php artisan migrate --force` → `npm ci && npm run build`
→ صلاحيات التخزين → `php artisan optimize` → إعادة تحميل opcache وworkers.

---

## المتطلّبات (تُضبط مرّة واحدة)

### 1) أسرار المستودع (GitHub → Settings → Secrets and variables → Actions → New repository secret)

| السرّ | مطلوب؟ | القيمة |
|------|--------|--------|
| `DEPLOY_SSH_HOST` | ✅ | عنوان الخادم (IP أو دومين)، مثل `203.0.113.10` |
| `DEPLOY_SSH_USER` | ✅ | مستخدم SSH — يُفضّل مستخدم النشر `deployer` (مالك الكود). يمكن استخدام `root`. |
| `DEPLOY_SSH_KEY`  | ✅ | **المفتاح الخاص** (private key) كاملًا لهذا المستخدم — انظر أدناه |
| `DEPLOY_SSH_PORT` | اختياري | منفذ SSH؛ الافتراضي `22` |
| `DEPLOY_APP_PATH` | اختياري | مسار المشروع؛ الافتراضي `/var/www/pluto-brand` |

### 2) توليد مفتاح SSH مخصّص للنشر (على جهازك أو الخادم)

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/pluto_deploy -N ""
```

- انسخ **المفتاح العام** إلى المستخدم على الخادم:
  ```bash
  ssh-copy-id -i ~/.ssh/pluto_deploy.pub DEPLOY_USER@SERVER_HOST
  # أو يدويًا: أضف محتوى pluto_deploy.pub إلى ~/.ssh/authorized_keys للمستخدم على الخادم
  ```
- ضع **المفتاح الخاص** كاملًا (بما فيه سطرا BEGIN/END) في السرّ `DEPLOY_SSH_KEY`:
  ```bash
  cat ~/.ssh/pluto_deploy
  ```

### 3) إعداد الخادم (لمرّة واحدة)

- تأكّد من وجود `deploy/deploy.env` على الخادم (منسوخ من `deploy/deploy.env.example` ومملوء).
- تأكّد أن `REPO_BRANCH="main"` في `deploy/deploy.env`.
- **إن كان مستخدم SSH غير root:** يحتاج `sudo` بلا كلمة مرور للأوامر التي يستخدمها `update.sh`
  (إعادة تحميل php-fpm وضبط صلاحيات التخزين). أضِف عبر `sudo visudo`:
  ```
  deployer ALL=(root) NOPASSWD: /bin/systemctl reload php8.3-fpm, /usr/bin/find, /bin/chmod
  ```
  (بدّل `deployer` و`php8.3-fpm` بما يطابق خادمك).

---

## كيف يعمل

1. تدمج Pull Request في `main` (أو تدفع مباشرةً).
2. ينطلق الإجراء `Deploy to production` تلقائيًا.
3. يتّصل بالخادم ويشغّل `deploy/update.sh`.
4. يظهر التحديث للزوّار بعد بناء الأصول (`npm run build`).

> **قفل التزامن:** `concurrency` يمنع تشغيل عمليتَي نشر متزامنتين؛ الدفعات المتتابعة تُنشر بالترتيب.

## التشغيل اليدوي

Actions → **Deploy to production** → **Run workflow** → اختر `main` → Run.

## استكشاف الأخطاء

- **`dubious ownership`**: يعالجه الإجراء تلقائيًا عبر `git config --global --add safe.directory`.
- **Composer يسأل عن root**: `update.sh` يضبط `COMPOSER_ALLOW_SUPERUSER=1` تلقائيًا.
- **`Permission denied (publickey)`**: تحقّق أن المفتاح العام في `authorized_keys` للمستخدم الصحيح،
  وأن `DEPLOY_SSH_USER`/`DEPLOY_SSH_HOST` صحيحان.
- **`sudo: a password is required`**: أضِف قاعدة NOPASSWD كما في الخطوة (3).
