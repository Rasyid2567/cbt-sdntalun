<?php
/**
 * Shared Modal Tambah Waktu Sesi Ujian (Custom Input)
 * CBT System - SDN Talun
 */
?>
<!-- Modal Tambah Waktu Sesi Ujian (Murni Custom Input) -->
<div id="modal-tambah-waktu" class="modal-overlay">
    <div class="modal-box" style="max-width: 460px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);">
        <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 1rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.85rem;">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <div style="background: #fef3c7; color: #d97706; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: inset 0 0 0 1px #fde68a;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                        <line x1="12" y1="2" x2="12" y2="4"></line>
                        <line x1="12" y1="20" x2="12" y2="22"></line>
                    </svg>
                </div>
                <div>
                    <h2 class="card-title" style="margin: 0; font-size: 1.15rem; font-weight: 800; color: #0f172a;">Tambah Waktu Ujian</h2>
                    <p class="text-xs text-muted" id="tambah_waktu_subtitle" style="margin: 0.15rem 0 0 0; font-weight: 500;">Sesi: -</p>
                </div>
            </div>
            <button type="button" class="btn-close" onclick="closeModal('modal-tambah-waktu')" aria-label="Tutup" style="background: none; border: none; font-size: 1.35rem; line-height: 1; cursor: pointer; color: #94a3b8; padding: 0.2rem 0.4rem; border-radius: 4px;">✕</button>
        </div>

        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.85rem 1rem; margin-bottom: 1.25rem; font-size: 0.85rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.45rem;">
                <span style="color: #64748b;">Durasi Terdaftar Saat Ini:</span>
                <strong style="color: #1e293b; font-size: 0.95rem;"><span id="tw_durasi_saat_ini">60</span> Menit</strong>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span style="color: #64748b;">Sisa Waktu Sesi Aktif:</span>
                <span id="tw_badge_sisa" class="badge" style="background: #eff6ff; color: #1d4ed8; font-family: monospace; font-size: 0.92rem; font-weight: 700; padding: 0.25rem 0.55rem; border: 1px solid #bfdbfe;">
                    <span id="tw_sisa_waktu_saat_ini">--:--:--</span>
                </span>
            </div>
        </div>

        <form action="<?= $modalFormAction ?? '' ?>" method="POST" id="form-tambah-waktu">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="tambah_waktu">
            <input type="hidden" name="id_sesi" id="tambah_waktu_id_sesi" value="">

            <div class="form-group mb-3">
                <label for="input_tambah_menit" style="font-weight: 700; font-size: 0.92rem; color: #1e293b; display: flex; align-items: center; justify-content: space-between;">
                    <span>Tambahan Waktu (Menit) <span class="text-danger">*</span></span>
                    <span style="font-size: 0.78rem; font-weight: 500; color: #64748b;">Custom / Bebas</span>
                </label>
                <div style="position: relative; display: flex; align-items: center; margin-top: 0.35rem;">
                    <input type="number" name="tambah_menit" id="input_tambah_menit" class="form-control" min="1" max="300" placeholder="Contoh: 15" required style="font-size: 1.35rem; font-weight: 800; font-family: monospace; padding-right: 4.5rem; letter-spacing: 0.5px; height: 48px;" oninput="updatePreviewTambahWaktu()">
                    <span style="position: absolute; right: 14px; font-weight: 700; font-size: 0.82rem; color: #64748b; pointer-events: none; letter-spacing: 0.5px;">MENIT</span>
                </div>
                <div class="text-xs text-muted" style="margin-top: 0.4rem; line-height: 1.4;">
                    Ketikkan durasi menit tambahan secara bebas (misal: 10, 15, 25, 45).
                </div>
            </div>

            <div id="box-preview-waktu" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 0.85rem 1rem; margin-bottom: 1.25rem; font-size: 0.84rem; color: #166534; display: flex; align-items: flex-start; gap: 0.65rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="flex-shrink: 0; margin-top: 2px;">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                <div style="line-height: 1.45;">
                    <div>Tambahan waktu: <strong>+<span id="tw_preview_tambah">0</span> Menit</strong></div>
                    <div style="font-size: 0.79rem; color: #15803d; margin-top: 0.2rem;">
                        Durasi total sesi akan menjadi <strong><span id="tw_preview_total">60</span> Menit</strong> dan langsung berlaku ke seluruh peserta secara real-time.
                    </div>
                </div>
            </div>

            <div class="flex gap-2" style="justify-content: flex-end; align-items: center; border-top: 1px solid #f1f5f9; padding-top: 0.85rem;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modal-tambah-waktu')" style="padding: 0.5rem 1rem;">Batal</button>
                <button type="submit" class="btn btn-warning font-bold" style="display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1.25rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span>Simpan & Tambah Waktu</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let currentTwDurasi = 60;
let currentTwSisaDetik = 0;

function openModalTambahWaktu(idSesi, namaUjian, durasiMenit, sisaDetik) {
    const idInput = document.getElementById('tambah_waktu_id_sesi');
    if (!idInput) return;
    idInput.value = idSesi;

    const sub = document.getElementById('tambah_waktu_subtitle');
    if (sub) sub.textContent = 'Sesi: ' + (namaUjian || '-');

    currentTwDurasi = parseInt(durasiMenit, 10) || 60;
    currentTwSisaDetik = parseInt(sisaDetik, 10) || 0;

    const durEl = document.getElementById('tw_durasi_saat_ini');
    if (durEl) durEl.textContent = currentTwDurasi;

    const sisaEl = document.getElementById('tw_sisa_waktu_saat_ini');
    const badgeEl = document.getElementById('tw_badge_sisa');
    if (sisaEl) {
        if (currentTwSisaDetik > 0) {
            const h = Math.floor(currentTwSisaDetik / 3600);
            const m = Math.floor((currentTwSisaDetik % 3600) / 60);
            const s = currentTwSisaDetik % 60;
            sisaEl.textContent = [
                String(h).padStart(2, '0'),
                String(m).padStart(2, '0'),
                String(s).padStart(2, '0')
            ].join(':');
            if (badgeEl) {
                badgeEl.style.backgroundColor = '#eff6ff';
                badgeEl.style.color = '#1d4ed8';
                badgeEl.style.borderColor = '#bfdbfe';
            }
        } else {
            sisaEl.textContent = 'Waktu Habis';
            if (badgeEl) {
                badgeEl.style.backgroundColor = '#fee2e2';
                badgeEl.style.color = '#b91c1c';
                badgeEl.style.borderColor = '#fca5a5';
            }
        }
    }

    const inp = document.getElementById('input_tambah_menit');
    if (inp) {
        inp.value = '';
    }
    updatePreviewTambahWaktu();

    if (typeof openModal === 'function') {
        openModal('modal-tambah-waktu');
    } else {
        const m = document.getElementById('modal-tambah-waktu');
        if (m) m.classList.add('active');
    }

    setTimeout(() => {
        if (inp) {
            inp.focus();
        }
    }, 150);
}

function updatePreviewTambahWaktu() {
    const inp = document.getElementById('input_tambah_menit');
    const tambah = inp ? (parseInt(inp.value, 10) || 0) : 0;

    const prevTambah = document.getElementById('tw_preview_tambah');
    if (prevTambah) prevTambah.textContent = tambah;

    const prevTotal = document.getElementById('tw_preview_total');
    if (prevTotal) prevTotal.textContent = (currentTwDurasi + tambah);
}
</script>
