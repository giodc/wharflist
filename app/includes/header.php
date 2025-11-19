<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'WharfList') ?> - WharfList</title>
    <script src="https://unpkg.com/tailwindcss-jit-cdn"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js"></script>
    <?php if (isset($additionalHead)): ?>
        <?= $additionalHead ?>
    <?php endif; ?>
    <style>
        [x-cloak] {
            display: none !important;
        }

        body.menu-open {
            overflow: hidden;
        }

        /* Black and White Theme - Override all colors */

        /* Background colors - Blues */
        .bg-blue-50,
        .bg-blue-100,
        .bg-blue-600,
        .bg-blue-700,
        .bg-blue-800 {
            background-color: #f3f4f6 !important;
            /* light gray for light blue */
        }

        .bg-blue-600,
        .bg-blue-700,
        .bg-blue-800 {
            background-color: #111827 !important;
            /* black for dark blue */
        }

        .hover\:bg-blue-700:hover,
        .hover\:bg-blue-800:hover {
            background-color: #000000 !important;
        }

        /* Background colors - Greens */
        .bg-green-50,
        .bg-green-100,
        .bg-green-600,
        .bg-green-700 {
            background-color: #f3f4f6 !important;
        }

        .bg-green-600,
        .bg-green-700 {
            background-color: #374151 !important;
            /* dark gray */
        }

        .hover\:bg-green-700:hover {
            background-color: #1f2937 !important;
        }

        /* Background colors - Reds */
        .bg-red-50,
        .bg-red-100 {
            background-color: #f9fafb !important;
        }

        .bg-red-600 {
            background-color: #000000 !important;
        }

        /* Background colors - Yellows */
        .bg-yellow-50,
        .bg-yellow-100 {
            background-color: #e5e7eb !important;
        }

        /* Text colors - Blues */
        .text-blue-600,
        .text-blue-700,
        .text-blue-800 {
            color: #111827 !important;
            /* black */
        }

        .hover\:text-blue-800:hover,
        .hover\:text-blue-700:hover {
            color: #000000 !important;
        }

        /* Text colors - Greens */
        .text-green-600,
        .text-green-700,
        .text-green-800 {
            color: #374151 !important;
            /* dark gray */
        }

        /* Text colors - Reds */
        .text-red-600,
        .text-red-700,
        .text-red-800 {
            color: #1f2937 !important;
            /* dark gray */
        }

        .hover\:text-red-700:hover {
            color: #111827 !important;
        }

        /* Text colors - Yellows */
        .text-yellow-600,
        .text-yellow-700,
        .text-yellow-800 {
            color: #4b5563 !important;
            /* medium gray */
        }

        /* Border colors */
        .border-blue-200,
        .border-blue-300 {
            border-color: #d1d5db !important;
            /* gray */
        }

        .border-green-200,
        .border-green-300 {
            border-color: #d1d5db !important;
        }

        .border-red-200,
        .border-red-300 {
            border-color: #d1d5db !important;
        }

        .border-yellow-200,
        .border-yellow-300 {
            border-color: #d1d5db !important;
        }

        /* Focus rings */
        .focus\:ring-blue-500:focus,
        .focus\:ring-green-500:focus {
            --tw-ring-color: #6b7280 !important;
            /* gray */
        }

        /* SVG Icons - Force grayscale */
        svg {
            filter: grayscale(100%) !important;
        }

        /* Additional button states */
        button.bg-blue-600,
        button.bg-green-600 {
            background-color: #111827 !important;
        }

        button.bg-gray-600 {
            background-color: #4b5563 !important;
        }

        button.hover\:bg-gray-700:hover {
            background-color: #374151 !important;
        }

        /* Status badges and alerts */
        .bg-green-50.border-green-200 {
            background-color: #f9fafb !important;
            border-color: #d1d5db !important;
        }

        .bg-red-50.border-red-200 {
            background-color: #f9fafb !important;
            border-color: #d1d5db !important;
        }

        .bg-yellow-50.border-yellow-200 {
            background-color: #f3f4f6 !important;
            border-color: #d1d5db !important;
        }

        /* Apply grayscale to any remaining colored elements */
        /* Exclude fixed positioned elements to prevent stacking context issues */
        *:not(.fixed):not([class*="fixed"]) {
            -webkit-filter: grayscale(100%);
            filter: grayscale(100%);
        }

        /* Specifically exclude modal overlays and content from filter */
        .fixed,
        [class*="z-[9"],
        [style*="position: fixed"] {
            -webkit-filter: none !important;
            filter: none !important;
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen" x-data="{ mobileMenuOpen: false }" :class="{ 'menu-open': mobileMenuOpen }"
    @keydown.escape.window="mobileMenuOpen = false">
    <!-- Header -->
    <header class="bg-white border-b border-gray-200 sticky top-0 z-50">
        <div class="px-4 lg:px-6 py-4 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <!-- Mobile Menu Button -->
                <button @click="mobileMenuOpen = !mobileMenuOpen"
                    class="lg:hidden p-2 rounded-lg hover:bg-gray-100 transition">
                    <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16"></path>
                        <path x-show="mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
                <a href="dashboard.php" class="flex items-center space-x-2">
                    <img src="/app/includes/logo.svg" alt="WharfList" class="w-8 h-8">
                    <h1 class="text-xl lg:text-2xl font-bold text-gray-900">WharfList</h1>
                </a>
            </div>

            <!-- User Dropdown -->
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open"
                    class="flex items-center space-x-2 px-4 py-2 rounded-lg hover:bg-gray-100 transition">
                    <span
                        class="text-sm font-medium text-gray-700"><?= htmlspecialchars($user['username'] ?? 'User') ?></span>
                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>

                <div x-show="open" @click.away="open = false" x-transition style="display: none;"
                    class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-50">
                    <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Settings</a>
                    <form method="POST" action="logout.php" class="m-0">
                        <?php require_once __DIR__ . '/../csrf-helper.php';
                        echo getCSRFTokenField(); ?>
                        <button type="submit"
                            class="block w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-100">Logout</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <div class="flex min-h-screen relative">
        <!-- Mobile Sidebar Overlay -->
        <div x-show="mobileMenuOpen" @click="mobileMenuOpen = false"
            x-transition:enter="transition-opacity ease-linear duration-300" x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity ease-linear duration-300"
            x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
            class="fixed inset-0 bg-gray-600 bg-opacity-75 z-40 lg:hidden" style="display: none;">
        </div>

        <!-- Sidebar (Desktop: always visible, Mobile: offcanvas) -->
        <aside :class="mobileMenuOpen ? 'translate-x-0' : '-translate-x-full'"
            class="fixed lg:sticky top-0 left-0 z-50 lg:z-10 w-64 lg:w-64 bg-white border-r border-gray-200 h-screen overflow-y-auto pt-2 flex-shrink-0 transform transition-transform duration-300 ease-in-out lg:translate-x-0">
            <?php include __DIR__ . '/nav.php'; ?>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-4 lg:p-8 bg-gray-50 min-w-0 w-full lg:w-auto overflow-visible">
            <div class="max-w-7xl mx-auto overflow-visible">