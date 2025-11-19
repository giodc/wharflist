# WharfList

Email collection and newsletter management system built with PHP, SQLite, and Franken UI.

## Features

- **Multi-List Management** - Create and manage multiple subscriber lists
- **CSV/XLSX Import** - Bulk import subscribers from spreadsheets
- **Embeddable Widget** - JavaScript widget for collecting emails on any website
- **Email Verification** - Double opt-in with automated verification emails
- **Bulk Email Sending** - Compose and send emails to subscriber lists
- **2FA Authentication** - TOTP-based two-factor authentication
- **Rate Limiting** - Built-in protection against spam and abuse
- **Domain Security** - Restrict widget usage to approved domains
- **SMTP Support** - Configure your own SMTP server for email delivery
- **Setup Wizard** - Easy installation process

## Requirements

- PHP 7.4 or higher
- SQLite 3
- Web server (Apache/Nginx)

## Installation

1. Clone or download WharfList to your web server
2. Ensure the `data/` directory is writable by the web server
3. Navigate to your installation URL (e.g., `https://yourdomain.com/wharflist/`)
4. Follow the setup wizard to:
   - Create your admin account
   - Configure SMTP settings
   - Set timezone preferences

## Configuration

### SMTP Settings
Configure your SMTP server in Settings > SMTP or during setup:
- SMTP Host
- SMTP Port (usually 587 for TLS)
- SMTP Username
- SMTP Password
- From Email Address

### Security Settings
- **Change Password** - Update your admin password
- **2FA** - Enable two-factor authentication using any TOTP app (Google Authenticator, Authy, etc.)

## Usage

### Creating a List
1. Navigate to **Lists**
2. Click **Create List**
3. Enter list name and description

### Adding a Site
1. Navigate to **Sites**
2. Click **Add Site**
3. Configure:
   - Site name
   - Domain (without http://)
   - Target list for subscribers
   - Optional custom fields

### Embedding the Widget
1. After creating a site, click the **Embed** button
2. Copy the generated code
3. Paste it into your website's HTML

Example embed code:
```html
<div id="wharflist-form"></div>
<script>
(function() {
    var script = document.createElement('script');
    script.src = 'https://yourdomain.com/wharflist/widget.js';
    script.setAttribute('data-api-key', 'YOUR_API_KEY');
    script.setAttribute('data-site-id', 'YOUR_SITE_ID');
    document.head.appendChild(script);
})();
</script>
```

### Importing Subscribers
1. Navigate to **Import**
2. Select target list
3. Upload CSV or XLSX file
   - Required column: `email`
   - Optional: any custom fields (name, phone, etc.)
4. Preview the data
5. Select site and confirm import
6. Option to skip email verification

Download sample CSV template from the import page.

### Sending Campaigns
1. Navigate to **Compose**
2. Select target list
3. Enter subject and message
4. Click **Send Email**

## Security Features

### Rate Limiting
- 5 subscription attempts per IP per site per hour
- Prevents spam and abuse

### Domain Validation
- Checks referer header against configured domain
- Blocks unauthorized widget usage

### Email Verification
- Double opt-in process
- Verification tokens expire after use

### 2FA
- TOTP-based authentication
- Compatible with standard authenticator apps

## File Structure

```
wharflist/
├── api.php              # Subscription API endpoint
├── auth.php             # Authentication class
├── compose.php          # Email composition interface
├── config.php           # Configuration
├── database.php         # Database management
├── index.php            # Dashboard
├── lists.php            # List management
├── login.php            # Login page
├── logout.php           # Logout handler
├── phpmailer.php        # SMTP email sending
├── settings.php         # Settings interface
├── setup.php            # Setup wizard
├── sites.php            # Site management
├── subscribers.php      # Subscriber management
├── verify.php           # Email verification handler
├── widget.js            # Embeddable widget
├── includes/
│   └── nav.php          # Navigation component
└── data/
    └── wharflist.db     # SQLite database
```

## API Endpoint

**POST** `/api.php`

Request body:
```json
{
  "api_key": "YOUR_API_KEY",
  "email": "user@example.com",
  "custom_data": {}
}
```

Response:
```json
{
  "success": true,
  "message": "Please check your email to verify your subscription"
}
```

## Support

For issues or questions, refer to the source code or create an issue in your repository.

## License

Open source - use freely for personal or commercial projects.
