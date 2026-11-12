(() => {
  const status = document.querySelector('[data-status]');
  const forms = Object.fromEntries([...document.querySelectorAll('[data-form]')].map((form) => [form.dataset.form, form]));
  const setStatus = (text, error = false) => { status.textContent = text; status.dataset.error = error ? 'true' : 'false'; };
  document.querySelectorAll('[data-tab]').forEach((button) => button.addEventListener('click', () => {
    document.querySelectorAll('[data-tab]').forEach((item) => item.classList.toggle('is-active', item === button));
    Object.entries(forms).forEach(([key, form]) => { form.hidden = key !== button.dataset.tab; });
    setStatus('');
  }));
  async function submit(form, action) {
    const payload = Object.fromEntries(new FormData(form).entries());
    const response = await fetch(`./api.php?action=${action}`, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
    const body = await response.json();
    if (!response.ok || !body.ok) throw new Error(body.error || 'No fue posible completar la operación.');
    return body.data || {};
  }
  forms.login?.addEventListener('submit', async (event) => { event.preventDefault(); try { await submit(forms.login, 'login'); location.href = './webchat/index.php'; } catch (error) { setStatus(error.message, true); } });
  forms.register?.addEventListener('submit', async (event) => { event.preventDefault(); try { const data = await submit(forms.register, 'register'); forms.verify.email.value = data.user.email; forms.register.hidden = true; forms.login.hidden = true; forms.verify.hidden = false; setStatus('Revisa tu correo e introduce el código de verificación.'); } catch (error) { setStatus(error.message, true); } });
  forms.verify?.addEventListener('submit', async (event) => { event.preventDefault(); try { await submit(forms.verify, 'verify'); setStatus('Correo verificado. Ya puedes iniciar sesión.'); forms.verify.hidden = true; forms.login.hidden = false; } catch (error) { setStatus(error.message, true); } });
  document.querySelector('[data-action="logout"]')?.addEventListener('click', async () => { await fetch('./api.php?action=logout', {method: 'POST'}); location.reload(); });
  const pairingStart = forms['pairing-start'];
  const pairingConfirm = forms['pairing-confirm'];
  const pairingStatus = document.querySelector('[data-pairing-status]');
  pairingStart?.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      const data = await submit(pairingStart, 'pairing/start');
      pairingConfirm.pairing_id.value = data.pairing_id;
      pairingConfirm.hidden = false;
      pairingStatus.textContent = `Código: ${data.challenge}. Confírmalo después de usarlo en el canal.`;
    } catch (error) { pairingStatus.textContent = error.message; pairingStatus.dataset.error = 'true'; }
  });
  pairingConfirm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await submit(pairingConfirm, 'pairing/confirm');
      pairingStatus.textContent = 'Canal vinculado correctamente.';
      pairingStatus.dataset.error = 'false';
    } catch (error) { pairingStatus.textContent = error.message; pairingStatus.dataset.error = 'true'; }
  });
})();
