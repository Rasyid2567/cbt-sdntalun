/**
 * CBT Server Alert Live Listener & Modal Broadcaster
 * SDN TALUN - CBT System
 *
 * Mendengarkan pesan broadcast pop-up dari Terminal/CLI Server Admin
 * secara real-time tanpa me-refresh halaman atau mengganggu ujian.
 */

(function () {
    'use strict';

    // Mencegah inisialisasi ganda
    if (window.CBTServerAlertInitialized) return;
    window.CBTServerAlertInitialized = true;

    // Tentukan URL endpoint API
    const getApiUrl = () => {
        const scripts = document.getElementsByTagName('script');
        for (let i = 0; i < scripts.length; i++) {
            const src = scripts[i].src || '';
            if (src.includes('server-alert.js') || src.includes('app.js')) {
                const idx = src.indexOf('/assets/');
                if (idx !== -1) {
                    return src.substring(0, idx) + '/api/server_alert.php';
                }
            }
        }
        return window.location.origin + '/api/server_alert.php';
    };

    const STORAGE_KEY = 'cbt_last_server_alert_id';
    let lastSeenId = parseInt(localStorage.getItem(STORAGE_KEY) || '0', 10);
    if (isNaN(lastSeenId)) lastSeenId = 0;

    let isFetching = false;
    let pollInterval = null;
    let isModalOpen = false;

    /**
     * Memainkan nada pemberitahuan lembut menggunakan Web Audio API
     */
    function playChime() {
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            const ctx = new AudioContext();

            const playTone = (freq, start, duration) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(freq, ctx.currentTime + start);

                gain.gain.setValueAtTime(0.001, ctx.currentTime + start);
                gain.gain.exponentialRampToValueAtTime(0.18, ctx.currentTime + start + 0.04);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + start + duration);

                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(ctx.currentTime + start);
                osc.stop(ctx.currentTime + start + duration);
            };

            playTone(659.25, 0, 0.28);     // E5
            playTone(880.00, 0.15, 0.45);   // A5
        } catch (e) {
            // Audio context diblokir oleh browser autoplay policy jika belum ada interaksi
        }
    }

    /**
     * Hentikan polling background
     */
    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    /**
     * Jalankan polling background
     */
    function startPolling(intervalMs = 4000) {
        stopPolling();
        if (isModalOpen) return; // Jangan mulai polling jika modal sedang terbuka
        pollInterval = setInterval(checkServerAlert, intervalMs);
    }

    /**
     * Menampilkan Modal Pop-up Alert Server
     */
    function showAlertModal(alert) {
        // Jika modal sedang terbuka menampilkan alert ini, jangan lakukan apa-apa
        if (isModalOpen) return;

        // 1. HENTIKAN POLLING SEPENUHNYA saat pop-up tampil di layar
        // Ini menjamin 100% pop-up TIDAK AKAN PERNAH berkedip / me-render ulang sendiri!
        stopPolling();
        isModalOpen = true;

        // 2. Simpan ID ke memori dan localStorage saat itu juga
        lastSeenId = Math.max(lastSeenId, parseInt(alert.id, 10) || 0);
        try {
            localStorage.setItem(STORAGE_KEY, String(lastSeenId));
        } catch (e) {}

        let overlay = document.getElementById('cbt-server-alert-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'cbt-server-alert-overlay';
            overlay.className = 'cbt-server-alert-overlay';
            document.body.appendChild(overlay);
        }

        const escapeHtml = (str) => {
            const div = document.createElement('div');
            div.textContent = str || '';
            return div.innerHTML;
        };

        const safeJudul = escapeHtml(alert.judul || 'Pemberitahuan Admin Server');
        const safePesan = escapeHtml(alert.pesan || '');
        const safeWaktu = escapeHtml(alert.waktu ? alert.waktu + ' WIB' : '');
        const safeTanggal = escapeHtml(alert.tanggal || '');

        overlay.innerHTML = `
            <div class="cbt-server-alert-box" role="dialog" aria-modal="true">
                <div class="cbt-server-alert-badge">
                    <span class="cbt-server-alert-pulse"></span>
                    <span>Admin Server • Broadcast Langsung</span>
                </div>
                
                <h3 class="cbt-server-alert-title">${safeJudul}</h3>
                
                <div class="cbt-server-alert-body">${safePesan}</div>
                
                <div class="cbt-server-alert-meta">
                    <span>${safeTanggal ? 'Tanggal: ' + safeTanggal : ''}</span>
                    <span>${safeWaktu ? 'Pukul: ' + safeWaktu : ''}</span>
                </div>
                
                <button type="button" id="cbt-server-alert-dismiss" class="cbt-server-alert-btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    <span>Saya Mengerti / Tutup</span>
                </button>
            </div>
        `;

        overlay.classList.add('active');
        playChime();

        const closeAlert = () => {
            overlay.classList.remove('active');
            isModalOpen = false;
            // Lanjutkan polling kembali setelah alert ditutup
            setTimeout(() => {
                startPolling(4000);
            }, 1000);
        };

        const dismissBtn = overlay.querySelector('#cbt-server-alert-dismiss');
        if (dismissBtn) {
            dismissBtn.onclick = closeAlert;
            dismissBtn.focus();
        }

        // Tangkap tombol Enter atau Esc
        const keyHandler = (e) => {
            if (isModalOpen && (e.key === 'Escape' || e.key === 'Enter')) {
                document.removeEventListener('keydown', keyHandler);
                closeAlert();
            }
        };
        document.addEventListener('keydown', keyHandler);
    }

    /**
     * Melakukan polling ke server untuk mengecek alert baru
     */
    async function checkServerAlert() {
        if (isFetching || isModalOpen) return;
        isFetching = true;

        try {
            const url = `${getApiUrl()}?last_id=${encodeURIComponent(lastSeenId)}&_t=${Date.now()}`;
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                cache: 'no-store'
            });

            if (!response.ok) {
                isFetching = false;
                return;
            }

            const data = await response.json();
            if (data && data.has_alert && data.alert) {
                showAlertModal(data.alert);
            }
        } catch (err) {
            // Abaikan kesalahan jaringan sementara
        } finally {
            isFetching = false;
        }
    }

    // Manajemen visibilitas tab
    document.addEventListener('visibilitychange', () => {
        if (isModalOpen) return;

        if (document.hidden) {
            startPolling(10000); // 10 detik saat tab di background
        } else {
            checkServerAlert();
            startPolling(4000); // 4 detik saat tab aktif
        }
    });

    // Mulai inisialisasi polling saat halaman selesai dimuat
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            startPolling(4000);
            setTimeout(checkServerAlert, 1000);
        });
    } else {
        startPolling(4000);
        setTimeout(checkServerAlert, 1000);
    }
})();
