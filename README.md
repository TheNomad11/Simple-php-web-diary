
# 📔 Simple Php Web Diary (vibe coded)

A simple personal diary web application built with PHP and vanilla JavaScript.

**Coded mostly with Claude Sonnet 4.5 and double checked with other models. So use at your own risk!**

## 🌟 Features

- **Secure authentication**: Protected diary with username/password login
- **Image support**: Upload and display images with lightbox view
- **Search functionality**: Quickly find entries by keyword
- **On This Day memories**: View past entries from previous years
- **Responsive design**: Works well on desktop and mobile devices
- **Pagination**: Manage large numbers of entries easily
- **Simple file storage**: Entries saved as text files in a dedicated folder
- **Writing prompts and quick tags**: Get started writing easily

## 🛠️ Requirements

- PHP 7.4+ (with session, file, and GD extensions)
- Web server (Apache, Nginx, etc.) with PHP support

## 📂 Installation

1. Download latest release from Releases or lone this repository to your web server:

```bash
git clone https://github.com/TheNomad11/Simple-php-web-diary.git
```

3. Set up directory permissions:
   ```bash
   chmod -R 755 diary_entries
   chmod -R 755 diary_images
   ```

## 🔑 Configuration

Change the default credentials in `config.php`:
```php
define('AUTH_USERNAME', 'your_username');
define('AUTH_PASSWORD', password_hash('your_password', PASSWORD_DEFAULT));
```


**Important**: This application is designed for personal use only. For production use, consider implementing additional security measures and regular backups.
