(() => {
  let expanded = [];
// Each page visit starts collapsed; keep expansion in memory during operations.
  if (!Array.isArray(expanded)) expanded = [];
  const saveExpansion = () => {
    expanded = [...document.querySelectorAll('.category-card[open]')].map(item => item.id);
  };
  let restoring = false;
  let mutating = false;
  const init = () => {
    const filters = document.getElementById('category-filter');
    const filtered = filters && (filters.elements.q.value || filters.elements.status.value !== 'all' || filters.elements.view.value !== 'all');
    restoring = true;
    document.querySelectorAll('.category-card').forEach(card => {
      card.open = expanded.includes(card.id);
    });
    setTimeout(() => { restoring = false; }, 0);
  };
  const filter = () => {
    const form = document.getElementById('category-filter');
    if (!form) return;
    const q = form.elements.q.value.trim().toLocaleLowerCase();
    const status = form.elements.status.value;
    const level = form.elements.view.value;
    let categories = 0, subcategories = 0;
    document.querySelectorAll('.category-card').forEach(card => {
      const heading = card.querySelector('.category-summary');
      const mainMatch = !q || heading.dataset.name.toLocaleLowerCase().includes(q);
      let childCount = 0;
      card.querySelectorAll('.subcategory-row').forEach(row => {
        const child = row.querySelector('.subcategory-heading');
        const matches = level !== 'category' && (mainMatch || !q || child.dataset.name.toLocaleLowerCase().includes(q)) && (status === 'all' || child.dataset.status === status);
        row.hidden = !matches;
        if (matches) childCount++;
      });
      const visible = (level !== 'subcategory' && mainMatch && (status === 'all' || heading.dataset.status === status)) || childCount > 0;
      card.hidden = !visible;
      if (visible) { categories++; subcategories += childCount; }
      if (q || status === 'archived' || level === 'subcategory') card.open = visible && childCount > 0;
      else card.open = expanded.includes(card.id);
    });
    document.getElementById('category-result-count').textContent = `${categories} categories · ${subcategories} subcategories shown`;
    document.getElementById('category-no-results').hidden = categories !== 0;
    const url = new URL(window.location.href);
    for (const [name, value, empty] of [['q', q, ''], ['status', status, 'active'], ['view', level, 'all']]) {
      if (value === empty) url.searchParams.delete(name); else url.searchParams.set(name, value);
    }
    history.replaceState(null, '', url);
  };
  document.addEventListener('toggle', event => {
    if (!restoring && event.target.classList.contains('category-card')) {
      saveExpansion();
    }
  }, true);
  document.addEventListener('input', event => { if (event.target.closest('#category-filter')) filter(); });
  document.addEventListener('change', event => { if (event.target.closest('#category-filter')) filter(); });
  document.addEventListener('reset', event => {
    if (event.target.id === 'category-filter') {
      event.preventDefault();
      event.target.elements.q.value = ''; event.target.elements.status.value = 'active'; event.target.elements.view.value = 'all'; filter();
    }
  });
  document.addEventListener('click', event => {
    if (event.target.closest('[data-expand-all],[data-collapse-all]')) {
      restoring = true;
      const open = !!event.target.closest('[data-expand-all]');
      document.querySelectorAll('.category-card:not([hidden])').forEach(card => { card.open = open; });
      saveExpansion(); setTimeout(() => { restoring = false; }, 0); return;
    }
    const button = event.target.closest('[data-category-open]');
    if (!button) return;
    event.preventDefault(); event.stopPropagation();
    const card = button.closest('.category-card'); card.open = true;
    const editor = card.querySelector(button.dataset.categoryOpen); editor.open = true;
    editor.querySelector('input:not([type="hidden"]),select,button')?.focus();
  });
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (form.id === 'category-filter') { event.preventDefault(); filter(); return; }
    if (!form.closest('#category-workspace') || form.method.toLowerCase() !== 'post') return;
    if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { event.preventDefault(); return; }
    if (!window.fetch) return;
    event.preventDefault();
    if (mutating || form.getAttribute('aria-busy') === 'true') return;
    mutating = true;
    const data = new FormData(form);
    if (event.submitter?.name) data.set(event.submitter.name, event.submitter.value);
    const button = event.submitter;
    const feedback = document.getElementById('category-feedback');
    feedback.className = 'inline-feedback'; feedback.textContent = 'Saving changes…';
    form.setAttribute('aria-busy', 'true'); if (button) button.disabled = true;
    const url = new URL(form.getAttribute('action'), window.location.href);
    const params = new URL(window.location.href).searchParams;
    for (const name of ['q','status','view']) if (params.has(name)) url.searchParams.set(name, params.get(name));
    const anchor = form.closest('.category-card')?.id;
    const scroll = window.scrollY;
    try {
      const response = await fetch(url, {method:'POST',body:data,headers:{'Accept':'application/json'}});
      if (!response.headers.get('content-type')?.includes('application/json')) throw new Error(response.status === 503 ? 'Unable to save right now. Please try again.' : 'Your session may have expired. Reload the page to sign in again.');
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || 'Changes could not be saved.');
      const template = document.createElement('template'); template.innerHTML = result.html;
      document.getElementById('category-workspace').replaceWith(template.content);
      init();
      const card = anchor && document.getElementById(anchor);
      if (card) { card.querySelector('summary')?.focus({preventScroll:true}); }
      else document.querySelector('#category-workspace h1')?.scrollIntoView({block:'nearest'});
      window.scrollTo({top:scroll});
      document.getElementById('app-announcement').textContent = result.message;
    } catch (error) {
      const dialogError = form.closest('dialog')?.querySelector('.dialog-error');
      if (dialogError) dialogError.textContent = error.message || 'Changes could not be saved.';
      feedback.className = 'alert'; feedback.setAttribute('role','alert');
      feedback.textContent = error.message || 'Connection interrupted. Your form is preserved; please try again.';
    } finally {
      mutating = false;
      form.removeAttribute('aria-busy'); if (button) button.disabled = false;
    }
  });
  init();
})();
