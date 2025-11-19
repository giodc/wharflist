# Widget Debugging Guide

## "An error occurred. Please try again." - How to Debug

When you see this generic error, follow these steps to find the root cause:

### Step 1: Open Browser DevTools

**Chrome/Edge/Brave:**
- Press `F12` or `Cmd+Opt+I` (Mac) / `Ctrl+Shift+I` (Windows)

**Firefox:**
- Press `F12` or `Cmd+Opt+K` (Mac) / `Ctrl+Shift+K` (Windows)

### Step 2: Check Console Tab

Look for these log messages:

```
Submitting to: http://your-domain.com/api.php
API Response: {"success":true,"message":"..."}
Parsed data: {success: true, message: "..."}
Success/Error: {success: true, message: "..."}
```

**Common Console Errors:**

1. **HTTP error: 404**
   - API file not found
   - Check if `api.php` exists in your wharflist folder
   - Verify the URL in "Submitting to:" log

2. **HTTP error: 500**
   - PHP error on server
   - Check server error logs (see Step 4)
   - Database connection issue

3. **Invalid JSON response: <html>...**
   - API returning HTML instead of JSON
   - Usually means PHP error or wrong URL
   - Check "API Response:" log for HTML content

4. **CORS error**
   - Domain mismatch
   - Check if your site domain matches the configured domain

5. **Bot detected: honeypot filled**
   - Silent rejection (not an error, working as intended)

6. **Bot detected: too fast**
   - Submitted < 2 seconds after load
   - Wait a bit before testing

### Step 3: Check Network Tab

1. Click "Network" tab in DevTools
2. Submit the form
3. Look for `api.php` request
4. Click on it to see details

**Response Tab:**
- Should show JSON like: `{"success":true,"message":"..."}`
- If HTML, there's a PHP error

**Headers Tab:**
- Status Code should be `200 OK`
- Content-Type should be `application/json`

**Payload Tab:**
- Should show: `{"api_key":"...","email":"...","_t":123,"_hp":""}`

### Step 4: Check Server Logs

**Check PHP error log:**
```bash
# Common locations
tail -f /var/log/php_errors.log
tail -f /var/log/apache2/error.log
tail -f /var/log/nginx/error.log

# Or in your project
tail -f error_log
```

**Look for:**
```
API Exception: ...
Bot detected: honeypot filled for user@example.com
Bot detected: too fast submission
Email error: ...
```

### Step 5: Common Issues & Fixes

#### Issue: "Invalid API key"
```javascript
// Check your embed code has correct API key
script.setAttribute('data-api-key', 'YOUR_ACTUAL_KEY_HERE');
```

**Fix:**
1. Go to Sites page in admin
2. Find your site
3. Click "Embed"
4. Copy the FULL embed code with correct API key

#### Issue: "Missing required fields"
```javascript
// Console should show what was sent
Parsed data: {success: false, message: "Missing required fields"}
```

**Fix:** Email field is empty or API key is missing

#### Issue: "Domain not allowed"
```php
// In api.php around line 195
if ($refererHost && $refererHost !== $site['domain']) {
    sendResponse(false, 'Domain not allowed');
}
```

**Fix:**
1. Check HTTP_REFERER matches your site domain
2. Update domain in Sites settings
3. Test without domain check temporarily

#### Issue: "Too many attempts"
```
Rate limit exceeded
```

**Fix:**
- Wait 1 hour
- Or delete from rate_limits table in database
- Or increase limit in api.php (line 39)

#### Issue: Database errors
```
SQLSTATE[HY000]: ...
```

**Fix:**
1. Check `data/wharflist.db` exists
2. Check file permissions (should be writable)
3. Check database schema is initialized

### Step 6: Test API Directly

Create a test file `test-api.php`:

```php
<?php
// Test API directly
$data = [
    'api_key' => 'YOUR_API_KEY_HERE',
    'email' => 'test@example.com',
    '_hp' => '',
    '_t' => time() * 1000
];

$ch = curl_init('http://wharflist.test/api.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";

$json = json_decode($response, true);
print_r($json);
```

**Run it:**
```bash
php test-api.php
```

**Expected:**
```
HTTP Code: 200
Response: {"success":true,"message":"Please check your email..."}
```

### Step 7: Enable Debug Mode

**Temporarily add to api.php (top, after headers):**

```php
// DEBUG MODE - Remove in production!
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

**Re-test the form** - errors will show in API response

### Quick Checklist

- [ ] Database file exists and is writable
- [ ] API key is correct in embed code
- [ ] Site domain matches actual domain
- [ ] No double slashes in URLs
- [ ] Browser console shows API URL
- [ ] Network tab shows 200 response
- [ ] Response is valid JSON (not HTML)
- [ ] Server error logs checked
- [ ] Rate limit not exceeded
- [ ] Email field is filled

### Still Not Working?

**Try this minimal test:**

```html
<!-- Save as test.html in wharflist folder -->
<!DOCTYPE html>
<html>
<head><title>Widget Test</title></head>
<body>
<h1>Widget Test</h1>
<div id="wharflist-form"></div>
<script>
(function() {
    var script = document.createElement('script');
    script.src = '/widget.js';  // Relative path
    script.setAttribute('data-api-key', 'PASTE_YOUR_API_KEY');
    script.setAttribute('data-site-id', 'PASTE_YOUR_SITE_ID');
    script.onerror = function() { console.error('Failed to load widget.js'); };
    script.onload = function() { console.log('Widget.js loaded successfully'); };
    document.head.appendChild(script);
})();
</script>
</body>
</html>
```

Open: `http://wharflist.test/test.html`

This will help isolate the issue!
