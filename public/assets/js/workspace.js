(() => {
  document.documentElement.classList.add('js-enabled');
  const body = document.body;
  const sidebar = document.getElementById('app-sidebar');
  const backdrop = document.querySelector('.sidebar-backdrop');
  const main = document.querySelector('.app-main');
  const mobile = window.matchMedia('(max-width: 900px)');
  let collapsed = false;
  let returnFocus = null;
  try { collapsed = localStorage.getItem('spare-sidebar-collapsed') === 'true'; } catch (_) {}
  const sync = () => {
    body.classList.toggle('sidebar-collapsed', !mobile.matches && collapsed);
    const open = body.classList.contains('sidebar-open') && mobile.matches;
    if (!mobile.matches) body.classList.remove('sidebar-open');
    backdrop.hidden = !open;
    main.inert = open;
    sidebar.inert = mobile.matches && !open;
    document.querySelectorAll('[data-sidebar-toggle]').forEach(button => {
      button.setAttribute('aria-expanded', String(mobile.matches ? open : !collapsed));
      if (!button.classList.contains('mobile-close')) button.setAttribute('aria-label', mobile.matches ? (open ? 'Close navigation' : 'Open navigation') : (collapsed ? 'Expand navigation' : 'Collapse navigation'));
    });
  };
  const close = () => { body.classList.remove('sidebar-open'); sync(); returnFocus?.focus(); };
  document.querySelectorAll('[data-sidebar-toggle]').forEach(button => button.addEventListener('click', () => {
    if (mobile.matches) {
      if (body.classList.contains('sidebar-open')) return close();
      returnFocus = button;
      body.classList.add('sidebar-open');
      sync(); sidebar.querySelector('a')?.focus();
    } else {
      collapsed = !collapsed;
      try { localStorage.setItem('spare-sidebar-collapsed', String(collapsed)); } catch (_) {}
      sync();
    }
  }));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      if (body.classList.contains('sidebar-open')) close();
      document.querySelectorAll('.profile-menu[open]').forEach(menu => { menu.open = false; menu.querySelector('summary').focus(); });
    }
    if (event.key === 'Tab' && mobile.matches && body.classList.contains('sidebar-open')) {
      const items = [...sidebar.querySelectorAll('a,button,input:not([type="hidden"])')].filter(item => item.getClientRects().length && !item.disabled);
      const first = items[0], last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });
  document.addEventListener('click', event => document.querySelectorAll('.profile-menu[open]').forEach(menu => { if (!menu.contains(event.target)) menu.open = false; }));
  mobile.addEventListener('change', sync);
  sync();

  document.addEventListener('submit', async event => {
    const form = event.target.closest('[data-staff-permissions]');
    if (!form || !window.fetch) return;
    event.preventDefault();
    const button = form.querySelector('[type="submit"]');
    const status = form.querySelector('[role="status"]');
    const data = new FormData(form);
    button.disabled = true; form.setAttribute('aria-busy', 'true'); status.textContent = 'Saving…';
    try {
      const response = await fetch(form.getAttribute('action'), {method:'POST', body:data, headers:{'Accept':'application/json'}});
      if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Your session may have expired. Reload the page and sign in again.');
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || 'Unable to save permissions.');
      status.textContent = result.message;
    } catch (error) { status.textContent = error.message || 'Connection interrupted. Please try again.'; }
    finally { button.disabled = false; form.removeAttribute('aria-busy'); }
  });
})();
