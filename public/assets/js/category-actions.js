(() => {
  let opener = null;
  let previewVersion = 0;
  const dialog = () => document.getElementById('main-category-dialog');
  const closeMenus = () => document.querySelectorAll('.main-category-dropdown').forEach(menu => {
    menu.hidden = true; menu.previousElementSibling.setAttribute('aria-expanded', 'false');
  });
  const closeDialog = () => {
    if (dialog()?.querySelector('form').getAttribute('aria-busy') === 'true') return;
    previewVersion++; dialog()?.close(); opener?.focus({preventScroll:true});
  };
  let addOpener = null;
  const closeAdd = () => {
    const popup = document.getElementById('add-category-dialog');
    if (popup.querySelector('form').getAttribute('aria-busy') === 'true') return;
    popup.close(); addOpener?.focus({preventScroll:true});
  };
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-add-category],[data-edit-category]');
    if (button) {
      event.preventDefault(); event.stopPropagation(); closeMenus(); addOpener = button;
      const popup = document.getElementById('add-category-dialog');
      const form = popup.querySelector('form'); form.reset(); form.querySelector('.dialog-error').textContent = '';
      const editing = !!button.dataset.editCategory;
      const subcategory = (button.dataset.editCategory || button.dataset.addCategory) === 'subcategory';
      let entry = null;
      if (editing) {
        const categories = JSON.parse(document.getElementById('main-category-data').textContent);
        const entries = subcategory ? categories.flatMap(category => category.subcategories) : categories;
        entry = entries.find(row => Number(row.id) === Number(button.dataset.editId));
        if (!entry || entry.archived_at) return;
      }
      form.elements.id.disabled = !editing;
      form.elements.id.value = entry?.id || '';
      form.elements.level.value = subcategory ? 'subcategory' : 'category';
      form.elements.category_id.disabled = !subcategory; form.elements.category_id.required = subcategory;
      form.elements.category_id.value = entry?.category_id || button.dataset.parentId || '';
      document.getElementById('add-category-parent-label').hidden = !subcategory || editing;
      if (entry) {
        for (const field of ['name','slug','description','status','sort_order']) form.elements[field].value = entry[field] ?? '';
      }
      document.getElementById('add-category-title').textContent = `${editing ? 'Edit' : 'Add'} ${subcategory ? 'subcategory' : 'main category'}`;
      popup.showModal(); form.elements.name.focus();
    } else if (event.target.closest('[data-close-add-dialog]')) closeAdd();
  });
  document.addEventListener('keydown', event => {
    if(event.key==='Escape' && document.getElementById('add-category-dialog')?.open) { event.preventDefault(); closeAdd(); }
  });
  document.addEventListener('cancel', event => {
    if(event.target.id==='add-category-dialog') { event.preventDefault();closeAdd(); }
  },true);
  document.addEventListener('click', event => {
    const toggle = event.target.closest('[data-main-menu]');
    if (toggle) {
      event.preventDefault(); event.stopPropagation();
      const menu = toggle.nextElementSibling; const open = menu.hidden;
      closeMenus(); menu.hidden = !open; toggle.setAttribute('aria-expanded', String(open)); return;
    }
    const action = event.target.closest('[data-main-action]');
    if (!action) {
      if (!event.target.closest('.category-menu-wrap')) closeMenus();
      if (event.target.closest('[data-close-category-dialog]')) closeDialog();
      return;
    }
    event.preventDefault(); event.stopPropagation();
    opener = action.closest('.category-menu-wrap').querySelector('[data-main-menu]'); closeMenus();
    const categories = JSON.parse(document.getElementById('main-category-data').textContent);
    const category = categories.find(row => Number(row.id) === Number(action.dataset.id));
    const form = dialog().querySelector('form'); form.reset(); previewVersion++;
    form.elements.action.value = action.dataset.mainAction; form.elements.id.value = category.id;
    form.querySelector('.dialog-error').textContent = '';
    const mode = action.dataset.mainAction;
    document.getElementById('category-dialog-title').textContent = `${{merge:'Merge',archive:'Archive',restore:'Restore',delete:'Delete permanently'}[mode]} · ${category.name}`;
    const description = document.getElementById('category-dialog-description');
    const preview = document.getElementById('category-merge-preview'); preview.replaceChildren();
    const label = document.getElementById('merge-destination-label'); label.hidden = mode !== 'merge';
    form.elements.target_id.required = mode === 'merge'; form.elements.target_id.disabled = mode !== 'merge';
    const submit = document.getElementById('category-dialog-submit'); submit.disabled = false;
    document.getElementById('category-confirmation-label').hidden = false; form.elements.confirmed.required = true;
    if (mode === 'merge') {
      form.elements.target_id.replaceChildren(new Option('Choose destination',''));
      categories.filter(row => !row.archived_at && row.status === 'active' && row.id !== category.id).forEach(row => form.elements.target_id.add(new Option(row.name,row.id)));
      description.textContent = `${category.product_count} products and ${category.subcategories.filter(row=>!row.archived_at).length} live subcategories will be affected. Choose a destination to preview the merge.`;
      submit.disabled = true;
    } else if (mode === 'archive') {
      const children = category.subcategories.filter(row => !row.archived_at).length;
      const blocked = Number(category.product_count)>0 || children>0;
      description.textContent = blocked ? `Cannot archive: ${category.product_count} linked products and ${children} live subcategories remain. Reassign products and move or archive subcategories first, or use Merge. Products will never be archived automatically.` : 'Archive this empty category? Archived child records and historical references are retained. You can restore it later.';
      submit.disabled = blocked;
    } else if (mode === 'delete') {
      const blocked = !category.archived_at || Number(category.product_count)>0 || category.subcategories.length>0;
      description.textContent = !category.archived_at ? 'Archive this category first. Permanent deletion is separate and is only allowed for unreferenced archived entries.' : blocked ? `Deletion blocked: ${category.product_count} products and ${category.subcategories.length} child records remain, including archived children. Reassign products and move or safely delete children first.` : 'Permanently delete this archived category? This cannot be undone. The server will reject deletion if any product or historical foreign-key references exist. No products or transactions are deleted.';
      submit.disabled = blocked;
    } else description.textContent = 'Restore this category to the active catalogue? Archived subcategories remain archived until you restore them separately.';
    document.getElementById('category-confirmation-text').textContent = mode === 'merge' ? 'I have reviewed the preview and confirm this merge.' : 'I understand and confirm this action.';
    dialog().showModal();
  });
  document.addEventListener('change', async event => {
    if (!event.target.matches('#main-category-action-form [name="target_id"]')) return;
    const version = ++previewVersion;
    const form = event.target.form; const preview = document.getElementById('category-merge-preview');
    const submit = document.getElementById('category-dialog-submit'); submit.disabled = true; form.elements.confirmed.checked = false;
    if (!event.target.value) { preview.textContent = ''; return; }
    preview.textContent = 'Loading merge preview…';
    const url = new URL(form.getAttribute('action'),location.href);
    url.searchParams.set('preview','merge');url.searchParams.set('id',form.elements.id.value);url.searchParams.set('target_id',event.target.value);
    try {
      const response = await fetch(url,{headers:{Accept:'application/json'}});
      const result = await response.json(); if (version !== previewVersion) return;
      if (!response.ok) throw new Error(result.message || 'Unable to load preview.');
      preview.replaceChildren();
      const summary = document.createElement('p');summary.textContent = `${result.products} products · ${result.subcategories} subcategories → ${result.destination}. ${result.direct_products} directly assigned products will remain without a subcategory.`;preview.append(summary);
      const list = document.createElement('ul');
      result.rows.forEach(row => { const item=document.createElement('li');item.textContent=`${row.name}: ${row.action} (${row.products} products).${row.reason?' Blocked: '+row.reason:''}`;list.append(item); });
      preview.append(list);
      const note=document.createElement('p');note.textContent='Source records are archived; product IDs and historical transactions are preserved. The server rechecks the destination and performs the entire merge atomically.';preview.append(note);
      submit.disabled = result.blocked;
    } catch(error) { if(version===previewVersion) preview.textContent=error.message || 'Preview unavailable. Please try again.'; }
  });
  document.addEventListener('keydown', event => { if(event.key==='Escape') closeMenus(); });
  document.addEventListener('cancel', event => {
    if(event.target.id==='main-category-dialog') { event.preventDefault();closeDialog(); }
  },true);
})();
