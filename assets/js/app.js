(() => {
  const root = document.documentElement;
  const stored = localStorage.getItem('pasig-theme') || 'light';
  root.dataset.theme = stored;
  const updateThemeLabels = () => document.querySelectorAll('[data-theme-toggle]').forEach(btn => {
    const nextTheme = root.dataset.theme === 'dark' ? 'Light' : 'Dark';
    const label = btn.querySelector('span');
    if (label) label.textContent = nextTheme;
    btn.setAttribute('aria-label', `Switch to ${nextTheme.toLowerCase()} mode`);
    btn.title = `Switch to ${nextTheme.toLowerCase()} mode`;
  });
  updateThemeLabels();
  document.querySelectorAll('[data-theme-toggle]').forEach(btn => btn.addEventListener('click', () => {
    root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
    localStorage.setItem('pasig-theme', root.dataset.theme);
    updateThemeLabels();
    window.dispatchEvent(new Event('resize'));
  }));

  const sidebar = document.querySelector('.sidebar');
  const overlay = document.querySelector('.sidebar-overlay');
  document.querySelector('[data-sidebar-open]')?.addEventListener('click', () => {sidebar?.classList.add('open'); overlay?.classList.add('open');});
  overlay?.addEventListener('click', () => {sidebar?.classList.remove('open'); overlay.classList.remove('open');});

  const confirmOverlay = document.createElement('div');
  confirmOverlay.className = 'confirm-overlay';
  confirmOverlay.setAttribute('aria-hidden', 'true');
  confirmOverlay.innerHTML = `
    <section class="confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-message">
      <div class="confirm-icon" aria-hidden="true">!</div>
      <h2 id="confirm-title">Please confirm</h2>
      <p id="confirm-message"></p>
      <div class="confirm-actions">
        <button type="button" class="btn secondary" data-confirm-cancel>Cancel</button>
        <button type="button" class="btn danger" data-confirm-accept>Confirm</button>
      </div>
    </section>`;
  document.body.appendChild(confirmOverlay);

  const confirmMessage = confirmOverlay.querySelector('#confirm-message');
  const confirmAccept = confirmOverlay.querySelector('[data-confirm-accept]');
  const confirmCancel = confirmOverlay.querySelector('[data-confirm-cancel]');
  let confirmAction = null;

  const closeConfirm = () => {
    confirmOverlay.classList.remove('open');
    confirmOverlay.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    confirmAction = null;
  };
  const openConfirm = (message, action, destructive = true) => {
    confirmMessage.textContent = message;
    confirmAction = action;
    confirmAccept.textContent = destructive ? 'Yes, continue' : 'Log out';
    confirmAccept.classList.toggle('danger', destructive);
    confirmOverlay.classList.add('open');
    confirmOverlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    requestAnimationFrame(() => confirmCancel.focus());
  };

  confirmCancel.addEventListener('click', closeConfirm);
  confirmAccept.addEventListener('click', () => {
    const action = confirmAction;
    closeConfirm();
    action?.();
  });
  confirmOverlay.addEventListener('click', e => {
    if (e.target === confirmOverlay) closeConfirm();
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && confirmOverlay.classList.contains('open')) closeConfirm();
  });

  document.querySelectorAll('[data-confirm-delete]').forEach(el => el.addEventListener('click', e => {
    if (!e.target.closest('button, input[type="submit"]')) return;
    e.preventDefault();
    openConfirm(el.dataset.confirmDelete || 'Delete this record permanently?', () => el.requestSubmit());
  }));
  document.querySelector('[data-confirm-logout]')?.addEventListener('click', e => {
    e.preventDefault();
    const href = e.currentTarget.href;
    openConfirm('Are you sure you want to log out of the system?', () => window.location.assign(href), false);
  });
  const toast = document.querySelector('[data-toast]');
  if (toast) setTimeout(() => toast.remove(), 4500);

  document.querySelectorAll('[data-chart]').forEach(canvas => {
    const type = canvas.dataset.type || 'bar';
    let data = [];
    try { data = JSON.parse(canvas.dataset.chart || '[]'); } catch (_) {}
    const draw = () => {
      const rect = canvas.getBoundingClientRect();
      const dpr = window.devicePixelRatio || 1;
      canvas.width = Math.max(300, rect.width * dpr);
      canvas.height = Math.max(180, rect.height * dpr);
      const ctx = canvas.getContext('2d'); ctx.scale(dpr,dpr);
      const w = rect.width, h = rect.height;
      const css = getComputedStyle(document.documentElement);
      const text = css.getPropertyValue('--muted').trim();
      const blue = css.getPropertyValue('--blue').trim();
      const line = css.getPropertyValue('--line').trim();
      ctx.clearRect(0,0,w,h); ctx.font='12px Segoe UI'; ctx.fillStyle=text; ctx.strokeStyle=line;
      if (!data.length) {ctx.fillText('No data available yet.',20,30); return;}
      if (type === 'line') {
        const max = Math.max(100, ...data.map(d => Number(d.score || 0)));
        const left=35,right=15,top=20,bottom=35;
        for(let i=0;i<=4;i++){const y=top+(h-top-bottom)*(i/4);ctx.beginPath();ctx.moveTo(left,y);ctx.lineTo(w-right,y);ctx.stroke();ctx.fillText(String(Math.round(max*(1-i/4))),2,y+4);}
        ctx.strokeStyle=blue;ctx.lineWidth=3;ctx.beginPath();
        data.forEach((d,i)=>{const x=left+(w-left-right)*(data.length===1?.5:i/(data.length-1));const y=top+(h-top-bottom)*(1-Number(d.score||0)/max);i?ctx.lineTo(x,y):ctx.moveTo(x,y);ctx.fillStyle=text;ctx.fillText(d.label.slice(0,8),x-20,h-10);});ctx.stroke();
        data.forEach((d,i)=>{const x=left+(w-left-right)*(data.length===1?.5:i/(data.length-1));const y=top+(h-top-bottom)*(1-Number(d.score||0)/max);ctx.fillStyle=blue;ctx.beginPath();ctx.arc(x,y,4,0,Math.PI*2);ctx.fill();});
      } else {
        const max=Math.max(...data.map(d=>Number(d.value||0)),1);const gap=14;const bw=(w-40-gap*(data.length-1))/data.length;
        data.forEach((d,i)=>{const bh=(h-55)*(Number(d.value||0)/max);const x=20+i*(bw+gap);const y=h-30-bh;ctx.fillStyle=blue;ctx.fillRect(x,y,bw,bh);ctx.fillStyle=text;ctx.textAlign='center';ctx.fillText(String(d.value),x+bw/2,y-6);ctx.fillText(String(d.label).slice(0,12),x+bw/2,h-10);});ctx.textAlign='left';
      }
    };
    draw(); window.addEventListener('resize', draw);
  });
})();
