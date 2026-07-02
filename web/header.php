<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_auth();                 // deve rodar antes de qualquer saida
$appTitle = cfg()['app_title'];
$active = $active ?? '';
?><!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($appTitle) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <div class="brand"><?= e($appTitle) ?></div>
  <nav>
    <a href="index.php"     class="<?= $active==='dash'?'on':'' ?>">Painel</a>
    <a href="endpoints.php" class="<?= $active==='ep'?'on':'' ?>">Dispositivos</a>
    <a href="groups.php"    class="<?= $active==='grp'?'on':'' ?>">Grupos</a>
    <a href="profiles.php"  class="<?= $active==='prof'?'on':'' ?>">Perfis AMT</a>
  </nav>
  <?php $u = current_user(); if ($u && $u !== 'anonimo'): ?>
    <div class="userbox">
      <span class="uname">&#128100; <?= e($u) ?></span>
      <a href="logout.php" class="logout">Sair</a>
    </div>
  <?php endif; ?>
</header>
<main>
