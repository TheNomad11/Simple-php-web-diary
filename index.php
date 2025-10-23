<?php
/**
 * Simple Diary Web Application
 * Main file: index.php
 * Handles all CRUD operations for diary entries with image support
 */

// Start session and require authentication
session_name('diary_app_session');
session_start();

require_once 'config.php';
requireAuth(); // Redirect to login if not authenticated

// Set timezone to Berlin
date_default_timezone_set('Europe/Berlin');

// Configuration
define('ENTRIES_DIR', __DIR__ . '/diary_entries');
define('IMAGES_DIR', __DIR__ . '/diary_images');
define('ENTRIES_PER_PAGE', 5);
define('MAX_IMAGE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

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
 * Handle image upload
 */
function uploadImage($file) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload failed'];
    }
    
    if ($file['size'] > MAX_IMAGE_SIZE) {
        return ['success' => false, 'error' => 'Image size must be less than 5MB'];
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, ALLOWED_IMAGE_TYPES)) {
        return ['success' => false, 'error' => 'Invalid image type'];
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . time() . '.' . $extension;
    $filepath = IMAGES_DIR . '/' . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
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
    
    $filepath = IMAGES_DIR . '/' . $filename;
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    return false;
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
    
    return [
        'filename' => $filename,
        'date' => $dateTime['date'],
        'time' => $dateTime['time'],
        'title' => $lines[0] ?? '',
        'images' => $images,
        'content' => $lines[2] ?? ''
    ];
}

/**
 * Delete diary entry and its images
 */
function deleteEntry($filename) {
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
 * Get all diary entries sorted by date/time descending
 */
function getAllEntries() {
    $entries = [];
    $files = glob(ENTRIES_DIR . '/*.txt');
    
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save') {
        $date = $_POST['date'] ?? '';
        $time = $_POST['time'] ?? '';
        $title = $_POST['title'] ?? '';
        $content = $_POST['content'] ?? '';
        $originalFilename = $_POST['original_filename'] ?? '';
        $existingImages = isset($_POST['existing_images']) ? json_decode($_POST['existing_images'], true) : [];
        $deletedImages = isset($_POST['deleted_images']) ? json_decode($_POST['deleted_images'], true) : [];
        
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
        
        if (empty($content)) {
            $errors[] = 'Content is required.';
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
            
            if ($originalFilename && $originalFilename !== $filename) {
                $filepath = ENTRIES_DIR . '/' . $originalFilename;
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
            }
            
            if (saveEntry($filename, $title, $content, $allImages)) {
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
        
        if ($filename && deleteEntry($filename)) {
            $message = 'Diary entry deleted successfully!';
            $messageType = 'success';
        } else {
            $message = 'Error deleting diary entry.';
            $messageType = 'error';
        }
    }
    
    if (!empty($message)) {
        session_start();
        $_SESSION['message'] = $message;
        $_SESSION['messageType'] = $messageType;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Handle view action
if (isset($_GET['view'])) {
    $filename = $_GET['view'];
    $viewEntry = readEntry($filename);
    
    if (!$viewEntry) {
        $message = 'Entry not found.';
        $messageType = 'error';
    }
}

// Handle edit action
if (isset($_GET['edit'])) {
    $filename = $_GET['edit'];
    $editEntry = readEntry($filename);
    
    if (!$editEntry) {
        $message = 'Entry not found.';
        $messageType = 'error';
    }
}

// Retrieve session messages
session_start();
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $messageType = $_SESSION['messageType'];
    unset($_SESSION['message']);
    unset($_SESSION['messageType']);
}

// Handle search
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$isSearching = !empty($searchQuery);

if ($isSearching) {
    $allEntries = searchEntries($searchQuery);
} else {
    $allEntries = getAllEntries();
}

$memories = getMemories();

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
$formContent = $editEntry['content'] ?? '';
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
            <h1>📔 My Personal Diary</h1>
            <p class="subtitle">Capture your thoughts and memories</p>
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
                    
                    <?php if (!empty($viewEntry['images'])): ?>
                        <div class="view-images">
                            <?php foreach ($viewEntry['images'] as $image): ?>
                                <img src="diary_images/<?php echo sanitizeInput($image); ?>" 
                                     alt="Entry image" 
                                     class="view-image"
                                     onclick="openLightbox('diary_images/<?php echo sanitizeInput($image); ?>')">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="view-text">
                        <?php echo nl2br(sanitizeInput($viewEntry['content'])); ?>
                    </div>
                    
                    <div class="view-footer">
                        <form method="POST" action="" 
                              onsubmit="return confirm('Are you sure you want to delete this entry?');">
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
                                        <img src="diary_images/<?php echo sanitizeInput($image); ?>" 
                                             alt="Memory image" 
                                             class="memory-image"
                                             onclick="openLightbox('diary_images/<?php echo sanitizeInput($image); ?>')">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="memory-preview">
                                <?php echo nl2br(sanitizeInput(getPreview($memory['content'], 200))); ?>
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
            
            <form method="POST" action="" class="entry-form" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="original_filename" value="<?php echo sanitizeInput($originalFilename); ?>">
                <input type="hidden" name="existing_images" value="<?php echo htmlspecialchars(json_encode($formImages)); ?>">
                <input type="hidden" name="deleted_images" id="deleted_images" value="[]">
                
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
                
                <div class="form-group">
                    <label for="images">Images (Max 5MB each, JPEG/PNG/GIF/WebP)</label>
                    <input type="file" id="images" name="images[]" accept="image/*" multiple class="file-input">
                    <div id="image-preview" class="image-preview"></div>
                    
                    <?php if (!empty($formImages)): ?>
                        <div class="existing-images">
                            <p><strong>Current images:</strong></p>
                            <div class="image-grid">
                                <?php foreach ($formImages as $image): ?>
                                    <div class="image-item" data-image="<?php echo sanitizeInput($image); ?>">
                                        <img src="diary_images/<?php echo sanitizeInput($image); ?>" alt="Entry image">
                                        <button type="button" class="remove-image" onclick="removeImage('<?php echo sanitizeInput($image); ?>')">✕</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="content">Content *</label>
                    <textarea id="content" name="content" rows="8" 
                              placeholder="Write your diary entry here..." required><?php echo sanitizeInput($formContent); ?></textarea>
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
                <h2><?php echo $isSearching ? 'Search Results' : 'Recent Entries'; ?></h2>
                
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
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="filename" 
                                               value="<?php echo sanitizeInput($entry['filename']); ?>">
                                        <button type="submit" class="btn-icon" title="Delete">🗑️</button>
                                    </form>
                                </div>
                            </div>
                            
                            <h3 class="entry-title"><?php echo sanitizeInput($entry['title']); ?></h3>
                            
                            <?php if (!empty($entry['images'])): ?>
                                <div class="entry-images">
                                    <?php foreach ($entry['images'] as $image): ?>
                                        <img src="diary_images/<?php echo sanitizeInput($image); ?>" 
                                             alt="Entry image" 
                                             class="entry-image"
                                             onclick="openLightbox('diary_images/<?php echo sanitizeInput($image); ?>')">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="entry-preview">
                                <?php echo nl2br(sanitizeInput(getPreview($entry['content']))); ?>
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
