
# 📔 Simple Php Web Diary (vibe coded)

A secure, feature-rich diary application built with pure PHP - no frameworks, no database required. Designed for personal use with a focus on simplicity, privacy, and ease of writing.


**Coded mostly with Claude Sonnet 4.5 and double checked with other models. So use at your own risk!**

## ✨ Features

### 📝 **Writing Experience**
- **Daily Writing Prompts** - Rotating prompts to inspire writing
- **Quick Context Tags** - Capture location, weather, mood, and plans
- **Entry Tags** - Organize entries by topic (#work, #travel, #ideas)
- **Auto-save Drafts** - Never lose your writing
- **Auto-linkify URLs** - Paste links, they become clickable automatically
- **Optional Content** - Quick tags can be your whole entry

### 🖼️ **Image Management**
- **Multiple Images Per Entry** - Upload as many as you need
- **Automatic Resizing** - Max 800px, maintains aspect ratio
- **EXIF Orientation** - Phone photos display correctly
- **Image Optimization** - 70% JPEG quality, ~60KB per image
- **Lightbox View** - Click to zoom full screen

### 🔍 **Navigation & Discovery**
- **Calendar View** - Visual monthly calendar with entry indicators
- **Date Navigation** - Jump to any month/year
- **Search** - Find entries by keyword
- **Tag Cloud** - Filter entries by tag with counts
- **"On This Day" Memories** - See entries from past years (1-10 years ago)

### 🔒 **Security & Privacy**
- **Password Authentication** - Secure login with bcrypt hashing
- **Session Management** - 1-hour timeout
- **CSRF Protection** - Form resubmission prevention
- **Input Sanitization** - XSS protection
- **Directory Traversal Protection** - File access restrictions
- **No Database** - All data in plain text files you control

### 📱 **Mobile Optimized**
- **Responsive Design** - Works on all screen sizes
- **Touch-Friendly** - Large tap targets (44-48px)
- **Sticky Save Button** - Always accessible on mobile
- **Collapsible Widgets** - Space-efficient interface

## 🚀 Installation

### Requirements
- PHP 7.4 or higher
- Web server (Apache/Nginx)
- GD extension (for image processing)
- EXIF extension (optional, for photo orientation)

### Quick Setup

1. **Clone this repository or download the latest release** :


2. **Set up directory permissions:**:
```bash
chmod -R 755 diary_entries
chmod -R 755 diary_images
```

3. **Configure authentication** - Edit `config.php`:
```php
define('AUTH_USERNAME', 'your_username');
define('AUTH_PASSWORD', password_hash('your_secure_password', PASSWORD_DEFAULT));
```

Generate password hash:
```bash
php -r "echo password_hash('your_password', PASSWORD_DEFAULT);"
```

## 📁 File Structure
```
diary-app/
├── index.php           # Main application
├── login.php          # Login page
├── logout.php         # Logout handler
├── config.php         # Configuration & auth
├── styles.css         # All styling
├── ReadMe.md          # This file
├── diary_entries/     # Text files (auto-created)
└── diary_images/      # Images (auto-created)
```

## 💾 Data Format

Entries are stored as plain text files:
- **Filename**: `YYYY-MM-DD_HHMM.txt`
- **Format**:
```
Title
["image1.jpg","image2.jpg"]
Location: Home
Weather: Sunny
Mood: Good
Plans: Work on project
Tags: work, ideas

Entry content here...
```

## 🎨 Customization

### Change Session Timeout
```php
// config.php
define('SESSION_TIMEOUT', 7200); // 2 hours
```

### Change Image Settings
```php
// index.php
define('MAX_IMAGE_SIZE', 20 * 1024 * 1024); // 20MB
$maxDimension = 1200; // Max 1200px images
$quality = 85; // JPEG quality 85%
```

### Change Entries Per Page
```php
// index.php
define('ENTRIES_PER_PAGE', 10);
```

### Customize Colors
Edit `styles.css` - main gradient:
```css
background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
```

## 🔧 Troubleshooting

### Can't Upload Images
- Check directory permissions: `chmod 755 diary_images`
- Verify PHP upload limits in `php.ini`:
```ini
upload_max_filesize = 20M
post_max_size = 25M
```

### Session Timeout Issues
- Adjust `SESSION_TIMEOUT` in `config.php`
- Check PHP session settings

### Images Wrong Orientation
- Ensure EXIF extension is enabled
- Check with: `php -m | grep exif`

## 🔐 Security Best Practices

1. **Change default credentials immediately**
2. **Use HTTPS** - Enable SSL on your web server
3. **Strong passwords** - 12+ characters, mixed case, numbers, symbols
4. **Regular backups** - Backup `diary_entries/` and `diary_images/`
5. **Restrict access** - Add `.htaccess` to directories:
```apache
# diary_entries/.htaccess
Options -Indexes
<Files "*.txt">
    Order Allow,Deny
    Deny from all
</Files>

# diary_images/.htaccess
Options -Indexes
```


## 🤝 Contributing

This is a personal project, but suggestions and improvements are welcome! Feel free to:
- Open issues for bugs
- Submit pull requests
- Fork and customize for your needs

## 📄 License

MIT License - Free to use and modify for personal use.

## 🙏 Acknowledgments

Built with:
- Pure PHP (no frameworks)
- Vanilla JavaScript
- CSS3 with flexbox/grid
- GD library for image processing

## 💡 Tips for Daily Use

- **Write consistently** - Even quick tags count as an entry
- **Use tags** - Makes finding entries easier later
- **Upload photos** - Visual memories are powerful
- **Trust auto-save** - It saves every 3 seconds
- **Explore "On This Day"** - Rediscover old entries

---

**Start journaling! 📝✨**

Questions or issues? Open an issue on GitHub.
