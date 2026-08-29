/**
 * ============================================================================
 * CBT SYSTEM - TIMER SINKRON & AUTO-SUBMIT MODULE
 * ============================================================================
 * Mengatur hitung mundur waktu pengerjaan yang terkalibrasi dengan sisa waktu
 * server. Kebal terhadap background tab throttling dengan memanfaatkan delta timestamp.
 */

class CBTTimer {
    /**
     * Inisialisasi Timer
     * @param {Object} options 
     * @param {number} options.initialSeconds - Sisa waktu dari server dalam detik
     * @param {string} options.displayElementId - ID elemen HTML tempat waktu ditampilkan
     * @param {function} options.onTick - Callback setiap detik berdetak
     * @param {function} options.onTimeUp - Callback ketika waktu habis (auto-submit)
     */
    constructor(options = {}) {
        this.remainingSeconds = Math.max(0, parseInt(options.initialSeconds, 10) || 0);
        this.displayElement = document.getElementById(options.displayElementId || 'timer-display');
        this.onTick = typeof options.onTick === 'function' ? options.onTick : null;
        this.onTimeUp = typeof options.onTimeUp === 'function' ? options.onTimeUp : null;
        
        this.intervalId = null;
        this.lastTimestamp = Date.now();
        this.isFinished = false;
        
        this.updateDisplay();
    }

    /**
     * Memulai hitung mundur
     */
    start() {
        if (this.intervalId !== null) return;
        this.lastTimestamp = Date.now();

        this.intervalId = setInterval(() => {
            const currentTimestamp = Date.now();
            const elapsedDelta = Math.floor((currentTimestamp - this.lastTimestamp) / 1000);

            if (elapsedDelta >= 1) {
                this.remainingSeconds = Math.max(0, this.remainingSeconds - elapsedDelta);
                this.lastTimestamp = currentTimestamp;
                this.updateDisplay();

                if (this.onTick) {
                    this.onTick(this.remainingSeconds);
                }

                if (this.remainingSeconds <= 0 && !this.isFinished) {
                    this.stop();
                    this.isFinished = true;
                    if (this.onTimeUp) {
                        this.onTimeUp();
                    }
                }
            }
        }, 500); // Polling setiap 500ms untuk akurasi tinggi
    }

    /**
     * Menghentikan timer
     */
    stop() {
        if (this.intervalId !== null) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
    }

    /**
     * Memperbarui tampilan format HH:MM:SS
     */
    updateDisplay() {
        if (!this.displayElement) return;

        const hours = Math.floor(this.remainingSeconds / 3600);
        const minutes = Math.floor((this.remainingSeconds % 3600) / 60);
        const seconds = this.remainingSeconds % 60;

        const formatted = [
            String(hours).padStart(2, '0'),
            String(minutes).padStart(2, '0'),
            String(seconds).padStart(2, '0')
        ].join(':');

        this.displayElement.textContent = formatted;

        // Peringatan visual jika waktu tersisa di bawah 5 menit (300 detik)
        if (this.remainingSeconds <= 300) {
            this.displayElement.classList.add('timer-danger');
        } else {
            this.displayElement.classList.remove('timer-danger');
        }
    }

    /**
     * Mengambil sisa detik saat ini
     * @returns {number}
     */
    getRemainingSeconds() {
        return this.remainingSeconds;
    }

    /**
     * Sinkronisasi ulang waktu dengan server jika ada pembaruan response
     * @param {number} serverSeconds 
     */
    syncWithServer(serverSeconds) {
        if (typeof serverSeconds === 'number' && serverSeconds >= 0) {
            this.remainingSeconds = serverSeconds;
            this.lastTimestamp = Date.now();
            this.updateDisplay();
        }
    }
}

// Ekspor ke window agar dapat digunakan secara global
window.CBTTimer = CBTTimer;
