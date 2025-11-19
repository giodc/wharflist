<?php
// Ensure we have user data
if (!isset($user) || !$user) {
    if (isset($auth)) {
        $user = $auth->getCurrentUser();
    } else {
        // Fallback if auth is not available
        $user = ['username' => 'User'];
    }
}
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<nav class="py-4 px-2 lg:px-4">
    <ul class="space-y-1">
        <li>
            <a href="dashboard.php" @click="mobileMenuOpen = false" class="flex items-center space-x-2 lg:space-x-3 px-2 lg:px-4 py-2 lg:py-3 rounded-lg <?= $currentPage === 'dashboard.php' ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100' ?> transition text-sm lg:text-base">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
                <span class="font-medium">Dashboard</span>
            </a>
        </li>
        <li>
            <a href="subscribers.php" @click="mobileMenuOpen = false" class="flex items-center space-x-2 lg:space-x-3 px-2 lg:px-4 py-2 lg:py-3 rounded-lg <?= $currentPage === 'subscribers.php' ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100' ?> transition text-sm lg:text-base">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                </svg>
                <span class="font-medium">Subscribers</span>
            </a>
        </li>
        <li>
            <a href="lists.php" @click="mobileMenuOpen = false" class="flex items-center space-x-2 lg:space-x-3 px-2 lg:px-4 py-2 lg:py-3 rounded-lg <?= $currentPage === 'lists.php' ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100' ?> transition text-sm lg:text-base">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                </svg>
                <span class="font-medium">Lists</span>
            </a>
        </li>
        <li>
            <a href="sites.php" @click="mobileMenuOpen = false" class="flex items-center space-x-2 lg:space-x-3 px-2 lg:px-4 py-2 lg:py-3 rounded-lg <?= $currentPage === 'sites.php' ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100' ?> transition text-sm lg:text-base">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>
                </svg>
                <span class="font-medium">Sites</span>
            </a>
        </li>
        <li>
            <a href="import.php" @click="mobileMenuOpen = false" class="flex items-center space-x-2 lg:space-x-3 px-2 lg:px-4 py-2 lg:py-3 rounded-lg <?= $currentPage === 'import.php' ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100' ?> transition text-sm lg:text-base">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                </svg>
                <span class="font-medium">Import</span>
            </a>
        </li>
        <li>
            <a href="compose.php" @click="mobileMenuOpen = false" class="flex items-center space-x-2 lg:space-x-3 px-2 lg:px-4 py-2 lg:py-3 rounded-lg <?= $currentPage === 'compose.php' ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100' ?> transition text-sm lg:text-base">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
                <span class="font-medium">Compose</span>
            </a>
        </li>
        <li>
            <a href="settings.php" @click="mobileMenuOpen = false" class="flex items-center space-x-2 lg:space-x-3 px-2 lg:px-4 py-2 lg:py-3 rounded-lg <?= $currentPage === 'settings.php' ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100' ?> transition text-sm lg:text-base">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                <span class="font-medium">Settings</span>
            </a>
        </li>
    </ul>
</nav>
