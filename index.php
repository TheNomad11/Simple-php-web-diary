<?php
/**
 * Simple Diary Web Application
 * Main file: index.php
 * Handles all CRUD operations for diary entries with image support
 * Enhanced with writing prompts and quick tags
 * Now with CSRF protection
 */

// Start session and require authentication
session_name('diary_app_session');
session_start();

require_once 'config.php';
requireAuth(); // Redirect to login if not authenticated

// Generate CSRF token for this session
generateCsrfToken();

// Set timezone to Berlin
date_default_timezone_set('Europe/Berlin');

// Configuration
define('ENTRIES_DIR', __DIR__ . '/diary_entries');
define('IMAGES_DIR', __DIR__ . '/diary_images');
define('ENTRIES_PER_PAGE', 5);
define('MAX_IMAGE_SIZE', 12 * 1024 * 1024); // 12MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// Writing prompts that rotate daily - practical and easy to answer
$writingPrompts = [
    "Where are you right now?",
    "What do you see around you?",
    "What did you do today?",
    "What sounds do you hear at this moment?",
    "What's the weather like today?",
    "What did you eat today that you enjoyed?",
    "Who did you talk to today?",
    "Describe one object near you in detail.",
    "What time did you wake up today?",
    "How are you feeling right now?",
    "What are you wearing right now?",
    "What's one thing you noticed today?",
    "What's on your to-do list?",
    "What did you accomplish today?",
    "What are you thinking about?",
    "What's different about today?",
    "What made today memorable?",
    "What are you looking forward to?",
    "What did you learn or discover today?",
    "What's the first thing you remember from today?"
];

// Get daily prompt (same prompt for the whole day)
$promptIndex = date('z') % count($writingPrompts);
$todayPrompt = $writingPrompts[$promptIndex];

// Create directories if they don't exist
if (!is_dir(ENTRIES_DIR)) {
    mkdir(ENTRIES_DIR, 0755, true);
}
if (!is_dir(IMAGES_DIR)) {
    mkdir(IMAGES_DIR, 0755, true);
}

// Initialize variables
$message = '';
$messageType = '';
$editEntry = null;
$viewEntry = null;

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate date format (YYYY-MM-DD)
 */
function isValidDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

/**
 * Validate time format (HH:MM)
 */
function isValidTime($time) {
    return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time);
}

/**
 * Generate filename from date and time
 */
function generateFilename($date, $time) {
    $timeFormatted = str_replace(':', '', $time);
    return $date . '_' . $timeFormatted . '.txt';
}

/**
 * Parse filename to extract date and time
 */
function parseFilename($filename) {
    if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{4})\.txt$/', $filename, $matches)) {
        $date = $matches[1];
        $time = substr($matches[2], 0, 2) . ':' . substr($matches[2], 2, 2);
        return ['date' => $date, 'time' => $time];
    }
    return null;
}

/**
 * Handle image upload with automatic resizing
 */
function uploadImage($file) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed'];
    }
    
    if ($file['size'] > MAX_IMAGE_SIZE) {
        return ['success' => false, 'error' => 'Image size must be less than 12MB'];
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
        return ['success' => false, 'error' => 'Invalid image type'];
    }
    
    // Load image based on type
    switch ($mimeType) {
        case 'image/jpeg':
            $source = imagecreatefromjpeg($file['tmp_name']);
            break;
        case 'image/png':
            $source = imagecreatefrompng($file['tmp_name']);
            break;
        case 'image/gif':
            $source = imagecreatefromgif($file['tmp_name']);
            break;
        case 'image/webp':
            $source = imagecreatefromwebp($file['tmp_name']);
            break;
        default:
            return ['success' => false, 'error' => 'Unsupported image type'];
    }
    
    if (!$source) {
        return ['success' => false, 'error' => 'Failed to process image'];
    }
    
    // Fix EXIF orientation (for photos from phones/cameras)
    if ($mimeType === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        if ($exif && isset($exif['Orientation'])) {
            $orientation = $exif['Orientation'];
            
            switch ($orientation) {
                case 3:
                    $source = imagerotate($source, 180, 0);
                    break;
                case 6:
                    $source = imagerotate($source, -90, 0);
                    break;
                case 8:
                    $source = imagerotate($source, 90, 0);
                    break;
            }
        }
    }
    
    // Get original dimensions (after rotation)
    $origWidth = imagesx($source);
    $origHeight = imagesy($source);
    
    // Calculate new dimensions (max 800x800, maintain aspect ratio)
    $maxDimension = 800;
    if ($origWidth > $maxDimension || $origHeight > $maxDimension) {
        if ($origWidth > $origHeight) {
            $newWidth = $maxDimension;
            $newHeight = intval($origHeight * ($maxDimension / $origWidth));
        } else {
            $newHeight = $maxDimension;
            $newWidth = intval($origWidth * ($maxDimension / $origHeight));
        }
    } else {
        // Image is smaller than max, keep original size
        $newWidth = $origWidth;
        $newHeight = $origHeight;
    }
    
    // Create resized image
    $resized = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preserve transparency for PNG and GIF
    if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
        imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    // Resize
    imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
    
    // Generate filename
    $extension = 'jpg'; // Convert all to JPEG for consistency and smaller size
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $filepath = IMAGES_DIR . '/' . $filename;
    
    // Save as JPEG with 70% quality
    $saved = imagejpeg($resized, $filepath, 70);
    
    // Clean up
    imagedestroy($source);
    imagedestroy($resized);
    
    if ($saved) {
        return ['success' => true, 'filename' => $filename];
    }
    
    return ['success' => false, 'error' => 'Failed to save image'];
}

/**
 * Delete image file
 */
function deleteImage($filename) {
    if (empty($filename)) {
        return false;
    }
    
    // Security: prevent directory traversal
    $filename = basename($filename);
    
    $filepath = IMAGES_DIR . '/' . $filename;
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    return false;
}

/**
 * Format quick tags for storage
 */
function formatQuickTags($location, $weather, $mood, $plans) {
    $tags = [];
    if (!empty($location)) $tags[] = "Location: " . $location;
    if (!empty($weather)) $tags[] = "Weather: " . $weather;
    if (!empty($mood)) $tags[] = "Mood: " . $mood;
    if (!empty($plans)) $tags[] = "Plans: " . $plans;
    
    return !empty($tags) ? implode("\n", $tags) . "\n\n" : "";
}

/**
 * Format entry tags for storage (normalize and clean)
 */
function formatEntryTags($tagsInput) {
    if (empty($tagsInput)) return '';
    
    // Split by comma, trim whitespace, remove # if present
    $tags = array_map('trim', explode(',', $tagsInput));
    $tags = array_filter($tags); // Remove empty
    $tags = array_map(function($tag) {
        return ltrim($tag, '#'); // Remove leading #
    }, $tags);
    
    return !empty($tags) ? 'Tags: ' . implode(', ', $tags) . "\n\n" : '';
}

/**
 * Parse quick tags from content
 */
function parseQuickTags($content) {
    $tags = ['location' => '', 'weather' => '', 'mood' => '', 'plans' => ''];
    $entryTags = [];
    
    // Check if content starts with tags (format: Location: X\nWeather: Y\nMood: Z\nPlans: W\nTags: a, b, c)
    $lines = explode("\n", $content);
    $tagCount = 0;
    
    foreach ($lines as $line) {
        if (preg_match('/^Location:\s*(.+)$/', $line, $match)) {
            $tags['location'] = trim($match[1]);
            $tagCount++;
        } elseif (preg_match('/^Weather:\s*(.+)$/', $line, $match)) {
            $tags['weather'] = trim($match[1]);
            $tagCount++;
        } elseif (preg_match('/^Mood:\s*(.+)$/', $line, $match)) {
            $tags['mood'] = trim($match[1]);
            $tagCount++;
        } elseif (preg_match('/^Plans:\s*(.+)$/', $line, $match)) {
            $tags['plans'] = trim($match[1]);
            $tagCount++;
        } elseif (preg_match('/^Tags:\s*(.+)$/', $line, $match)) {
            $tagsStr = trim($match[1]);
            $entryTags = array_map('trim', explode(',', $tagsStr));
            $tagCount++;
        } elseif (!empty(trim($line))) {
            // Hit non-tag content, stop
            break;
        }
    }
    
    // Remove tag lines from content
    if ($tagCount > 0) {
        $contentLines = array_slice($lines, $tagCount);
        // Remove empty lines at the start
        while (!empty($contentLines) && empty(trim($contentLines[0]))) {
            array_shift($contentLines);
        }
        $content = implode("\n", $contentLines);
    }
    
    return ['tags' => $tags, 'entry_tags' => $entryTags, 'content' => $content];
}

/**
 * Save diary entry to file
 */
function saveEntry($filename, $title, $content, $images = []) {
    $filepath = ENTRIES_DIR . '/' . $filename;
    $imagesJson = json_encode($images);
    $data = $title . "\n" . $imagesJson . "\n" . $content;
    
    $fp = fopen($filepath, 'w');
    if ($fp && flock($fp, LOCK_EX)) {
        fwrite($fp, $data);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }
    
    if ($fp) {
        fclose($fp);
    }
    return false;
}

/**
 * Read diary entry from file
 */
function readEntry($filename) {
    // Security: prevent directory traversal
    $filename = basename($filename);
    $filepath = ENTRIES_DIR . '/' . $filename;
    
    if (!file_exists($filepath)) {
        return null;
    }
    
    $content = file_get_contents($filepath);
    $lines = explode("\n", $content, 3);
    
    $dateTime = parseFilename($filename);
    
    $images = [];
    if (isset($lines[1])) {
        $decoded = json_decode($lines[1], true);
        if (is_array($decoded)) {
            $images = $decoded;
        }
    }
    
    $entryContent = $lines[2] ?? '';
    $parsed = parseQuickTags($entryContent);
    
    return [
        'filename' => $filename,
        'date' => $dateTime['date'],
        'time' => $dateTime['time'],
        'title' => $lines[0] ?? '',
        'images' => $images,
        'content' => $entryContent,
        'content_without_tags' => $parsed['content'],
        'location' => $parsed['tags']['location'],
        'weather' => $parsed['tags']['weather'],
        'mood' => $parsed['tags']['mood'],
        'plans' => $parsed['tags']['plans'],
        'entry_tags' => $parsed['entry_tags']
    ];
}

/**
 * Delete diary entry and its images
 */
function deleteEntry($filename) {
    // Security: prevent directory traversal
    $filename = basename($filename);
    
    $entry = readEntry($filename);
    
    if ($entry && !empty($entry['images'])) {
        foreach ($entry['images'] as $image) {
            deleteImage($image);
        }
    }
    
    $filepath = ENTRIES_DIR . '/' . $filename;
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    return false;
}

/**
 * Get all entries for a specific month/year
 */
function getEntriesForMonth($year, $month) {
    $allEntries = getAllEntries();
    $monthEntries = [];
    
    $targetMonth = sprintf('%04d-%02d', $year, $month);
    
    foreach ($allEntries as $entry) {
        if (strpos($entry['date'], $targetMonth) === 0) {
            $monthEntries[] = $entry;
        }
    }
    
    return $monthEntries;
}

/**
 * Get days that have entries for a specific month
 */
function getDaysWithEntries($year, $month) {
    $entries = getEntriesForMonth($year, $month);
    $days = [];
    
    foreach ($entries as $entry) {
        $day = (int)date('j', strtotime($entry['date']));
        if (!in_array($day, $days)) {
            $days[] = $day;
        }
    }
    
    return $days;
}

/**
 * Get all diary entries sorted by date/time descending
 */
function getAllEntries() {
    $entries = [];
    $files = glob(ENTRIES_DIR . '/*.txt');
    
    if ($files === false) {
        return [];
    }
    
    foreach ($files as $filepath) {
        $filename = basename($filepath);
        $entry = readEntry($filename);
        if ($entry) {
            $entries[] = $entry;
        }
    }
    
    usort($entries, function($a, $b) {
        return strcmp($b['filename'], $a['filename']);
    });
    
    return $entries;
}

/**
 * Get all unique tags from all entries
 */
function getAllTags() {
    $allEntries = getAllEntries();
    $tagCounts = [];
    
    foreach ($allEntries as $entry) {
        if (!empty($entry['entry_tags'])) {
            foreach ($entry['entry_tags'] as $tag) {
                $tag = strtolower(trim($tag));
                if (!empty($tag)) {
                    if (!isset($tagCounts[$tag])) {
                        $tagCounts[$tag] = 0;
                    }
                    $tagCounts[$tag]++;
                }
            }
        }
    }
    
    // Sort by count (descending)
    arsort($tagCounts);
    
    return $tagCounts;
}

/**
 * Search entries by keyword in title and content
 */
function searchEntries($keyword) {
    $allEntries = getAllEntries();
    $results = [];
    
    $keyword = strtolower($keyword);
    
    foreach ($allEntries as $entry) {
        $titleMatch = stripos($entry['title'], $keyword) !== false;
        $contentMatch = stripos($entry['content'], $keyword) !== false;
        
        if ($titleMatch || $contentMatch) {
            $results[] = $entry;
        }
    }
    
    return $results;
}

/**
 * Search entries by tag
 */
function searchEntriesByTag($tag) {
    $allEntries = getAllEntries();
    $results = [];
    
    $tag = strtolower(trim($tag));
    
    foreach ($allEntries as $entry) {
        if (!empty($entry['entry_tags'])) {
            $entryTags = array_map('strtolower', array_map('trim', $entry['entry_tags']));
            if (in_array($tag, $entryTags)) {
                $results[] = $entry;
            }
        }
    }
    
    return $results;
}

/**
 * Get memories from past years (same date)
 */
function getMemories() {
    $today = new DateTime();
    $memories = [];
    
    for ($yearsAgo = 1; $yearsAgo <= 10; $yearsAgo++) {
        $pastDate = clone $today;
        $pastDate->modify("-$yearsAgo years");
        $datePrefix = $pastDate->format('Y-m-d');
        
        $files = glob(ENTRIES_DIR . '/' . $datePrefix . '_*.txt');
        
        if ($files === false) {
            continue;
        }
        
        foreach ($files as $filepath) {
            $filename = basename($filepath);
            $entry = readEntry($filename);
            if ($entry) {
                $entry['years_ago'] = $yearsAgo;
                $memories[] = $entry;
            }
        }
    }
    
    return $memories;
}

/**
 * Get preview of content (first 150 characters)
 */
function getPreview($content, $length = 150) {
    $content = strip_tags($content);
    if (strlen($content) > $length) {
        return substr($content, 0, $length) . '...';
    }
    return $content;
}

/**
 * Convert URLs in text to clickable links
 */
function linkifyText($text) {
    // Pattern to match URLs
    $pattern = '/\b(https?:\/\/[^\s<]+)/i';
    
    // Replace URLs with clickable links
    $text = preg_replace_callback($pattern, function($matches) {
        $url = $matches[1];
        // Remove trailing punctuation that's probably not part of the URL
        $url = rtrim($url, '.,;:!?)');
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" class="auto-link">' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a>';
    }, $text);
    
    return $text;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        die('CSRF token validation failed. Please refresh the page and try again.');
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save') {
        $date = $_POST['date'] ?? '';
        $time = $_POST['time'] ?? '';
        $title = $_POST['title'] ?? '';
        $content = $_POST['content'] ?? '';
        $location = $_POST['location'] ?? '';
        $weather = $_POST['weather'] ?? '';
        $mood = $_POST['mood'] ?? '';
        $plans = $_POST['plans'] ?? '';
        $entryTags = $_POST['entry_tags'] ?? '';
        $originalFilename = $_POST['original_filename'] ?? '';
        $existingImages = isset($_POST['existing_images']) ? json_decode($_POST['existing_images'], true) : [];
        $deletedImages = isset($_POST['deleted_images']) ? json_decode($_POST['deleted_images'], true) : [];
        
        // Security: validate arrays
        if (!is_array($existingImages)) $existingImages = [];
        if (!is_array($deletedImages)) $deletedImages = [];
        
        // Security: sanitize filenames in arrays
        $existingImages = array_map('basename', $existingImages);
        $deletedImages = array_map('basename', $deletedImages);
        
        $errors = [];
        
        if (empty($date) || !isValidDate($date)) {
            $errors[] = 'Invalid date format.';
        }
        
        if (empty($time) || !isValidTime($time)) {
            $errors[] = 'Invalid time format (HH:MM required).';
        }
        
        if (empty($title)) {
            $errors[] = 'Title is required.';
        }
        
        $uploadedImages = [];
        if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
            $fileCount = count($_FILES['images']['name']);
            
            for ($i = 0; $i < $fileCount; $i++) {
                $file = [
                    'name' => $_FILES['images']['name'][$i],
                    'type' => $_FILES['images']['type'][$i],
                    'tmp_name' => $_FILES['images']['tmp_name'][$i],
                    'error' => $_FILES['images']['error'][$i],
                    'size' => $_FILES['images']['size'][$i]
                ];
                
                if ($file['error'] === UPLOAD_ERR_OK) {
                    $result = uploadImage($file);
                    if ($result['success']) {
                        $uploadedImages[] = $result['filename'];
                    } else {
                        $errors[] = $result['error'];
                    }
                }
            }
        }
        
        if (empty($errors)) {
            $filename = generateFilename($date, $time);
            
            if (!empty($deletedImages)) {
                foreach ($deletedImages as $img) {
                    deleteImage($img);
                    $existingImages = array_diff($existingImages, [$img]);
                }
            }
            
            $allImages = array_merge($existingImages, $uploadedImages);
            
            // Add quick tags and entry tags to content
            $quickTagsText = formatQuickTags($location, $weather, $mood, $plans);
            $entryTagsText = formatEntryTags($entryTags);
            $fullContent = $quickTagsText . $entryTagsText . $content;
            
            if ($originalFilename && $originalFilename !== $filename) {
                // Security: prevent directory traversal
                $originalFilename = basename($originalFilename);
                $filepath = ENTRIES_DIR . '/' . $originalFilename;
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
            }
            
            if (saveEntry($filename, $title, $fullContent, $allImages)) {
                $message = 'Diary entry saved successfully!';
                $messageType = 'success';
            } else {
                $message = 'Error saving diary entry.';
                $messageType = 'error';
            }
        } else {
            $message = implode(' ', $errors);
            $messageType = 'error';
        }
    } elseif ($action === 'delete') {
        $filename = $_POST['filename'] ?? '';
        
        // Security: prevent directory traversal
        $filename = basename($filename);
        
        if ($filename && deleteEntry($filename)) {
            $message = 'Diary entry deleted successfully!';
            $messageType = 'success';
        } else {
            $message = 'Error deleting diary entry.';
            $messageType = 'error';
        }
    }
    
    if (!empty($message)) {
        $_SESSION['message'] = $message;
        $_SESSION['messageType'] = $messageType;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Handle view action
if (isset($_GET['view'])) {
    $filename = basename($_GET['view']); // Security: prevent directory traversal
    $viewEntry = readEntry($filename);
    
    if (!$viewEntry) {
        $message = 'Entry not found.';
        $messageType = 'error';
    }
}

// Handle edit action
if (isset($_GET['edit'])) {
    $filename = basename($_GET['edit']); // Security: prevent directory traversal
    $editEntry = readEntry($filename);
    
    if (!$editEntry) {
        $message = 'Entry not found.';
        $messageType = 'error';
    }
}

// Retrieve session messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['messageType'];
    unset($_SESSION['message']);
    unset($_SESSION['messageType']);
}

// Handle search
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$isSearching = !empty($searchQuery);

// Handle tag filtering
$filterTag = isset($_GET['tag']) ? trim($_GET['tag']) : '';
$isFilteringByTag = !empty($filterTag);

// Handle date filtering
$filterYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$filterMonth = isset($_GET['month']) ? intval($_GET['month']) : date('n');
$filterDate = isset($_GET['date']) ? $_GET['date'] : '';

if ($isSearching) {
    $allEntries = searchEntries($searchQuery);
} elseif ($isFilteringByTag) {
    $allEntries = searchEntriesByTag($filterTag);
} elseif (!empty($filterDate) && isValidDate($filterDate)) {
    // Filter by specific date
    $allEntries = getAllEntries();
    $allEntries = array_filter($allEntries, function($entry) use ($filterDate) {
        return $entry['date'] === $filterDate;
    });
} elseif (isset($_GET['year']) && isset($_GET['month'])) {
    // Filter by month
    $allEntries = getEntriesForMonth($filterYear, $filterMonth);
} else {
    $allEntries = getAllEntries();
}

$memories = getMemories();

// Get days with entries for calendar
$daysWithEntries = getDaysWithEntries($filterYear, $filterMonth);

// Get all tags for tag cloud
$allTags = getAllTags();

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$totalEntries = count($allEntries);
$totalPages = ceil($totalEntries / ENTRIES_PER_PAGE);
$offset = ($page - 1) * ENTRIES_PER_PAGE;
$recentEntries = array_slice($allEntries, $offset, ENTRIES_PER_PAGE);

// Set default values for form
$formDate = $editEntry['date'] ?? date('Y-m-d');
$formTime = $editEntry['time'] ?? date('H:i');
$formTitle = $editEntry['title'] ?? '';
$formContent = $editEntry['content_without_tags'] ?? '';
$formLocation = $editEntry['location'] ?? '';
$formWeather = $editEntry['weather'] ?? '';
$formMood = $editEntry['mood'] ?? '';
$formPlans = $editEntry['plans'] ?? '';
$formEntryTags = $editEntry['entry_tags'] ?? [];
$formEntryTagsString = !empty($formEntryTags) ? implode(', ', $formEntryTags) : '';
$formImages = $editEntry['images'] ?? [];
$originalFilename = $editEntry['filename'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Diary</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <header>
            <div class="header-content">
                <div class="header-title">
                    <h1>📔 My Personal Diary</h1>
                    <p class="subtitle">Capture your thoughts and memories</p>
                </div>
                <a href="logout.php" class="btn-logout" title="Logout">🚪 Logout</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo sanitizeInput($message); ?>
            </div>
        <?php endif; ?>

        <!-- View Entry Modal -->
        <?php if ($viewEntry): ?>
            <section class="view-entry-section">
                <div class="view-entry-header">
                    <h2>📖 View Entry</h2>
                    <div class="view-actions">
                        <a href="?edit=<?php echo urlencode($viewEntry['filename']); ?>" class="btn btn-primary">✏️ Edit</a>
                        <a href="index.php" class="btn btn-secondary">← Back</a>
                    </div>
                </div>
                
                <article class="view-entry-content">
                    <div class="view-meta">
                        <span class="view-date">📅 <?php echo sanitizeInput($viewEntry['date']); ?></span>
                        <span class="view-time">🕐 <?php echo sanitizeInput($viewEntry['time']); ?></span>
                    </div>
                    
                    <h3 class="view-title"><?php echo sanitizeInput($viewEntry['title']); ?></h3>
                    
                    <?php if (!empty($viewEntry['entry_tags'])): ?>
                        <div class="entry-tags-display">
                            <?php foreach ($viewEntry['entry_tags'] as $tag): ?>
                                <a href="?tag=<?php echo urlencode($tag); ?>" class="entry-tag">#<?php echo sanitizeInput($tag); ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($viewEntry['location']) || !empty($viewEntry['weather']) || !empty($viewEntry['mood']) || !empty($viewEntry['plans'])): ?>
                        <div class="view-quick-tags">
                            <?php if (!empty($viewEntry['location'])): ?>
                                <span class="quick-tag location-tag">📍 <?php echo sanitizeInput($viewEntry['location']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($viewEntry['weather'])): ?>
                                <span class="quick-tag weather-tag">🌤️ <?php echo sanitizeInput($viewEntry['weather']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($viewEntry['mood'])): ?>
                                <span class="quick-tag mood-tag">😊 <?php echo sanitizeInput($viewEntry['mood']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($viewEntry['plans'])): ?>
                                <span class="quick-tag plans-tag">📅 <?php echo sanitizeInput($viewEntry['plans']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($viewEntry['images'])): ?>
                        <div class="view-images">
                            <?php foreach ($viewEntry['images'] as $image): ?>
                                <?php $safeImage = basename(sanitizeInput($image)); ?>
                                <img src="diary_images/<?php echo $safeImage; ?>" 
                                     alt="Entry image" 
                                     class="view-image"
                                     onclick="openLightbox('diary_images/<?php echo $safeImage; ?>')">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="view-text">
                        <?php echo linkifyText(nl2br(sanitizeInput($viewEntry['content_without_tags']))); ?>
                    </div>
                    
                    <div class="view-footer">
                        <form method="POST" action="" 
                              onsubmit="return confirm('Are you sure you want to delete this entry?');">
                            <?php echo csrfTokenField(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="filename" value="<?php echo sanitizeInput($viewEntry['filename']); ?>">
                            <button type="submit" class="btn btn-danger">🗑️ Delete Entry</button>
                        </form>
                    </div>
                </article>
            </section>
        <?php endif; ?>

        <!-- Memories Section -->
        <?php if (!empty($memories) && !$isSearching && !$viewEntry): ?>
            <section class="memories-section">
                <h2>🎂 On This Day</h2>
                <div class="memories-list">
                    <?php foreach ($memories as $memory): ?>
                        <article class="memory-card">
                            <div class="memory-header">
                                <span class="memory-badge">
                                    <?php 
                                    if ($memory['years_ago'] == 1) {
                                        echo '1 year ago';
                                    } else {
                                        echo $memory['years_ago'] . ' years ago';
                                    }
                                    ?>
                                </span>
                                <span class="memory-date">
                                    <?php echo sanitizeInput($memory['date'] . ' ' . $memory['time']); ?>
                                </span>
                            </div>
                            <h3 class="memory-title"><?php echo sanitizeInput($memory['title']); ?></h3>
                            
                            <?php if (!empty($memory['images'])): ?>
                                <div class="memory-images">
                                    <?php foreach (array_slice($memory['images'], 0, 2) as $image): ?>
                                        <?php $safeImage = basename(sanitizeInput($image)); ?>
                                        <img src="diary_images/<?php echo $safeImage; ?>" 
                                             alt="Memory image" 
                                             class="memory-image"
                                             onclick="openLightbox('diary_images/<?php echo $safeImage; ?>')">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="memory-preview">
                                <?php echo linkifyText(nl2br(sanitizeInput(getPreview($memory['content_without_tags'], 200)))); ?>
                            </div>
                            <a href="?view=<?php echo urlencode($memory['filename']); ?>" class="memory-link">
                                Read full entry →
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if (!$viewEntry): ?>
        <section class="entry-form-section">
            <h2><?php echo $editEntry ? 'Edit Entry' : 'New Entry'; ?></h2>
            
            <!-- Writing Prompts Section -->
            <div class="writing-prompts">
                <button type="button" class="prompts-toggle" onclick="togglePrompts()">
                    <span class="toggle-icon">💡</span>
                    <span class="toggle-text">Writing Prompts</span>
                    <span class="toggle-arrow">▼</span>
                </button>
                <div class="prompts-content" id="promptsContent">
                    <div class="daily-prompt">
                        <div class="prompt-label">Today's Prompt:</div>
                        <div class="prompt-text"><?php echo sanitizeInput($todayPrompt); ?></div>
                    </div>
                </div>
            </div>
            
            <form method="POST" action="" class="entry-form" enctype="multipart/form-data">
                <?php echo csrfTokenField(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="original_filename" value="<?php echo sanitizeInput($originalFilename); ?>">
                <input type="hidden" name="existing_images" value="<?php echo htmlspecialchars(json_encode($formImages)); ?>">
                <input type="hidden" name="deleted_images" id="deleted_images" value="[]">
                
                <!-- Auto-save status indicator -->
                <div id="autosave-status" class="autosave-status" style="display: none;">
                    <span class="autosave-icon">💾</span>
                    <span class="autosave-text">Draft saved at <span id="autosave-time"></span></span>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="date">Date *</label>
                        <input type="date" id="date" name="date" 
                               value="<?php echo sanitizeInput($formDate); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="time">Time *</label>
                        <input type="time" id="time" name="time" 
                               value="<?php echo sanitizeInput($formTime); ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="title">Title *</label>
                    <input type="text" id="title" name="title" 
                           value="<?php echo sanitizeInput($formTitle); ?>" 
                           placeholder="Enter a title for your entry" required maxlength="200">
                </div>
                
                <!-- Quick Tags Section -->
                <div class="quick-tags-section">
                    <div class="quick-tags-header">
                        <span>✨ Quick Context (optional)</span>
                    </div>
                    <div class="quick-tags-grid">
                        <div class="form-group quick-tag-input">
                            <label for="location">📍 Location</label>
                            <textarea id="location" name="location" rows="2" 
                                   placeholder="Where are you? What does it look like?"><?php echo sanitizeInput($formLocation); ?></textarea>
                        </div>
                        
                        <div class="form-group quick-tag-input">
                            <label for="weather">🌤️ Weather</label>
                            <textarea id="weather" name="weather" rows="2" 
                                   placeholder="How's the weather? Temperature?"><?php echo sanitizeInput($formWeather); ?></textarea>
                        </div>
                        
                        <div class="form-group quick-tag-input">
                            <label for="mood">😊 Mood</label>
                            <textarea id="mood" name="mood" rows="2" 
                                   placeholder="How are you feeling?"><?php echo sanitizeInput($formMood); ?></textarea>
                        </div>
                        
                        <div class="form-group quick-tag-input">
                            <label for="plans">📅 Plans today</label>
                            <textarea id="plans" name="plans" rows="2" 
                                   placeholder="What are you doing or planning today?"><?php echo sanitizeInput($formPlans); ?></textarea>
                        </div>
                        
                        <div class="form-group quick-tag-input">
                            <label for="entry_tags">🏷️ Tags</label>
                            <input type="text" id="entry_tags" name="entry_tags" 
                                   value="<?php echo sanitizeInput($formEntryTagsString); ?>" 
                                   placeholder="work, ideas, travel (comma-separated)">
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="images">Images (Max 12MB each, JPEG/PNG/GIF/WebP)</label>
                    <input type="file" id="images" name="images[]" accept="image/*" multiple class="file-input">
                    <div id="image-preview" class="image-preview"></div>
                    
                    <?php if (!empty($formImages)): ?>
                        <div class="existing-images">
                            <p><strong>Current images:</strong></p>
                            <div class="image-grid">
                                <?php foreach ($formImages as $image): ?>
                                    <?php $safeImage = basename(sanitizeInput($image)); ?>
                                    <div class="image-item" data-image="<?php echo $safeImage; ?>">
                                        <img src="diary_images/<?php echo $safeImage; ?>" alt="Entry image">
                                        <button type="button" class="remove-image" onclick="removeImage('<?php echo $safeImage; ?>')">✕</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="content">Anything else?</label>
                    <textarea id="content" name="content" rows="6" 
                              placeholder="Any other thoughts, questions, or details..."><?php echo sanitizeInput($formContent); ?></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <?php echo $editEntry ? 'Update Entry' : 'Save Entry'; ?>
                    </button>
                    <?php if ($editEntry): ?>
                        <a href="index.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="entries-section">
            <div class="section-header">
                <h2><?php 
                    if ($isSearching) {
                        echo 'Search Results';
                    } elseif ($isFilteringByTag) {
                        echo 'Entries tagged: #' . sanitizeInput($filterTag);
                    } else {
                        echo 'Recent Entries';
                    }
                ?></h2>
                
                <form method="GET" action="" class="search-form">
                    <input type="text" name="search" 
                           placeholder="🔍 Search entries..." 
                           value="<?php echo sanitizeInput($searchQuery); ?>"
                           class="search-input">
                    <button type="submit" class="btn btn-search">Search</button>
                    <?php if ($isSearching): ?>
                        <a href="index.php" class="btn btn-secondary">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Tag Cloud -->
            <?php if (!$isSearching && !$isFilteringByTag && !empty($allTags)): ?>
            <div class="tag-cloud-widget">
                <button type="button" class="tag-cloud-toggle" onclick="toggleTagCloud()">
                    <span class="toggle-icon">🏷️</span>
                    <span class="toggle-text">Filter by Tag</span>
                    <span class="toggle-arrow-tags">▼</span>
                </button>
                <div class="tag-cloud-content" id="tagCloudContent" style="display: none;">
                    <div class="tag-cloud">
                        <?php foreach ($allTags as $tag => $count): ?>
                            <a href="?tag=<?php echo urlencode($tag); ?>" class="tag-cloud-item" title="<?php echo $count; ?> <?php echo $count == 1 ? 'entry' : 'entries'; ?>">
                                #<?php echo sanitizeInput($tag); ?> <span class="tag-count">(<?php echo $count; ?>)</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($isFilteringByTag): ?>
                <div class="filter-info">
                    <p>Showing entries tagged with <strong>#<?php echo sanitizeInput($filterTag); ?></strong></p>
                    <a href="index.php" class="btn btn-secondary btn-small">Clear filter</a>
                </div>
            <?php endif; ?>

            <!-- Date Navigation -->
            <?php if (!$isSearching): ?>
            <div class="date-navigation">
                <button type="button" class="date-nav-toggle" onclick="toggleCalendar()">
                    <span class="toggle-icon">📅</span>
                    <span class="toggle-text">
                        <?php 
                        if (!empty($filterDate)) {
                            echo date('F j, Y', strtotime($filterDate));
                        } else {
                            echo date('F Y', mktime(0, 0, 0, $filterMonth, 1, $filterYear));
                        }
                        ?>
                    </span>
                    <span class="toggle-arrow-cal">▼</span>
                </button>
                
                <div class="calendar-content" id="calendarContent" style="display: none;">
                    <div class="calendar-controls">
                        <form method="GET" action="" class="month-selector">
                            <button type="submit" name="month" value="<?php echo $filterMonth == 1 ? 12 : $filterMonth - 1; ?>" 
                                    <?php if ($filterMonth == 1): ?>name="year" value="<?php echo $filterYear - 1; ?>"<?php else: ?>name="year" value="<?php echo $filterYear; ?>"<?php endif; ?>
                                    class="btn-nav-month" title="Previous month">◀</button>
                            
                            <select name="month" onchange="this.form.submit()" class="month-select">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m == $filterMonth ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            
                            <select name="year" onchange="this.form.submit()" class="year-select">
                                <?php 
                                $currentYear = date('Y');
                                for ($y = $currentYear; $y >= $currentYear - 10; $y--): 
                                ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y == $filterYear ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            
                            <button type="submit" name="month" value="<?php echo $filterMonth == 12 ? 1 : $filterMonth + 1; ?>" 
                                    <?php if ($filterMonth == 12): ?>name="year" value="<?php echo $filterYear + 1; ?>"<?php else: ?>name="year" value="<?php echo $filterYear; ?>"<?php endif; ?>
                                    class="btn-nav-month" title="Next month">▶</button>
                            
                            <a href="index.php" class="btn-today">Today</a>
                        </form>
                    </div>
                    
                    <div class="mini-calendar">
                        <?php
                        $firstDay = mktime(0, 0, 0, $filterMonth, 1, $filterYear);
                        $daysInMonth = date('t', $firstDay);
                        $dayOfWeek = date('w', $firstDay); // 0 (Sunday) to 6 (Saturday)
                        
                        // Week day headers
                        $weekDays = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
                        echo '<div class="calendar-grid">';
                        foreach ($weekDays as $day) {
                            echo '<div class="calendar-day-header">' . $day . '</div>';
                        }
                        
                        // Empty cells before first day
                        for ($i = 0; $i < $dayOfWeek; $i++) {
                            echo '<div class="calendar-day-empty"></div>';
                        }
                        
                        // Days of the month
                        for ($day = 1; $day <= $daysInMonth; $day++) {
                            $date = sprintf('%04d-%02d-%02d', $filterYear, $filterMonth, $day);
                            $hasEntry = in_array($day, $daysWithEntries);
                            $isToday = $date === date('Y-m-d');
                            
                            $classes = ['calendar-day'];
                            if ($hasEntry) $classes[] = 'has-entry';
                            if ($isToday) $classes[] = 'is-today';
                            if ($date === $filterDate) $classes[] = 'is-selected';
                            
                            echo '<a href="?date=' . $date . '&year=' . $filterYear . '&month=' . $filterMonth . '" class="' . implode(' ', $classes) . '">';
                            echo $day;
                            echo '</a>';
                        }
                        
                        echo '</div>';
                        ?>
                    </div>
                    
                    <?php if (!empty($filterDate)): ?>
                        <div class="calendar-footer">
                            <a href="?year=<?php echo $filterYear; ?>&month=<?php echo $filterMonth; ?>" class="btn-clear-date">
                                Show all entries for <?php echo date('F Y', mktime(0, 0, 0, $filterMonth, 1, $filterYear)); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($isSearching && $totalEntries > 0): ?>
                <p class="search-results-info">
                    Found <?php echo $totalEntries; ?> result<?php echo $totalEntries != 1 ? 's' : ''; ?> 
                    for "<?php echo sanitizeInput($searchQuery); ?>"
                </p>
            <?php endif; ?>
            
            <?php if (empty($allEntries)): ?>
                <div class="no-entries">
                    <p>
                        <?php 
                        if ($isSearching) {
                            echo 'No entries found matching your search.';
                        } else {
                            echo 'No diary entries yet. Start writing your first entry above!';
                        }
                        ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="entries-list">
                    <?php foreach ($recentEntries as $entry): ?>
                        <article class="entry-card">
                            <div class="entry-header">
                                <div class="entry-meta">
                                    <span class="entry-date">📅 <?php echo sanitizeInput($entry['date']); ?></span>
                                    <span class="entry-time">🕐 <?php echo sanitizeInput($entry['time']); ?></span>
                                </div>
                                <div class="entry-actions">
                                    <a href="?view=<?php echo urlencode($entry['filename']); ?>" 
                                       class="btn-icon" title="View">👁️</a>
                                    <a href="?edit=<?php echo urlencode($entry['filename']); ?>" 
                                       class="btn-icon" title="Edit">✏️</a>
                                    <form method="POST" action="" class="delete-form" 
                                          onsubmit="return confirm('Are you sure you want to delete this entry?');">
                                        <?php echo csrfTokenField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="filename" 
                                               value="<?php echo sanitizeInput($entry['filename']); ?>">
                                        <button type="submit" class="btn-icon" title="Delete">🗑️</button>
                                    </form>
                                </div>
                            </div>
                            
                            <h3 class="entry-title"><?php echo sanitizeInput($entry['title']); ?></h3>
                            
                            <?php if (!empty($entry['entry_tags'])): ?>
                                <div class="entry-tags-display">
                                    <?php foreach ($entry['entry_tags'] as $tag): ?>
                                        <a href="?tag=<?php echo urlencode($tag); ?>" class="entry-tag entry-tag-small">#<?php echo sanitizeInput($tag); ?></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($entry['location']) || !empty($entry['weather']) || !empty($entry['mood']) || !empty($entry['plans'])): ?>
                                <div class="entry-quick-tags">
                                    <?php if (!empty($entry['location'])): ?>
                                        <span class="quick-tag-mini location-tag">📍 <?php echo sanitizeInput($entry['location']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['weather'])): ?>
                                        <span class="quick-tag-mini weather-tag">🌤️ <?php echo sanitizeInput($entry['weather']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['mood'])): ?>
                                        <span class="quick-tag-mini mood-tag">😊 <?php echo sanitizeInput($entry['mood']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['plans'])): ?>
                                        <span class="quick-tag-mini plans-tag">📅 <?php echo sanitizeInput($entry['plans']); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($entry['images'])): ?>
                                <div class="entry-images">
                                    <?php foreach ($entry['images'] as $image): ?>
                                        <?php $safeImage = basename(sanitizeInput($image)); ?>
                                        <img src="diary_images/<?php echo $safeImage; ?>" 
                                             alt="Entry image" 
                                             class="entry-image"
                                             onclick="openLightbox('diary_images/<?php echo $safeImage; ?>')">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="entry-preview">
                                <?php echo linkifyText(nl2br(sanitizeInput(getPreview($entry['content_without_tags'])))); ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?><?php echo $isSearching ? '&search=' . urlencode($searchQuery) : ''; ?>" 
                               class="btn btn-secondary">« Previous</a>
                        <?php endif; ?>
                        
                        <span class="page-info">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo $isSearching ? '&search=' . urlencode($searchQuery) : ''; ?>" 
                               class="btn btn-secondary">Next »</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <footer>
            <p>Total entries: <?php echo count(getAllEntries()); ?></p>
        </footer>
    </div>

    <div id="lightbox" class="lightbox" onclick="closeLightbox()">
        <span class="lightbox-close">&times;</span>
        <img id="lightbox-image" class="lightbox-content" src="" alt="Full size image">
    </div>

    <script>
    // Auto-save functionality
    let autoSaveTimer = null;
    let hasUnsavedChanges = false;
    const AUTOSAVE_KEY = 'diary_autosave_draft';
    const AUTOSAVE_DELAY = 3000; // 3 seconds
    
    // Form fields to auto-save
    const formFields = ['title', 'location', 'weather', 'mood', 'plans', 'entry_tags', 'content'];
    
    // Save draft to localStorage
    function saveDraft() {
        const draft = {
            timestamp: Date.now(),
            date: document.getElementById('date')?.value || '',
            time: document.getElementById('time')?.value || '',
            title: document.getElementById('title')?.value || '',
            location: document.getElementById('location')?.value || '',
            weather: document.getElementById('weather')?.value || '',
            mood: document.getElementById('mood')?.value || '',
            plans: document.getElementById('plans')?.value || '',
            entry_tags: document.getElementById('entry_tags')?.value || '',
            content: document.getElementById('content')?.value || ''
        };
        
        localStorage.setItem(AUTOSAVE_KEY, JSON.stringify(draft));
        
        // Update status indicator
        showAutoSaveStatus(draft.timestamp);
        
        console.log('Draft auto-saved');
    }
    
    // Show auto-save status with timestamp
    function showAutoSaveStatus(timestamp) {
        const statusDiv = document.getElementById('autosave-status');
        const timeSpan = document.getElementById('autosave-time');
        
        if (statusDiv && timeSpan) {
            const now = new Date(timestamp);
            const timeStr = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit'
            });
            
            timeSpan.textContent = timeStr;
            statusDiv.style.display = 'flex';
            
            // Fade out after 3 seconds
            setTimeout(() => {
                statusDiv.style.opacity = '1';
                statusDiv.style.transition = 'opacity 0.5s';
            }, 10);
            
            setTimeout(() => {
                statusDiv.style.opacity = '0.7';
            }, 3000);
        }
    }
    
    // Restore draft from localStorage
    function restoreDraft() {
        const saved = localStorage.getItem(AUTOSAVE_KEY);
        if (!saved) return false;
        
        try {
            const draft = JSON.parse(saved);
            
            // Check if draft is recent (within last 7 days)
            const daysOld = (Date.now() - draft.timestamp) / (1000 * 60 * 60 * 24);
            if (daysOld > 7) {
                localStorage.removeItem(AUTOSAVE_KEY);
                return false;
            }
            
            // Check if any field has content
            const hasContent = draft.title || draft.location || draft.weather || 
                             draft.mood || draft.plans || draft.entry_tags || draft.content;
            
            if (!hasContent) return false;
            
            // Show restore prompt
            const timeAgo = formatTimeAgo(draft.timestamp);
            const restore = confirm(
                `Found an auto-saved draft from ${timeAgo}.\n\n` +
                `Title: ${draft.title || '(none)'}\n` +
                `Do you want to restore it?`
            );
            
            if (restore) {
                if (draft.date) document.getElementById('date').value = draft.date;
                if (draft.time) document.getElementById('time').value = draft.time;
                if (draft.title) document.getElementById('title').value = draft.title;
                if (draft.location) document.getElementById('location').value = draft.location;
                if (draft.weather) document.getElementById('weather').value = draft.weather;
                if (draft.mood) document.getElementById('mood').value = draft.mood;
                if (draft.plans) document.getElementById('plans').value = draft.plans;
                if (draft.entry_tags) document.getElementById('entry_tags').value = draft.entry_tags;
                if (draft.content) document.getElementById('content').value = draft.content;
                
                hasUnsavedChanges = true;
                
                // Show restored status
                showAutoSaveStatus(draft.timestamp);
                
                return true;
            } else {
                // User declined, clear the draft
                localStorage.removeItem(AUTOSAVE_KEY);
            }
        } catch (e) {
            console.error('Error restoring draft:', e);
            localStorage.removeItem(AUTOSAVE_KEY);
        }
        
        return false;
    }
    
    // Clear draft from localStorage
    function clearDraft() {
        localStorage.removeItem(AUTOSAVE_KEY);
        hasUnsavedChanges = false;
        
        // Hide status indicator
        const statusDiv = document.getElementById('autosave-status');
        if (statusDiv) {
            statusDiv.style.display = 'none';
        }
        
        console.log('Draft cleared');
    }
    
    // Format time ago
    function formatTimeAgo(timestamp) {
        const seconds = Math.floor((Date.now() - timestamp) / 1000);
        
        if (seconds < 60) return 'just now';
        if (seconds < 3600) return `${Math.floor(seconds / 60)} minutes ago`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)} hours ago`;
        return `${Math.floor(seconds / 86400)} days ago`;
    }
    
    // Schedule auto-save
    function scheduleAutoSave() {
        if (autoSaveTimer) clearTimeout(autoSaveTimer);
        
        autoSaveTimer = setTimeout(() => {
            saveDraft();
        }, AUTOSAVE_DELAY);
    }
    
    // Setup auto-save listeners
    function setupAutoSave() {
        formFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('input', () => {
                    hasUnsavedChanges = true;
                    scheduleAutoSave();
                });
            }
        });
        
        // Also listen to date/time changes
        ['date', 'time'].forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('change', () => {
                    hasUnsavedChanges = true;
                    scheduleAutoSave();
                });
            }
        });
    }
    
    // Warn before leaving if unsaved changes
    window.addEventListener('beforeunload', (e) => {
        if (hasUnsavedChanges) {
            e.preventDefault();
            e.returnValue = ''; // Modern browsers ignore custom message
            return '';
        }
    });
    
    // Clear draft on successful form submission
    document.querySelector('.entry-form')?.addEventListener('submit', function(e) {
        // Clear the unsaved flag immediately so beforeunload doesn't trigger
        hasUnsavedChanges = false;
        
        // Clear the draft immediately (no timeout)
        clearDraft();
    });
    
    // Toggle prompts section
    function togglePrompts() {
        const content = document.getElementById('promptsContent');
        const arrow = document.querySelector('.toggle-arrow');
        
        if (content.style.display === 'none' || content.style.display === '') {
            content.style.display = 'block';
            arrow.textContent = '▲';
        } else {
            content.style.display = 'none';
            arrow.textContent = '▼';
        }
    }
    
    // Toggle calendar
    function toggleCalendar() {
        const content = document.getElementById('calendarContent');
        const arrow = document.querySelector('.toggle-arrow-cal');
        
        if (content.style.display === 'none' || content.style.display === '') {
            content.style.display = 'block';
            arrow.textContent = '▲';
        } else {
            content.style.display = 'none';
            arrow.textContent = '▼';
        }
    }
    
    // Toggle tag cloud
    function toggleTagCloud() {
        const content = document.getElementById('tagCloudContent');
        const arrow = document.querySelector('.toggle-arrow-tags');
        
        if (content && arrow) {
            if (content.style.display === 'none' || content.style.display === '') {
                content.style.display = 'block';
                arrow.textContent = '▲';
            } else {
                content.style.display = 'none';
                arrow.textContent = '▼';
            }
        }
    }
    
    // Show prompts by default on page load
    document.addEventListener('DOMContentLoaded', function() {
        const content = document.getElementById('promptsContent');
        if (content) {
            content.style.display = 'block';
            const arrow = document.querySelector('.toggle-arrow');
            if (arrow) arrow.textContent = '▲';
        }
        
        // Setup auto-save
        setupAutoSave();
        
        // Try to restore draft (only on new entry page, not when editing)
        const isEditing = document.querySelector('input[name="original_filename"]')?.value;
        if (!isEditing) {
            restoreDraft();
        }
    });
    
    document.getElementById('images')?.addEventListener('change', function(e) {
        const preview = document.getElementById('image-preview');
        preview.innerHTML = '';
        
        for (let i = 0; i < e.target.files.length; i++) {
            const file = e.target.files[i];
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const div = document.createElement('div');
                    div.className = 'preview-item';
                    const img = document.createElement('img');
                    img.src = event.target.result;
                    div.appendChild(img);
                    preview.appendChild(div);
                };
                reader.readAsDataURL(file);
            }
        }
    });

    function removeImage(imageName) {
        if (confirm('Remove this image?')) {
            const deletedInput = document.getElementById('deleted_images');
            const deleted = JSON.parse(deletedInput.value);
            deleted.push(imageName);
            deletedInput.value = JSON.stringify(deleted);
            
            const imageItem = document.querySelector(`.image-item[data-image="${imageName}"]`);
            if (imageItem) {
                imageItem.remove();
            }
        }
    }

    function openLightbox(imageSrc) {
        const lightbox = document.getElementById('lightbox');
        const lightboxImage = document.getElementById('lightbox-image');
        lightboxImage.src = imageSrc;
        lightbox.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        const lightbox = document.getElementById('lightbox');
        lightbox.style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeLightbox();
        }
    });
    </script>
</body>
</html>
