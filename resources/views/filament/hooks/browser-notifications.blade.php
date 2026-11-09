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
        if (!unreadContainers || unreadContainers.length === 0) return;

        const seenIds = getSeenIds();
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

    // Hook Livewire v3 saat request polling commit selesai
    document.addEventListener('livewire:init', () => {
        if (window.Livewire) {
            window.Livewire.hook('commit', ({ component, succeed }) => {
                succeed(() => {
                    if (component.name === 'filament.notifications.database-notifications') {
                        setTimeout(processIncomingNotifications, 150);
                    }
                });
            });
        }
    });

    // MutationObserver cadangan jika DOM di-update di luar hook Livewire
    const observer = new MutationObserver((mutations) => {
        let hasRelevantMutation = false;
        for (const mutation of mutations) {
            if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                hasRelevantMutation = true;
                break;
            }
        }
        if (hasRelevantMutation) {
            processIncomingNotifications();
        }
    });

    const setupObserver = () => {
        const target = document.getElementById('database-notifications') || document.body;
        if (target) {
            observer.observe(target, { childList: true, subtree: true });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupObserver);
    } else {
        setupObserver();
    }
})();
</script>
