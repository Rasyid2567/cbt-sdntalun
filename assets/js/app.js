/**
 * CBT Global Navigation & Modal Interactions
 */
window.toggleNavMenu = function(e) {
    if (e) {
        if (typeof e.preventDefault === 'function') e.preventDefault();
        if (typeof e.stopPropagation === 'function') e.stopPropagation();
    }
    const navMenu = document.getElementById('cbt-nav-menu');
    if (navMenu) {
        navMenu.classList.toggle('open');
    }
};

window.initCustomSelects = function(root = document) {
    const selects = root.querySelectorAll('select:not([data-customized])');
    selects.forEach(select => {
        if (select.dataset.customized || select.classList.contains('no-custom-select')) return;
        select.dataset.customized = 'true';

        const wrapper = document.createElement('div');
        wrapper.className = 'cbt-select-container';
        if (select.classList.contains('form-control-sm')) wrapper.classList.add('cbt-select-sm');

        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);

        const trigger = document.createElement('div');
        trigger.className = 'cbt-select-trigger';
        trigger.tabIndex = 0;

        const label = document.createElement('span');
        label.className = 'cbt-select-label';

        const arrow = document.createElement('span');
        arrow.className = 'cbt-select-arrow';
        arrow.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg>`;

        trigger.appendChild(label);
        trigger.appendChild(arrow);
        wrapper.appendChild(trigger);

        const dropdown = document.createElement('div');
        dropdown.className = 'cbt-select-dropdown';
        wrapper.appendChild(dropdown);

        const renderOptions = () => {
            dropdown.innerHTML = '';
            const selectedOpt = select.options[select.selectedIndex];
            label.textContent = selectedOpt ? selectedOpt.textContent : 'Pilih...';

            Array.from(select.options).forEach((opt, idx) => {
                // Jangan tampilkan opsi placeholder kosong / teks "Pilih ..." di dalam list item
                if (opt.disabled || opt.hidden) return;
                if (opt.value === '' && (select.required || opt.textContent.trim().toLowerCase().startsWith('pilih'))) {
                    return;
                }

                const item = document.createElement('div');
                item.className = 'cbt-select-option' + (idx === select.selectedIndex ? ' selected' : '');
                item.textContent = opt.textContent;
                item.dataset.value = opt.value;

                item.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (select.selectedIndex !== idx) {
                        select.selectedIndex = idx;
                        select.value = opt.value;
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    renderOptions();
                    document.querySelectorAll('.cbt-select-container.open').forEach(el => el.classList.remove('open'));
                });

                dropdown.appendChild(item);
            });
        };

        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const isOpen = wrapper.classList.contains('open');
            document.querySelectorAll('.cbt-select-container.open').forEach(el => {
                if (el !== wrapper) el.classList.remove('open');
            });
            if (!isOpen) {
                renderOptions();
                wrapper.classList.add('open');
            } else {
                wrapper.classList.remove('open');
            }
        });

        trigger.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
                e.preventDefault();
                renderOptions();
                wrapper.classList.add('open');
            } else if (e.key === 'Escape') {
                wrapper.classList.remove('open');
            }
        });

        select.addEventListener('change', () => {
            const opt = select.options[select.selectedIndex];
            if (opt) label.textContent = opt.textContent;
            renderOptions();
        });

        renderOptions();
    });
};

window.openModal = function(id) {
    const m = document.getElementById(id);
    if (m) {
        m.classList.add('active');
        window.initCustomSelects(m);
        const input = m.querySelector('input[type="text"]:not([readonly]), input[type="password"]');
        if (input) setTimeout(() => input.focus(), 100);
    }
};

window.closeModal = function(id) {
    const m = document.getElementById(id);
    if (m) {
        m.classList.remove('active');
        document.querySelectorAll('.cbt-select-container.open').forEach(el => el.classList.remove('open'));
    }
};

/**
 * Modern Custom Confirmation Modal
 * @param {Object} options
 * @returns {Promise<boolean>}
 */
window.cbtConfirm = function(options = {}) {
    const title = options.title || 'Konfirmasi Tindakan';
    const message = options.message || 'Apakah Anda yakin ingin melanjutkan tindakan ini?';
    const type = options.type || 'danger'; // 'danger', 'warning', 'info'
    const confirmText = options.confirmText || (type === 'danger' ? 'Ya, Hapus' : 'Ya, Lanjutkan');
    const cancelText = options.cancelText || 'Batal';

    return new Promise((resolve) => {
        let modal = document.getElementById('cbt-global-confirm-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'cbt-global-confirm-modal';
            modal.className = 'modal-overlay';
            modal.innerHTML = `
                <div class="modal-box" style="max-width: 440px; text-align: center; border-radius: 12px; padding: 1.5rem; box-shadow: var(--shadow-lg);">
                    <div id="cbt-confirm-icon-box" style="width: 52px; height: 52px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem;">
                        <svg id="cbt-confirm-icon" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"></svg>
                    </div>
                    <h3 id="cbt-confirm-title" style="font-size: 1.15rem; font-weight: 800; color: var(--gray-900); margin: 0 0 0.5rem;"></h3>
                    <p id="cbt-confirm-message" style="font-size: 0.9rem; color: var(--gray-600); margin: 0 0 1.25rem; line-height: 1.5;"></p>
                    <div style="display: flex; gap: 0.65rem; justify-content: center;">
                        <button type="button" id="cbt-confirm-btn-cancel" class="btn btn-outline" style="min-width: 100px; font-weight: 600;"></button>
                        <button type="button" id="cbt-confirm-btn-ok" class="btn" style="min-width: 130px; font-weight: 700;"></button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        const iconBox = document.getElementById('cbt-confirm-icon-box');
        const iconSvg = document.getElementById('cbt-confirm-icon');
        const titleEl = document.getElementById('cbt-confirm-title');
        const msgEl = document.getElementById('cbt-confirm-message');
        const btnOk = document.getElementById('cbt-confirm-btn-ok');
        const btnCancel = document.getElementById('cbt-confirm-btn-cancel');

        titleEl.textContent = title;
        msgEl.textContent = message;
        btnCancel.textContent = cancelText;
        btnOk.textContent = confirmText;

        btnOk.style.background = '';
        btnOk.style.borderColor = '';

        if (type === 'danger') {
            iconBox.style.background = '#fee2e2';
            iconBox.style.color = '#dc2626';
            iconSvg.innerHTML = '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>';
            btnOk.className = 'btn btn-danger';
        } else if (type === 'warning') {
            iconBox.style.background = '#fef3c7';
            iconBox.style.color = '#d97706';
            iconSvg.innerHTML = '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>';
            btnOk.className = 'btn btn-primary';
            btnOk.style.background = '#d97706';
            btnOk.style.borderColor = '#d97706';
        } else {
            iconBox.style.background = '#eff6ff';
            iconBox.style.color = '#2563eb';
            iconSvg.innerHTML = '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line>';
            btnOk.className = 'btn btn-primary';
        }

        modal.classList.add('active');

        const cleanUp = (result) => {
            modal.classList.remove('active');
            btnOk.onclick = null;
            btnCancel.onclick = null;
            resolve(result);
        };

        btnOk.onclick = () => cleanUp(true);
        btnCancel.onclick = () => cleanUp(false);
    });
};

document.addEventListener('DOMContentLoaded', function() {
    // Initialize modern custom select dropdowns across the page
    window.initCustomSelects();

    // Intercept form submissions with data-confirm
    document.addEventListener('submit', function(e) {
        const form = e.target;
        const confirmMsg = form.dataset.confirm;
        if (confirmMsg && !form.dataset.confirmed) {
            e.preventDefault();
            const confirmTitle = form.dataset.confirmTitle || 'Konfirmasi Tindakan';
            const confirmType = form.dataset.confirmType || 'danger';
            const confirmBtn = form.dataset.confirmBtn || (confirmType === 'danger' ? 'Ya, Hapus' : 'Ya, Lanjutkan');
            
            cbtConfirm({
                title: confirmTitle,
                message: confirmMsg,
                type: confirmType,
                confirmText: confirmBtn
            }).then(ok => {
                if (ok) {
                    form.dataset.confirmed = 'true';
                    form.submit();
                }
            });
        }
    });

    // Click outside to close nav menu, modal & custom select dropdowns
    document.addEventListener('click', function(e) {
        const navMenu = document.getElementById('cbt-nav-menu');
        const toggleBtn = document.querySelector('.cbt-menu-toggle');
        if (navMenu && navMenu.classList.contains('open')) {
            if (!navMenu.contains(e.target) && (!toggleBtn || !toggleBtn.contains(e.target))) {
                navMenu.classList.remove('open');
            }
        }

        // Close custom select dropdowns when clicking outside
        if (!e.target.closest('.cbt-select-container')) {
            document.querySelectorAll('.cbt-select-container.open').forEach(el => el.classList.remove('open'));
        }

        // Close modal when clicking backdrop (except if global confirm)
        if (e.target.classList.contains('modal-overlay') && e.target.id !== 'cbt-global-confirm-modal') {
            e.target.classList.remove('active');
        }
    });

    // Close when Esc key pressed
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const navMenu = document.getElementById('cbt-nav-menu');
            if (navMenu && navMenu.classList.contains('open')) {
                navMenu.classList.remove('open');
            }
            document.querySelectorAll('.cbt-select-container.open').forEach(el => el.classList.remove('open'));
            const activeModal = document.querySelector('.modal-overlay.active');
            if (activeModal) {
                activeModal.classList.remove('active');
            }
        }
    });

    // Global Live Countdown Timers (.countdown-timer)
    const timers = document.querySelectorAll('.countdown-timer');
    if (timers.length > 0) {
        timers.forEach(t => {
            let sec = parseInt(t.getAttribute('data-seconds'), 10) || 0;
            const updateDisplay = () => {
                if (sec <= 0) {
                    t.textContent = '00:00:00';
                    return;
                }
                const h = String(Math.floor(sec / 3600)).padStart(2, '0');
                const m = String(Math.floor((sec % 3600) / 60)).padStart(2, '0');
                const s = String(sec % 60).padStart(2, '0');
                t.textContent = `${h}:${m}:${s}`;
            };
            updateDisplay();
            const interval = setInterval(() => {
                sec--;
                if (sec <= 0) {
                    sec = 0;
                    updateDisplay();
                    clearInterval(interval);
                } else {
                    updateDisplay();
                }
            }, 1000);
        });
    }
});

// Global Stacking Toast Notification Function
window.cbtToast = function(message, type = 'success', duration = 4000) {
    let container = document.getElementById('cbt-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'cbt-toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `cbt-toast cbt-toast-${type}`;

    let iconSvg = '';
    if (type === 'success') {
        iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" style="flex-shrink:0;"><polyline points="20 6 9 17 4 12"></polyline></svg>`;
    } else if (type === 'danger' || type === 'error') {
        iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>`;
    } else if (type === 'warning') {
        iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.5" style="flex-shrink:0;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>`;
    } else {
        iconSvg = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.5" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>`;
    }

    toast.innerHTML = `
        <div class="cbt-toast-body">
            ${iconSvg}
            <span class="cbt-toast-msg">${message}</span>
        </div>
        <button type="button" class="cbt-toast-close" aria-label="Tutup Notifikasi">&times;</button>
    `;

    const closeToast = () => {
        if (toast.classList.contains('toast-hiding')) return;
        toast.classList.add('toast-hiding');
        setTimeout(() => {
            if (toast.parentElement) toast.remove();
        }, 320);
    };

    toast.querySelector('.cbt-toast-close').addEventListener('click', closeToast);

    container.appendChild(toast);

    if (duration > 0) {
        setTimeout(closeToast, duration);
    }
};

// Responsive Mobile Table Expand / Collapse Controller
window.initMobileTableExtend = function(root = document) {
    const tables = root.querySelectorAll('.table-mobile-cards table');
    tables.forEach(table => {
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            if (row.querySelector('.cbt-mobile-extend-btn')) return;

            const cells = Array.from(row.querySelectorAll('td'));
            if (cells.length <= 1) return;

            // Cari cell utama (Nama / Judul / Ujian / Paket)
            let primaryCell = cells.find(c => {
                const label = (c.getAttribute('data-label') || '').toLowerCase();
                return label.includes('nama') || label.includes('ujian') || label.includes('judul') || label.includes('paket');
            }) || cells.find(c => {
                const label = (c.getAttribute('data-label') || '').toLowerCase();
                return label !== 'no' && label !== 'aksi' && label !== '';
            }) || cells[0];

            // Tandai kelas cell untuk styling mobile
            cells.forEach(c => {
                const label = (c.getAttribute('data-label') || '').toLowerCase();
                if (c === primaryCell) {
                    c.classList.add('mobile-primary-cell');
                } else if (label === 'no') {
                    c.classList.add('mobile-no-cell');
                } else {
                    c.classList.add('mobile-detail-cell');
                }
            });

            // Buat Tombol Icon Extend di bagian kanan header kartu
            const extendBtn = document.createElement('button');
            extendBtn.type = 'button';
            extendBtn.className = 'cbt-mobile-extend-btn';
            extendBtn.setAttribute('aria-label', 'Toggle Detail');
            extendBtn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg>`;

            extendBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                row.classList.toggle('expanded');
            });

            // Klik pada nama / header baris juga bisa untuk membuka/menutup detail
            if (primaryCell) {
                primaryCell.appendChild(extendBtn);
                primaryCell.addEventListener('click', (e) => {
                    if (window.innerWidth <= 768) {
                        if (e.target.closest('a, button, input, select, form')) return;
                        row.classList.toggle('expanded');
                    }
                });
            } else {
                row.appendChild(extendBtn);
            }
        });
    });
};

// Automatic conversion of server-side PHP flash messages into floating stacked toasts
document.addEventListener('DOMContentLoaded', () => {
    window.initCustomSelects();
    window.initMobileTableExtend();

    document.querySelectorAll('.alert:not(.alert-inline)').forEach(alert => {
        if (!alert.closest('.modal-box') && !alert.closest('.soal-box')) {
            let type = 'info';
            if (alert.classList.contains('alert-success')) type = 'success';
            else if (alert.classList.contains('alert-danger')) type = 'danger';
            else if (alert.classList.contains('alert-warning')) type = 'warning';

            const msg = alert.innerHTML.trim();
            // Remove the static DOM alert
            alert.remove();
            // Trigger floating stacked toast
            window.cbtToast(msg, type);
        }
    });
});

