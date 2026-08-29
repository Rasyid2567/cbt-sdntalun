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

        // Elemen DOM
        this.dom = {
            nomorBadge:    document.getElementById('soal-nomor-badge'),
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
                if (this.currentIndex > 0) {
                    this.renderSoal(this.currentIndex - 1);
                }
            });
        }

        if (this.dom.btnNext) {
            this.dom.btnNext.addEventListener('click', () => {
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
        this.currentIndex = index;

        const currentSoal = this.soalData[index];

        // 1. Update Nomor Badge
        if (this.dom.nomorBadge) {
            this.dom.nomorBadge.textContent = `SOAL NO. ${index + 1} DARI ${this.soalData.length}`;
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

        // 4. Render Opsi Pilihan Ganda (A, B, C, D, E)
        if (this.dom.opsiList) {
            this.dom.opsiList.innerHTML = '';

            currentSoal.opsi.forEach(opt => {
                if (!opt.text || opt.text.trim() === '') return; // Lewati jika opsi E kosong

                const isSelected = (currentSoal.jawaban_terpilih === opt.code);

                const itemDiv = document.createElement('div');
                itemDiv.className = `opsi-item ${isSelected ? 'selected' : ''}`;
                itemDiv.dataset.code = opt.code;

                itemDiv.innerHTML = `
                    <input type="radio" name="pilihan_jawaban" value="${opt.code}" class="opsi-radio" ${isSelected ? 'checked' : ''}>
                    <div class="opsi-code">${opt.code}.</div>
                    <div class="opsi-text">${opt.text}</div>
                `;

                itemDiv.addEventListener('click', () => {
                    this.handleSelectOpsi(opt.code);
                });

                this.dom.opsiList.appendChild(itemDiv);
            });
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

        // 7. Update Highlight Grid
        this.updateGridClasses();
    }

    /**
     * Memilih Opsi Jawaban dan Menjalankan Auto-Save Realtime
     * @param {string} opsiCode 
     */
    handleSelectOpsi(opsiCode) {
        const currentSoal = this.soalData[this.currentIndex];

        // Jika sudah dipilih opsi yang sama, tidak perlu kirim ulang
        if (currentSoal.jawaban_terpilih === opsiCode) {
            return;
        }

        currentSoal.jawaban_terpilih = opsiCode;

        // Perbarui tampilan item opsi
        const items = this.dom.opsiList.querySelectorAll('.opsi-item');
        items.forEach(item => {
            const isMatch = (item.dataset.code === opsiCode);
            item.classList.toggle('selected', isMatch);
            const radio = item.querySelector('input[type="radio"]');
            if (radio) radio.checked = isMatch;
        });

        // Perbarui grid nomor
        this.updateGridClasses();

        // Kirim Auto-Save ke server via Fetch API
        this.saveJawabanToServer(currentSoal.id_soal, opsiCode);
    }

    /**
     * Mengubah Status Ragu-Ragu dan Menyimpan ke Server
     * @param {boolean} isRagu 
     */
    handleRaguChange(isRagu) {
        const currentSoal = this.soalData[this.currentIndex];
        currentSoal.status_ragu = isRagu;

        this.updateGridClasses();
        this.saveRaguToServer(currentSoal.id_soal, isRagu);
    }

    /**
     * Mengirimkan Jawaban ke ajax_save.php
     * @param {number} idSoal 
     * @param {string} jawaban 
     */
    saveJawabanToServer(idSoal, jawaban) {
        const sisaDetik = this.timerInstance ? this.timerInstance.getRemainingSeconds() : 0;
        const payload = {
            csrf_token: this.csrfToken,
            id_ujian_siswa: this.idUjianSiswa,
            id_soal: idSoal,
            jawaban_terpilih: jawaban,
            sisa_detik: sisaDetik
        };

        this.setSyncStatus('saving', 'Menyimpan jawaban...');

        fetch(this.saveUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        })
        .then(response => {
            if (!response.ok) throw new Error('Network error');
            return response.json();
        })
        .then(data => {
            if (data.success) {
                this.setSyncStatus('saved', 'Jawaban tersimpan');
            } else {
                throw new Error(data.message || 'Gagal menyimpan');
            }
        })
        .catch(err => {
            console.warn('Gagal koneksi auto-save, memasukkan ke antrian coba lagi:', err);
            this.setSyncStatus('error', 'Koneksi terganggu (Akan dicoba ulang)');
            this.retryQueue.push({ type: 'save', payload: payload });
            setTimeout(() => this.processRetryQueue(), 4000);
        });
    }

    /**
     * Mengirimkan Status Ragu-ragu ke ajax_ragu.php
     * @param {number} idSoal 
     * @param {boolean} isRagu 
     */
    saveRaguToServer(idSoal, isRagu) {
        const payload = {
            csrf_token: this.csrfToken,
            id_ujian_siswa: this.idUjianSiswa,
            id_soal: idSoal,
            status_ragu: isRagu ? 1 : 0
        };

        fetch(this.raguUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .catch(err => {
            console.warn('Gagal simpan status ragu:', err);
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
        .then(res => res.json())
        .then(() => {
            this.setSyncStatus('saved', 'Semua data tersinkronisasi');
            if (this.retryQueue.length > 0) {
                this.processRetryQueue();
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

            // Status Ragu atau Dijawab
            if (soal.status_ragu) {
                btn.classList.add('ragu');
            } else if (soal.jawaban_terpilih && soal.jawaban_terpilih.trim() !== '') {
                btn.classList.add('dijawab');
            }
        });
    }

    /**
     * Menampilkan Modal Konfirmasi Selesai Ujian
     */
    showFinishModal() {
        let dijawab = 0;
        let ragu = 0;
        let belum = 0;

        this.soalData.forEach(soal => {
            if (soal.status_ragu) {
                ragu++;
            }
            if (soal.jawaban_terpilih && soal.jawaban_terpilih.trim() !== '') {
                dijawab++;
            } else {
                belum++;
            }
        });

        if (this.dom.modalRingkasan) {
            this.dom.modalRingkasan.innerHTML = `
                <div style="background:#f8fafc; padding:1rem; border-radius:6px; margin:1rem 0; font-size:0.95rem;">
                    <div class="flex-between mb-2">
                        <span>Total Butir Soal:</span>
                        <strong>${this.soalData.length}</strong>
                    </div>
                    <div class="flex-between mb-2 text-success">
                        <span>Sudah Dijawab:</span>
                        <strong>${dijawab}</strong>
                    </div>
                    <div class="flex-between mb-2 text-danger">
                        <span>Belum Dijawab:</span>
                        <strong>${belum}</strong>
                    </div>
                    <div class="flex-between text-warning">
                        <span>Masih Ragu-ragu:</span>
                        <strong>${ragu}</strong>
                    </div>
                </div>
                ${(belum > 0 || ragu > 0) ? `
                    <div class="alert alert-warning text-sm">
                        <strong>Perhatian:</strong> Masih ada soal yang belum dijawab atau bertanda ragu-ragu. Anda yakin ingin mengakhiri sesi sekarang?
                    </div>
                ` : `
                    <div class="alert alert-success text-sm">
                        Seluruh soal telah Anda jawab. Klik <strong>SELESAIKAN UJIAN</strong> untuk mengirimkan lembar jawaban akhir.
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
