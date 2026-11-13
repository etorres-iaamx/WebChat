(() => {
  const status = document.querySelector('[data-status]');
  const forms = Object.fromEntries([...document.querySelectorAll('[data-form]')].map((form) => [form.dataset.form, form]));
  const tabs = document.querySelector('.tabs');
  const tabButtons = [...document.querySelectorAll('[data-tab]')];
  let registrationStarted = false;
  const setStatus = (text, error = false) => { status.textContent = text; status.dataset.error = error ? 'true' : 'false'; };
  tabButtons.forEach((button) => button.addEventListener('click', () => {
    if (registrationStarted && button.dataset.tab === 'register') return;
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
  forms.register?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (registrationStarted) return;
    try {
      const data = await submit(forms.register, 'register');
      registrationStarted = true;
      forms.verify.email.value = data.user.email;
      forms.register.hidden = true;
      forms.login.hidden = true;
      forms.verify.hidden = false;
      if (tabs) tabs.hidden = true;
      setStatus('Revisa tu correo e introduce el código de verificación.');
    } catch (error) { setStatus(error.message, true); }
  });
  forms.verify?.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await submit(forms.verify, 'verify');
      setStatus('Correo verificado. Ya puedes iniciar sesión.');
      forms.verify.hidden = true;
      forms.login.hidden = false;
    } catch (error) { setStatus(error.message, true); }
  });
})();
