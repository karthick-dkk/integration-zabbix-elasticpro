/*
 * The Maintenance and Delete-for-good dialogs (ClientActions::dialogs), for whichever page draws
 * them. A button with data-open-maint="<client>" opens Maintenance for that client; with an empty
 * value, for the ticked ones — the list passes ticked(). data-open-delete="<client>" opens Delete.
 */
window.EpDialogs = {
  init(root, ticked) {
    const maint = root.querySelector('#ep-maint');
    // A read-only page has neither dialog.
    if (!maint) return;
    root.querySelectorAll('[data-open-maint]').forEach((b) => b.addEventListener('click', () => {
      const names = b.dataset.openMaint ? [b.dataset.openMaint] : (ticked ? ticked() : []);
      const who = maint.querySelector('#ep-maint-who');
      who.textContent = '';
      names.forEach((n) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'clients[' + n + ']'; i.value = '1'; who.appendChild(i); });
      maint.querySelector('#ep-maint-title').textContent = 'Maintenance: ' + names.join(', ');
      maint.showModal();
    }));
    maint.querySelectorAll('input[name="when"]').forEach((r) => r.addEventListener('change', () => {
      maint.querySelector('#m-start').hidden = maint.querySelector('#m-at').checked === false;
    }));
    maint.querySelector('form').addEventListener('submit', () => {
      if (!maint.querySelector('#m-at').checked) maint.querySelector('#m-start').value = '';
    });

    // Delete for good: enabled only once the name is typed.
    const del = root.querySelector('#ep-delete');
    root.querySelectorAll('[data-open-delete]').forEach((b) => b.addEventListener('click', () => {
      del.querySelector('#d-client').value = b.dataset.openDelete;
      del.querySelector('#d-confirm').value = '';
      del.querySelector('#d-confirm').placeholder = b.dataset.openDelete;
      del.querySelector('#d-go').disabled = true;
      del.showModal();
    }));
    del.querySelector('#d-confirm').addEventListener('input', (e) => { del.querySelector('#d-go').disabled = e.target.value.trim() !== del.querySelector('#d-client').value; });
    // Remove only in ElasticPro: the same typed-name gate, on the client's page only.
    const rel = root.querySelector('#ep-release');
    if (rel) {
      root.querySelectorAll('[data-open-release]').forEach((b) => b.addEventListener('click', () => {
        rel.querySelector('#r-client').value = b.dataset.openRelease;
        rel.querySelector('#r-confirm').value = '';
        rel.querySelector('#r-confirm').placeholder = b.dataset.openRelease;
        rel.querySelector('#r-go').disabled = true;
        rel.showModal();
      }));
      rel.querySelector('#r-confirm').addEventListener('input', (e) => { rel.querySelector('#r-go').disabled = e.target.value.trim() !== rel.querySelector('#r-client').value; });
    }
    root.querySelectorAll('dialog [data-close]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));
  }
};
