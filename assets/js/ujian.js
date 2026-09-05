/**
 * ============================================================================
 * CBT SYSTEM - CORE INTERAKTIF UJIAN (AJAX AUTO-SAVE, GRID & NAVIGASI)
 * ============================================================================
 * Mengatur interaksi 1 soal per layar, auto-save realtime via Fetch API,
 * penanganan offline retry, serta navigasi grid nomor soal UNBK.
 */

class CBTExamManager {
    /**
     * @param {Object} config
     */
    constructor(config) {
        this.idUjianSiswa = config.idUjianSiswa;
        this.csrfToken    = config.csrfToken;
        this.saveUrl      = config.saveUrl;
        this.raguUrl      = config.raguUrl;
        this.finishUrl    = config.finishUrl;
        this.soalData     = config.soalData || [];
        this.timerInstance = config.timerInstance || null;
        
        this.currentIndex = 0;
        this.retryQueue   = [];
        this.isSaving     = false;
        this.debounceTimer = null;

        // Elemen DOM
        this.dom = {
            nomorBadge:    document.getElementById('soal-nomor-badge'),
            tipeBadge:     document.getElementById('soal-tipe-badge'),
            pertanyaanBox: document.getElementById('soal-pertanyaan'),
            imageBox:      document.getElementById('soal-image-container'),
            soalImg:       document.getElementById('soal-img-tag'),
            opsiList:      document.getElementById('opsi-list-container'),
            btnPrev:       document.getElementById('btn-prev'),
            btnNext:       document.getElementById('btn-next'),
            btnFinish:     document.getElementById('btn-finish-modal'),
            chkRagu:       document.getElementById('chk-ragu'),
            statusSync:    document.getElementById('status-sync'),
            gridContainer: document.getElementById('soal-grid-container'),
            modalSelesai:  document.getElementById('modal-selesai'),
            modalRingkasan:document.getElementById('modal-ringkasan-soal'),
            btnBatalModal: document.getElementById('btn-batal-modal'),
            btnSubmitAkhir:document.getElementById('btn-submit-akhir'),
            formSelesai:   document.getElementById('form-selesai-ujian')
        };

        this.init();
    }

    /**
     * Inisialisasi Event Listener dan Render Awal
     */
    init() {
        if (!this.soalData || this.soalData.length === 0) {
            console.error('Data soal tidak ditemukan.');
            return;
        }

        // Render Grid Nomor
        this.renderGrid();

        // Render Soal Pertama
        this.renderSoal(0);

        // Event Navigasi
        if (this.dom.btnPrev) {
            this.dom.btnPrev.addEventListener('click', () => {
                this.flushEssaiSave();
                if (this.currentIndex > 0) {
                    this.renderSoal(this.currentIndex - 1);
                }
            });
        }

        if (this.dom.btnNext) {
            this.dom.btnNext.addEventListener('click', () => {
                this.flushEssaiSave();
                if (this.currentIndex < this.soalData.length - 1) {
                    this.renderSoal(this.currentIndex + 1);
                }
            });
        }

        // Event Ragu-Ragu
        if (this.dom.chkRagu) {
            this.dom.chkRagu.addEventListener('change', (e) => {
                this.handleRaguChange(e.target.checked);
            });
        }

        // Event Modal Selesai
        if (this.dom.btnFinish) {
            this.dom.btnFinish.addEventListener('click', () => {
                this.flushEssaiSave();
                this.showFinishModal();
            });
        }

        if (this.dom.btnBatalModal) {
            this.dom.btnBatalModal.addEventListener('click', () => {
                this.hideFinishModal();
            });
        }

        // Listener jika koneksi internet kembali online
        window.addEventListener('online', () => {
            this.processRetryQueue();
        });
    }

    /**
     * Render Soal Berdasarkan Index
     * @param {number} index 
     */
    renderSoal(index) {
        if (index < 0 || index >= this.soalData.length) return;
        this.flushEssaiSave();
        this.currentIndex = index;

        const currentSoal = this.soalData[index];

        // 1. Update Nomor Badge & Tipe Soal
        if (this.dom.nomorBadge) {
            this.dom.nomorBadge.textContent = `SOAL NO. ${index + 1} DARI ${this.soalData.length}`;
        }
        if (this.dom.tipeBadge) {
            if (currentSoal.jenis_soal === 'essai') {
                this.dom.tipeBadge.textContent = 'Essai / Uraian';
                this.dom.tipeBadge.style.display = 'inline-block';
                this.dom.tipeBadge.style.background = '#ede9fe';
                this.dom.tipeBadge.style.color = '#6d28d9';
                this.dom.tipeBadge.style.border = '1px solid #ddd6fe';
            } else if (currentSoal.is_kompleks) {
                this.dom.tipeBadge.textContent = 'Pilihan Ganda Kompleks (Bisa pilih lebih dari 1)';
                this.dom.tipeBadge.style.display = 'inline-block';
                this.dom.tipeBadge.style.background = '#e0e7ff';
                this.dom.tipeBadge.style.color = '#3730a3';
                this.dom.tipeBadge.style.border = '1px solid #c7d2fe';
            } else {
                this.dom.tipeBadge.textContent = 'Pilihan Ganda';
                this.dom.tipeBadge.style.display = 'inline-block';
                this.dom.tipeBadge.style.background = '#e0f2fe';
                this.dom.tipeBadge.style.color = '#0369a1';
                this.dom.tipeBadge.style.border = '1px solid #bae6fd';
            }
        }

        // 2. Pertanyaan
        if (this.dom.pertanyaanBox) {
            this.dom.pertanyaanBox.innerHTML = currentSoal.pertanyaan;
        }

        // 3. Gambar (jika ada)
        if (this.dom.imageBox && this.dom.soalImg) {
            if (currentSoal.gambar && currentSoal.gambar.trim() !== '') {
                this.dom.soalImg.src = currentSoal.gambar;
                this.dom.imageBox.style.display = 'block';
            } else {
                this.dom.imageBox.style.display = 'none';
                this.dom.soalImg.src = '';
            }
        }

        // 4. Render Opsi Pilihan Ganda atau Form Essai
        if (this.dom.opsiList) {
            this.dom.opsiList.innerHTML = '';

            if (currentSoal.jenis_soal === 'essai') {
                const textarea = document.createElement('textarea');
                textarea.id = 'cbt-textarea-essai';
                textarea.className = 'cbt-essai-textarea';
                textarea.rows = 7;
                textarea.placeholder = 'Tuliskan jawaban uraian Anda di sini...';
                textarea.value = currentSoal.jawaban_terpilih || '';

                this.dom.opsiList.appendChild(textarea);

                textarea.addEventListener('input', (e) => {
                    const val = e.target.value;
                    currentSoal.jawaban_terpilih = val;
                    this.setSyncStatus('saving', 'Menyimpan...');
                    this.updateGridClasses();

                    if (this.debounceTimer) clearTimeout(this.debounceTimer);
                    this.debounceTimer = setTimeout(() => {
                        this.debounceTimer = null;
                        this.saveJawabanToServer(currentSoal.id_soal, val);
                    }, 500);
                });

                textarea.addEventListener('blur', (e) => {
                    if (this.debounceTimer) {
                        clearTimeout(this.debounceTimer);
                        this.debounceTimer = null;
                        this.saveJawabanToServer(currentSoal.id_soal, e.target.value);
                    }
                });
            } else {
                // Pilihan Ganda (Single atau Kompleks / Multi-Select)
                const isKompleks = Boolean(currentSoal.is_kompleks);
                const selectedCodes = (currentSoal.jawaban_terpilih || '')
                    .split(',')
                    .map(s => s.trim().toUpperCase())
                    .filter(Boolean);

                currentSoal.opsi.forEach(opt => {
                    if (!opt.text || opt.text.trim() === '') return; // Lewati jika opsi E kosong

                    const isSelected = selectedCodes.includes(opt.code.toUpperCase());

                    const itemDiv = document.createElement('div');
                    itemDiv.className = `opsi-item ${isSelected ? 'selected' : ''} ${isKompleks ? 'kompleks' : ''}`;
                    itemDiv.dataset.code = opt.code;

                    if (isKompleks) {
                        itemDiv.innerHTML = `
                            <input type="checkbox" name="pilihan_jawaban" value="${opt.code}" class="opsi-checkbox" ${isSelected ? 'checked' : ''} style="pointer-events: none;">
                            <div class="opsi-code">${opt.code}.</div>
                            <div class="opsi-text">${opt.text}</div>
                        `;
                    } else {
                        itemDiv.innerHTML = `
                            <input type="radio" name="pilihan_jawaban" value="${opt.code}" class="opsi-radio" ${isSelected ? 'checked' : ''} style="pointer-events: none;">
                            <div class="opsi-code">${opt.code}.</div>
                            <div class="opsi-text">${opt.text}</div>
                        `;
                    }

                    itemDiv.addEventListener('click', () => {
                        this.handleSelectOpsi(opt.code);
                    });

                    this.dom.opsiList.appendChild(itemDiv);
                });
            }
        }

        // 5. Update Status Checkbox Ragu-ragu
        if (this.dom.chkRagu) {
            this.dom.chkRagu.checked = Boolean(currentSoal.status_ragu);
        }

        // 6. Update Tombol Prev / Next
        if (this.dom.btnPrev) {
            this.dom.btnPrev.disabled = (index === 0);
            this.dom.btnPrev.style.opacity = (index === 0) ? '0.5' : '1';
        }
        if (this.dom.btnNext) {
            if (index === this.soalData.length - 1) {
                this.dom.btnNext.style.display = 'none';
            } else {
                this.dom.btnNext.style.display = 'inline-flex';
            }
        }

        // 7. Update Warna Grid Nomor
        this.updateGridClasses();
    }

    /**
     * Memilih Opsi Jawaban (Mendukung Single Radio dan Pilihan Ganda Kompleks)
     * @param {string} code 
     */
    handleSelectOpsi(code) {
        const currentSoal = this.soalData[this.currentIndex];
        if (!currentSoal) return;

        if (currentSoal.is_kompleks) {
            // Multi-select toggle untuk Pilihan Ganda Kompleks
            let currentArray = (currentSoal.jawaban_terpilih || '')
                .split(',')
                .map(s => s.trim().toUpperCase())
                .filter(Boolean);

            const targetCode = code.toUpperCase();
            const idx = currentArray.indexOf(targetCode);
            if (idx > -1) {
                // Hapus jika sudah dipilih
                currentArray.splice(idx, 1);
            } else {
                // Tambahkan jika belum dipilih
                currentArray.push(targetCode);
            }

            // Urutkan abjad A, B, C, D, E
            currentArray.sort();
            const joined = currentArray.join(',');
            currentSoal.jawaban_terpilih = joined;

            // Update UI Opsi Checkbox
            const allItems = this.dom.opsiList.querySelectorAll('.opsi-item');
            allItems.forEach(el => {
                const isMatch = currentArray.includes(el.dataset.code);
                el.classList.toggle('selected', isMatch);
                const chk = el.querySelector('.opsi-checkbox');
                if (chk) chk.checked = isMatch;
            });

            // Update Grid
            this.updateGridClasses();

            // Kirim ke server via AJAX
            this.saveJawabanToServer(currentSoal.id_soal, joined);
        } else {
            // Single Radio Pilihan Ganda Biasa
            currentSoal.jawaban_terpilih = code;

            // Update UI Opsi Radio
            const allItems = this.dom.opsiList.querySelectorAll('.opsi-item');
            allItems.forEach(el => {
                const isMatch = (el.dataset.code === code);
                el.classList.toggle('selected', isMatch);
                const radio = el.querySelector('.opsi-radio');
                if (radio) radio.checked = isMatch;
            });

            // Update Grid
            this.updateGridClasses();

            // Kirim ke server via AJAX
            this.saveJawabanToServer(currentSoal.id_soal, code);
        }
    }

    /**
     * Menangani Status Ragu-Ragu
     * @param {boolean} isRagu 
     */
    handleRaguChange(isRagu) {
        const currentSoal = this.soalData[this.currentIndex];
        if (!currentSoal) return;

        currentSoal.status_ragu = isRagu ? 1 : 0;

        // Update Grid
        this.updateGridClasses();

        // Kirim status ragu ke server via AJAX
        this.saveRaguToServer(currentSoal.id_soal, isRagu);
    }

    /**
     * Mengirim Jawaban ke Server via Fetch API
     * @param {number} idSoal 
     * @param {string} jawaban 
     */
    saveJawabanToServer(idSoal, jawaban) {
        this.setSyncStatus('saving', 'Menyimpan...');

        const payload = {
            id_ujian_siswa:   this.idUjianSiswa,
            id_soal:          idSoal,
            jawaban:          jawaban,
            jawaban_terpilih: jawaban,
            sisa_detik:       (this.timerInstance && typeof this.timerInstance.remainingSeconds === 'number') ? this.timerInstance.remainingSeconds : null,
            csrf_token:       this.csrfToken
        };

        fetch(this.saveUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        })
        .then(response => {
            if (!response.ok) throw new Error('HTTP Error: ' + response.status);
            return response.json();
        })
        .then(data => {
            if (data.success || data.status === 'success') {
                this.setSyncStatus('saved', 'Jawaban tersimpan');
            } else {
                throw new Error(data.message || 'Gagal menyimpan');
            }
        })
        .catch(err => {
            console.warn('Gagal sync jawaban, masuk antrean retry:', err);
            this.setSyncStatus('offline', 'Tersimpan lokal (offline)');
            this.retryQueue.push({ type: 'save', payload: payload });
        });
    }

    /**
     * Mengirim Status Ragu ke Server via Fetch API
     * @param {number} idSoal 
     * @param {boolean} isRagu 
     */
    saveRaguToServer(idSoal, isRagu) {
        this.setSyncStatus('saving', 'Menyimpan...');

        const payload = {
            id_ujian_siswa: this.idUjianSiswa,
            id_soal:        idSoal,
            status_ragu:    isRagu ? 1 : 0,
            csrf_token:     this.csrfToken
        };

        fetch(this.raguUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        })
        .then(response => {
            if (!response.ok) throw new Error('HTTP Error: ' + response.status);
            return response.json();
        })
        .then(data => {
            if (data.success || data.status === 'success') {
                this.setSyncStatus('saved', 'Status tersimpan');
            } else {
                throw new Error(data.message || 'Gagal menyimpan status ragu');
            }
        })
        .catch(err => {
            console.warn('Gagal sync status ragu, masuk antrean retry:', err);
            this.setSyncStatus('offline', 'Tersimpan lokal (offline)');
            this.retryQueue.push({ type: 'ragu', payload: payload });
        });
    }

    /**
     * Memproses antrean data yang tertunda karena gangguan koneksi
     */
    processRetryQueue() {
        if (this.retryQueue.length === 0) return;

        const item = this.retryQueue.shift();
        const url = (item.type === 'save') ? this.saveUrl : this.raguUrl;

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(item.payload)
        })
        .then(res => {
            if (!res.ok) throw new Error('HTTP Error: ' + res.status);
            return res.json();
        })
        .then(data => {
            if (data.success || data.status === 'success') {
                this.setSyncStatus('saved', 'Semua data tersinkronisasi');
                if (this.retryQueue.length > 0) {
                    this.processRetryQueue();
                }
            } else {
                throw new Error(data.message || 'Gagal sync retry');
            }
        })
        .catch(() => {
            this.retryQueue.unshift(item);
        });
    }

    /**
     * Mengubah teks dan status badge sinkronisasi
     */
    setSyncStatus(status, text) {
        if (!this.dom.statusSync) return;
        this.dom.statusSync.className = `sync-badge ${status}`;
        this.dom.statusSync.textContent = text;

        if (status === 'saved') {
            setTimeout(() => {
                if (this.dom.statusSync.textContent === 'Jawaban tersimpan') {
                    this.dom.statusSync.textContent = 'Tersinkronisasi';
                }
            }, 2500);
        }
    }

    /**
     * Render Grid Nomor Soal di Panel Kanan
     */
    renderGrid() {
        if (!this.dom.gridContainer) return;
        this.dom.gridContainer.innerHTML = '';

        this.soalData.forEach((soal, idx) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'soal-num-btn';
            btn.id = `btn-grid-${idx}`;
            btn.textContent = idx + 1;

            btn.addEventListener('click', () => {
                this.flushEssaiSave();
                this.renderSoal(idx);
            });

            this.dom.gridContainer.appendChild(btn);
        });

        this.updateGridClasses();
    }

    /**
     * Memperbarui Warna dan Indikator Status pada Grid Nomor Soal
     */
    updateGridClasses() {
        this.soalData.forEach((soal, idx) => {
            const btn = document.getElementById(`btn-grid-${idx}`);
            if (!btn) return;

            // Reset class
            btn.className = 'soal-num-btn';

            // Sedang aktif
            if (idx === this.currentIndex) {
                btn.classList.add('active');
            }

            // Status Ragu, Dijawab, atau Essai Belum Diisi
            if (soal.status_ragu) {
                btn.classList.add('ragu');
            } else if (soal.jawaban_terpilih && soal.jawaban_terpilih.trim() !== '') {
                btn.classList.add('dijawab');
            } else if (soal.jenis_soal === 'essai') {
                btn.classList.add('essai');
            }
        });
    }

    /**
     * Menyimpan draf jawaban essai jika ada timer debounce yang masih berjalan
     */
    flushEssaiSave() {
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
            this.debounceTimer = null;
            const currentSoal = this.soalData[this.currentIndex];
            if (currentSoal && currentSoal.jenis_soal === 'essai') {
                this.saveJawabanToServer(currentSoal.id_soal, currentSoal.jawaban_terpilih || '');
            }
        }
    }

    /**
     * Menampilkan Modal Konfirmasi Selesai Ujian
     */
    showFinishModal() {
        let pgTotal = 0;
        let pgDijawab = 0;
        let pgBelum = 0;
        let ragu = 0;
        let essaiTotal = 0;
        let essaiDijawab = 0;
        let essaiBelum = 0;

        this.soalData.forEach(soal => {
            if (soal.status_ragu) {
                ragu++;
            }
            if (soal.jenis_soal === 'essai') {
                essaiTotal++;
                if (soal.jawaban_terpilih && soal.jawaban_terpilih.trim() !== '') {
                    essaiDijawab++;
                } else {
                    essaiBelum++;
                }
            } else {
                pgTotal++;
                if (soal.jawaban_terpilih && soal.jawaban_terpilih.trim() !== '') {
                    pgDijawab++;
                } else {
                    pgBelum++;
                }
            }
        });

        if (this.dom.modalRingkasan) {
            this.dom.modalRingkasan.innerHTML = `
                <div style="background:#f8fafc; padding:1rem; border-radius:6px; margin:1rem 0; font-size:0.92rem;">
                    <div class="flex-between mb-2">
                        <span>Total Butir Soal:</span>
                        <strong>${this.soalData.length} Soal</strong>
                    </div>
                    ${pgTotal > 0 ? `
                    <div class="flex-between mb-2 text-success">
                        <span>Pilihan Ganda Terjawab:</span>
                        <strong>${pgDijawab} / ${pgTotal}</strong>
                    </div>
                    ${pgBelum > 0 ? `
                    <div class="flex-between mb-2 text-danger">
                        <span>Pilihan Ganda Belum Dijawab:</span>
                        <strong>${pgBelum}</strong>
                    </div>` : ''}
                    ` : ''}
                    ${essaiTotal > 0 ? `
                    <div class="flex-between mb-2" style="color: #6d28d9; border-top: 1px dashed var(--gray-300); padding-top: 0.5rem; margin-top: 0.5rem;">
                        <span>Soal Essai / Uraian Terjawab:</span>
                        <strong>${essaiDijawab} / ${essaiTotal}</strong>
                    </div>
                    ${essaiBelum > 0 ? `
                    <div class="flex-between mb-2 text-danger">
                        <span>Soal Essai Belum Diisi:</span>
                        <strong>${essaiBelum}</strong>
                    </div>` : ''}
                    ` : ''}
                    ${ragu > 0 ? `
                    <div class="flex-between mb-2 text-warning" style="border-top: 1px dashed var(--gray-300); padding-top: 0.5rem; margin-top: 0.5rem;">
                        <span>Masih Ragu-ragu:</span>
                        <strong>${ragu}</strong>
                    </div>` : ''}
                </div>
                ${(pgBelum > 0 || essaiBelum > 0 || ragu > 0) ? `
                    <div class="alert alert-warning text-sm">
                        <strong>Perhatian:</strong> Masih ada butir soal yang belum dijawab atau bertanda ragu-ragu. Pastikan seluruh jawaban telah Anda periksa sebelum mengakhiri sesi.
                    </div>
                ` : `
                    <div class="alert alert-success text-sm">
                        Seluruh butir soal telah dijawab. Silakan klik <strong>SELESAIKAN UJIAN</strong> untuk mengumpulkan lembar jawaban Anda.
                    </div>
                `}
            `;
        }

        if (this.dom.modalSelesai) {
            this.dom.modalSelesai.classList.add('active');
        }
    }

    /**
     * Menutup Modal Konfirmasi Selesai
     */
    hideFinishModal() {
        if (this.dom.modalSelesai) {
            this.dom.modalSelesai.classList.remove('active');
        }
    }

    /**
     * Finalisasi Submit Ujian (Bisa dipanggil oleh Timer secara otomatis)
     */
    submitUjianFinal() {
        if (this.dom.formSelesai) {
            this.dom.formSelesai.submit();
        }
    }
}

// Ekspor ke window
window.CBTExamManager = CBTExamManager;
