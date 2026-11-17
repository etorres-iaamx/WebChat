<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/bootstrap.php';

use Webchat\App\Token;
$authHeaders = [
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
    'Pragma: no-cache',
    'Expires: 0',
];
foreach ($authHeaders as $header) header($header);
$auth = webchat_auth()->userFromSession((string) ($_COOKIE['webchat_auth'] ?? ''));
$onboardingIntent = Token::verify((string) ($_GET['intent'] ?? ''), (string) webchat_config()->get('APP_SECRET'));
$pairingChannel = is_array($onboardingIntent) && ($onboardingIntent['purpose'] ?? '') === 'channel_onboarding' ? strtolower(trim((string) ($onboardingIntent['channel'] ?? ''))) : '';
$pairingIdentity = is_array($onboardingIntent) && ($onboardingIntent['purpose'] ?? '') === 'channel_onboarding' ? trim((string) ($onboardingIntent['external_identity'] ?? '')) : '';
$intent = $pairingChannel !== '' && $pairingIdentity !== '' ? (string) ($_GET['intent'] ?? '') : '';
if ($auth !== null) {
    if ($intent !== '') {
        webchat_channels()->link(webchat_auth()->userIdFromSession((string) ($_COOKIE['webchat_auth'] ?? '')) ?? 0, $pairingChannel, $pairingIdentity);
    }
    header('Location: ' . webchat_url('dashboard'));
    exit;
}
?><!doctype html>
<html lang="es" data-webchat-path="<?= webchat_h(webchat_url()) ?>" data-onboarding-intent="<?= webchat_h($intent) ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WebChat IAAMX</title><link rel="stylesheet" href="<?= webchat_h(webchat_url('auth.css?v=20261117-1')) ?>"></head>
<body>
<main class="auth-shell">
  <section class="auth-card" aria-labelledby="title">
    <img class="brand" src="<?= webchat_h(webchat_url('brand/iaa-mx-pleno-claro.svg')) ?>" alt="IAA MX">
    <p class="eyebrow">WebChat</p>
    <h1 id="title">Inicia sesión o regístrate</h1>
      <div class="tabs"><button class="tab is-active" data-tab="login">Iniciar sesión</button><button class="tab" data-tab="register">Crear cuenta</button></div>
      <form data-form="login">
        <label>Correo<input name="email" type="email" autocomplete="email" required></label>
        <label>Contraseña<input name="password" type="password" autocomplete="current-password" required></label>
        <label class="checkbox-row"><input name="remember_me" type="checkbox" value="1"><span>Recordarme en este dispositivo</span></label>
        <button class="button" type="submit">Continuar</button>
      </form>
      <form data-form="register" hidden>
        <label>Nombre<input name="name" autocomplete="name" required></label>
        <label>País / código telefónico
          <select name="country_code" autocomplete="country" required>
            <option value="" selected disabled>Selecciona un código</option>
            <option value="+52">México (+52)</option>
            <option value="+1">Estados Unidos / Canadá (+1)</option>
            <option value="+54">Argentina (+54)</option>
            <option value="+55">Brasil (+55)</option>
            <option value="+56">Chile (+56)</option>
            <option value="+57">Colombia (+57)</option>
            <option value="+506">Costa Rica (+506)</option>
            <option value="+34">España (+34)</option>
            <option value="+33">Francia (+33)</option>
            <option value="+49">Alemania (+49)</option>
            <option value="+39">Italia (+39)</option>
            <option value="+44">Reino Unido (+44)</option>
            <option value="+51">Perú (+51)</option>
            <option value="+58">Venezuela (+58)</option>
            <option value="+91">India (+91)</option>
            <option value="+81">Japón (+81)</option>
            <option value="+86">China (+86)</option>
            <option value="+61">Australia (+61)</option>
          </select>
        </label>
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
    <p class="status" data-status role="status"></p>
  </section>
</main>
<script src="<?= webchat_h(webchat_url('auth.js?v=20261117-1')) ?>" defer></script>
</body></html>
