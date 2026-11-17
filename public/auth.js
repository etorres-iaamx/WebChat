(() => {
  const basePath = document.documentElement.dataset.webchatPath || '/webchat';
  const onboardingIntent = document.documentElement.dataset.onboardingIntent || '';
  const status = document.querySelector('[data-status]');
  const forms = Object.fromEntries([...document.querySelectorAll('[data-form]')].map((form) => [form.dataset.form, form]));
  const tabs = document.querySelector('.tabs');
  const tabButtons = [...document.querySelectorAll('[data-tab]')];
  let registrationStarted = false;
  const setStatus = (text, error = false) => { status.textContent = text; status.dataset.error = error ? 'true' : 'false'; };
  const setFormBusy = (form, busy) => {
    if (!form) return;
    form.dataset.busy = busy ? 'true' : 'false';
    form.setAttribute('aria-busy', busy ? 'true' : 'false');
    form.querySelectorAll('button, input, select').forEach((control) => { control.disabled = busy; });
  };
  tabButtons.forEach((button) => button.addEventListener('click', () => {
    if (registrationStarted && button.dataset.tab === 'register') return;
    document.querySelectorAll('[data-tab]').forEach((item) => item.classList.toggle('is-active', item === button));
    Object.entries(forms).forEach(([key, form]) => { form.hidden = key !== button.dataset.tab; });
    setStatus('');
  }));
  async function submit(form, action) {
    const payload = Object.fromEntries(new FormData(form).entries());
    const response = await fetch(`${basePath}/api.php?action=${action}`, { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload) });
    const body = await response.json();
    if (!response.ok || !body.ok) throw new Error(body.error || 'No fue posible completar la operación.');
    return body.data || {};
  }
  async function provisionRuntimeWithRetry() {
    const delays = [0, 1000, 3000];
    for (let attempt = 0; attempt < delays.length; attempt += 1) {
      if (delays[attempt] > 0) await new Promise((resolve) => setTimeout(resolve, delays[attempt]));
      setStatus('Preparando tu espacio…');
      try {
        await submit(forms.verify, 'runtime/provision');
        return true;
      } catch (_) {
        // The backend records the technical failure; the user only sees the final generic state.
      }
    }
    return false;
  }
  forms.login?.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await submit(forms.login, 'login');
      if (onboardingIntent) {
        const linkResponse = await fetch(`${basePath}/api.php?action=channel/link`, { method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify({intent: onboardingIntent}) });
        const link = await linkResponse.json();
        if (!linkResponse.ok || !link.ok) throw new Error(link.error || 'No fue posible vincular el canal.');
      }
      location.href = `${basePath}/dashboard`;
    } catch (error) { setStatus(error.message, true); }
  });
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
    setFormBusy(forms.verify, true);
    setStatus('Verificando tu correo…');
    try {
      const verification = await submit(forms.verify, 'verify');
      if (verification.provisioning_pending && !(await provisionRuntimeWithRetry())) {
        setFormBusy(forms.verify, false);
        setStatus('No fue posible completar la operación. Intenta nuevamente más tarde.', true);
        return;
      }
      if (onboardingIntent) {
        const linkResponse = await fetch(`${basePath}/api.php?action=channel/link`, { method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin', body: JSON.stringify({intent: onboardingIntent}) });
        const link = await linkResponse.json();
        if (!linkResponse.ok || !link.ok) throw new Error(link.error || 'No fue posible vincular el canal.');
      }
      setStatus('Correo verificado. Ya puedes iniciar sesión.');
      forms.verify.hidden = true;
      forms.login.hidden = false;
    } catch (error) {
      setFormBusy(forms.verify, false);
      setStatus(error.message, true);
    }
  });
})();
