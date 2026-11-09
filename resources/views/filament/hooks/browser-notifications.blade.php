<script>
(() => {
    const STORAGE_KEY = 'palorinjani_seen_notification_ids';
    let isInitialized = false;

    // 1. Ambil ID notifikasi yang sudah pernah dilihat dari localStorage
    function getSeenIds() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            return raw ? new Set(JSON.parse(raw)) : new Set();
        } catch {
            return new Set();
        }
    }

    function saveSeenIds(seenSet) {
        try {
            // Batasi ukuran cache localStorage agar tidak membesar tanpa batas (simpan max 200 ID terbaru)
            const arr = Array.from(seenSet).slice(-200);
            localStorage.setItem(STORAGE_KEY, JSON.stringify(arr));
        } catch (e) {
            console.warn('Gagal menyimpan seen notification ids:', e);
        }
    }

    // 2. Audio Chime menggunakan Web Audio API murni (Zero-dependency, tanpa file mp3 eksternal)
    function playNotificationChime() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const ctx = new AudioCtx();

            if (ctx.state === 'suspended') {
                ctx.resume();
            }

            const now = ctx.currentTime;

            // Nada 1: 880 Hz (A5)
            const osc1 = ctx.createOscillator();
            const gain1 = ctx.createGain();
            osc1.type = 'sine';
            osc1.frequency.setValueAtTime(880, now);
            gain1.gain.setValueAtTime(0.12, now);
            gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.28);
            osc1.connect(gain1);
            gain1.connect(ctx.destination);
            osc1.start(now);
            osc1.stop(now + 0.28);

            // Nada 2: 1318.51 Hz (E6 - harmoni kuint tinggi)
            const osc2 = ctx.createOscillator();
            const gain2 = ctx.createGain();
            osc2.type = 'sine';
            osc2.frequency.setValueAtTime(1318.51, now + 0.1);
            gain2.gain.setValueAtTime(0.18, now + 0.1);
            gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
            osc2.connect(gain2);
            gain2.connect(ctx.destination);
            osc2.start(now + 0.1);
            osc2.stop(now + 0.5);
        } catch (err) {
            console.warn('Audio chime playback error:', err);
        }
    }

    // 3. Minta izin Browser Desktop Notification saat user berinteraksi
    function initNotificationPermission() {
        if (!('Notification' in window)) return;

        if (Notification.permission === 'default') {
            const requestPermissionOnFirstClick = () => {
                Notification.requestPermission().then((permission) => {
                    if (permission === 'granted' && window.FilamentNotification) {
                        new window.FilamentNotification()
                            .title('Notifikasi Desktop Aktif')
                            .body('Anda akan menerima notifikasi sistem setiap ada pesanan masuk.')
                            .success()
                            .duration(5000)
                            .send();
                    }
                });
            };

            document.addEventListener('click', requestPermissionOnFirstClick, { once: true });
        }
    }

    // 4. Deteksi & proses notifikasi unread yang baru masuk
    function processIncomingNotifications() {
        const unreadContainers = document.querySelectorAll('.fi-no-notification-unread-ctn');
        const seenIds = getSeenIds();

        // Fix initial load empty state bug: pastikan isInitialized selalu true meski belum ada unread notifikasi
        if (!unreadContainers || unreadContainers.length === 0) {
            isInitialized = true;
            return;
        }

        let newOrdersFound = false;

        unreadContainers.forEach((container) => {
            // Ambil ID unik notifikasi dari wire:key atau atribut
            const wireKey = container.getAttribute('wire:key') || '';
            const notifId = wireKey.split('.')[0] || container.id || '';

            if (!notifId) return;

            // Jika pada load pertama, tandai semua notifikasi yang sudah ada sebagai "sudah dilihat"
            if (!isInitialized) {
                seenIds.add(notifId);
                return;
            }

            // Jika belum pernah dilihat, picu pop-up dan suara!
            if (!seenIds.has(notifId)) {
                seenIds.add(notifId);
                newOrdersFound = true;

                const titleEl = container.querySelector('.fi-no-notification-title');
                const bodyEl = container.querySelector('.fi-no-notification-body');
                const actionLink = container.querySelector('.fi-no-notification-actions a');

                const title = titleEl ? titleEl.innerText.trim() : 'Pesanan Baru Masuk!';
                const body = bodyEl ? bodyEl.innerText.trim() : '';
                const targetUrl = actionLink ? actionLink.getAttribute('href') : null;

                const isWarning = title.toLowerCase().includes('cod') || title.toLowerCase().includes('konfirmasi');

                // A. Tampilkan Filament In-App Toast Popup
                if (window.FilamentNotification) {
                    const toast = new window.FilamentNotification()
                        .title(title)
                        .body(body)
                        .duration(10000);

                    if (isWarning) {
                        toast.warning();
                    } else {
                        toast.success();
                    }

                    if (targetUrl && window.FilamentNotificationAction) {
                        toast.actions([
                            new window.FilamentNotificationAction('view')
                                .label('Buka')
                                .button()
                                .url(targetUrl)
                        ]);
                    }

                    toast.send();
                }

                // B. Tampilkan Native Desktop Notification (Bisa muncul meski tab sedang di-minimize/background)
                if ('Notification' in window && Notification.permission === 'granted') {
                    try {
                        const desktopNotif = new Notification(title, {
                            body: body,
                            icon: '/favicon.ico',
                            tag: notifId, // Mencegah duplikasi di level OS
                        });

                        if (targetUrl) {
                            desktopNotif.onclick = () => {
                                window.focus();
                                window.location.href = targetUrl;
                            };
                        }
                    } catch (e) {
                        console.warn('Gagal memunculkan notifikasi desktop:', e);
                    }
                }
            }
        });

        if (newOrdersFound) {
            playNotificationChime();
        }

        saveSeenIds(seenIds);
        isInitialized = true;
    }

    // 5. Inisialisasi Lifecycle & Event Listeners
    document.addEventListener('DOMContentLoaded', () => {
        initNotificationPermission();
        setTimeout(processIncomingNotifications, 800);
    });

    // Registrasi Hook Livewire v3 secara eager & event-based
    function registerLivewireHook() {
        if (!window.Livewire) return;
        window.Livewire.hook('commit', ({ component, succeed }) => {
            succeed(() => {
                if (component.name === 'database-notifications' || component.name === 'filament.livewire.database-notifications') {
                    setTimeout(processIncomingNotifications, 150);
                }
            });
        });
    }

    if (window.Livewire) {
        registerLivewireHook();
    } else {
        document.addEventListener('livewire:init', registerLivewireHook);
    }

    // Fallback polling timer sisi klien setiap 30 detik memanggil $refresh()
    if (window.__palorinjaniNotifTimer) {
        clearInterval(window.__palorinjaniNotifTimer);
    }
    window.__palorinjaniNotifTimer = setInterval(() => {
        const btn = document.querySelector('.fi-topbar-database-notifications-btn');
        const wireEl = btn?.closest('[wire\\:id]') || document.querySelector('.fi-no-database[wire\\:id]');
        if (wireEl && window.Livewire) {
            const comp = window.Livewire.find(wireEl.getAttribute('wire:id'));
            if (comp && typeof comp.$refresh === 'function') {
                comp.$refresh();
            }
        }
    }, 30000);
})();
</script>
