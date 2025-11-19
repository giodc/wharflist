<?php
// URL Helper - Generate clean URLs

/**
 * Generate a clean URL for the application
 * @param string $page The page name (e.g., 'settings', 'campaign-status')
 * @param mixed $id Optional ID parameter
 * @param array $params Optional query parameters
 * @return string The clean URL
 */
function url($page, $id = null, $params = []) {
    // Check if .htaccess is being used (cleaner URLs without router.php)
    $url = '/' . $page;
    
    if ($id !== null) {
        $url .= '/' . $id;
    }
    
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    
    return $url;
}

/**
 * Check if current page matches the given page
 * @param string $page Page name to check
 * @return bool True if current page matches
 */
function is_current_page($page) {
    $currentFile = basename($_SERVER['PHP_SELF']);
    $route = $_SERVER['PATH_INFO'] ?? '';
    $routePage = trim(explode('/', $route)[1] ?? '', '/');
    
    // Check direct file access
    if ($currentFile === $page . '.php') {
        return true;
    }
    
    // Check router access
    if ($routePage === $page) {
        return true;
    }
    
    // Special cases
    if ($page === 'index' && ($currentFile === 'index.php' || $routePage === '' || $routePage === 'dashboard')) {
        return true;
    }
    
    return false;
}
