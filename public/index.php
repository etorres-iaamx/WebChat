<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/bootstrap.php';
$auth = webchat_auth()->userFromSession((string) ($_COOKIE['webchat_auth'] ?? ''));
?><!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WebChat IAAMX</title><link rel="stylesheet" href="./auth.css"></head>
<body>
<main class="auth-shell">
  <section class="auth-card" aria-labelledby="title">
    <div class="brand">IAAMX</div>
    <p class="eyebrow">WebChat</p>
    <h1 id="title"><?= $auth ? 'Tu cuenta está activa' : 'Inicia sesión o regístrate' ?></h1>
    <?php if ($auth): ?>
      <p>Hola, <?= htmlspecialchars((string) $auth['name'], ENT_QUOTES, 'UTF-8') ?>. Tu agente está asociado a tu cuenta.</p>
      <a class="button" href="./webchat/index.php">Abrir WebChat</a>
      <button class="button button--secondary" data-action="logout">Cerrar sesión</button>
    <?php else: ?>
      <div class="tabs"><button class="tab is-active" data-tab="login">Iniciar sesión</button><button class="tab" data-tab="register">Crear cuenta</button></div>
      <form data-form="login">
        <label>Correo<input name="email" type="email" autocomplete="email" required></label>
        <label>Contraseña<input name="password" type="password" autocomplete="current-password" required></label>
        <button class="button" type="submit">Continuar</button>
      </form>
      <form data-form="register" hidden>
        <label>Nombre<input name="name" autocomplete="name" required></label>
        <label>País / código telefónico<input name="country_code" placeholder="MX" required></label>
        <label>Teléfono<input name="phone" type="tel" autocomplete="tel" required></label>
        <label>Correo<input name="email" type="email" autocomplete="email" required></label>
        <label>Contraseña<input name="password" type="password" minlength="10" autocomplete="new-password" required></label>
        <label>Confirmar contraseña<input name="password_confirmation" type="password" minlength="10" autocomplete="new-password" required></label>
        <button class="button" type="submit">Crear cuenta</button>
      </form>
      <form data-form="verify" hidden>
        <p>Te enviamos un código al correo. Escríbelo para activar tu cuenta.</p>
        <input type="hidden" name="email"><label>Código<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label>
        <button class="button" type="submit">Verificar correo</button>
      </form>
    <?php endif; ?>
    <p class="status" data-status role="status"></p>
  </section>
</main>
<script src="./auth.js" defer></script>
</body></html>
