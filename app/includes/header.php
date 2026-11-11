<?php
/**
 * Main Layout Header
 * PT Mayora Indah Tbk. - Sistem Informasi Akuntansi & Operasional
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';

$currentUser = currentUser();
$currentPage = $_GET['page'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' - ' : '' ?>PT Mayora Indah Tbk.</title>
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main">
        <header class="top">
            <div class="bc">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <a href="index.php?page=dashboard">Beranda</a>
                <?php if (isset($breadcrumbCategory)): ?>
                    <span>/</span>
                    <span style="color:#6b7280;"><?= e($breadcrumbCategory) ?></span>
                <?php endif; ?>
                <?php if (isset($pageTitle) && $currentPage !== 'dashboard'): ?>
                    <span>/</span>
                    <strong style="color:#111827;"><?= e($pageTitle) ?></strong>
                <?php endif; ?>
            </div>
            <div class="usr">
                <?php $sysNotif = getSystemNotificationCount($currentUser); ?>
                <span class="bell" title="Pemberitahuan Sistem">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                    <?php if ($sysNotif > 0): ?>
                        <i><?= $sysNotif ?></i>
                    <?php endif; ?>
                </span>
                <a href="index.php?page=logout" class="me" title="Pengguna: <?= e($currentUser['full_name'] ?? '') ?> (Klik untuk keluar)">
                    <b><?= getUserInitials($currentUser['full_name'] ?? '') ?></b>
                    <span>
                        <strong><?= e($currentUser['full_name'] ?? 'Pengguna') ?></strong>
                        <small><?= e($currentUser['role_name'] ?? '') ?></small>
                    </span>
                </a>
                <a href="index.php?page=logout" class="btn-logout" title="Keluar dari sesi">Keluar</a>
            </div>
        </header>
        <section class="content">
            <?= renderFlashMessages() ?>
