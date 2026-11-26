<?php
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/notification_service.php';

// Test creating sample notification
$nid = NotificationService::create([
    'role_code' => 'admin',
    'title'     => 'Uji Notifikasi Mayora',
    'message'   => 'Sistem notifikasi berhasil diinisialisasi.',
    'type'      => 'success',
    'category'  => 'system',
    'link'      => 'index.php?page=reports/notifications'
]);

echo "Created test notif ID: " . $nid . PHP_EOL;

// Test unread count
$adminUser = ['id' => 1, 'role_code' => 'admin'];
$unread = NotificationService::getUnreadCount($adminUser);
echo "Admin unread count: " . $unread . PHP_EOL;

// Test fetching recent
$recent = NotificationService::getRecent($adminUser, 3);
echo "Fetched recent count: " . count($recent) . PHP_EOL;
foreach ($recent as $r) {
    echo " - [{$r['type']}] {$r['title']} ({$r['category']})" . PHP_EOL;
}
