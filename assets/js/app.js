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

window.openModal = function(id) {
    const m = document.getElementById(id);
    if (m) {
        m.classList.add('active');
        const input = m.querySelector('input[type="text"]:not([readonly]), input[type="password"]');
        if (input) setTimeout(() => input.focus(), 100);
    }
};

window.closeModal = function(id) {
    const m = document.getElementById(id);
    if (m) m.classList.remove('active');
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

    // Click outside to close nav menu & modal
    document.addEventListener('click', function(e) {
        const navMenu = document.getElementById('cbt-nav-menu');
        const toggleBtn = document.querySelector('.cbt-menu-toggle');
        if (navMenu && navMenu.classList.contains('open')) {
            if (!navMenu.contains(e.target) && (!toggleBtn || !toggleBtn.contains(e.target))) {
                navMenu.classList.remove('open');
            }
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
