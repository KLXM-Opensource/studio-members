/*
 * Mitgliederbereich (Erweiterung members): Passkeys auf der Website – Anmelden ([data-mb-passkey-login]) und Einrichten
 * ([data-mb-passkey-add], im Konto und beim Annehmen einer Einladung). Ohne WebAuthn bleiben die Bereiche verborgen (hidden),
 * Passwort und Anmelde-Link funktionieren weiter. CSRF-Token im Kopf X-CSRF-Token, Antworten JSON.
 */
(() => {
  'use strict';
  if (!window.PublicKeyCredential || !navigator.credentials) return;

  const b64u = {
    enc(buf) {
      const b = new Uint8Array(buf);
      let s = '';
      for (let i = 0; i < b.length; i++) s += String.fromCharCode(b[i]);
      return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    },
    dec(str) {
      const s = atob(String(str).replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((String(str).length + 3) % 4));
      const out = new Uint8Array(s.length);
      for (let i = 0; i < s.length; i++) out[i] = s.charCodeAt(i);
      return out.buffer;
    },
  };

  async function post(url, csrf, body) {
    const res = await fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, Accept: 'application/json' },
      body: JSON.stringify(body || {}),
    });
    let data = {};
    try { data = await res.json(); } catch { /* leer */ }
    if (!res.ok || data.error) throw new Error(data.error || 'Fehler ' + res.status);
    return data;
  }

  const credJson = (c) => {
    const r = c.response;
    const out = { id: c.id, rawId: b64u.enc(c.rawId), type: c.type, response: { clientDataJSON: b64u.enc(r.clientDataJSON) } };
    if (r.attestationObject) out.response.attestationObject = b64u.enc(r.attestationObject);
    if (r.authenticatorData) out.response.authenticatorData = b64u.enc(r.authenticatorData);
    if (r.signature) out.response.signature = b64u.enc(r.signature);
    if (r.userHandle) out.response.userHandle = b64u.enc(r.userHandle);
    if (typeof r.getTransports === 'function') out.response.transports = r.getTransports();
    return out;
  };

  const showError = (box, err) => {
    const p = box.querySelector('[data-mb-error]');
    if (!p) return;
    // Abbruch durch die Person (NotAllowedError) ist kein Fehler, der erklärt werden muss
    if (err && err.name === 'NotAllowedError') { p.hidden = true; return; }
    p.textContent = err && err.message ? err.message : String(err);
    p.hidden = false;
  };

  const busy = (btn, on) => { btn.disabled = on; btn.setAttribute('aria-busy', on ? 'true' : 'false'); };

  document.querySelectorAll('[data-mb-passkey-login]').forEach((box) => {
    box.hidden = false;
    const btn = box.querySelector('button');
    btn.addEventListener('click', async () => {
      busy(btn, true);
      try {
        const o = await post(box.dataset.options, box.dataset.csrf);
        o.challenge = b64u.dec(o.challenge);
        if (o.allowCredentials) o.allowCredentials = o.allowCredentials.map((c) => ({ ...c, id: b64u.dec(c.id) }));
        const cred = await navigator.credentials.get({ publicKey: o });
        const res = await post(box.dataset.url, box.dataset.csrf, { credential: credJson(cred), ziel: box.dataset.target || '' });
        location.assign(res.redirect || '/');
      } catch (err) {
        showError(box, err);
        busy(btn, false);
      }
    });
  });

  document.querySelectorAll('[data-mb-passkey-add]').forEach((box) => {
    box.hidden = false;
    const btn = box.querySelector('button');
    const form = box.closest('form');
    btn.addEventListener('click', async () => {
      const nameInput = form ? form.querySelector('input[name="name"]') : null;
      const memberName = nameInput ? nameInput.value.trim() : '';
      if ('invite' in box.dataset && nameInput && !memberName) {
        nameInput.focus();
        nameInput.reportValidity && nameInput.reportValidity();
        return;
      }
      busy(btn, true);
      try {
        const o = await post(box.dataset.options, box.dataset.csrf, { name: memberName });
        o.challenge = b64u.dec(o.challenge);
        o.user.id = b64u.dec(o.user.id);
        o.excludeCredentials = (o.excludeCredentials || []).map((c) => ({ ...c, id: b64u.dec(c.id) }));
        const cred = await navigator.credentials.create({ publicKey: o });
        const res = await post(box.dataset.url, box.dataset.csrf, { credential: credJson(cred), member_name: memberName, label: '' });
        location.assign(res.redirect || location.href);
      } catch (err) {
        showError(box, err);
        busy(btn, false);
      }
    });
  });
})();
