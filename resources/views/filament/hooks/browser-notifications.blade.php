<script>
(() => {
    const ENABLED_KEY = 'palorinjani_admin_alerts_enabled';
    const SEEN_KEY = 'palorinjani_seen_notification_ids';
    const BANNER_ID = 'palorinjani-alerts-banner';
    const MAX_SEEN_IDS = 200;

    let isInitialized = false;
    let isSpaNavigating = false;
    let audioContext = null;
    // Chrome hanya mengizinkan AudioContext dibuat/di-resume setelah gesture
    // pengguna sejak halaman dimuat. Tanpa flag ini, chime memicu warning
    // "AudioContext was prevented from starting automatically".
    let hasUserGesture = false;

    function alertsEnabled() {
        try {
            return localStorage.getItem(ENABLED_KEY) === '1';
        } catch {
            return false;
        }
    }

    function setAlertsEnabled() {
        try {
            localStorage.setItem(ENABLED_KEY, '1');
        } catch (error) {
            console.warn('Gagal menyimpan preferensi alert admin:', error);
        }
    }

    function getSeenIds() {
        try {
            const raw = localStorage.getItem(SEEN_KEY);
            const parsed = raw ? JSON.parse(raw) : [];

            return new Set(Array.isArray(parsed) ? parsed : []);
        } catch {
            return new Set();
        }
    }

    function saveSeenIds(seenIds) {
        try {
            localStorage.setItem(SEEN_KEY, JSON.stringify(Array.from(seenIds).slice(-MAX_SEEN_IDS)));
        } catch (error) {
            console.warn('Gagal menyimpan daftar notifikasi yang sudah dilihat:', error);
        }
    }

    function getAudioContext() {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;

        if (!AudioContextClass) {
            return null;
        }

        if (!audioContext) {
            audioContext = new AudioContextClass();
        }

        return audioContext;
    }

    async function resumeAudio() {
        if (!hasUserGesture) {
            return;
        }

        const context = getAudioContext();

        if (!context) {
            return;
        }

        if (context.state === 'suspended') {
            try {
                await context.resume();
            } catch {
                return;
            }
        }
    }

    async function playNotificationChime() {
        if (!alertsEnabled() || !hasUserGesture) {
            return;
        }

        try {
            const context = getAudioContext();

            if (!context) {
                return;
            }

            if (context.state === 'suspended') {
                await context.resume();
            }

            if (context.state !== 'running') {
                return;
            }

            const now = context.currentTime;

            const firstTone = context.createOscillator();
            const firstGain = context.createGain();
            firstTone.type = 'sine';
            firstTone.frequency.setValueAtTime(880, now);
            firstGain.gain.setValueAtTime(0.12, now);
            firstGain.gain.exponentialRampToValueAtTime(0.001, now + 0.28);
            firstTone.connect(firstGain);
            firstGain.connect(context.destination);
            firstTone.start(now);
            firstTone.stop(now + 0.28);

            const secondTone = context.createOscillator();
            const secondGain = context.createGain();
            secondTone.type = 'sine';
            secondTone.frequency.setValueAtTime(1318.51, now + 0.1);
            secondGain.gain.setValueAtTime(0.18, now + 0.1);
            secondGain.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
            secondTone.connect(secondGain);
            secondGain.connect(context.destination);
            secondTone.start(now + 0.1);
            secondTone.stop(now + 0.5);
        } catch (error) {
            console.warn('Gagal memutar audio notifikasi:', error);
        }
    }

    function hideBanner() {
        document.getElementById(BANNER_ID)?.remove();
    }

    function showBannerIfNeeded() {
        if (alertsEnabled()) {
            hideBanner();

            return;
        }

        if (document.getElementById(BANNER_ID)) {
            return;
        }

        const banner = document.createElement('div');
        banner.id = BANNER_ID;
        banner.setAttribute('role', 'region');
        banner.setAttribute('aria-label', 'Aktifkan notifikasi pesanan');
        banner.style.cssText = [
            'position:fixed',
            'right:1rem',
            'bottom:1rem',
            'z-index:60',
            'display:flex',
            'align-items:center',
            'gap:0.75rem',
            'max-width:calc(100vw - 2rem)',
            'padding:0.75rem 0.875rem',
            'border:1px solid #d6d3d1',
            'border-radius:0.75rem',
            'background:#ffffff',
            'box-shadow:0 12px 30px rgba(0,0,0,0.14)',
            'font-family:inherit',
        ].join(';');

        const message = document.createElement('span');
        message.textContent = 'Aktifkan audio bel & notifikasi pesanan masuk';
        message.style.cssText = 'font-size:0.8125rem;line-height:1.35;color:#292524;';
        banner.appendChild(message);

        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Aktifkan';
        button.style.cssText = [
            'flex-shrink:0',
            'border:0',
            'border-radius:0.5rem',
            'background:#1e5e3e',
            'color:#ffffff',
            'padding:0.5rem 0.75rem',
            'font-size:0.8125rem',
            'font-weight:600',
            'cursor:pointer',
        ].join(';');
        button.addEventListener('click', enableAlerts);
        banner.appendChild(button);

        document.body.appendChild(banner);
    }

    async function enableAlerts() {
        hasUserGesture = true;
        setAlertsEnabled();
        hideBanner();

        const context = getAudioContext();
        const permissionPromise = ('Notification' in window && Notification.permission === 'default')
            ? Notification.requestPermission()
            : Promise.resolve('Notification' in window ? Notification.permission : 'unsupported');

        if (context && context.state === 'suspended') {
            try {
                await context.resume();
            } catch {
                // Browser tetap bisa memblokir audio sampai interaksi berikutnya.
            }
        }

        try {
            await permissionPromise;
        } catch {
            // Izin desktop bersifat opsional; audio tetap aktif.
        }

        await playNotificationChime();
    }

    function showToast(title, body, targetUrl) {
        if (!window.FilamentNotification) {
            return;
        }

        const toast = new window.FilamentNotification()
            .title(title)
            .body(body)
            .duration(10000);

        if (/cod|konfirmasi/i.test(title)) {
            toast.warning();
        } else {
            toast.success();
        }

        if (targetUrl && window.FilamentNotificationAction) {
            toast.actions([
                new window.FilamentNotificationAction('view')
                    .label('Buka')
                    .button()
                    .url(targetUrl),
            ]);
        }

        toast.send();
    }

    function showDesktopNotification(title, body, notificationId, targetUrl) {
        if (!document.hidden || !alertsEnabled()) {
            return;
        }

        if (!('Notification' in window) || Notification.permission !== 'granted') {
            return;
        }

        try {
            const desktopNotification = new Notification(title, {
                body: body,
                icon: '/favicon.ico',
                tag: notificationId,
            });

            desktopNotification.onclick = () => {
                window.focus();

                if (targetUrl) {
                    window.location.href = targetUrl;
                }

                desktopNotification.close();
            };
        } catch (error) {
            console.warn('Gagal memunculkan notifikasi desktop:', error);
        }
    }

    function isDatabaseNotificationsComponentLoaded() {
        return document.querySelector('.fi-no-database') !== null;
    }

    function processIncomingNotifications() {
        const unreadContainers = document.querySelectorAll('.fi-no-notification-unread-ctn');
        const seenIds = getSeenIds();

        if (unreadContainers.length === 0) {
            if (isDatabaseNotificationsComponentLoaded()) {
                isInitialized = true;
            }

            saveSeenIds(seenIds);

            return;
        }

        let hasNewNotification = false;

        unreadContainers.forEach((container) => {
            const wireKey = container.getAttribute('wire:key') || '';
            const notificationId = wireKey.split('.')[0];

            if (!notificationId) {
                return;
            }

            if (!isInitialized) {
                seenIds.add(notificationId);

                return;
            }

            if (seenIds.has(notificationId)) {
                return;
            }

            seenIds.add(notificationId);
            hasNewNotification = true;

            const titleElement = container.querySelector('.fi-no-notification-title');
            const bodyElement = container.querySelector('.fi-no-notification-body');
            const actionLink = container.querySelector('.fi-no-notification-actions a');

            const title = titleElement?.textContent?.trim() || 'Pesanan Baru Masuk!';
            const body = bodyElement?.textContent?.trim() || '';
            const targetUrl = actionLink?.getAttribute('href') || null;

            if (!document.hidden) {
                showToast(title, body, targetUrl);
            }

            showDesktopNotification(title, body, notificationId, targetUrl);
        });

        saveSeenIds(seenIds);
        isInitialized = true;

        if (hasNewNotification && alertsEnabled()) {
            playNotificationChime();
        }
    }

    function registerLivewireHook() {
        if (window.__palorinjaniAlertsLivewireHooked) {
            return true;
        }

        // Livewire v4 menyuntikkan script-nya sendiri di akhir <body>, yaitu
        // SETELAH render hook BODY_END. Jadi window.Livewire bisa belum ada
        // saat skrip ini dieksekusi; jangan menyerah, tunggu.
        if (!window.Livewire || typeof window.Livewire.hook !== 'function') {
            return false;
        }

        window.__palorinjaniAlertsLivewireHooked = true;

        window.Livewire.hook('commit', ({ component, succeed }) => {
            succeed(() => {
                if (component?.name === 'database-notifications') {
                    setTimeout(processIncomingNotifications, 150);
                }
            });
        });

        return true;
    }

    function waitForLivewire(attempt = 0) {
        if (registerLivewireHook() || attempt >= 40) {
            return;
        }

        setTimeout(() => waitForLivewire(attempt + 1), 250);
    }

    function init() {
        waitForLivewire();
        showBannerIfNeeded();

        setTimeout(processIncomingNotifications, 800);

        // Jaring pengaman: bila hook 'commit' Livewire tidak tersedia/gagal,
        // DOM tetap dipindai tiap 15 detik (sama dengan interval polling server).
        setInterval(processIncomingNotifications, 15000);
    }

    if (window.__palorinjaniAlertsInit) {
        return;
    }

    window.__palorinjaniAlertsInit = true;

    // Livewire v4 biasanya dimuat setelah skrip ini; daftarkan hook begitu siap.
    document.addEventListener('livewire:init', () => {
        waitForLivewire();
    });

    document.addEventListener('livewire:navigate', () => {
        isSpaNavigating = true;
    });

    document.addEventListener('livewire:navigated', () => {
        showBannerIfNeeded();

        if (!isSpaNavigating) {
            return;
        }

        isSpaNavigating = false;
        isInitialized = false;

        setTimeout(processIncomingNotifications, 500);
    });

    const resumeOnGesture = () => {
        hasUserGesture = true;

        if (alertsEnabled()) {
            resumeAudio();
        }
    };

    // Jangan { once: true }: Chrome bisa men-suspend AudioContext setelah idle
    // atau pindah tab, dan resume butuh gesture pengguna. Listener ini murah.
    document.addEventListener('pointerdown', resumeOnGesture, { capture: true });
    document.addEventListener('keydown', resumeOnGesture, { capture: true });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            // Fokus tab bukan "user activation"; resume hanya berhasil bila
            // sebelumnya sudah ada gesture (dijaga di resumeAudio()).
            resumeAudio();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
</script>
