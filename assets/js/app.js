(() => {
  const sweetAlertAvailable = () => typeof window.Swal?.fire === 'function';
  const sweetAlertOptions = options => ({
    background: '#111c2f',
    color: '#eef4ff',
    confirmButtonColor: '#2563eb',
    cancelButtonColor: '#64748b',
    reverseButtons: true,
    ...options
  });

  const showNotice = (title, text, icon = 'info') => {
    if (sweetAlertAvailable()) {
      return window.Swal.fire(sweetAlertOptions({title, text, icon}));
    }
    window.alert(text ? `${title}\n\n${text}` : title);
    return Promise.resolve({isConfirmed: true});
  };

  document.querySelectorAll('[data-show-password]').forEach(toggle => {
    const passwordInput = document.getElementById(toggle.dataset.showPassword || '');
    if (!passwordInput) return;
    toggle.addEventListener('change', () => {
      passwordInput.type = toggle.checked ? 'text' : 'password';
    });
  });
  document.querySelectorAll('[data-password-toggle]').forEach(button => {
    const input = document.getElementById(button.dataset.passwordToggle || '');
    if (!input) return;
    button.addEventListener('click', () => {
      const visible = input.type === 'password';
      input.type = visible ? 'text' : 'password';
      button.classList.toggle('visible', visible);
      button.setAttribute('aria-pressed', String(visible));
      button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
      input.focus();
    });
  });
  document.querySelectorAll('[data-account-edit-cancel]').forEach(button => {
    button.addEventListener('click', () => {
      const editor = button.closest('details');
      const form = editor?.querySelector('form');
      form?.reset();
      form?.querySelectorAll('[data-show-password]').forEach(toggle => {
        const input = document.getElementById(toggle.dataset.showPassword || '');
        if (input) input.type = 'password';
      });
      form?.querySelectorAll('[data-password-toggle]').forEach(toggle => {
        const input = document.getElementById(toggle.dataset.passwordToggle || '');
        if (input) input.type = 'password';
        toggle.classList.remove('visible');
        toggle.setAttribute('aria-pressed', 'false');
        toggle.setAttribute('aria-label', 'Show password');
      });
      if (editor) editor.open = false;
    });
  });

  document.querySelectorAll('input[name="age"][min="18"]').forEach(input => {
    const validateAge = () => {
      const age = Number(input.value);
      input.setCustomValidity(input.value !== '' && age < 18
        ? 'Hindi ka pa maaaring sumagot. Ang survey na ito ay para lamang sa edad 18 pataas.'
        : '');
    };
    input.addEventListener('input', validateAge);
    input.addEventListener('invalid', validateAge);
  });

  const enhanceSelect = select => {
    if (select.multiple || select.dataset.nativeSelect !== undefined || select.closest('.custom-select')) return;
    const shell = document.createElement('div');
    shell.className = 'custom-select';
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'custom-select-trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    const menu = document.createElement('div');
    menu.className = 'custom-select-menu';
    menu.setAttribute('role', 'listbox');
    const refresh = () => {
      const selected = select.options[select.selectedIndex];
      trigger.textContent = selected?.textContent || 'Select an option';
      menu.querySelectorAll('[data-value]').forEach(item => {
        const active = item.dataset.value === select.value;
        item.classList.toggle('selected', active);
        item.setAttribute('aria-selected', String(active));
      });
    };
    [...select.options].forEach(option => {
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'custom-select-option';
      item.dataset.value = option.value;
      item.textContent = option.textContent;
      item.disabled = option.disabled;
      item.setAttribute('role', 'option');
      item.addEventListener('click', () => {
        select.value = option.value;
        select.dispatchEvent(new Event('change', {bubbles:true}));
        shell.classList.remove('open');
        trigger.setAttribute('aria-expanded', 'false');
        refresh();
        trigger.focus();
      });
      menu.appendChild(item);
    });
    trigger.addEventListener('click', () => {
      document.querySelectorAll('.custom-select.open').forEach(open => {if (open !== shell) open.classList.remove('open');});
      const open = shell.classList.toggle('open');
      trigger.setAttribute('aria-expanded', String(open));
      if (open) (menu.querySelector('.selected:not(:disabled)') || menu.querySelector('.custom-select-option:not(:disabled)'))?.focus();
    });
    shell.addEventListener('keydown', event => {
      const enabled = [...menu.querySelectorAll('.custom-select-option:not(:disabled)')];
      const current = enabled.indexOf(document.activeElement);
      if (event.key === 'ArrowDown') {event.preventDefault();enabled[Math.min(current + 1,enabled.length - 1)]?.focus();}
      if (event.key === 'ArrowUp') {event.preventDefault();enabled[Math.max(current - 1,0)]?.focus();}
      if (event.key === 'Escape') {shell.classList.remove('open');trigger.setAttribute('aria-expanded','false');trigger.focus();}
    });
    select.parentNode.insertBefore(shell, select);
    shell.append(select, trigger, menu);
    select.classList.add('custom-select-native');
    select.addEventListener('change', refresh);
    refresh();
  };
  document.querySelectorAll('select').forEach(enhanceSelect);
  document.addEventListener('click', event => {
    if (!event.target.closest('.custom-select')) document.querySelectorAll('.custom-select.open').forEach(select => {select.classList.remove('open');select.querySelector('.custom-select-trigger')?.setAttribute('aria-expanded','false');});
  });

  const sidebar = document.querySelector('.sidebar');
  const overlay = document.querySelector('.sidebar-overlay');
  document.querySelector('[data-sidebar-open]')?.addEventListener('click', () => {sidebar?.classList.add('open'); overlay?.classList.add('open');});
  overlay?.addEventListener('click', () => {sidebar?.classList.remove('open'); overlay.classList.remove('open');});

  const scopePill = document.querySelector('.scope-pill');
  if (scopePill) {
    const profileMenu = document.createElement('div');
    profileMenu.className = 'top-profile-menu';
    const profileTrigger = document.createElement('button');
    profileTrigger.type = 'button';
    profileTrigger.className = 'scope-pill top-profile-trigger';
    profileTrigger.setAttribute('aria-expanded', 'false');
    profileTrigger.innerHTML = `<span>${scopePill.textContent.trim()}</span><i aria-hidden="true">⌄</i>`;
    const accountHref = document.querySelector('.nav-link[href*="account.php"]')?.href || 'account.php';
    const logoutHref = document.querySelector('[data-confirm-logout]')?.href || 'logout.php';
    const menu = document.createElement('div');
    menu.className = 'top-profile-dropdown';
    menu.innerHTML = `<small>ACCOUNT</small><a href="${accountHref}">My Profile</a><a class="profile-logout" href="${logoutHref}" data-confirm-logout>Logout</a>`;
    scopePill.replaceWith(profileMenu);
    profileMenu.append(profileTrigger, menu);
    profileTrigger.addEventListener('click', event => {
      event.stopPropagation();
      const open = profileMenu.classList.toggle('open');
      profileTrigger.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('click', event => {
      if (!event.target.closest('.top-profile-menu')) {
        profileMenu.classList.remove('open');
        profileTrigger.setAttribute('aria-expanded', 'false');
      }
    });
  }

  const notificationToggle = document.querySelector('[data-notification-toggle]');
  const notificationPreview = document.querySelector('[data-notification-preview]');
  const notificationReadConfig = document.querySelector('[data-notification-read-config]');
  let notificationsMarkedRead = false;
  const markVisibleNotificationsRead = async () => {
    if (notificationsMarkedRead || !notificationReadConfig || !notificationToggle?.querySelector('b')) return;
    notificationsMarkedRead = true;
    const body = new FormData();
    body.append('csrf_token', notificationReadConfig.dataset.csrf || '');
    try {
      const response = await fetch(notificationReadConfig.dataset.url || '', {
        method: 'POST', body, headers: {'X-Requested-With': 'XMLHttpRequest'}
      });
      if (!response.ok || !(await response.json()).ok) throw new Error('Unable to mark notifications read');
      notificationToggle.querySelector('b')?.remove();
      notificationPreview?.querySelectorAll('.notification-preview-item.unread').forEach(item => item.classList.remove('unread'));
      const unreadLabel = notificationPreview?.querySelector('.notification-preview-head small');
      if (unreadLabel) unreadLabel.textContent = '0 unread';
    } catch (error) {
      notificationsMarkedRead = false;
    }
  };
  notificationToggle?.addEventListener('click', e => {
    e.stopPropagation();
    const open = notificationPreview?.classList.toggle('open') || false;
    notificationPreview?.setAttribute('aria-hidden', String(!open));
    notificationToggle.setAttribute('aria-expanded', String(open));
    if (open) markVisibleNotificationsRead();
  });
  document.addEventListener('click', e => {
    if (!e.target.closest('.notification-menu')) {
      notificationPreview?.classList.remove('open');
      notificationPreview?.setAttribute('aria-hidden', 'true');
      notificationToggle?.setAttribute('aria-expanded', 'false');
    }
  });

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
  const confirmTitle = confirmOverlay.querySelector('#confirm-title');
  const confirmAccept = confirmOverlay.querySelector('[data-confirm-accept]');
  const confirmCancel = confirmOverlay.querySelector('[data-confirm-cancel]');
  let confirmAction = null;

  const closeConfirm = () => {
    confirmOverlay.classList.remove('open');
    confirmOverlay.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    confirmAction = null;
  };
  const openConfirm = (message, action, options = {}) => {
    const {
      title = 'Please confirm',
      confirmText = 'Yes, continue',
      icon = 'warning',
      destructive = true
    } = options;

    if (sweetAlertAvailable()) {
      window.Swal.fire(sweetAlertOptions({
        title,
        text: message,
        icon,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Cancel',
        confirmButtonColor: destructive ? '#dc2626' : '#2563eb',
        focusCancel: true
      })).then(result => {
        if (result.isConfirmed) action?.();
      });
      return;
    }

    confirmTitle.textContent = title;
    confirmMessage.textContent = message;
    confirmAction = action;
    confirmAccept.textContent = confirmText;
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

  document.querySelectorAll('[data-confirm-delete]').forEach(el => {
    if ((el.dataset.confirmDelete || '').startsWith('Permanently delete')) {
      el.remove();
      return;
    }
    el.addEventListener('click', e => {
      if (!e.target.closest('button, input[type="submit"]')) return;
      e.preventDefault();
      openConfirm(el.dataset.confirmDelete || 'Continue with this action?', () => el.requestSubmit(), {
        title: 'Confirm this action',
        confirmText: 'Yes, continue',
        destructive: true
      });
    });
  });
  document.querySelectorAll('[data-confirm-logout]').forEach(link => link.addEventListener('click', e => {
    e.preventDefault();
    const href = e.currentTarget.href;
    openConfirm('Are you sure you want to log out of the system?', () => window.location.assign(href), {
      title: 'Log out?',
      confirmText: 'Yes, log out',
      icon: 'question',
      destructive: false
    });
  }));

  document.querySelectorAll('form[data-swal-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
      if (form.dataset.swalConfirmed === 'true') {
        delete form.dataset.swalConfirmed;
        return;
      }
      event.preventDefault();
      const submitter = event.submitter;
      openConfirm(form.dataset.swalConfirm || 'Continue with this action?', () => {
        form.dataset.swalConfirmed = 'true';
        form.requestSubmit(submitter || undefined);
      }, {
        title: form.dataset.swalTitle || 'Please confirm',
        confirmText: form.dataset.swalConfirmText || 'Yes, continue',
        icon: form.hasAttribute('data-swal-destructive') ? 'warning' : 'question',
        destructive: form.hasAttribute('data-swal-destructive')
      });
    });
  });
  const closeModal = modal => {
    modal?.classList.remove('open');
    modal?.setAttribute('aria-hidden', 'true');
    if (!document.querySelector('.app-modal.open,.confirm-overlay.open')) document.body.classList.remove('modal-open');
  };
  document.querySelectorAll('[data-modal-open]').forEach(button => button.addEventListener('click', () => {
    const modal = document.getElementById(button.dataset.modalOpen);
    if (!modal) return;
    modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('modal-open');
    requestAnimationFrame(() => modal.querySelector('input,select,textarea,button')?.focus());
  }));
  document.querySelectorAll('.app-modal').forEach(modal => {
    modal.querySelectorAll('[data-modal-close]').forEach(button => button.addEventListener('click', () => closeModal(modal)));
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(modal); });
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.app-modal.open').forEach(closeModal); });

  document.querySelectorAll('[data-report-print-form]').forEach(form => {
    const options = form.querySelector('.scope-options');
    const insertBefore = options?.querySelector('input[value="services"]')?.closest('label');
    const extras = [
      ['insights', 'Feedback Insights', 'Top positive and critical feedback.'],
      ['clients', 'Client Count', 'Total, daily average, peak, and active days.'],
      ['ratings', 'Rating Distribution', 'Actual counts for every survey criterion.']
    ];
    extras.forEach(([value, title, description]) => {
      if (!document.querySelector(`[data-report-section="${value}"]`) || form.querySelector(`input[value="${value}"]`)) return;
      const label = document.createElement('label');
      label.className = 'scope-option';
      label.innerHTML = `<input type="checkbox" name="print_section" value="${value}" checked><span><strong>${title}</strong><br><small>${description}</small></span>`;
      options?.insertBefore(label, insertBefore || null);
    });
    form.addEventListener('submit', e => {
      e.preventDefault();
      const selected = new Set([...form.querySelectorAll('input[name="print_section"]:checked')].map(input => input.value));
      if (!selected.size) {
        showNotice('No section selected', 'Select at least one report section to export.', 'warning');
        return;
      }

      openConfirm(
        `${selected.size} report section(s) will be included. The browser print window will open; choose “Save as PDF” to download the file.`,
        () => {
          const sections = [...document.querySelectorAll('[data-report-section]')];
          sections.forEach(section => section.classList.toggle('print-excluded', !selected.has(section.dataset.reportSection)));
          closeModal(form.closest('.app-modal'));
          const restore = () => sections.forEach(section => section.classList.remove('print-excluded'));
          window.addEventListener('afterprint', restore, {once:true});
          window.setTimeout(() => window.print(), 200);
          window.setTimeout(restore, 1500);
        },
        {title: 'Export report as PDF?', confirmText: 'Continue to PDF', icon: 'question', destructive: false}
      );
    });
  });

  const submitAfterExportConfirmation = (form, submitter, message) => {
    if (form.dataset.exportConfirmed === 'true') {
      delete form.dataset.exportConfirmed;
      return true;
    }
    openConfirm(message, () => {
      form.dataset.exportConfirmed = 'true';
      form.requestSubmit(submitter || undefined);
    }, {
      title: 'Export file?',
      confirmText: 'Yes, export',
      icon: 'question',
      destructive: false
    });
    return false;
  };

  document.querySelectorAll('form.export-csv-form').forEach(form => {
    form.addEventListener('submit', event => {
      if (!submitAfterExportConfirmation(form, event.submitter, 'Download the currently filtered records as a CSV file?')) {
        event.preventDefault();
      }
    });
  });

  document.querySelectorAll('form[target="_blank"]').forEach(form => {
    if (!form.querySelector('input[name="export"][value="pdf"]')) return;
    form.addEventListener('submit', event => {
      if (!submitAfterExportConfirmation(form, event.submitter, 'Open a printable PDF report using the current filters?')) {
        event.preventDefault();
      } else {
        closeModal(form.closest('.app-modal'));
      }
    });
  });

  document.querySelectorAll('a[href*="export=pdf"]').forEach(link => {
    link.addEventListener('click', event => {
      event.preventDefault();
      const href = link.href;
      openConfirm('Generate and download the dashboard PDF using the current data?', () => window.location.assign(href), {
        title: 'Download dashboard PDF?',
        confirmText: 'Yes, download',
        icon: 'question',
        destructive: false
      });
    });
  });

  document.querySelectorAll('form.filters[method="get"]').forEach(form => {
    let timer;
    const submit = () => { form.querySelectorAll('input[name="page"]').forEach(input => input.remove()); form.requestSubmit(); };
    form.querySelectorAll('select,input[type="date"],input[type="month"],input[type="checkbox"],input[type="radio"]').forEach(control => control.addEventListener('change', submit));
    form.querySelectorAll('input:not([type]),input[type="text"],input[type="search"]').forEach(input => input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(submit, 450); }));
    form.querySelectorAll('button[type="submit"]:not([name="export"]),button:not([type]):not([name="export"])').forEach(button => button.classList.add('filter-submit-fallback'));
  });

  const toast = document.querySelector('[data-toast]');
  if (toast) {
    const permissionDenied = toast.classList.contains('denied');
    const type = ['success', 'error', 'warning', 'info'].includes(toast.classList[1])
      ? toast.classList[1]
      : 'info';
    const message = toast.textContent.trim();
    if (sweetAlertAvailable()) {
      toast.remove();
      window.Swal.fire(sweetAlertOptions(permissionDenied ? {
        icon: 'error',
        title: 'Access denied',
        text: message,
        confirmButtonText: 'OK'
      } : {
        toast: true,
        position: 'top-end',
        icon: type,
        title: message,
        showConfirmButton: false,
        timer: 4500,
        timerProgressBar: true
      }));
    } else {
      setTimeout(() => toast.remove(), 4500);
    }
  }

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
