# Aharam Deployment Guide — BigRock / cPanel Shared Hosting

## Prerequisites

- **PHP 7.4+** with PDO, PDO_MySQL, JSON, mbstring, openssl extensions
- **MySQL 5.7+** or MariaDB 10.3+
- **cPanel** access (BigRock/Hostinger/SiteGround compatible)
- Domain: `yourdomain.com`

---

## 1. Directory Structure on Server

```
public_html/
├── aharam/
│   ├── backend-api/          ← PHP REST API
│   ├── customer-app/         ← Customer web app
│   ├── restaurant-panel/     ← Restaurant dashboard
│   ├── delivery-app/         ← Delivery partner app
│   ├── admin-panel/          ← Admin console
│   └── database/             ← SQL files (delete after import)
```

For production with subdomains, map:
| Subdomain | Document Root |
|-----------|--------------|
| `api.yourdomain.com` | `public_html/aharam/backend-api` |
| `partner.yourdomain.com` | `public_html/aharam/restaurant-panel` |
| `rider.yourdomain.com` | `public_html/aharam/delivery-app` |
| `admin.yourdomain.com` | `public_html/aharam/admin-panel` |
| `yourdomain.com` | `public_html/aharam/customer-app` |

---

## 2. Database Setup

1. Log in to **cPanel → MySQL Databases**
2. Create database: `aharam_prod`
3. Create user: `aharam_user` with a strong password
4. **Privileges:** Grant ALL on `aharam_prod` to `aharam_user`
5. Open **phpMyAdmin** → select `aharam_prod`
6. Import `database/schema.sql` (creates all 20 tables)
7. Import `database/seed.sql` (demo data — skip in production or use your own)

---

## 3. Environment Configuration

Copy and edit the env file:
```bash
cp backend-api/.env.example backend-api/.env
```

Edit `backend-api/.env`:
```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com

DB_HOST=localhost
DB_NAME=aharam_prod
DB_USER=aharam_user
DB_PASS=your_strong_password_here

JWT_SECRET=change_this_to_a_64_char_random_string_use_openssl_rand

RAZORPAY_KEY_ID=rzp_live_xxxxxxxxxxxx
RAZORPAY_KEY_SECRET=your_razorpay_secret
RAZORPAY_WEBHOOK_SECRET=your_webhook_secret

CORS_ORIGIN=https://yourdomain.com,https://partner.yourdomain.com,https://rider.yourdomain.com,https://admin.yourdomain.com

UPLOAD_MAX_SIZE=5242880
UPLOAD_DIR=uploads/
```

Generate a secure JWT secret:
```bash
openssl rand -hex 32
```

---

## 4. File Permissions

Set via cPanel File Manager or FTP:
```bash
chmod 755 backend-api/
chmod 644 backend-api/.env
chmod 755 backend-api/uploads/
chmod 644 backend-api/**/*.php
```

**Important:** `.htaccess` in backend-api must be readable (644).

---

## 5. Apache / .htaccess

The `backend-api/.htaccess` already contains the correct rewrite rules. Ensure `mod_rewrite` is enabled on your host (it is on all major shared hosts).

If you're in a subdirectory (`/aharam/backend-api`), the existing `.htaccess` uses relative paths — this works correctly.

For subdomain deployment (recommended for production), no path prefix is needed and the rewrite rule works as-is.

---

## 6. Update Frontend API Base URLs

Before deploying, update `API_BASE` in each frontend JS file:

**customer-app/js/api.js** (line 1):
```javascript
const API_BASE = 'https://api.yourdomain.com';
```

**restaurant-panel/js/api-partner.js** (line 1):
```javascript
const API_BASE = 'https://api.yourdomain.com';
```

**delivery-app/js/api-delivery.js** (line 1):
```javascript
const API_BASE = 'https://api.yourdomain.com';
```

**admin-panel/js/api-admin.js** (line 1):
```javascript
const API_BASE = 'https://api.yourdomain.com';
```

---

## 7. Razorpay Setup

1. Create account at razorpay.com
2. Go to **Settings → API Keys** → Generate Test Key
3. Add `rzp_test_...` key to `.env` for testing
4. For live payments, complete KYC and switch to live keys
5. In Razorpay dashboard, add webhook:
   - URL: `https://api.yourdomain.com/payments/webhook`
   - Events: `payment.captured`, `payment.failed`
   - Copy webhook secret to `.env` `RAZORPAY_WEBHOOK_SECRET`

---

## 8. cPanel Cron Jobs

Go to **cPanel → Cron Jobs** and add these three:

**Daily Settlement** (runs at 2:00 AM):
```
0 2 * * * php /home/username/public_html/aharam/backend-api/cron/daily_settlement.php >> /home/username/logs/aharam_settlement.log 2>&1
```

**Auto Cancel Orders** (every 15 minutes):
```
*/15 * * * * php /home/username/public_html/aharam/backend-api/cron/auto_cancel_orders.php >> /home/username/logs/aharam_cancel.log 2>&1
```

**Weekly Cleanup** (Sundays at 3:00 AM):
```
0 3 * * 0 php /home/username/public_html/aharam/backend-api/cron/cleanup_records.php >> /home/username/logs/aharam_cleanup.log 2>&1
```

Replace `username` with your actual cPanel username.

**Alternative (web trigger):** If CLI cron access is limited, each script can be triggered via URL with a secret key:
```
https://api.yourdomain.com/cron/settlement?cron_key=YOUR_CRON_SECRET
```
Add `CRON_SECRET` to `.env` and use a URL monitoring service (e.g. cron-job.org — free tier) to call it on schedule.

---

## 9. SSL Certificate

Enable **Let's Encrypt** SSL in cPanel → SSL/TLS → AutoSSL for all subdomains. This is free and auto-renews.

After SSL:
- All `http://` in `.env` become `https://`
- Add HSTS header (already in `.htaccess`)

---

## 10. Admin Account Setup

After database import, the seed data creates an admin user:
- **Email:** `admin@aharam.in`
- **Password:** `Admin@123`

**Change this immediately** after first login via the API:
```bash
curl -X PATCH https://api.yourdomain.com/me/password \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"current_password":"Admin@123","new_password":"YourNewStrongPass"}'
```

---

## 11. Upload Directory

Create and protect the uploads directory:
```bash
mkdir -p backend-api/uploads/restaurants
mkdir -p backend-api/uploads/menu
chmod 755 backend-api/uploads/
```

Add a `.htaccess` inside `uploads/` to prevent PHP execution:
```apache
Options -ExecCGI
AddHandler cgi-script .php .php3 .php4 .php5 .phtml .pl .py .jsp .asp
```

---

## 12. Production Checklist

- [ ] `.env` has `APP_ENV=production` and `APP_DEBUG=false`
- [ ] JWT_SECRET is a random 64-char string (not the example)
- [ ] Razorpay live keys configured (after KYC)
- [ ] All frontend `API_BASE` URLs updated to HTTPS production URL
- [ ] SSL enabled on all subdomains
- [ ] `database/` directory deleted or blocked from web access
- [ ] Admin password changed from default
- [ ] Cron jobs configured in cPanel
- [ ] Webhook URL added in Razorpay dashboard
- [ ] Error logs path set and writable
- [ ] Test a full order flow end-to-end

---

## 13. Troubleshooting

**500 Error on API:**  
Check `backend-api/logs/error.log`. Common causes: `.env` not found, DB credentials wrong, missing PHP extension.

**CORS errors in browser:**  
Ensure `CORS_ORIGIN` in `.env` includes the frontend domain. Check no trailing slash.

**Rewrite not working:**  
Confirm `AllowOverride All` is set in Apache config (contact host if not). Add `RewriteBase /aharam/backend-api/` if deploying in subdirectory.

**Cron jobs not running:**  
Use the web-trigger approach with cron-job.org (free). Test by calling the URL manually first.

**JWT errors after password:**  
Ensure `JWT_SECRET` in `.env` has no spaces or newlines. Use `trim()` safe value.

---

## Support

For issues, check the project GitHub or contact the development team.
